<?php

namespace PHPZip\Zip\Stream;

use PHPZip\Zip\Core\AbstractException;
use PHPZip\Zip\Core\AbstractZipArchive;
use PHPZip\Zip\Core\Header\EndOfCentralDirectory;
use PHPZip\Zip\Core\Header\ZipFileEntry;
use PHPZip\Zip\Core\Header\AbstractZipHeader;
use PHPZip\Zip\Exception\HeaderPositionError;
use PHPZip\Zip\Exception\BufferNotEmpty;
use PHPZip\Zip\Exception\HeadersSent;
use PHPZip\Zip\Exception\IncompatiblePhpVersion;

/**
 * Description of PHPZipMerge
 *
 * @author Grandt
 */
class ZipMerge {
	const APP_NAME = 'PHPZipMerge';
	const VERSION = "0.1.0";
	const MIN_PHP_VERSION = 5.3; // for namespaces
	
	const CONTENT_TYPE = 'application/zip';

    private $_listeners = array();

	protected $isFinalized = false;

	protected $FILES = array();
	protected $eocd = null;
	protected $LFHindex = 0;
	protected $CDRindex = 0;
	protected $entryOffset = 0;
	protected $streamChunkSize = 65536; // 64kb

	/**
	 * Constructor.
	 *
	 * @param String $fileName The name of the Zip archive, in ISO-8859-1 (or ASCII) encoding, ie. "archive.zip". Optional, defaults to NULL, which means that no ISO-8859-1 encoded file name will be specified.
	 * @param String $contentType Content mime type. Optional, defaults to "application/zip".
	 * @param String $utf8FileName The name of the Zip archive, in UTF-8 encoding. Optional, defaults to NULL, which means that no UTF-8 encoded file name will be specified.
	 * @param bool $inline Use Content-Disposition with "inline" instead of "attached". Optional, defaults to false.
	 */
	public function __construct($fileName = "", $contentType = "application/zip", $utf8FileName = null, $inline = false) {
        $this->checkVersion();

		$this->buildResponseHeader($fileName, $contentType, $utf8FileName, $inline);
		$this->zipFlushBuffer();
		
		$this->eocd = new EndOfCentralDirectory();
	}

	public function __destruct() {
		$this->isFinalized = true;
		exit;
	}

	public function appendZip($file, $subPath = '') {
		if ($this->isFinalized) {
			return false;
		}

        if (!empty($subPath)) {
            $subPath = str_replace("\\", '/', $subPath);
            $subPath = rtrim($subPath, '/') . '/';
/*
            $fileEntry = new ZipFileEntry();
            $fileEntry->gzType = 0;
            $fileEntry->gpFlags = 0;
            $fileEntry->fileCRC32 = 0;
            $fileEntry->externalFileAttributes = ZipFileEntry::EXT_FILE_ATTR_DIR;
            $fileEntry->gzLength = 0;
            $fileEntry->dataLength = 0;
            $fileEntry->isDirectory = true;

            $this->FILES[$this->LFHindex++] = $fileEntry;
            $this->CDRindex++;
*/
        }
		
		if (is_string($file) && is_file($file)) {
			$handle = fopen($file, 'r');
			$this->processStream($handle, $subPath);
			fclose($handle);
		} else if (is_resource($file) && get_resource_type($file) == "stream") {
			$curPos = ftell($file);
			$this->processStream($file, $subPath);
			fseek($file, $curPos, SEEK_SET);
		}
        return true;
	}
	
