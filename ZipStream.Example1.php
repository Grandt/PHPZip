<?php
// Example. Zip all .html files in the current directory and save to current directory.
// Make a copy, also to the current dir, for good measure.
//$mem = ini_get('memory_limit');
$extime = ini_get('max_execution_time');
//
////ini_set('memory_limit', '512M');
ini_set('max_execution_time', 600);

include_once("ZipStream.php");
//print_r(ini_get_all());

$fileTime = date("D, d M Y H:i:s T");

$chapter1 = "Chapter 1\n"
. "Lorem ipsum\n"
. "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec magna lorem, mattis sit amet porta vitae, consectetur ut eros. Nullam id mattis lacus. In eget neque magna, congue imperdiet nulla. Aenean erat lacus, imperdiet a adipiscing non, dignissim eget felis. Nulla facilisi. Vivamus sit amet lorem eget mauris dictum pharetra. In mauris nulla, placerat a accumsan ac, mollis sit amet ligula. Donec eget facilisis dui. Cras elit quam, imperdiet at malesuada vitae, luctus id orci. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Pellentesque eu libero in leo ultrices tristique. Etiam quis ornare massa. Donec in velit leo. Sed eu ante tortor.\n";

$zip = new ZipStream("ZipStreamExample1.zip");

$zip->setComment("Example Zip file for Large file sets.\nCreated on " . date('l jS \of F Y h:i:s A'));
$zip->addFile("Hello World!\r\n", "Hello.txt");

$zip->openStream("big one3.txt");
$zip->addStreamData($chapter1."\n\n\n");
$zip->addStreamData($chapter1."\n\n\n");
$zip->addStreamData($chapter1."\n\n\n");
$zip->closeStream();

// For this test you need to create a large text file called "big one1.txt"
if (file_exists("big one1.txt")) {
    $zip->addLargeFile("big one1.txt", "big one2a.txt");

    $fhandle = fopen("big one1.txt", "rb");
    $zip->addLargeFile($fhandle, "big one2b.txt");
    fclose($fhandle);
}

$zip->addDirectory("Empty Dir");

//Dir test, using the stream option on $zip->addLargeFile
$fileDir = './';
@$handle = opendir($fileDir);
if ($handle) {
    /* This is the correct way to loop over the directory. */
    while (false !== ($file = readdir($handle))) {
        if (($file != '.') && ($file != '..') && is_file($file)) {
            $zip->addLargeFile(($fileDir . $file), "dirTest/".$file, filectime($fileDir . $file));
        }
    }
}

// Add a directory, first recursively, then the same directory, but without recursion.
// Naturally this requires you to change the path to ../test to point to a directory of your own.
$zip->addDirectory("recursiveDir/");
$zip->addDirectoryContent("../test", "recursiveDir/test");
$zip->addDirectoryContent("../test", "recursiveDir/testFlat", FALSE);

$zip->finalize(); // Mandatory, needed to send the Zip files central directory structure.
?>