<?php
/**
 *
 * @author A. Grandt <php@grandt.com>
 * @author Greg Kappatos
 *
 * This class serves as an abstract superclass for zip archives.
 *
 */

namespace PHPZip\Zip\Core;

use PHPZip\Zip\Listener\ZipArchive as ZipArchiveListener;
use PHPZip\Zip\Exception\IncompatiblePhpVersion as IncompatiblePhpVersionException;
use PHPZip\Zip\Exception\InvalidPhpConfiguration as InvalidPhpConfigurationException;
use PHPZip\Zip\Exception\HeadersSent as HeadersSentException;
use PHPZip\Zip\Exception\BufferNotEmpty as BufferNotEmptyException;
use PHPZip\Zip\Exception\LengthMismatch as LengthMismatchException;

abstract class AbstractZipArchive {

	const APP_NAME = 'PHPZip';
	const VERSION = 1.63;
	const MIN_PHP_VERSION = 5.3; // for namespaces

	const CONTENT_TYPE = 'application/zip';

	const ZIP_LOCAL_FILE_HEADER = "\x50\x4b\x03\x04"; // Local file header signature
	const ZIP_CENTRAL_FILE_HEADER = "\x50\x4b\x01\x02"; // Central file header signature
	const ZIP_END_OF_CENTRAL_DIRECTORY = "\x50\x4b\x05\x06\x00\x00\x00\x00"; //end of Central directory record

	const EXT_FILE_ATTR_DIR = 010173200020;  // Permission 755 drwxr-xr-x = (((S_IFDIR | 0755) << 16) | S_DOS_D);
	const EXT_FILE_ATTR_FILE = 020151000040; // Permission 644 -rw-r--r-- = (((S_IFREG | 0644) << 16) | S_DOS_A);

	const ATTR_VERSION_TO_EXTRACT = "\x14\x00"; // Version needed to extract
	const ATTR_MADE_BY_VERSION = "\x1E\x03"; // Made By Version

	// UID 1000, GID 0
	const EXTRA_FIELD_NEW_UNIX_GUID = "\x75\x78\x0B\x00\x01\x04\xE8\x03\x00\x00\x04\x00\x00\x00\x00";

	// Unix file types
	const S_IFIFO  = 0010000; // named pipe (fifo)
	const S_IFCHR  = 0020000; // character special
	const S_IFDIR  = 0040000; // directory
	const S_IFBLK  = 0060000; // block special
	const S_IFREG  = 0100000; // regular
	const S_IFLNK  = 0120000; // symbolic link
	const S_IFSOCK = 0140000; // socket

	// setuid/setgid/sticky bits, the same as for chmod:

	const S_ISUID  = 0004000; // set user id on execution
	const S_ISGID  = 0002000; // set group id on execution
	const S_ISTXT  = 0001000; // sticky bit

	// And of course, the other 12 bits are for the permissions, the same as for chmod:
	// When adding these up, you can also just write the permissions as a single octal number
	// ie. 0755. The leading 0 specifies octal notation.
	const S_IRWXU  = 0000700; // RWX mask for owner
	const S_IRUSR  = 0000400; // R for owner
	const S_IWUSR  = 0000200; // W for owner
	const S_IXUSR  = 0000100; // X for owner
	const S_IRWXG  = 0000070; // RWX mask for group
	const S_IRGRP  = 0000040; // R for group
	const S_IWGRP  = 0000020; // W for group
	const S_IXGRP  = 0000010; // X for group
	const S_IRWXO  = 0000007; // RWX mask for other
	const S_IROTH  = 0000004; // R for other
	const S_IWOTH  = 0000002; // W for other
	const S_IXOTH  = 0000001; // X for other
	const S_ISVTX  = 0001000; // save swapped text even after use

	// File type, sticky and permissions are added up, and shifted 16 bits left BEFORE adding the DOS flags.

	// DOS file type flags, we really only use the S_DOS_D flag.

	const S_DOS_A  = 0000040; // DOS flag for Archive
	const S_DOS_D  = 0000020; // DOS flag for Directory
	const S_DOS_V  = 0000010; // DOS flag for Volume
	const S_DOS_S  = 0000004; // DOS flag for System
	const S_DOS_H  = 0000002; // DOS flag for Hidden
	const S_DOS_R  = 0000001; // DOS flag for Read Only

