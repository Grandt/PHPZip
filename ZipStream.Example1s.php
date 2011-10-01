<?php
// Example. Zip all .html files in the current directory and save to current directory.
// Make a copy, also to the current dir, for good measure.
//$mem = ini_get('memory_limit');
//$extime = ini_get('max_execution_time');
//
////ini_set('memory_limit', '512M');
//ini_set('max_execution_time', 120);

include_once("ZipStream.php");
//print_r(ini_get_all());

$fileTime = date("D, d M Y H:i:s T");

$chapter1 = "Chapter 1\n"
. "Lorem ipsum\n"
. "Lorem ipsum dolor sit amet, consectetur adipiscing elit.\n";

$zip = new ZipStream("ZipStreamExample1s.zip");

$zip->setComment("Example Zip file for Large file sets.\nCreated on " . date('l jS \of F Y h:i:s A'));

$zip->openStream("big one3.txt");
$zip->addStreamData($chapter1."\n\n\n");
$zip->addStreamData($chapter1."\n\n\n");
$zip->addStreamData($chapter1."\n\n\n");
$zip->closeStream();

$zip->addDirectory("Empty Dir");

$zip->finalize(); // Mandatory, needed to send the Zip files central directory structure.
?>