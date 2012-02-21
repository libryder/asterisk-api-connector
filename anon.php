<?php
// This function allows you to set ringto/tracking/callerid manually by operating
// on each file being sent to LogMyCall's services endpoint. 
//
// It is in the same scope as the script and as such any variables can be accessed directly. 
// NOTE: This function isn't meant to return anything. 
// 
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

    $criteria = array(
      'ringto_number' => '',
      'caller_id' => '',
      'call_date' => '',
      'duration' => '',
      'tracking_number' => '',
      'ouid' => ''
    );

    return $criteria;
  }
}



?>
