<?php
/**
 * Class to create zip file, aimed at large files, or even large target zip file.
 * This class will stream the generated zip file directly to the HTTP client as the content is added.
 *
 * If you need the Zip file data on the server, for storage in a database of the server file system, look at
 *  the Zip class at http://www.phpclasses.org/browse/package/6110.html
 *
 * License: GNU LGPL, Attribution required for commercial implementations, requested for everything else.
 *
 * Inspired on CreateZipFile by Rochak Chauhan  www.rochakchauhan.com (http://www.phpclasses.org/browse/package/2322.html)
 * and
 * http://www.pkware.com/documents/casestudies/APPNOTE.TXT Zip file specification.
 *
 * @author A. Grandt
 * @copyright A. Grandt 2010-2011
 * @license GNU LGPL, Attribution required for commercial implementations, requested for everything else.
 * @link http://www.phpclasses.org/package/6616
 * @version 1.30
 */
class ZipStream {
	const VERSION = 1.30;

	const ZIP_LOCAL_FILE_HEADER = "\x50\x4b\x03\x04"; // Local file header signature
	const ZIP_CENTRAL_FILE_HEADER = "\x50\x4b\x01\x02"; // Central file header signature
	const ZIP_END_OF_CENTRAL_DIRECTORY = "\x50\x4b\x05\x06\x00\x00\x00\x00"; //end of Central directory record

	const EXT_FILE_ATTR_DIR = "\x10\x40\xed\x41"; // Unix : Dir + mod:755
	const EXT_FILE_ATTR_FILE = "\x00\x40\xa4\x81"; // Unix : File + mod:644

	const ATTR_VERSION_TO_EXTRACT = "\x0A\x00"; // Version needed to extract
	const ATTR_MADE_BY_VERSION = "\x15\x03"; // Made By Version

	private $zipMemoryThreshold = 1048576; // Autocreate tempfile if the zip data exceeds 1048576 bytes (1 MB)

	private $zipComment = null;
	private $cdRec = array(); // central directory
	private $offset = 0;
	private $isFinalized = false;
	private $addExtraField = TRUE;

	private $streamChunkSize = 65536;
	private $streamFilePath = null;
	private $streamTimeStamp = null;
	private $streamComment = null;
	private $streamFile = null;
	private $streamData = null;
	private $streamFileLength = 0;

	/**
	 * Constructor.
	 *
	 * @param $archiveName String. Name to send to the HTTP client.
	 * @param String $contentType Content mime type. Optional, defaults to "application/zip".
	 */
	function __construct($archiveName = "", $contentType = "application/zip") {
		if ( !function_exists('sys_get_temp_dir')) {
			die ("ERROR: ZipStream " . self::VERSION . " requires PHP version 5.2.1 or above.");
		}
		if (!headers_sent($headerFile, $headerLine) or die("<p><strong>Error:</strong> Unable to send file $archiveName. HTML Headers have already been sent from <strong>$headerFile</strong> in line <strong>$headerLine</strong></p>")) {
			if ((ob_get_contents() === false || ob_get_contents() == '') or die("\n<p><strong>Error:</strong> Unable to send file <strong>$archiveName.epub</strong>. Output buffer contains the following text (typically warnings or errors):<br>" . ob_get_contents() . "</p>")) {
				if (ini_get('zlib.output_compression')) {
					ini_set('zlib.output_compression', 'Off');
				}

				header('Pragma: public');
				header("Last-Modified: " . gmdate("D, d M Y H:i:s T"));
				header("Expires: 0");
				header("Accept-Ranges: bytes");
				header("Connection: close");
				header("Content-Type: " . $contentType);
				header('Content-Disposition: attachment; filename="' . $archiveName . '";' );
				header("Content-Transfer-Encoding: binary");
				flush();
			}
		}
	}

	function __destruct() {
		$this->isFinalized = true;
		$cd = null;
		$this->cdRec = null;
		exit;
	}

