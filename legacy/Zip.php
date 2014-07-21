<?php
/**
 * Class to create and manage a Zip file.
 *
 * Initially inspired by CreateZipFile by Rochak Chauhan  www.rochakchauhan.com (http://www.phpclasses.org/browse/package/2322.html)
 * and
 * http://www.pkware.com/documents/casestudies/APPNOTE.TXT Zip file specification.
 *
 * License: GNU LGPL 2.1.
 *
 * @author A. Grandt <php@grandt.com>
 * @copyright 2009-2014 A. Grandt
 * @license GNU LGPL 2.1
 * @link http://www.phpclasses.org/package/6110
 * @link https://github.com/Grandt/PHPZip
 * @version 1.63
 */
class Zip {
	const APP_NAME = 'PHPZip';
	const VERSION = 1.63;
	const MIN_PHP_VERSION = 5.3; // for namespaces

	const CONTENT_TYPE = 'application/zip';
	const ZIP_LOCAL_FILE_HEADER = "PK\x03\x04"; // Local file header signature
	const ZIP_CENTRAL_FILE_HEADER = "PK\x01\x02"; // Central file header signature
	const ZIP_END_OF_CENTRAL_DIRECTORY = "PK\x05\x06\x00\x00\x00\x00"; //end of Central directory record

	const EXT_FILE_ATTR_DIR = 010173200020; // Permission 755 drwxr-xr-x = (((S_IFDIR | 0755) << 16) | S_DOS_D);
	const EXT_FILE_ATTR_FILE = 020151000040; // Permission 644 -rw-r--r-- = (((S_IFREG | 0644) << 16) | S_DOS_A);

	const ATTR_VERSION_TO_EXTRACT = "\x14\x00"; // Version needed to extract = 20 (File is compressed using Deflate compression)
	const ATTR_MADE_BY_VERSION = "\x1E\x03"; // Made By Version

	const NUL_NUL = "\x00\x00"; // Two nul bytes, used often enough.

	const DEFAULT_GZ_TYPE = "\x08\x00";	 // Compression type 8 = deflate
	const DEFAULT_GP_FLAGS = self::NUL_NUL;	 // General Purpose bit flags for compression type 8 it is: 0=Normal, 1=Maximum, 2=Fast, 3=super fast compression.

	const DEFAULT_GZ_TYPE_STORED = self::NUL_NUL;  // Compression type 0 = stored
	const DEFAULT_GP_FLAGS_STORED = self::NUL_NUL; // Compression type 0 = stored

	// UID 1000, GID 0
	const EXTRA_FIELD_NEW_UNIX_GUID = "ux\x0B\x00\x01\x04\xE8\x03\x00\x00\x04\x00\x00\x00\x00"; // \x75\x78 3rd gen Unis GUID
	const EXTRA_FIELD_NEW_UNIX_GUID_CD = "ux\x00\x00"; // \x75\x78 3rd gen Unis GUID CD record version must have length 0.

	// Unix file types
	const S_IFIFO = 0010000; // named pipe (fifo)
	const S_IFCHR = 0020000; // character special
	const S_IFDIR = 0040000; // directory
	const S_IFBLK = 0060000; // block special
	const S_IFREG = 0100000; // regular
	const S_IFLNK = 0120000; // symbolic link
	const S_IFSOCK = 0140000; // socket

	// setuid/setgid/sticky bits, the same as for chmod:
	const S_ISUID = 0004000; // set user id on execution
	const S_ISGID = 0002000; // set group id on execution
	const S_ISTXT = 0001000; // sticky bit

	// And of course, the other 12 bits are for the permissions, the same as for chmod:
	// When adding these up, you can also just write the permissions as a single octal number
	// ie. 0755. The leading 0 specifies octal notation.
	const S_IRWXU = 0000700; // RWX mask for owner
	const S_IRUSR = 0000400; // R for owner
	const S_IWUSR = 0000200; // W for owner
	const S_IXUSR = 0000100; // X for owner
	const S_IRWXG = 0000070; // RWX mask for group
	const S_IRGRP = 0000040; // R for group
	const S_IWGRP = 0000020; // W for group
	const S_IXGRP = 0000010; // X for group
	const S_IRWXO = 0000007; // RWX mask for other
	const S_IROTH = 0000004; // R for other
	const S_IWOTH = 0000002; // W for other
	const S_IXOTH = 0000001; // X for other
	const S_ISVTX = 0001000; // save swapped text even after use

