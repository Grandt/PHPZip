<?php
error_reporting(E_ALL | E_STRICT);
ini_set('error_reporting', E_ALL | E_STRICT);
ini_set('display_errors', 1);

$extime = ini_get('max_execution_time');

ini_set('memory_limit', '128M');
ini_set('max_execution_time', 600);

require 'bootstrap.php';


$outFile = "ZipMerge.Example1.zip";

$zipMerge = new PHPZip\Zip\Stream\ZipMerge($outFile);
//$zipMerge->appendZip("../testData/500k.zip", "TrueCryptRandomFile");
$zipMerge->appendZip("../testData/test.zip", "Sub Dir test/");
$zipMerge->appendZip("../testData/test.zip", "");
/*
$handle = fopen("ZipStreamExample1.zip", 'r');
$zipMerge->appendZip($handle, "ZipStreamExample1.zip");
fclose($handle);
*/
$zipMerge->finalize();