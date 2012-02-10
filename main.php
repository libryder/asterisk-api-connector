<?php

// *********************** MAIN **************************
//
// Make connection to the database, instantiate the LmcApi 
// class, and process calls
// 


include 'lib/adodb5/adodb.inc.php';
include 'lib/lmc_api.class.php';

$settings = parse_ini_file('settings.ini', true);

// Setup the db connection
$db = NewADOConnection($settings['database']['adapter']);
$db->Connect($settings['database']['hostname'], 
        $settings['database']['username'], 
        $settings['database']['password'], 
        $settings['database']['db']) or die("Unable to connect!");
$ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;

$endpoint = new LmcApi($db, $settings);
$calls = $endpoint->process_calls();

// Show results 
echo "\nResults";
echo "\n-------------------------------\n";
echo "{$calls['found']} files found\n{$calls['processed']} files processed\n";
echo "Errors:\n";
foreach ($calls['error'] as $err)
{
  echo "$err \n";
}
echo "-------------------------------\n";

// cleanup
$db->Close();

?>