	// File type, sticky and permissions are added up, and shifted 16 bits left BEFORE adding the DOS flags.
	// DOS file type flags, we really only use the S_DOS_D flag.

	const S_DOS_A = 0000040; // DOS flag for Archive
	const S_DOS_D = 0000020; // DOS flag for Directory
	const S_DOS_V = 0000010; // DOS flag for Volume
	const S_DOS_S = 0000004; // DOS flag for System
	const S_DOS_H = 0000002; // DOS flag for Hidden
	const S_DOS_R = 0000001; // DOS flag for Read Only

	protected $zipComment = null;
	protected $cdRec = array(); // central directory
	protected $offset = 0;
	protected $isFinalized = false;
	protected $addExtraField = true;

	protected $streamChunkSize = 65536; // 64kb
	protected $streamFilePath = null;
	protected $streamTimestamp = null;
	protected $streamFileComment = null;
	protected $streamFile = null;
	protected $streamData = null;
	protected $streamFileLength = 0;
	protected $streamExtFileAttr = null;

	/**
	 * A custom temporary folder, or a callable that returns a custom temporary file.
	 * @var string|callable
	 */
	public static $temp = null;

	private $zipMemoryThreshold = 1048576; // Auto-create temp file if the zip data exceeds 1048576 bytes (1 MB)

	protected $zipData = null;
	protected $zipFile = null;

	/**
	 * Constructor.
	 *
	 * @param boolean $useZipFile Write temp zip data to tempFile? Default false
	 */
	public function __construct($useZipFile = false) {
		$this->checkVersion();

		if ($useZipFile) {
			$this->zipFile = tmpfile();
		} else {
			$this->zipData = "";
		}
	}

	public function __destruct() {
		if (is_resource($this->zipFile)) {
			fclose($this->zipFile);
		}
		$this->zipData = null;
	}

	/**
	 * Extra fields on the Zip directory records are Unix time codes needed for compatibility on the default Mac zip archive tool.
	 * These are enabled as default, as they do no harm elsewhere and only add 26 bytes per file added.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param bool $setExtraField true (default) will enable adding of extra fields, anything else will disable it.
	 */
	public function setExtraField($setExtraField = true) {
		$this->addExtraField = ($setExtraField === true);
	}

	/**
	 * Set Zip archive comment.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $newComment New comment. null to clear.
	 *
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
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $directoryPath Directory Path and name to be added to the archive.
	 * @param int    $timestamp     (Optional) Time stamp for the added directory, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment   (Optional) Comment to be added to the archive for this directory. To use $fileComment, $timestamp must be given.
	 * @param int    $extFileAttr   (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @return bool  $success
	 */
	public function addDirectory($directoryPath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_DIR) {
		if ($this->isFinalized) {
			return false;
		}

		$directoryPath = str_replace("\\", '/', $directoryPath);
		$directoryPath = rtrim($directoryPath, '/');

		if (self::bin_strlen($directoryPath) > 0) {
			$this->buildZipEntry($directoryPath . '/',
					$fileComment,
					self::DEFAULT_GZ_TYPE_STORED,
					self::DEFAULT_GP_FLAGS_STORED,
					$timestamp,
					"\x00\x00\x00\x00",
					0, 0, $extFileAttr);
			return true;
		}
		return false;
	}

