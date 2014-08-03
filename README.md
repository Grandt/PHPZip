A pair of PHP classes to generate zip files.

The projects that started these two classes are hosted on PHPClasses.org at the addresses:
Zip      : http://www.phpclasses.org/package/6110
ZipStream: http://www.phpclasses.org/package/6616

*****************************************************************************************************************
WARNING: THE CURRENT VERSION OF PHPZip *MAY* FAIL IF THE SERVER HAS mbstring.func_overload INSTALLED AND ACTIVE!
OLDER VERSIONS OF PHPZip *WILL* FAIL IF THE SERVER HAS mbstring.func_overload INSTALLED AND ACTIVE!
EXPERIMENTAL FEATURES HAVE BEEN ADDED TO ALLEVIATE THE LOBOTOMIZATION OF PHP, CAUSED BY mbstring.func_overload
*****************************************************************************************************************

Note: PHPZip currently uses the 32-bit deflate, and is limited by that.
The largest files that can be added are 4GB, and the total size of the archive can't exceed 4gb either.

Zip.php generates the Zip file in memory (or temp file) allowing the parent script to save the final Zip file elsewhere, and/or send it to the user.
ZipStream has much of the same features and functions of Zip.php, with a few notable differences, it does not cache and build the zip file on the server, instead it'll send the file to the user as a stream.

See the examples for example usage. The php files have "some" documentation in them in the form of Javadoc style function headers.

NOTE: Please ensure that output buffering is disabled when using especially ZipStream. It defeats the purpose of the class, and large zip files may cause a memory exceeded exception.
NOTE2: THe Zip and ZipStream classes support UTF-8 in file paths and file comments, and will autodetect UTF-8 strings to that end, however it is up to the user to ensure that other Multibyte chracter sets aren't sent to the class.

TODO:
* Documentation, no one reads it, but everyone complains if it is missing.
* Better examples to fully cover the capabilities of the Zip classes.
* more TODO's.

