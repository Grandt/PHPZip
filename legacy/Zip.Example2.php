<?php
// Example. Zip all .html files in the current directory and save to current directory.
// Make a copy, also to the current dir, for good measure.
$fileDir = './';

include_once("Zip.php");
$fileTime = date("D, d M Y H:i:s T");

$zip = new Zip();
$zip->setZipFile("ZipExample.zip");

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

$zip->finalize(); // as we are not using getZipData or getZipFile, we need to call finalize ourselves.
$zip->setZipFile("ZipExample2.zip");
?>
<html>
<head>
<title>Zip Test</title>
</head>
<body>
<h1>Zip Test</h1>
<p>Zip files saved, length is <?php echo strlen($zip->getZipData()); ?> bytes.</p>
</body>
</html>