	private function processStream($handle, $subPath = '') {
		$pkHeader = null;

		do {
			$curPos = ftell($handle);
			$pkHeader = AbstractZipHeader::seekPKHeader($handle);

			if ($pkHeader === false || feof($handle)) {
				break;
			}
			$pkPos = ftell($handle);

			if ($curPos < ($pkPos)) {
				$this->_throwException(new HeaderPositionError(array(
					'expected' => $curPos,
					'actual' => $pkPos
				)));
			}

			if ($pkHeader === AbstractZipHeader::ZIP_CENTRAL_FILE_HEADER) {
				$fileEntry = $this->FILES[$this->CDRindex++];
                /* @var $fileEntry ZipFileEntry */
				$fileEntry->parseHeader($handle);
			} else if ($pkHeader === AbstractZipHeader::ZIP_LOCAL_FILE_HEADER) {
				$fileEntry = new ZipFileEntry($handle);
				$this->FILES[$this->LFHindex++] = $fileEntry;

                $fileEntry->prependPath($subPath);

				$lf = $fileEntry->getLocalHeader();
				$lfLen = $this->bin_strlen($lf);
				$this->zipWrite($lf);
				fseek($handle, $fileEntry->offset + $fileEntry->dataOffset, SEEK_SET);
				if (!$fileEntry->isDirectory) {
					$len = $fileEntry->gzLength;
					while ($len >= $this->streamChunkSize) {
						$this->zipWrite(fread($handle, $this->streamChunkSize));
						$len -= $this->streamChunkSize;
					}
					$this->zipWrite(fread($handle, $len));
				}
				
				$fileEntry->offset = $this->entryOffset;
				$this->entryOffset += $lfLen + $fileEntry->gzLength;
			} else if ($pkHeader === AbstractZipHeader::ZIP_END_OF_CENTRAL_DIRECTORY) {
				fread($handle, 4);
				$this->eocd = new EndOfCentralDirectory($handle);
			}
		} while (!feof($handle));
	}
	
	/**
	 * Close the archive.
	 * A closed archive can no longer have new files added to it.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return bool Success
	 */
	public function finalize() {
		if (!$this->isFinalized) {
			$this->eocd->cdrStart = $this->entryOffset;
			$this->eocd->cdrLength = 0;
			$this->eocd->cdrCount1 = 0;

			foreach ($this->FILES as $fileEntry) {
                /* @var $fileEntry ZipFileEntry */
                $this->eocd->cdrCount1++;
				$cd = $fileEntry->getCentralDirectoryHeader();

				$this->eocd->cdrLength += $this->bin_strlen($cd);
				$this->zipWrite($cd);
			}

			$this->eocd->cdrCount2 = $this->eocd->cdrCount1;
			$this->zipWrite(''.$this->eocd);

			return true;
		}
		return false;
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
	 * 
 	 * @throws \PHPZip\Zip\Exception\BufferNotEmpty, HeadersSent In case of errors
	 */
	protected function buildResponseHeader($fileName = null, $contentType = self::CONTENT_TYPE, $utf8FileName = null, $inline = false) {
		$ob = null;
		$headerFile = null;
		$headerLine = null;
		$zlibConfig = 'zlib.output_compression';

		$ob = ob_get_contents();
		if ($ob !== false && strlen($ob)) {
			$this->_throwException(new BufferNotEmpty(array(
				'outputBuffer' => $ob,
				'fileName' => $fileName,
			)));
		}

		if (headers_sent($headerFile, $headerLine)) {
			$this->_throwException(new HeadersSent(array(
				'headerFile' => $headerFile,
				'headerLine' => $headerLine,
				'fileName' => $fileName,
			)));
		}

		if (@ini_get($zlibConfig)) {
			@ini_set($zlibConfig, 'Off');
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
			$this->_throwException(new IncompatiblePhpVersion(array(
				'appName' => self::APP_NAME,
				'appVersion' => self::VERSION,
				'minVersion' => self::MIN_PHP_VERSION,
			)));
			return false;
		}
		return true;
	}

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
    private function _notifyListeners($method = null, array $data = array()) {
        if (is_null($method)) {
            $trace = debug_backtrace();
            $trace = $trace[1];
            $method = 'on' . ucwords($trace['function']);
        }

        foreach ($this->_listeners as $listener) {
            if (count($data) > 0) {
                $listener->$method($data);
            } else {
                $listener->$method();
            }
        }
    }

    /**
	 * Helper method to fire OnException event for listeners and then throw the appropriate exception.
	 *
	 * @author Greg Kappatos
	 *
	 * @param AbstractException $exception Whatever exception needs to be thrown.
	 *
	 * @throws AbstractException $exception
	 */
	private function _throwException(AbstractException $exception) {
		$this->_notifyListeners('Exception', array(
			'exception' => $exception,
		));

		throw $exception;
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
		// Does nothing, used to "streamline" code differences between PHPZip and PHPZipStream
	}

	/**
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @param string $data
	 */
	public function zipWrite($data) {
		print($data);
	}

	/**
	 * Flush Zip Data stored in memory, to a temp file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 */
	public function zipFlush() {
		// Does nothing, used to "streamline" code differences between PHPZip and PHPZipStream
	}

	/**
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 */
	public function zipFlushBuffer() {
		flush();
	}
}
