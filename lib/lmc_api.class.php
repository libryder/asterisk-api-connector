<?php
// API class used to manage sending JSON or Media to LogMyCall's
// services endpoint.
//
// See http://api.logmycalls.com/docs for more information

class LmcApi extends Callback {
  private $endpoint, $data, $settings, $db, $api_auth;
  private $use_cdr = false;

  function __construct($db, $settings, $data=null) 
  {
    $this->endpoint = 'https://api.logmycalls.com/services';
    $this->data = $data;
    $this->settings = $settings;
    $this->db = $db;
    $this->api_auth = $this->settings['auth'];
  }

  public function __get($property) {
    if (property_exists($this, $property)) {
      return $this->$property;
    }
  }

  public function __set($property, $value) {
    if (property_exists($this, $property)) {
      $this->$property = $value;
    }

    return $this;
  }

  // *********************** PROCESS_CALLS **************************
  //
  // Go through all files in directory and attempt to create a new call detail and upload
  // the audio file to LogMyCall's services endpoint
  // 
  // $directories - array   - directories to process calls from
  // $move_files  - boolean - whether to move files according to settings
  public function process_calls($directories=array(), $move_files=null)
  {
    if (count($directories) == 0) 
    { 
      $directories = $this->settings['directories'];
    }
    if ($move_files == null || $move_files == true) 
    {
      $move_files = $this->settings['file_move']['enabled'];
      $move_location = $this->settings['file_move']['directory'];
    }
    
    $count = 0;
    $processed = 0;
    $error = array(); 
    // CDR map settings from settings.ini
    $cdr = $this->settings['cdr'];
    
    foreach ($directories['audio'] as $dir) 
    {
      try 
      {
        if (is_dir($dir)) 
        {
          if ($dh = opendir($dir)) 
          {
            while (($file = readdir($dh)) !== false) 
            {
              $fileinfo = pathinfo($dir.'/'.$file);

              // Directory entry is a file and is an allowable type; proceed.
              if (is_file($dir.'/'.$file) && isset($fileinfo['extension']) && in_array(strtolower($fileinfo['extension']), array("mp3", "wav"))) 
              {
                $count++;
                
                if ($this->use_cdr)
                {
                  $query = "select * from ".$this->settings['database']['table']." where `".$cdr['filename']."` = '".$file."'";
                  $result = $this->db->Execute($query) or die("Error in query: $query. " . $this->db->ErrorMsg());
                  $result_cnt = $result->RecordCount();
                  
                  // Ensure we got 1 and only 1 result from the query
                  if ($result_cnt == 1) {
                    $result = $result->FetchNextObject();
                    
                    // Parse results according to column mapping definition from [cdr] section in settings
                    $criteria = array(
                      'duration' => @$result->{strtoupper($cdr['duration'])},
                      'calldate' => @$result->{strtoupper($cdr['calldate'])},
                      'caller_id' => @$result->{strtoupper($cdr['callerid'])},
                      'tracking_number' => @$result->{strtoupper($cdr['tracking_number'])},
                      'ringto_number' => @$result->{strtoupper($cdr['ringto'])}
                    );
                    
                  }                  
                  else 
                  {
                    // Could not find CDR record, skip this iteration
                    $error[] = "Incorrect number of results while querying CDR table: $result_cnt of 1 expected found.";
                    continue;
                  }
                  
                }
                else
                {
                  // criteria is being set manually, in the callback function
                  $criteria = array();
                }
                // This function will run the function in anon.php. See the settings
                // file for more information. 
                if ($this->settings['callback']['enabled']) 
                { 
                  $criteria = $this->user_function($file, $criteria, $dir);
                  if ($criteria == null) { $error[] = "Problem finding OUID for $file"; continue; }
                }
                else
                {
                  $ouid = '1';
                }
                
                $data = array_merge(array("criteria" => $criteria), $this->api_auth);

                //create call detail
                $call_detail = json_decode($this->post_json($data, "insertCall"), true);

                if ($call_detail['status'] == "success") 
                {
                  $call_detail_id = $call_detail['call_detail']['id'];

                  //upload audio and create recording record
                  $data = array_merge(array("audio" => "@".$dir.'/'.$file, "call_detail_id" => $call_detail_id), $this->api_auth);

                  $recording = json_decode($this->post_media($data), true);
                  if ($recording['status'] == "success") {
                    $processed++;
                    echo "Uploaded $file successfully.\n";
                    // Move file to archive folder if enabled in settings
                    if ($this->settings['file_move']) 
                    {
                      copy($dir.'/'.$file, $move_location.'/'.basename($file));
                      unlink ($dir.'/'.$file);
                    }
                  }
                  else
                  {
                    $error[] = json_encode($recording."($file)";
                  }

                }
                else 
                {
                  $error[] = json_encode($call_detail['error_message'])."($file)";
                }

              }
            }
            closedir($dh);
          }
        }
        else {
          $error[] = "Directory not found: $dir";
        }
      }
      catch (Exception $e) {
        $error[] = "Caught exception: " . $e->getMessage();
      }

    }
    
    return array("processed" => $processed, "found" => $count, "error" => $error);
    
  }

  // post_json()
  //    $data     - array("criteria" => array(), "api_key" => "...", "api_secret" => "...", ...)
  //    $action   - one of the available endpoint actions
  //    $method   - method to send data. POST or GET can be used to send JSON
  //
  //  Returns json array from LogMyCall's services endpoint.
  private function post_json($data, $action, $method = "POST") 
  {
    $formed_uri = $this->endpoint.'/'.$action;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $formed_uri);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));

    if(isset($data)) 
    {
      $data = json_encode($data);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $json_return_data = curl_exec($ch);
    curl_close($ch);
    return $json_return_data;
  }


  // post_media()
  //    $data - array("audio" => "@local_file.mp3", "call_detail_id" => 34231)
  //    $action   - one of the available endpoint actions to send media.
  //    $method   - method to send data. POST has to most definitely be used to transfer
  //                binary data.
  //
  //  NOTE: the audio parameter has to have the "@" symbol in front of the filename
  //        to indicate to CURL it is a binary file on the local system.
  //
  // Returns json array with information about call_detail.
  private function post_media($data=null, $action="uploadAudio", $method = "POST") 
  {
      $formed_uri = $this->endpoint.'/'.$action;

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_HEADER, 0);
      curl_setopt($ch, CURLOPT_VERBOSE, 0);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
      curl_setopt($ch, CURLOPT_URL, $formed_uri);
      curl_setopt($ch, CURLOPT_POST, true);

    if(isset($data)) 
    {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $return_data = curl_exec($ch);
    curl_close($ch);
    return $return_data;
  }

}
?>
