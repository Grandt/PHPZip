<?php

set_error_handler("customError"); 
error_reporting(E_ALL | E_STRICT);
ini_set('error_reporting', E_ALL | E_STRICT);
ini_set('display_errors', 1);

$errors = "";

// Example. Zip all .html files in the current directory and send the file for Download.
// Also adds a static text "Hello World!" to the file Hello.txt
$fileDir = './';
ob_start(); // This is only to show that ob_start can be called, however the buffer must be empty when sending.

include_once("Zip.php");
$fileTime = date("D, d M Y H:i:s T");

// Set a temp file to use, instead of the default system temp file directory. 
// The temp file is used if the generated Zip file is becoming too large to hold in memory.
//Zip::$temp = "./tempFile";

// Setting this to a function to create the temp files requires PHP 5.3 or newer:
//Zip::$temp = function() { return tempnam(sys_get_temp_dir(), 'Zip');};
Zip::$temp = function() { return "./tempFile_" . rand(100000, 999999);};

$zip = new Zip();
// Archive comments don't really support utf-8. Some tools detect and read it though.
$zip->setComment("Example Zip file.\nCreated on " . date('l jS \of F Y h:i:s A'));
// A bit of russian (I hope), to test UTF-8 file names.
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

// Uses my Lipsum generator from https://github.com/Grandt/PHPLipsumGenerator
if(file_exists('./LipsumGenerator.php')) {
	require_once './LipsumGenerator.php';
	$lg = new com\grandt\php\LipsumGenerator();
	$zip->openStream("big one3.txt");
	for ($i = 1 ; $i <= 20 ; $i++) {
		$zip->addStreamData("Chapter $i\r\n\r\n" . $lg->generate(300, 2500) . "\r\n");
	}
	$zip->closeStream();
}
$zip->sendZip("ZipExample3.zip", "application/zip", "ZipExample3.zip");

// If non-fatal errors occured during execution, this will append them 
//  to the end of the generated file. 
// It'll create an invalid Zip file, however chances are that it is invalid
//  already due to the error happening in the first place.
// The idea is that errors will be very easy to spot.
if (!empty($errors)) {
	echo "\n\n**************\n*** ERRORS ***\n**************\n\n$errors";
}

function customError($error_level, $error_message, $error_file, $error_line) {
	global $errors;
	switch ($error_level) {
		case 1:     $e_type = 'E_ERROR'; $exit_now = true; break;
		case 2:     $e_type = 'E_WARNING'; break;
		case 4:     $e_type = 'E_PARSE'; break;
		case 8:     $e_type = 'E_NOTICE'; break;
		case 16:    $e_type = 'E_CORE_ERROR'; $exit_now = true; break;
		case 32:    $e_type = 'E_CORE_WARNING'; break;
		case 64:    $e_type = 'E_COMPILE_ERROR'; $exit_now = true; break;
		case 128:   $e_type = 'E_COMPILE_WARNING'; break;
		case 256:   $e_type = 'E_USER_ERROR'; $exit_now = true; break;
		case 512:   $e_type = 'E_USER_WARNING'; break;
		case 1024:  $e_type = 'E_USER_NOTICE'; break;
		case 2048:  $e_type = 'E_STRICT'; break;
		case 4096:  $e_type = 'E_RECOVERABLE_ERROR'; $exit_now = true; break;
		case 8192:  $e_type = 'E_DEPRECATED'; break;
		case 16384: $e_type = 'E_USER_DEPRECATED'; break;
		case 30719: $e_type = 'E_ALL'; $exit_now = true; break;
		default:    $e_type = 'E_UNKNOWN'; break;
	}

	$errors .= "[$error_level: $e_type]: $error_message\n    in $error_file ($error_line)\n\n";
}
/*
// Create a random chapter text.
$lipsum = null;
$lipsumcount = 0;
function createLoremGarbagum($minWordCount = 200, $maxWordCount = 2000, $startlorem = TRUE, $indent = "  ", $eol = "\r\n") {
	
	if (empty($lipsum)) {
		$lipsum = split(" ", "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec magna lorem, mattis sit amet porta vitae, consectetur ut eros. Nullam id mattis lacus. In eget neque magna, congue imperdiet nulla. Aenean erat lacus, imperdiet a adipiscing non, dignissim eget felis. Nulla facilisi. Vivamus sit amet lorem eget mauris dictum pharetra. In mauris nulla, placerat a accumsan ac, mollis sit amet ligula. Donec eget facilisis dui. Cras elit quam, imperdiet at malesuada vitae, luctus id orci. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Pellentesque eu libero in leo ultrices tristique. Etiam quis ornare massa. Donec in velit leo. Sed eu ante tortor.");
		$lipsumcount = sizeof($lipsum);
	}
	$tcount = rand($minWordCount-($startlorem === TRUE ? 8 : 0), $maxWordCount);
	$wcount = 0;
	$wscount = 0;
		
	$chapter = "";
	if ($startlorem === TRUE) {
		$chapter = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. ";
	}
	
	for ($i = 0 ; $i < $tcount ; $i++) {
		$w = $lipsum[rand(1, $lipsumcount)-1];
		
		if ($wscount == 0) {
			$w = ucfirst($w);
		}
		$chapter .= $w;
		
		if (strpos($w, ".") === FALSE) {
			$chapter .= " ";
			$wcount++;
			$wscount++;
		} else {
			$wscount = 0;
			if ($wcount > 3 && rand(0, 5) == 1) {
				$chapter .= $eol . $indent;
				$wcount = 0;
			} else {
				$chapter .= " ";
				$wcount++;
			}
		}
	}
	return $indent . trim($chapter) . ".$eol";
}
 */
 