	/**
	 * Add a file to the archive at the specified location and file name.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $data        File data.
	 * @param string $filePath    File path and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Time stamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use $fileComment, $timestamp must be given.
	 * @param bool   $compress    (Optional) Compress file, if set to false the file will only be stored. Default true.
	 * @param int    $extFileAttr (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @return bool  $success
	 */
	public function addFile($data, $filePath, $timestamp = 0, $fileComment = null, $compress = null, $extFileAttr = self::EXT_FILE_ATTR_FILE) {
		if ($this->isFinalized) {
			return false;
		}

		if (is_resource($data) && get_resource_type($data) === 'stream') {
			$this->addLargeFile($data, $filePath, $timestamp, $fileComment, $extFileAttr);
			return false;
		}

		$gzData = '';
		$gzType = self::DEFAULT_GZ_TYPE;
		$gpFlags = self::DEFAULT_GP_FLAGS;
		$dataLength = self::bin_strlen($data);
		$fileCRC32 = pack("V", crc32($data));
		$gzLength = $dataLength;

		if ($compress) {
			$gzTmp = gzcompress($data);
			// gzcompress adds a 2 byte header and 4 byte Adler-32 CRC at the end, which we can't use.
			$gzData = substr($gzTmp, 2, -4);
			// The 2 byte header does contain useful data,
			// though in this case the 2 parameters we'd be interested in will
			// always be 8 for compression type, and 2 for General purpose flag.
			$gzLength = self::bin_strlen($gzData);
		}

		if ($gzLength >= $dataLength) {
			$gzLength = $dataLength;
			$gzData = $data;

			$gzType = self::DEFAULT_GZ_TYPE_STORED;
			$gpFlags = self::DEFAULT_GP_FLAGS_STORED;
		}

		$this->zipVerifyMemBuffer($gzLength);
		$this->buildZipEntry($filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr);
		$this->zipWrite($gzData);
		return true;
	}

	/**
	 * Add the content to a directory.
	 *
	 * @author Adam Schmalhofer <Adam.Schmalhofer@gmx.de>
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $realPath       Path on the file system.
	 * @param string $zipPath        File path and name to be used in the archive.
	 * @param bool   $recursive      Add content recursively, default is true.
	 * @param bool   $followSymlinks Follow and add symbolic links, if they are accessible, default is true.
	 * @param array &$addedFiles     Reference to the added files, this is used to prevent duplicates, default is an empty array.
	 *                               If you start the function by parsing an array, the array will be populated with the $realPath
	 *                               and $zipPath kay/value pairs added to the archive by the function.
	 * @param bool   $overrideFilePermissions Force the use of the file/dir permissions set in the $extDirAttr
	 *                               and $extFileAttr parameters.
	 * @param int    $extDirAttr     Permissions for directories.
	 * @param int    $extFileAttr    Permissions for files.
	 */
	public function addDirectoryContent($realPath, $zipPath, $recursive = true, $followSymlinks = true, &$addedFiles = array(),
			$overrideFilePermissions = false, $extDirAttr = self::EXT_FILE_ATTR_DIR, $extFileAttr = self::EXT_FILE_ATTR_FILE) {
		if (file_exists($realPath) && !isset($addedFiles[realpath($realPath)])) {
			if (is_dir($realPath)) {
				$this->addDirectory(
					$zipPath,
					0,
					null,
					$overrideFilePermissions ? $extDirAttr : self::getFileExtAttr($realPath)
				);
			}

			$addedFiles[realpath($realPath)] = $zipPath;

			$iter = new DirectoryIterator($realPath);

			foreach ($iter as $file) {
				/* @var $file \DirectoryIterator */
				if ($file->isDot()) {
					continue;
				}

				$newRealPath = $file->getPathname();
				$newZipPath  = self::pathJoin($zipPath, $file->getFilename());

				if (file_exists($newRealPath) && ($followSymlinks || !is_link($newRealPath))) {
					if ($file->isFile()) {
						$addedFiles[realpath($newRealPath)] = $newZipPath;
						$this->addLargeFile(
							$newRealPath,
							$newZipPath,
							0,
							null,
							$overrideFilePermissions ? $extFileAttr : self::getFileExtAttr($newRealPath)
						);
					} else if ($recursive) {
						$this->addDirectoryContent(
							$newRealPath,
							$newZipPath,
							$recursive,
							$followSymlinks,
							$addedFiles,
							$overrideFilePermissions,
							$extDirAttr,
							$extFileAttr
						);
					} else {
						$this->addDirectory(
							$zipPath,
							0,
							null,
							$overrideFilePermissions ? $extDirAttr : self::getFileExtAttr($newRealPath)
						);
					}
				}
			}
		}
	}