	const DEFAULT_GZ_TYPE = "\x08\x00"; 	// Compression type 8 = deflate
	const DEFAULT_GP_FLAGS = "\x00\x00"; 	// General Purpose bit flags for compression type 8 it is: 0=Normal, 1=Maximum, 2=Fast, 3=super fast compression.

	protected $zipComment = null;
	protected $cdRec = array(); // central directory
	protected $offset = 0;
	protected $isFinalized = false;
	protected $addExtraField = true;

	protected $streamChunkSize = 0;
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

	private $_listeners = array();
	private $_phpConfigurationWatch = array(
		'mbstring.func_overload' => '0' // throw an exception if setting in php.ini is not '0'
	);

	/**
	 * Constructor.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param boolean $streamChunkSize Size of each chunk
	 *
	 * @throws \PHPZip\Zip\Exception\InvalidPhpConfiguration In case of errors
	 */
	protected function __construct($streamChunkSize) {

		$this->streamChunkSize = $streamChunkSize;

		if (count($this->_phpConfigurationWatch) > 0){

			foreach ($this->_phpConfigurationWatch as $k => $v){

				$s = (string)$v;

				if (@ini_get($k) !== $s){

					$this->_throwException(new InvalidPhpConfigurationException(array(
						'setting' => $k,
						'expected' => $s,
					)));

					break; // technically not needed.
				}

			}

		}

	}

	/**
	 * Extra fields on the Zip directory records are Unix time codes needed for compatibility on the default Mac zip archive tool.
	 * These are enabled as default, as they do no harm elsewhere and only add 26 bytes per file added.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param bool $setExtraField TRUE (default) will enable adding of extra fields, anything else will disable it.
	 */
	public function setExtraField($setExtraField = true) {

		$this->addExtraField = ($setExtraField === true);

	}

	/**
	 * Set Zip archive comment.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $newComment New comment. NULL to clear.
	 *
	 * @return bool $success
	 */
	public function setComment($newComment = null) {

		if ($this->isFinalized)
			return false;

		$this->zipComment = $newComment;

		return true;

	}

	/**
	 * Add an empty directory entry to the zip archive.
	 * Basically this is only used if an empty directory is added.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param string $directoryPath Directory Path and name to be added to the archive.
	 * @param int    $timestamp     (Optional) Timestamp for the added directory, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment   (Optional) Comment to be added to the archive for this directory. To use fileComment, timestamp must be given.
	 * @param int    $extFileAttr   (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @return bool  $success
	 */
	public function addDirectory($directoryPath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_DIR) {

		// TODO: get rid of magic numbers.
		$result = false;

		if (!$this->isFinalized){

			$directoryPath = str_replace("\\", "/", $directoryPath);
			$directoryPath = rtrim($directoryPath, "/");

			if (strlen($directoryPath) > 0) {
				$this->buildZipEntry($directoryPath.'/', $fileComment, "\x00\x00", "\x00\x00", $timestamp, "\x00\x00\x00\x00", 0, 0, $extFileAttr);
				$result = true;
			}

		}

		return $result;

	}