	/**
	 * Extra fields on the Zip directory records are Unix time codes needed for compatibility on the default Mac zip archive tool.
	 * These are enabled as default, as they do no harm elsewhere and only add 26 bytes per file added.
	 *
	 * @param bool $setExtraField TRUE (default) will enable adding of extra fields, anything else will disable it.
	 */
	function setExtraField($setExtraField = TRUE) {
		$this->addExtraField = ($setExtraField === TRUE);
	}

	/**
	 * Set Zip archive comment.
	 *
	 * @param string $newComment New comment. null to clear.
	 * @return bool $success
	 */
	public function setComment($newComment = null) {
		if ($this->isFinalized) {
			return false;
		}
		$this->zipComment = $newComment;

		return true;
	}

	/**
	 * Add an empty directory entry to the zip archive.
	 * Basically this is only used if an empty directory is added.
	 *
	 * @param string $directoryPath  Directory Path and name to be added to the archive.
	 * @param int    $timestamp      (Optional) Timestamp for the added directory, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment    (Optional) Comment to be added to the archive for this directory. To use fileComment, timestamp must be given.
	 * @return bool $success
	 */
	public function addDirectory($directoryPath, $timestamp = 0, $fileComment = null) {
		if ($this->isFinalized) {
			return false;
		}
		$this->buildZipEntry($directoryPath, $fileComment, "\x00\x00", "\x00\x00", $timestamp, "\x00\x00\x00\x00", 0, 0, self::EXT_FILE_ATTR_DIR);

		return true;
	}

	/**
	 * Add a file to the archive at the specified location and file name.
	 *
	 * @param string $data        File data.
	 * @param string $filePath    Filepath and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use fileComment, timestamp must be given.
	 * @return bool $success
	 */
	public function addFile($data, $filePath, $timestamp = 0, $fileComment = null)   {
		if ($this->isFinalized) {
			return false;
		}

		if (is_resource($data) && get_resource_type($data) == "stream") {
			$this->addLargeFile($data, $filePath, $timestamp, $fileComment);
			return false;
		}

		$gzType = "\x08\x00"; // Compression type 8 = deflate
		$gpFlags = "\x02\x00"; // General Purpose bit flags for compression type 8 it is: 0=Normal, 1=Maximum, 2=Fast, 3=super fast compression.
		$dataLength = strlen($data);
		$fileCRC32 = pack("V", crc32($data));

		$gzData = gzcompress($data);
		$gzData = substr( substr($gzData, 0, strlen($gzData) - 4), 2); // gzcompress adds a 2 byte header and 4 byte CRC we can't use.
		// The 2 byte header does contain useful data, though in this case the 2 parameters we'd be interrested in will always be 8 for compression type, and 2 for General purpose flag.
		$gzLength = strlen($gzData);

		if ($gzLength >= $dataLength) {
			$gzLength = $dataLength;
			$gzData = $data;
			$gzType = "\x00\x00"; // Compression type 0 = stored
			$gpFlags = "\x00\x00"; // Compression type 0 = stored
		}

		$this->buildZipEntry($filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, self::EXT_FILE_ATTR_FILE);
		print ($gzData);

		return true;
	}