	/**
	 * Add a file to the archive at the specified location and file name.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $dataFile    File name/path.
	 * @param string $filePath    File path and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use $fileComment, $timestamp must be given.
	 * @param int    $extFileAttr (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @return bool  $success
	 */
	public function addLargeFile($dataFile, $filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE) {
		if ($this->isFinalized) {
			return false;
		}

		if (is_string($dataFile) && is_file($dataFile)) {
			$this->processFile($dataFile, $filePath, $timestamp, $fileComment, $extFileAttr);
		} else if (is_resource($dataFile) && get_resource_type($dataFile) == "stream") {
			$fh = $dataFile;
			$this->openStream($filePath, $timestamp, $fileComment, $extFileAttr);

			while (!feof($fh)) {
				$this->addStreamData(fread($fh, $this->streamChunkSize));
			}
			$this->closeStream($this->addExtraField);
		}
		return true;
	}

	/**
	 * Create a stream to be used for large entries.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $filePath    File path and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Time stamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use $fileComment, $timestamp must be given.
	 * @param int    $extFileAttr (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @return bool $success
	 */
	public function openStream($filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE) {
		if (!function_exists('sys_get_temp_dir')) {
			die("ERROR: Zip " . self::VERSION . " requires PHP version 5.2.1 or above if large files are used.");
		}

		if ($this->isFinalized) {
			return false;
		}

		$this->zipFlush();

		if (self::bin_strlen($this->streamFilePath) > 0) {
			$this->closeStream();
		}

		$this->streamFile = self::getTemporaryFile();
		$this->streamData = fopen($this->streamFile, "wb");
		$this->streamFilePath = $filePath;
		$this->streamTimestamp = $timestamp;
		$this->streamFileComment = $fileComment;
		$this->streamFileLength = 0;
		$this->streamExtFileAttr = $extFileAttr;

		return true;
	}

	/**
	 * Add data to the open stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $data
	 *
	 * @return mixed length in bytes added or false if the archive is finalized or there are no open stream.
	 */
	public function addStreamData($data) {
		if ($this->isFinalized || self::bin_strlen($this->streamFilePath) == 0) {
			return false;
		}

		$dataLength = self::bin_strlen($data);
		$length = fwrite($this->streamData, $data, $dataLength);

		if ($length != $dataLength) {
			die("<p>File IO: Error writing; Length mismatch: Expected " . $dataLength . " bytes, wrote " . ($length === false ? "NONE!" : $length) . "</p>\n");
		}
		$this->streamFileLength += $length;

		return $length;
	}

	/**
	 * Close the current stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return bool $success
	 */
	public function closeStream() {
		if ($this->isFinalized || self::bin_strlen($this->streamFilePath) == 0) {
			return false;
		}

		fflush($this->streamData);
		fclose($this->streamData);

		$this->processFile(
			$this->streamFile,
			$this->streamFilePath,
			$this->streamTimestamp,
			$this->streamFileComment,
			$this->streamExtFileAttr
		);

		$this->streamData = null;
		$this->streamFilePath = null;
		$this->streamTimestamp = null;
		$this->streamFileComment = null;
		$this->streamFileLength = 0;
		$this->streamExtFileAttr = null;

		// Windows is a little slow at times, so a millisecond later, we can unlink this.
		unlink($this->streamFile);
		$this->streamFile = null;

		return true;
	}

	/**
	 * Process the current file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string    $dataFile
	 * @param string    $filePath
	 * @param int       $timestamp
	 * @param string    $fileComment
	 * @param int       $extFileAttr
	 *
	 * @return bool     $success
	 */
	protected function processFile($dataFile, $filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE) {
		if ($this->isFinalized) {
			return false;
		}

		$tempZip = self::getTemporaryFile();

		$zip = new ZipArchive;
        $rv = $zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		if ($rv === true) { // open returns true if successful, however one of the error values is 1, which will also read as true.
			$zip->addFile($dataFile, 'file');
			$zip->close();
		} else {
			// TODO: An error occured reading the ZipArchive tempp file (Seen on Windows installations)
		}

		$handle = fopen($tempZip, "rb");
		$stats = fstat($handle);
		$eof = $stats['size']-72; // set EOF to the position of the end of the zip data, before the CD record.
		// Should probably use 34 + gzLength instead.

		fseek($handle, 6); // Skip Zip local file header and version

		$gpFlags = fread($handle, 2);
		$gzType = fread($handle, 2);
		fread($handle, 4); // Skip DOS Time and Date
		$fileCRC32 = fread($handle, 4);

		$v = unpack("Vval", fread($handle, 4));
		$gzLength = $v['val'];

		$v = unpack("Vval", fread($handle, 4));
		$dataLength = $v['val'];

		$this->buildZipEntry($filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr);

		$pos = 34;
		fseek($handle, $pos); // Position pointer at the start of the actual zip data.

		while (!feof($handle) && $pos < $eof) {
			$len = $this->streamChunkSize;

			if ($pos + $this->streamChunkSize > $eof) {
				$len = $eof-$pos;
			}
			$data = fread($handle, $len);
			$pos += $len;

			$this->zipWrite($data);
			$this->zipFlushBuffer();
		}

		fclose($handle);
		unlink($tempZip);

		return true;
	}

