<?php
/*
 * A test to see if it was possible to recreate the result of
 *  Issue #7, if not the cause.
 * And a demnostration why the author of the script calling zip
 *  needs to be dilligent not to add extra characters to the output.
 */
include_once("ZipStream.php");

$zip = new ZipStream("test.zip");

/*
 * As seen in the output, the above construct with a PHP end and start tag after
 * creating the ZipStream is a bad idea. The Zip file will be starting with a
 * space followed by the newline characters.
 */
$zip->addDirectory("test");
$zip->addDirectoryContent("testData/test","test");
return $zip->finalize();

?>