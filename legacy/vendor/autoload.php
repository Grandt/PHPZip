<?php
/**
 * User: Grandt
 * Date: 29-07-14
 */

$currentExtensions = spl_autoload_extensions();
if (strpos($currentExtensions, ",.php") === false) {
    spl_autoload_extensions($currentExtensions . ',.php');
}

/*** class Loader ***/
function PHPZipLoader($class) {
    $filename = str_replace('PHPZip\\Zip\\', '../src/Zip/', $class);

    $file = $filename . '.php';

    if ("\\" != DIRECTORY_SEPARATOR) {
        $file = str_replace("\\", DIRECTORY_SEPARATOR, $file);
    }

    if ("/" != DIRECTORY_SEPARATOR) {
        $file = str_replace("/", DIRECTORY_SEPARATOR, $file);
    }

    if (!file_exists($file)) {
        return false;
    }
    require($file);
}

/*** register the loader functions ***/
spl_autoload_register('PHPZipLoader');