	/**
	 * Close the archive.
	 * A closed archive can no longer have new files added to it.
	 *
	 * @return bool $success
	 */
	public function finalize() {
		if (!$this->isFinalized) {
			if (self::bin_strlen($this->streamFilePath) > 0) {
				$this->closeStream();
			}

			$cd = implode("", $this->cdRec);
			$cdRecSize = pack("v", sizeof($this->cdRec));

			$cdRec = $cd
					. self::ZIP_END_OF_CENTRAL_DIRECTORY
					. $cdRecSize . $cdRecSize
					. pack("VV", self::bin_strlen($cd), $this->offset);

			if (!empty($this->zipComment)) {
				$cdRec .= pack("v", self::bin_strlen($this->zipComment))
						. $this->zipComment;
			} else {
				$cdRec .= self::NUL_NUL;
			}

			$this->zipWrite($cdRec);
			$this->zipFlushBuffer();

			$this->isFinalized = true;
			$this->cdRec = null;

			return true;
		}
		return false;
	}

	/**
	 * Build the Zip file structures
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $filePath
	 * @param string $fileComment
	 * @param string $gpFlags
	 * @param string $gzType
	 * @param int    $timestamp
	 * @param string $fileCRC32
	 * @param int    $gzLength
	 * @param int    $dataLength
	 * @param int    $extFileAttr Use self::EXT_FILE_ATTR_FILE for files, self::EXT_FILE_ATTR_DIR for Directories.
	 */
	protected function buildZipEntry($filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr) {
		$filePath = str_replace("\\", "/", $filePath);
		$fileCommentLength = (empty($fileComment) ? 0 : self::bin_strlen($fileComment));
		$timestamp = (int)$timestamp;
		$timestamp = ($timestamp == 0 ? time() : $timestamp);

		$dosTime = $this->getDosTime($timestamp);
		$tsPack = pack("V", $timestamp);

		if (!isset($gpFlags) || self::bin_strlen($gpFlags) != 2) {
			$gpFlags = self::DEFAULT_GP_FLAGS;
		}

		$isFileUTF8 = mb_check_encoding($filePath, "UTF-8") && !mb_check_encoding($filePath, "ASCII");
		$isCommentUTF8 = !empty($fileComment) && mb_check_encoding($fileComment, "UTF-8") && !mb_check_encoding($fileComment, "ASCII");

		$localExtraField = "";
		$centralExtraField = "";

		if ($this->addExtraField) {
			$localExtraField .= "UT\x09\x00\x03" // \x55\x54
				. $tsPack . $tsPack
				. self::EXTRA_FIELD_NEW_UNIX_GUID;
			$centralExtraField .= "UT\x05\x00\x03" // \x55\x54
				. $tsPack
				. self::EXTRA_FIELD_NEW_UNIX_GUID_CD;
		}

		if ($isFileUTF8 || $isCommentUTF8) {
			$flag = 0;
			$gpFlagsV = unpack("vflags", $gpFlags);
			if (isset($gpFlagsV['flags'])) {
				$flag = $gpFlagsV['flags'];
			}
			$gpFlags = pack("v", $flag | (1 << 11));

			if ($isFileUTF8) {
				$utfPathExtraField = "up" // "\x75\x70" // utf8 encoded File path extra field
					. pack("v", (5 + self::bin_strlen($filePath)))
					. "\x01"
					. pack("V", crc32($filePath))
					. $filePath;

				$localExtraField .= $utfPathExtraField;
				$centralExtraField .= $utfPathExtraField;
			}
			if ($isCommentUTF8) {
				$centralExtraField .= "uc" // "\x75\x63" // utf8 encoded file comment extra field
					. pack("v", (5 + self::bin_strlen($fileComment)))
					. "\x01"
					. pack("V", crc32($fileComment))
					. $fileComment;
			}
		}

		$header = $gpFlags . $gzType . $dosTime . $fileCRC32
				. pack("VVv", $gzLength, $dataLength, self::bin_strlen($filePath)); // File name length

		$zipEntry = self::ZIP_LOCAL_FILE_HEADER
				. self::ATTR_VERSION_TO_EXTRACT
				. $header
				. pack("v", self::bin_strlen($localExtraField)) // Extra field length
				. $filePath // FileName
				. $localExtraField; // Extra fields

		$this->zipWrite($zipEntry);

		$cdEntry = self::ZIP_CENTRAL_FILE_HEADER
				. self::ATTR_MADE_BY_VERSION
				. ($dataLength === 0 ? "\x0A\x00" : self::ATTR_VERSION_TO_EXTRACT)
				. $header
				. pack("v", self::bin_strlen($centralExtraField)) // Extra field length
				. pack("v", $fileCommentLength) // File comment length
				. self::NUL_NUL // Disk number start
				. self::NUL_NUL // internal file attributes
				. pack("V", $extFileAttr) // External file attributes
				. pack("V", $this->offset) // Relative offset of local header
				. $filePath // FileName
				. $centralExtraField; // Extra fields

		if (!empty($fileComment)) {
			$cdEntry .= $fileComment; // Comment
		}

		$this->cdRec[] = $cdEntry;
		$this->offset += self::bin_strlen($zipEntry) + $gzLength;
	}