	/**
	 * Add a file to the archive at the specified location and file name.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param string $data        File data.
	 * @param string $filePath    File path and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use fileComment, timestamp must be given.
	 * @param bool   $compress    (Optional) Compress file, if set to FALSE the file will only be stored. Default TRUE.
	 * @param int    $extFileAttr (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @return bool  $success
	 */
	public function addFile($data, $filePath, $timestamp = 0, $fileComment = null, $compress = null, $extFileAttr = self::EXT_FILE_ATTR_FILE){

		if ($this->isFinalized)
			return false;

		if (is_resource($data) && get_resource_type($data) === 'stream') {
			$this->addLargeFile($data, $filePath, $timestamp, $fileComment, $extFileAttr);
			return false;
		}

		$gzData = '';
		$gzType = self::DEFAULT_GZ_TYPE;
		$gpFlags = self::DEFAULT_GP_FLAGS;
		$dataLength = strlen($data);
		$fileCRC32 = pack("V", crc32($data));
		$gzLength = $dataLength;

		if ($compress) {

			$gzTmp = gzcompress($data);
			// gzcompress adds a 2 byte header and 4 byte CRC we can't use.
			$gzData = substr(substr($gzTmp, 0, strlen($gzTmp) - 4), 2);
			// The 2 byte header does contain useful data,
			// though in this case the 2 parameters we'd be interested in will
			// always be 8 for compression type, and 2 for General purpose flag.
			$gzLength = strlen($gzData);

		}

		if ($gzLength >= $dataLength) {
			$gzLength = $dataLength;
			$gzData = $data;
			$gzType = "\x00\x00"; // Compression type 0 = stored
			$gpFlags = "\x00\x00"; // Compression type 0 = stored
		}

		$this->onBeginAddFile(array(
			'gzLength' => $gzLength,
		));

		$this->buildZipEntry($filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr);

		$this->onEndAddFile(array(
			'gzData' => $gzData,
		));

		$this->_notifyListeners(null, array(
			'data' => $data,
		));

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
	 * @param bool   $recursive      Add content recursively, default is TRUE.
	 * @param bool   $followSymlinks Follow and add symbolic links, if they are accessible, default is TRUE.
	 * @param array  &$addedFiles     Reference to the added files, this is used to prevent duplicates, default is an empty array.
	 *                               If you start the function by parsing an array, the array will be populated with the realPath
	 *                               and zipPath kay/value pairs added to the archive by the function.
	 * @param bool   $overrideFilePermissions Force the use of the file/dir permissions set in the $extDirAttr
	 *							     and $extFileAttr parameters.
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

			$iter = new \DirectoryIterator($realPath);

			foreach ($iter as $file) {

				if ($file->isDot())
					continue;

				$newRealPath = $file->getPathname();
				$newZipPath = self::pathJoin($zipPath, $file->getFilename());

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
	 * @author Greg Kappatos
	 *
	 * @param string $dataFile    File name/path.
	 * @param string $filePath    File path and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use fileComment, timestamp must be given.
	 * @param int    $extFileAttr (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @return bool  $success
	 */
	public function addLargeFile($dataFile, $filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE) {

		$result = false;

		if (!$this->isFinalized){

			if (is_string($dataFile) && is_file($dataFile)) {

				$this->processFile($dataFile, $filePath, $timestamp, $fileComment, $extFileAttr);

			} else if (is_resource($dataFile) && get_resource_type($dataFile) == "stream") {

				$fh = $dataFile;
				$this->openStream($filePath, $timestamp, $fileComment, $extFileAttr);

				while (!feof($fh))
					$this->addStreamData(fread($fh, $this->streamChunkSize));

				$this->closeStream($this->addExtraField);

			}

			$result = true;

		}

		$this->_notifyListeners(null, array(
			'file' => $dataFile,
			'result' => $result,
		));

		return $result;

	}

	/**
	 * Create a stream to be used for large entries.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param string $filePath    File path and name to be used in the archive.
	 * @param int    $timestamp   (Optional) Timestamp for the added file, if omitted or set to 0, the current time will be used.
	 * @param string $fileComment (Optional) Comment to be added to the archive for this file. To use fileComment, timestamp must be given.
	 * @param int    $extFileAttr (Optional) The external file reference, use generateExtAttr to generate this.
	 *
	 * @throws \PHPZip\Zip\Exception\IncompatiblePhpVersion Throws an exception in case of errors
	 *
	 * @return bool $success
	 */
	public function openStream($filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE){

		$result = false;

		if (!function_exists('sys_get_temp_dir')) {
			$this->_throwException(new IncompatiblePhpVersionException(array(
				'appName' => self::APP_NAME,
				'appVersion' => self::VERSION,
				'minVersion' => self::MIN_PHP_VERSION,
			)));
		}

		if (!$this->isFinalized){

			$this->onOpenStream();

			if (strlen($this->streamFilePath) > 0)
				$this->closeStream();

			$this->streamFile = self::getTemporaryFile();
			$this->streamData = fopen($this->streamFile, "wb");
			$this->streamFilePath = $filePath;
			$this->streamTimestamp = $timestamp;
			$this->streamFileComment = $fileComment;
			$this->streamFileLength = 0;
			$this->streamExtFileAttr = $extFileAttr;

			$result = true;
		}

		$this->_notifyListeners(null, array(
			'file' => $this->streamFile,
			'result' => $result,
		));

		return $result;

	}

	/**
	 * Add data to the open stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param string $data
	 *
	 * @throws LengthMismatchException Throws an exception in case of errors
	 *
	 * @return mixed length in bytes added or FALSE if the archive is finalized or there are no open stream.
	 */
	public function addStreamData($data) {

		if ($this->isFinalized || strlen($this->streamFilePath) == 0)
			return false;

		$length = fwrite($this->streamData, $data, strlen($data));

		if ($length != strlen($data)) {
			$this->_throwException(new LengthMismatchException(array(
				'expected' => strlen($data),
				'written' => (!$length ? 'NONE!' : $length),
			)));
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

		if ($this->isFinalized || strlen($this->streamFilePath) == 0)
			return false;

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
	 * @author Greg Kappatos
	 *
	 * @param string 	$dataFile
	 * @param string 	$filePath
	 * @param int 		$timestamp
	 * @param string 	$fileComment
	 * @param int 		$extFileAttr
	 *
	 * @return bool 	$success
	 */
	protected function processFile($dataFile, $filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE){

		// TODO: change the magic numbers below to constants.

		if ($this->isFinalized)
			return false;

		$tempZip = self::getTemporaryFile();

		$zip = new \ZipArchive;

		if ($zip->open($tempZip)) {
			$zip->addFile($dataFile, 'file');
			$zip->close();
		}

		$handle = fopen($tempZip, "rb");
		$stats = fstat($handle);
		$eof = $stats['size'] - 72;

		fseek($handle, 6);

		$gpFlags = fread($handle, 2);
		$gzType = fread($handle, 2);
		fread($handle, 4);
		$fileCRC32 = fread($handle, 4);

		$v = unpack("Vval", fread($handle, 4));
		$gzLength = $v['val'];

		$v = unpack("Vval", fread($handle, 4));
		$dataLength = $v['val'];

		$this->buildZipEntry($filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr);

		$pos = 34;
		fseek($handle, $pos);

		while (!feof($handle) && $pos < $eof) {

			$len = $this->streamChunkSize;

			if ($pos + $this->streamChunkSize > $eof)
				$len = $eof - $pos;

			$data = fread($handle, $len);
			$pos += $len;

			$this->onProcessFile(array(
				'data' => $data,
			));

		}

		fclose($handle);
		unlink($tempZip);

		$this->_notifyListeners(null, array(
			'file' => $dataFile,
		));

		return true;

	}

	/**
	 * Build the Zip file structures
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
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
		$fileCommentLength = (empty($fileComment) ? 0 : strlen($fileComment));
		$timestamp = (int)$timestamp;
		$timestamp = ($timestamp == 0 ? time() : $timestamp);

		$dosTime = $this->getDosTime($timestamp);
		$tsPack = pack("V", $timestamp);

		if (!isset($gpFlags) || strlen($gpFlags) != 2)
			$gpFlags = "\x00\x00";

		$isFileUTF8 = mb_check_encoding($filePath, "UTF-8") && !mb_check_encoding($filePath, "ASCII");
		$isCommentUTF8 = !empty($fileComment) && mb_check_encoding($fileComment, "UTF-8") && !mb_check_encoding($fileComment, "ASCII");

		$localExtraField = "";
		$centralExtraField = "";

		if ($this->addExtraField) {
			$localExtraField .= "\x55\x54\x09\x00\x03" . $tsPack . $tsPack . self::EXTRA_FIELD_NEW_UNIX_GUID;
			$centralExtraField .= "\x55\x54\x05\x00\x03" . $tsPack . self::EXTRA_FIELD_NEW_UNIX_GUID;
		}

		if ($isFileUTF8 || $isCommentUTF8) {
			$flag = 0;
			$gpFlagsV = unpack("vflags", $gpFlags);
			if (isset($gpFlagsV['flags'])) {
				$flag = $gpFlagsV['flags'];
			}
			$gpFlags = pack("v", $flag | (1 << 11));

			if ($isFileUTF8) {
				$utfPathExtraField = "\x75\x70"
					. pack ("v", (5 + strlen($filePath)))
					. "\x01"
					.  pack("V", crc32($filePath))
					. $filePath;

				$localExtraField .= $utfPathExtraField;
				$centralExtraField .= $utfPathExtraField;
			}
			if ($isCommentUTF8) {
				$centralExtraField .= "\x75\x63" // utf8 encoded file comment extra field
					. pack ("v", (5 + strlen($fileComment)))
					. "\x01"
					. pack("V", crc32($fileComment))
					. $fileComment;
			}
		}

		$header = $gpFlags . $gzType . $dosTime. $fileCRC32
			. pack("VVv", $gzLength, $dataLength, strlen($filePath)); // File name length

		$zipEntry  = self::ZIP_LOCAL_FILE_HEADER
			. self::ATTR_VERSION_TO_EXTRACT
			. $header
			. pack("v", strlen($localExtraField)) // Extra field length
			. $filePath // FileName
			. $localExtraField; // Extra fields

		$this->onBuildZipEntry(array(
			'zipEntry' => $zipEntry,
		));

		$cdEntry  = self::ZIP_CENTRAL_FILE_HEADER
			. self::ATTR_MADE_BY_VERSION
			. ($dataLength === 0 ? "\x0A\x00" : self::ATTR_VERSION_TO_EXTRACT)
			. $header
			. pack("v", strlen($centralExtraField)) // Extra field length
			. pack("v", $fileCommentLength) // File comment length
			. "\x00\x00" // Disk number start
			. "\x00\x00" // internal file attributes
			. pack("V", $extFileAttr) // External file attributes
			. pack("V", $this->offset) // Relative offset of local header
			. $filePath // FileName
			. $centralExtraField; // Extra fields

		if (!empty($fileComment))
			$cdEntry .= $fileComment; // Comment

		$this->cdRec[] = $cdEntry;
		$this->offset += strlen($zipEntry) + $gzLength;

		$this->_notifyListeners(null, array(
			'file' => $zipEntry,
		));

	}

	/**
	 * Send the archive as a zip download
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param String $fileName The name of the Zip archive, in ISO-8859-1 (or ASCII) encoding, ie. "archive.zip". Optional, defaults to NULL, which means that no ISO-8859-1 encoded file name will be specified.
	 * @param String $contentType Content mime type. Optional, defaults to "application/zip".
	 * @param String $utf8FileName The name of the Zip archive, in UTF-8 encoding. Optional, defaults to NULL, which means that no UTF-8 encoded file name will be specified.
	 * @param bool   $inline Use Content-Disposition with "inline" instead of "attached". Optional, defaults to FALSE.
	 *
	 * @throws \PHPZip\Zip\Exception\IncompatiblePhpVersion, BufferNotEmpty, HeadersSent In case of errors
	 *
	 * @return bool Always returns true (for backward compatibility).
	 */
	public function sendZip($fileName = null, $contentType = self::CONTENT_TYPE, $utf8FileName = null, $inline = false) {

		$ob = null;
		$headerFile = null;
		$headerLine = null;
		$zlibConfig = 'zlib.output_compression';

		$this->onBeginSendZip();

		if (!function_exists('sys_get_temp_dir')){
			$this->_throwException(new IncompatiblePhpVersionException(array(
				'appName' => self::APP_NAME,
				'appVersion' => self::VERSION,
				'minVersion' => self::MIN_PHP_VERSION,
			)));
		}

		$ob = ob_get_contents();

		if ($ob !== false && strlen($ob)) {
			$this->_throwException(new BufferNotEmptyException(array(
				'outputBuffer' => $ob,
				'fileName' => $fileName,
			)));
		}

		if (headers_sent($headerFile, $headerLine)) {
			$this->_throwException(new HeadersSentException(array(
				'headerFile' => $headerFile,
				'headerLine' => $headerLine,
				'fileName' => $fileName,
			)));
		}

		if (@ini_get($zlibConfig))
			@ini_set($zlibConfig, 'Off');

		$cd = 'Content-Disposition: ' . ($inline ? 'inline' : 'attached');

		if ($fileName)
			$cd .= '; filename="' . $fileName . '"';

		if ($utf8FileName)
			$cd .= "; filename*=UTF-8''" . rawurlencode($utf8FileName);

		header('Pragma: public');
		header("Last-Modified: " . @gmdate("D, d M Y H:i:s T"));
		header("Expires: 0");
		header("Accept-Ranges: bytes");
		header("Content-Type: " . $contentType);
		header($cd);

		$this->onEndSendZip();
		$this->_notifyListeners(null, array(
			'file' => $fileName,
			'utf8FileName' => $utf8FileName,
			'contentType' => $contentType,
		));

		return true;
	}

	/**
	 * Listen to events fired by this class.
	 *
	 * @author Greg Kappatos
	 *
	 * @param ZipArchiveListener $listener Class that implements the ZipArchiveListener interface.
	 */
	public function addListener(ZipArchiveListener $listener){

		$this->_listeners[] = $listener;

	}

	/**
	 * Stop listening to events fired by this class.
	 *
	 * @author Greg Kappatos
	 *
	 * @param ZipArchiveListener $listener Class that implements the ZipArchiveListener interface.
	 */
	public function removeListener(ZipArchiveListener $listener){

		$key = array_search($listener, $this->_listeners);

		if ($key !== false)
			unset($this->_listeners[$key]);

	}

	/*
	 * ************************************************************************
	 * Abstract methods.
	 * ************************************************************************
	 */

	/**
	 * Close the archive.
	 * A closed archive can no longer have new files added to it.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @return bool $success
	 */
	abstract public function finalize();

	/**
	 * Called when specialised action is needed
	 * while building a zip entry.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains zipEntry.
	 */
	abstract protected function onBuildZipEntry(array $params);

	/**
	 * Called when specialised action is needed
	 * at the start of adding a file to the archive.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains gzLength.
	 */
	abstract protected function onBeginAddFile(array $params);

	/**
	 * Called when specialised action is needed
	 * at the end of adding a file to the archive.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains gzData.
	 */
	abstract protected function onEndAddFile(array $params);

	/**
	 * Called when specialised action is needed
	 * at the start of sending a zip file|stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	abstract protected function onBeginSendZip();

	/**
	 * Called when specialised action is needed
	 * at the end of sending a zip file|stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	abstract protected function onEndSendZip();

	/**
	 * Called when specialised action is needed
	 * while opening a file|stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	abstract protected function onOpenStream();

	/**
	 * Called when specialised action is needed
	 * while processing a file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains data.
	 */
	abstract protected function onProcessFile(array $params);

	/*
	 * ************************************************************************
	 * Private methods.
	 * ************************************************************************
	 */

	/**
	 * Helper method to fire appropriate event.
	 *
	 * @author Greg Kappatos
	 *
	 * @param string|null $method (Optional) The name of the event to fire. If this is null, then the calling method is used.
	 * @param array 	  $data Method parameters passed as an array.
	 */
	private function _notifyListeners($method = null, array $data = array()){

		if (is_null($method)){
			$trace = debug_backtrace()[1];
			$method = 'on' . ucwords($trace['function']);
		}

		foreach ($this->_listeners as $listener){

			if (count($data) > 0)
				$listener->$method($data);
			else
				$listener->$method();

		}

	}

	/**
	 * Helper method to fire OnException event for listeners and then throw the appropriate exception.
	 *
	 * @author Greg Kappatos
	 *
	 * @param \PHPZip\Zip\Core\AbstractException $exception Whatever exception needs to be thrown.
	 *
	 * @throws \PHPZip\Zip\Core\AbstractException $exception
	 */
	private function _throwException(\PHPZip\Zip\Core\AbstractException $exception){

		$this->_notifyListeners('onException', array(
			'exception' => $exception,
		));

		throw $exception;

	}

	/**
	 * Calculate the 2 byte dos time used in the zip entries.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param int 		$timestamp
	 *
	 * @return string 	2-byte encoded DOS Date
	 */
	private function getDosTime($timestamp = 0) {

		$epoc = 1980;
		$timestamp = (int)$timestamp;
		$oldTZ = @date_default_timezone_get();
		date_default_timezone_set('UTC');

		$date = ($timestamp == 0 ? getdate() : getdate($timestamp));
		date_default_timezone_set($oldTZ);

		if ($date["year"] >= $epoc) {
			return pack("V", (($date["mday"] + ($date["mon"] << 5) + (($date["year"] - $epoc) << 9)) << 16) |
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
	 * @author Greg Kappatos
	 *
	 * @param string $dir
	 * @param string $file
	 *
	 * @return string Joined path
	 */
	static public function pathJoin($dir, $file) {

		return self::getRelativePath(
			$dir . (empty($dir) || empty($file) ? '' : DIRECTORY_SEPARATOR) . $file
		);

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
	static public function getRelativePath($path) {

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

					if ($subOffset > $sub)
						$sub++;

				}

			}

		}

		if (empty($root))
			$root = str_repeat("../", $sub);

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
	static public function generateExtAttr($owner = 07, $group = 05, $other = 05, $isFile = true) {

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
	 * @return string|bool external ref field, or FALSE if the file is not found.
	 */
	static public function getFileExtAttr($filename) {

		if (file_exists($filename)) {

			$fp = fileperms($filename) << 16;
			return $fp | (is_dir($filename) ? self::S_DOS_D : self::S_DOS_A);

		}

		return false;
	}

	/**
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @return string The full path to a temporary file.
	 */
	static private function getTemporaryFile() {

		if (is_callable(self::$temp)) {

			$file = @call_user_func(self::$temp);

			if (is_string($file) && strlen($file) && is_writable($file))
				return $file;

		}

		$dir = (is_string(self::$temp) && strlen(self::$temp)) ? self::$temp : sys_get_temp_dir();

		return tempnam($dir, __NAMESPACE__);

	}

}