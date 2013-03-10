<?php
// Example. Zip all .html files in the current directory and send the file for Download.
// Also adds a static text "Hello World!" to the file Hello.txt
$fileDir = './';
ob_start(); // This is only to show that ob_start can be called, however the buffer must be empty when sending.

include_once("Zip.php");
$fileTime = date("D, d M Y H:i:s T");

$zip = new Zip();
$zip->setComment("Example Zip file.\nCreated on " . date('l jS \of F Y h:i:s A'));
$zip->addFile("Hello World!", "hello.txt");

@$handle = opendir($fileDir);
if ($handle) {
    /* This is the correct way to loop over the directory. */
    while (false !== ($file = readdir($handle))) {
        if (strpos($file, ".html") !== false) {
            $pathData = pathinfo($fileDir . $file);
            $fileName = $pathData['filename'];

            $zip->addFile(file_get_contents($fileDir . $file), $file, filectime($fileDir . $file));
        }
    }
}

// Add a directory, first recursively, then the same directory, but without recursion.
// Naturally this requires you to change the path to ../test to point to a directory of your own.
$zip->addDirectoryContent("testData/test", "recursiveDir/test");
$zip->addDirectoryContent("testData/test", "recursiveDir/testFlat", FALSE);

$zip->sendZip("ZipExample1.zip");
?>