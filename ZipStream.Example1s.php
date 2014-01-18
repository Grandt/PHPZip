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

//$zip = new ZipStream("ZipStreamExample1s.zip");
//$zip = new ZipStream("ZipStreamExample1s_€2,000.zip");
$zip = new ZipStream("ZipStreamExample1s_€2,000.zip", "application/zip", "ZipStreamExample1s_€2,000_utf8.zip");

// Archive comments don't really support utf-8. Some tools detect and read it though.
$zip->setComment("Example Zip file for Large file sets.\nАрхив Комментарий\nCreated on " . date('l jS \of F Y h:i:s A'));

// A bit of russian (I hope), to test UTF-8 file names.
$zip->addFile("Привет мир!", "Кириллица имя файла.txt");
$zip->addFile("Привет мир!", "Привет мир. С комментарий к файлу.txt", 0, "Кириллица файл комментарий");


$zip->openStream("big one3.txt");
$zip->addStreamData($chapter1."\n\n\n");
$zip->addStreamData($chapter1."\n\n\n");
$zip->addStreamData($chapter1."\n\n\n");
$zip->closeStream();

$zip->addDirectory("Empty Dir");

$zip->finalize(); // Mandatory, needed to send the Zip files central directory structure.
?>