<?php
// Example. Zip all .html files in the current directory and send the file for Download.
// Also adds a static text "Hello World!" to the file Hello.txt
$fileDir = './';
ob_start(); // This is only to show that ob_start can be called, however the buffer must be empty when sending.

include_once("Zip.php");
$fileTime = date("D, d M Y H:i:s T");

$zip = new Zip();
// Archive comments don't really support utf-8. Some tools detect and read it though.
$zip->setComment("Example Zip file.\nАрхив Комментарий\nCreated on " . date('l jS \of F Y h:i:s A'));
// A bit of russian (I hope), to test UTF-8 file names.
$zip->addFile("Привет мир!", "Кириллица имя файла.txt");
$zip->addFile("Привет мир!", "Привет мир. С комментарий к файлу.txt", 0, "Кириллица файл комментарий");
$zip->addFile("Hello World!", "hello.txt");

@$handle = opendir($fileDir);
if ($handle) {
    /* This is the correct way to loop over the directory. */
    while (false !== ($file = readdir($handle))) {
        if (strpos($file, ".php") !== false) {
            $pathData = pathinfo($fileDir . $file);
            $fileName = $pathData['filename'];

            $zip->addFile(file_get_contents($fileDir . $file), $file, filectime($fileDir . $file), NULL, TRUE, Zip::getFileExtAttr($file));
        }
    }
}

// Add a directory, first recursively, then the same directory, but without recursion.
// Naturally this requires you to change the path to ../test to point to a directory of your own.
// $zip->addDirectoryContent("testData/test", "recursiveDir/test");
// $zip->addDirectoryContent("testData/test", "recursiveDir/testFlat", FALSE);

//$zip->sendZip("ZipExample1.zip");
//$zip->sendZip("ZipExample1_€2,000.zip");
$zip->sendZip("ZipExample1_€2,000.zip", "application/zip", "ZipExample1_€2,000_utf8.zip");
?>