	/*
	 * ************************************************************************
	 * protected methods.
	 * ************************************************************************
	 */

	/**
	 * Build the base standard response headers, and ensure the content can be streamed.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param String $fileName The name of the Zip archive, in ISO-8859-1 (or ASCII) encoding, ie. "archive.zip". Optional, defaults to null, which means that no ISO-8859-1 encoded file name will be specified.
	 * @param String $contentType Content mime type. Optional, defaults to "application/zip".
	 * @param String $utf8FileName The name of the Zip archive, in UTF-8 encoding. Optional, defaults to null, which means that no UTF-8 encoded file name will be specified.
	 * @param bool   $inline Use Content-Disposition with "inline" instead of "attached". Optional, defaults to false.
	 *
	 * @return bool Always returns true (for backward compatibility).
	 */
	protected function buildResponseHeader($fileName = null, $contentType = self::CONTENT_TYPE, $utf8FileName = null, $inline = false) {
		$ob = null;
		$headerFile = null;
		$headerLine = null;

		$ob = ob_get_contents();
		if ($ob !== false && strlen($ob)) {
			die("\n<p><strong>Error:</strong> Unable to send file <strong>$fileName</strong>. Output buffer contains the following text (typically warnings or errors):<br>" . htmlentities(ob_get_contents()) . "</p>");
		}

		if (headers_sent($headerFile, $headerLine)) {
			die("\n<p><strong>Error:</strong> Unable to send file <strong>$fileName</strong>. HTML Headers have already been sent from <strong>$headerFile</strong> in line <strong>$headerLine</strong></p>");
		}

		if (ini_get('zlib.output_compression')) {
			ini_set('zlib.output_compression', 'Off');
		}

		$cd = 'Content-Disposition: ' . ($inline ? 'inline' : 'attached');

		if ($fileName) {
			$cd .= '; filename="' . $fileName . '"';
		}

		if ($utf8FileName) {
			$cd .= "; filename*=UTF-8''" . rawurlencode($utf8FileName);
		}

		header('Pragma: public');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s T'));
		header('Expires: 0');
		header('Accept-Ranges: bytes');
		header('Connection: close');
		header('Content-Type: ' . $contentType);
		header($cd);

		return true;
	}

	/**
	 * Calculate the 2 byte dostime used in the zip entries.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param int $timestamp
	 *
	 * @return string 2-byte encoded DOS Date
	 */
	private static function getDosTime($timestamp = 0) {
		$timestamp = (int) $timestamp;
		$oldTZ = @date_default_timezone_get();
		date_default_timezone_set('UTC');

		$date = ($timestamp == 0 ? getdate() : getdate($timestamp));
		date_default_timezone_set($oldTZ);

		if ($date["year"] >= 1980) { // Dos dates start on 1 Jan 1980
			return pack("V", (($date["mday"] + ($date["mon"] << 5) + (($date["year"] - 1980) << 9)) << 16) |
				(($date["seconds"] >> 1) + ($date["minutes"] << 5) + ($date["hours"] << 11)));
		}
		return "\x00\x00\x00\x00";
	}

