<?php
// This function allows you to set ringto/tracking/callerid manually by operating
// on each file being sent to LogMyCall's services endpoint. 
//
// It is in the same scope as the script and as such any variables can be accessed directly. 
// NOTE: This function isn't meant to return anything. 
// php5-cli / ffmpeg
class Callback
{
  function user_function($file, $criteria, $dir) 
  {
    /*
     *  $file_parts = explode('_', $file);
     *  $ringto_number = $file_parts[1];
     * 
     *  OR
     * 
     *  [custom logging function], etc
     * 
     */
    $file_parts = explode('_', $file);
    $calldate = substr($file_parts[5], 0, -4);
    
    $year = substr($calldate, 0, 4);
    $month = substr($calldate, 4, 2);
    $day = substr($calldate, 6, 2);
    $hr = substr($calldate, 9, 2);;
    $min = substr($calldate, 11, 2);
    $sec = substr($calldate, 13, 2);
    $calldate = date("Y-m-d G:i:s", strtotime("$year-$month-$day $hr:$min:$sec") - 60*60*6);
    
    // get duration of file
    $time = exec("ffmpeg -i " . escapeshellarg($dir.'/'.$file) . " 2>&1 | grep 'Duration' | cut -d ' ' -f 4 | sed s/,//");
    list($hms, $milli) = explode('.', $time);
    list($hours, $minutes, $seconds) = explode(':', $hms);
    $duration = ($hours * 3600) + ($minutes * 60) + $seconds;  
    
    $settings = parse_ini_file('settings.ini', true);
    
    // Get OUID of group to assign call to by ringto number
    $lmcdb = NewADOConnection($settings['lmcdb']['adapter']);
    $lmcdb->Connect($settings['lmcdb']['hostname'], 
          $settings['lmcdb']['username'], 
          $settings['lmcdb']['password'], 
          $settings['lmcdb']['db']) or die("Unable to connect!");  
    
    $ou = $lmcdb->Execute("select * from organizational_units where phone_number like '%{$file_parts[2]}%'");
    $result = $ou->FetchNextObject();
    if ($result) {
      $criteria = array(
        'ringto_number' => $file_parts[2],
        'caller_id' => $file_parts[4],
        'call_date' => $calldate,
        'duration' => $duration,
        'tracking_number' => 'None',
        'ouid' => $result->ID
      );
    }
    else {
      $criteria = null;
      echo "Number: $file_parts[2]\n";
    }
    return $criteria;
  }
}



?>