	/**
	 * Add the content to a directory.
	 *
	 * @author Adam Schmalhofer <Adam.Schmalhofer@gmx.de>
	 * @author A. Grandt
	 *
	 * @param String $realPath       Path on the file system.
	 * @param String $zipPath        Filepath and name to be used in the archive.
	 * @param bool   $recursive      Add content recursively, default is TRUE.
	 * @param bool   $followSymlinks Follow and add symbolic links, if they are accessible, default is TRUE.
	 * @param array  $addedFiles     Reference to the added files, this is used to prevent duplicates, efault is an empty array.
	 *                               If you start the function by parsing an array, the array will be populated with the realPath
	 *                               and zipPath kay/value pairs added to the archive by the function.
	 */
	public function addDirectoryContent($realPath, $zipPath, $recursive = TRUE, $followSymlinks = TRUE, &$addedFiles = array()) {
		if (file_exists($realPath) && !isset($addedFiles[realpath($realPath)])) {
			if (is_dir($realPath)) {
				$this->addDirectory($zipPath);
			}

			$addedFiles[realpath($realPath)] = $zipPath;

			$iter = new DirectoryIterator($realPath);
			foreach ($iter as $file) {
				if ($file->isDot()) {
					continue;
				}
				$newRealPath = $file->getPathname();
				$newZipPath = self::pathJoin($zipPath, $file->getFilename());

				if(file_exists($newRealPath) && ($folowSymliks === TRUE || !is_link($newRealPath))) {
					if ($file->isFile()) {
						$addedFiles[realpath($newRealPath)] = $newZipPath;
						$this->addLargeFile($newRealPath, $newZipPath);
					} else if ($recursive === TRUE) {
						$this->addDirectoryContent($newRealPath, $newZipPath, $recursive);
					} else {
						$this->addDirectory($zipPath);
					}
				}
			}
		}
	}


	/**
	 * Add a file to the archive at the specified location and file name.
	 *
	 * @param string $dataFile    File name/path.
	 * @param string $filePath    Filepath and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use fileComment, timestamp must be given.
	 * @return bool $success
	 */
	public function addLargeFile($dataFile, $filePath, $timestamp = 0, $fileComment = null)   {
		if ($this->isFinalized) {
			return false;
		}

		$this->openStream($filePath, $timestamp, $fileComment);

		$fh = null;
		$doClose = false;

		if (is_string($dataFile)) {
			$fh = fopen($dataFile, "rb");
			$doClose = true;
		} else if (is_resource($dataFile) && get_resource_type($dataFile) == "stream") {
			$fh = $dataFile;
		}

		while(!feof($fh)) {
			$this->addStreamData(fread($fh, $this->streamChunkSize));
		}

		if ($doClose) {
			fclose($fh);
		}
		$this->closeStream($this->addExtraField);

		return true;
	}

	/**
	 * Create a stream to be used for large entries.
	 *
	 * @param string $filePath    Filepath and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use fileComment, timestamp must be given.
	 * @return bool $success
	 */
	public function openStream($filePath, $timestamp = 0, $fileComment = null)   {
		if ($this->isFinalized) {
			return false;
		}

		if (strlen($this->streamFilePath) > 0) {
			closeStream();
		}

		$this->streamFile = tempnam(sys_get_temp_dir(), 'ZipStream');
		$this->streamData = gzopen($this->streamFile, "w9");
		$this->streamFilePath = $filePath;
		$this->streamTimestamp = $timestamp;
		$this->streamFileComment = $fileComment;
		$this->streamFileLength = 0;

		return true;
	}

	/**
	 * Add data to the open stream.
	 *
	 * @param String $data
	 * @return $length bytes added or false if the archive is finalized or there are no open stream.
	 */
	public function addStreamData($data) {
		if ($this->isFinalized || strlen($this->streamFilePath) == 0) {
			return false;
		}

		$length = gzwrite($this->streamData, $data, strlen($data));
		if ($length != strlen($data)) {
			print "<p>Length mismatch</p>\n";
		}
		$this->streamFileLength += $length;
		return $length;
	}

