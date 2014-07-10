<?php
// Example. Zip all .html files in the current directory and send the file for Download.
// Also adds a static text "Hello World!" to the file Hello.txt
$fileDir = './';
ob_start(); // This is only to show that ob_start can be called, however the buffer must be empty when sending.

include_once("Zip.php");
$fileTime = date("D, d M Y H:i:s T");

$zip = new Zip();
//$zip->setExtraField(FALSE);

// The comment can only be ASCII, the Chinese characters fail here, and the Zip spec have no flags for handling that.
$zip->setComment("Example 你好 Zip file.\nCreated on " . date('l jS \of F Y h:i:s A'));

$zip->addFile("你好 1", "hello 1.txt");
$zip->addFile("你好 1", "hello 2.txt", 0, "Hello 1");
$zip->addFile("你好 1", "hello 3.txt", 0, "Hello 你好");
$zip->addFile("你好 2", "你好 1.txt");
$zip->addFile("你好 2", "你好 2.txt", 0, "Hello 1");
$zip->addFile("你好 2", "你好 3.txt", 0, "Hello 你好");
$zip->addFile("你好 3", "你好/hello.txt");
$zip->addFile("你好 4", "你好/你好.txt");
    
$zip->sendZip("Zip.Test6.zip");
?>