	 /*
	 * ************************************************************************
	 * Static methods/
	 * ************************************************************************
	 */

	/**
	 * Join $file to $dir path, and clean up any excess slashes.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $dir
	 * @param string $file
	 *
	 * @return string a Joined path, with the correct forward slash dir separator.
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
	 * Sometimes, when a path is generated from multiple fragments,
	 *  you can get something like "../data/html/../images/image.jpeg"
	 * This will normalize that example path to "../data/images/image.jpeg"
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $path The path to clean up
	 *
	 * @return string the clean path
	 */
	public static function getRelativePath($path) {
		$path = preg_replace("#/+\.?/+#", "/", str_replace("\\", "/", $path));
		$dirs = explode("/", rtrim(preg_replace('#^(?:\./)+#', '', $path), '/'));

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
		foreach ($dirs as $dir) {
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

	/**
	 * Create the file permissions for a file or directory, for use in the extFileAttr parameters.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param int   $owner Unix permissions for owner (octal from 00 to 07)
	 * @param int   $group Unix permissions for group (octal from 00 to 07)
	 * @param int   $other Unix permissions for others (octal from 00 to 07)
	 * @param bool  $isFile
	 *
	 * @return string EXTERNAL_REF field.
	 */
	public static function generateExtAttr($owner = 07, $group = 05, $other = 05, $isFile = true) {
		$fp = $isFile ? self::S_IFREG : self::S_IFDIR;
		$fp |= (($owner & 07) << 6) | (($group & 07) << 3) | ($other & 07);

		return ($fp << 16) | ($isFile ? self::S_DOS_A : self::S_DOS_D);
	}

	/**
	 * Get the file permissions for a file or directory, for use in the extFileAttr parameters.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $filename
	 *
	 * @return string|bool external ref field, or false if the file is not found.
	 */
	public static function getFileExtAttr($filename) {
		if (file_exists($filename)) {
			$fp = fileperms($filename) << 16;
			return $fp | (is_dir($filename) ? self::S_DOS_D : self::S_DOS_A);
		}

		return false;
	}

	/**
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return string The full path to a temporary file.
	 */
	public static function getTemporaryFile() {
		if (is_callable(self::$temp)) {
			$file = @call_user_func(self::$temp);

			if (is_string($file) && self::bin_strlen($file) && is_writable($file)) {
				return $file;
			}
		}

		$dir = (is_string(self::$temp) && self::bin_strlen(self::$temp)) ? self::$temp : sys_get_temp_dir();

		return tempnam($dir, __NAMESPACE__);
	}

	/**
	 * Initialize the vars that'll allow us to override mbstring.func_overload, if needed.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return bool true if mbstring.func_overload is enabled.
	 */
	public static function isMBStringOveridden() {
		static $has_mb_overload = null;
		if ($has_mb_overload ===  null) {
			$has_mbstring = extension_loaded('mbstring');
			$has_mb_shadow = (int) ini_get('mbstring.func_overload');
			$has_mb_overload = $has_mbstring && ($has_mb_shadow & 2);
		}
		return $has_mb_overload;
	}

	/**
	 * Wrapper of strlen to escapt bmstring.func_overload.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $string
	 *
	 * @return int byte length of the string parsed.
	 */
	public function bin_strlen($string) {
		if (self::isMBStringOveridden()) {
		   return mb_strlen($string,'latin1');
		} else {
		   return strlen($string);
		}
	}

	/**
	 * Check PHP version.
	 *
	 * @author A. Grandt <php@grandt.com>
	 */
	public function checkVersion() {
		if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<') || !function_exists('sys_get_temp_dir') ) {
			die ("ERROR: " . self::APP_NAME . " " . self::VERSION . " requires PHP version " . self::MIN_PHP_VERSION . " or above.");
		}
	}

	// ***********************************
	// ** Abstract functions            **
	// ***********************************

	/**
	 * Verify if the memory buffer is about to be exceeded.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param int $gzLength length of the pending data.
	 */
	public function zipVerifyMemBuffer($gzLength) {
		if (!is_resource($this->zipFile) && ($this->offset + $gzLength) > $this->zipMemoryThreshold) {
			$this->zipFlush();
		}
	}

	/**
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $data
	 */
	public function zipWrite($data) {
		if (!is_resource($this->zipFile)) {
			$this->zipData .= $data;
		} else {
			fwrite($this->zipFile, $data);
			fflush($this->zipFile);
		}
	}

	/**
	 * Flush Zip Data stored in memory, to a temp file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 */
	public function zipFlush() {
		if (!is_resource($this->zipFile)) {
			$this->zipFile = tmpfile();
			fwrite($this->zipFile, $this->zipData);
			$this->zipData = null;
		}
	}

	/**
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 */
	public function zipFlushBuffer() {
		// Does nothing, used to "streamline" code differences between PHPZip and PHPZipStream
	}

	// ***********************************
	// ** PHPZip specific functions     **
	// ***********************************

	/**
	 * Set zip file to write zip data to.
	 * This will cause all present and future data written to this class to be written to this file.
	 * This can be used at any time, even after the Zip Archive have been finalized. Any previous file will be closed.
	 * Warning: If the given file already exists, it will be overwritten.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $fileName
	 *
	 * @return bool  $success
	 */
	public function setZipFile($fileName) {
		if (is_file($fileName)) {
			unlink($fileName);
		}
		$fd = fopen($fileName, "x+b");
		if (is_resource($this->zipFile)) {
			rewind($this->zipFile);
			while (!feof($this->zipFile)) {
				fwrite($fd, fread($this->zipFile, $this->streamChunkSize));
			}

			fclose($this->zipFile);
		} else {
			fwrite($fd, $this->zipData);
			$this->zipData = null;
		}
		$this->zipFile = $fd;

		return true;
	}

	/**
	 * Send the archive as a zip download
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param String $fileName      The name of the Zip archive, in ISO-8859-1 (or ASCII) encoding,
	 *                              ie. "archive.zip". Optional, defaults to null, which means that
	 *                              no ISO-8859-1 encoded file name will be specified.
	 * @param String $contentType   Content mime type. Optional, defaults to "application/zip".
	 * @param String $utf8FileName  The name of the Zip archive, in UTF-8 encoding. Optional, defaults
	 *                              to null, which means that no UTF-8 encoded file name will be specified.
	 * @param bool $inline          Use Content-Disposition with "inline" instead of "attached". Optional, defaults to false.
	 *
	 * @return bool $success
	 */
	public function sendZip($fileName = null, $contentType = self::CONTENT_TYPE, $utf8FileName = null, $inline = false) {
		if (!$this->isFinalized) {
			$this->finalize();
		}

		if ($this->buildResponseHeader($fileName, $contentType, $utf8FileName, $inline)) {
			header('Content-Length: ' . $this->getArchiveSize());

			if (!is_resource($this->zipFile)) {
				echo $this->zipData;
			} else {
				rewind($this->zipFile);

				while (!feof($this->zipFile)) {
					echo fread($this->zipFile, $this->streamChunkSize);
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Get the handle resource for the archive zip file.
	 * If the zip haven't been finalized yet, this will cause it to become finalized
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return zip file handle
	 */
	public function getZipFile() {
		if (!$this->isFinalized) {
			$this->finalize();
		}

		$this->zipFlush();

		rewind($this->zipFile);

		return $this->zipFile;
	}

	/**
	 * Get the zip file contents
	 * If the zip haven't been finalized yet, this will cause it to become finalized
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return zip data
	 */
	public function getZipData() {
		if (!$this->isFinalized) {
			$this->finalize();
		}
		if (!is_resource($this->zipFile)) {
			return $this->zipData;
		} else {
			rewind($this->zipFile);
			$filestat = fstat($this->zipFile);
			return fread($this->zipFile, $filestat['size']);
		}

	}

	/**
	 * Return the current size of the archive
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return int Size of the archive
	 */
	public function getArchiveSize() {
		if (!is_resource($this->zipFile)) {
			return self::bin_strlen($this->zipData);
		}
		$filestat = fstat($this->zipFile);

		return $filestat['size'];
	}
}