	/**
	 * Close the current stream.
	 *
	 * @return bool $success
	 */
	public function closeStream() {
		if ($this->isFinalized || strlen($this->streamFilePath) == 0) {
			return false;
		}

		fflush($this->streamData);
		gzclose($this->streamData);

		$gzType = "\x08\x00"; // Compression type 8 = deflate
		$gpFlags = "\x02\x00"; // General Purpose bit flags for compression type 8 it is: 0=Normal, 1=Maximum, 2=Fast, 3=super fast compression.

		$file_handle = fopen($this->streamFile, "rb");
		$stats = fstat($file_handle);
		$eof = $stats['size'];

		fseek($file_handle, $eof-8);
		$fileCRC32 = fread($file_handle, 4);
		$dataLength = $this->streamFileLength;

		$gzLength = $eof-10;
		$eof -= 9;

		fseek($file_handle, 10);
		$pos = 10;

		$this->buildZipEntry($this->streamFilePath, $this->streamFileComment, $gpFlags, $gzType, $this->streamTimestamp, $fileCRC32, $gzLength, $dataLength, self::EXT_FILE_ATTR_FILE);
		while(!feof($file_handle)) {
			print fread($file_handle, $this->streamChunkSize);
		}
		flush();

		unlink($this->streamFile);
		$this->streamFile = null;
		$this->streamData = null;
		$this->streamFilePath = null;
		$this->streamTimestamp = null;
		$this->streamFileComment = null;
		$this->streamFileLength = 0;

		return true;
	}

	/**
	 * Close the archive.
	 * A closed archive can no longer have new files added to it.
	 * @return bool $success
	 */
	public function finalize() {
		if(!$this->isFinalized) {
			if (strlen($this->streamFilePath) > 0) {
				$this->closeStream();
			}

			$cdRecSize = pack("v", sizeof($this->cdRec));
			
			$cd = implode("", $this->cdRec);
			print($cd);
			print(self::ZIP_END_OF_CENTRAL_DIRECTORY);
			print($cdRecSize.$cdRecSize);
			print(pack("VV", strlen($cd), $this->offset));
			if (!is_null($this->zipComment)) {
				print(pack("v", strlen($this->zipComment)));
				print($this->zipComment);
			} else {
				print("\x00\x00");
			}

			flush();

			$this->isFinalized = true;
			$cd = null;
			$this->cdRec = null;

			return true;
		}
		return false;
	}

	/**
	 * Calculate the 2 byte dostime used in the zip entries.
	 *
	 * @param int $timestamp
	 * @return 2-byte encoded DOS Date
	 */
	private function getDosTime($timestamp = 0) {
		$timestamp = (int)$timestamp;
		$oldTZ = @date_default_timezone_get();
		date_default_timezone_set('UTC');
		$date = ($timestamp == 0 ? getdate() : getdate($timestamp));
		date_default_timezone_set($oldTZ);
		if ($date["year"] >= 1980) {
			return pack("V", (($date["mday"] + ($date["mon"] << 5) + (($date["year"]-1980) << 9)) << 16) |
			(($date["seconds"] >> 1) + ($date["minutes"] << 5) + ($date["hours"] << 11)));
		}
		return "\x00\x00\x00\x00";
	}

	/**
	 * Build the Zip file structures
	 *
	 * @param String $filePath
	 * @param String $fileComment
	 * @param String $gpFlags
	 * @param String $gzType
	 * @param int $timestamp
	 * @param string $fileCRC32
	 * @param int $gzLength
	 * @param int $dataLength
	 * @param integer $extFileAttr Use self::EXT_FILE_ATTR_FILE for files, self::EXT_FILE_ATTR_DIR for Directories.
	 */
	private function buildZipEntry($filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr) {
		$filePath = str_replace("\\", "/", $filePath);
		$fileCommentLength = (is_null($fileComment) ? 0 : strlen($fileComment));
		$timestamp = (int)$timestamp;
		$timestamp = ($timestamp == 0 ? time() : $timestamp);

		$dosTime = $this->getDosTime($timestamp);
		$tsPack = pack("V", $timestamp);
		
		$header = self::ATTR_VERSION_TO_EXTRACT
		. $gpFlags . $gzType . $dosTime. $fileCRC32
		. pack("VVv", $gzLength, $dataLength, strlen($filePath) ); // File name length
		
		$zipEntry  = self::ZIP_LOCAL_FILE_HEADER;
		$zipEntry .= $header;
		$zipEntry .= $this->addExtraField ? "\x10\x00" : "\x00\x00"; // Extra field length
		$zipEntry .= $filePath; // FileName
		// Extra fields
		if ($this->addExtraField) {
			$zipEntry .= "\x55\x58";// 0x5855	Short	tag for this extra block type ("UX")
			$zipEntry .= "\x0c\x00";// TSize	Short	total data size for this block
			$zipEntry .= $tsPack;	// AcTime	Long	time of last access (UTC/GMT)
			$zipEntry .= $tsPack;	// ModTime	Long	time of last modification (UTC/GMT)
			$zipEntry .= "\x00\x00\x00\x00";
		}

		print($zipEntry);

		$cdEntry  = self::ZIP_CENTRAL_FILE_HEADER;
		$cdEntry .= self::ATTR_MADE_BY_VERSION;
		$cdEntry .= $header;
		$cdEntry .= $this->addExtraField ? "\x0c\x00" : "\x00\x00"; // Extra field length
		$cdEntry .= pack("v", $fileCommentLength); // File comment length
		$cdEntry .= "\x00\x00"; // Disk number start
		$cdEntry .= "\x00\x00"; // internal file attributes
		$cdEntry .= $extFileAttr; // External file attributes
		$cdEntry .= pack("V", $this->offset ); // Relative offset of local header
		$cdEntry .= $filePath; // FileName
		// Extra fields
		if ($this->addExtraField) {
			$cdEntry .= "\x55\x58"; // 0x5855	Short	tag for this extra block type ("UX")
			$cdEntry .= "\x08\x00"; // TSize	Short	total data size for this block
			$cdEntry .= $tsPack;	// AcTime	Long	time of last access (UTC/GMT)
			$cdEntry .= $tsPack;	// ModTime	Long	time of last modification (UTC/GMT)
		}

		if (!is_null($fileComment)) {
			$cdEntry .= $fileComment; // Comment
		}

		$this->cdRec[] = $cdEntry;
		$this->offset += strlen($zipEntry) + $gzLength;
	}

	/**
	 * Join $file to $dir path, and clean up any excess slashes.
	 *
	 * @param String $dir
	 * @param String $file
	 */
	public static function pathJoin($dir, $file) {
		if (empty($dir) || empty($file)) {
			return self::getRelativePath($dir . $file);
		}
		return self::getRelativePath($dir . '/' . $file);
	}

	/**
	 * Clean up a path, removing any unnecessary elements such as /./, // or redundant ../ segments.
	 * If the path starts with a "/", it is deemed an absolute path and any /../ in the beginning is stripped off.
	 * The returned path will not end in a "/".
	 *
	 * @param String $relPath The path to clean up
	 * @return String the clean path
	 */
	public static function getRelativePath($path) {
		$path = preg_replace("#/+\.?/+#", "/", str_replace("\\", "/", $path));
		$dirs = explode("/", rtrim(preg_replace('#^(\./)+#', '', $path), '/'));

		$offset = 0;
		$sub = 0;
		$subOffset = 0;
		$root = "";

		if (empty($dirs[0])) {
			$root = "/";
			$dirs = array_splice($dirs, 1);
		} else if (preg_match("#[A-Za-z]:#", $dirs[0])) {
			$root = strtoupper($dirs[0]) . "/";
			$dirs = array_splice($dirs, 1);
		}

		$newDirs = array();
		foreach($dirs as $dir) {
			if ($dir !== "..") {
				$subOffset--;
				$newDirs[++$offset] = $dir;
			} else {
				$subOffset++;
				if (--$offset < 0) {
					$offset = 0;
					if ($subOffset > $sub) {
						$sub++;
					}
				}
			}
		}

		if (empty($root)) {
			$root = str_repeat("../", $sub);
		}
		return $root . implode("/", array_slice($newDirs, 0, $offset));
	}
}
?>