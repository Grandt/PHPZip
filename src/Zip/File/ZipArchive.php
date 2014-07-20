<?php
/**
 *
 * @author A. Grandt <php@grandt.com>
 * @author Greg Kappatos
 *
 * This class serves as a concrete zip file archive.
 *
 */

namespace PHPZip\Zip\File;

class ZipArchive extends \PHPZip\Zip\Core\AbstractZipArchive {

	const MEMORY_THRESHOLD = 1048576; // 1 MB - Auto create temp file if the zip data exceeds this
	const STREAM_CHUNK_SIZE = 65536; // 64 KB

	private $_zipData = null;
	private $_zipFile = null;

	/**
	 * Constructor.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param boolean $useZipFile Write temp zip data to tempFile? Default FALSE
	 *
	 * @throws \PHPZip\Zip\Exception\InvalidPhpConfiguration In case of errors
	 */
	public function __construct($useZipFile = false){

		parent::__construct(self::STREAM_CHUNK_SIZE);

		if ($useZipFile) {

			$this->_zipFile = tmpfile();

		} else {

			$this->_zipData = '';

		}

	}

	/**
	 * Destructor.
	 * Perform clean up actions.
	 *
	 * @author A. Grandt <php@grandt.com>
	 */
	public function __destruct(){

		if (is_resource($this->_zipFile))
			fclose($this->_zipFile);

		$this->_zipData = null;

	}

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
	 * @return bool Success
	 */
	public function setZipFile($fileName) {

		if (is_file($fileName))
			unlink($fileName);

		$fd = fopen($fileName, "x+b");

		if (is_resource($this->_zipFile)) {

			rewind($this->_zipFile);

			while (!feof($this->_zipFile))
				fwrite($fd, fread($this->_zipFile, $this->streamChunkSize));

			fclose($this->_zipFile);

		} else {

			fwrite($fd, $this->_zipData);
			$this->_zipData = null;

		}

		$this->_zipFile = $fd;

		return true;

	}

	/**
	 * Get the handle resource for the archive zip file.
	 * If the zip haven't been finalized yet, this will cause it to become finalized
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return resource zip file handle
	 */
	public function getZipFile() {

		if (!$this->isFinalized)
			$this->finalize();

		$this->_zipFlush();

		rewind($this->_zipFile);

		return $this->_zipFile;

	}

	/**
	 * Get the zip file contents
	 * If the zip haven't been finalized yet, this will cause it to become finalized
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @return string zip data
	 */
	public function getZipData() {

		$result = null;

		if (!$this->isFinalized)
			$this->finalize();

		if (!is_resource($this->_zipFile)) {

			$result = $this->_zipData;

		} else {

			rewind($this->_zipFile);
			$stat = fstat($this->_zipFile);
			$result = fread($this->_zipFile, $stat['size']);

		}

		return $result;

	}

	/**
	 * Return the current size of the archive
	 *
	 * @author A. Grandt <php@grandt.com>
	 *
	 * @return int Size of the archive
	 */
	public function getArchiveSize() {

		if (!is_resource($this->_zipFile))
			return strlen($this->_zipData);

		$stat = fstat($this->_zipFile);

		return $stat['size'];

	}

	/*
	 * ************************************************************************
	 * Private methods.
	 * ************************************************************************
	 */

	/**
	 * Write data to file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param string $data Data to be written
	 */
	private function _zipWrite($data) {

		if (!is_resource($this->_zipFile)) {

			$this->_zipData .= $data;

		} else {

			fwrite($this->_zipFile, $data);
			fflush($this->_zipFile);

		}

	}

	/**
	 * Flush the data to file, and reset the data.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 */
	private function _zipFlush() {

		if (!is_resource($this->_zipFile)) {

			$this->_zipFile = tmpfile();
			fwrite($this->_zipFile, $this->_zipData);
			$this->_zipData = null;

		}

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

			if (strlen($this->streamFilePath) > 0)
				$this->closeStream();

			$cd = implode("", $this->cdRec);

			$cdRecSize = pack("v", sizeof($this->cdRec));
			$cdRec = $cd . self::ZIP_END_OF_CENTRAL_DIRECTORY
				. $cdRecSize . $cdRecSize
				. pack("VV", strlen($cd), $this->offset);

			if (!empty($this->zipComment)) {
				$cdRec .= pack("v", strlen($this->zipComment)) . $this->zipComment;
			} else {
				$cdRec .= "\x00\x00";
			}

			$this->_zipWrite($cdRec);

			$this->isFinalized = true;
			$this->cdRec = null;

			return true;

		}

		return false;
	}

	/*
	 * ************************************************************************
	 * Superclass callbacks.
	 * ************************************************************************
	 */

	/**
	 * Called by superclass when specialised action is needed
	 * while building a zip entry.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains zipEntry.
	 */
	public function onBuildZipEntry(array $params){

		$this->_zipWrite($params['zipEntry']);

	}

	/**
	 * Called by superclass when specialised action is needed
	 * at the start of adding a file to the archive.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains gzLength.
	 */
	public function onBeginAddFile(array $params){

		if (!is_resource($this->_zipFile) && ($this->offset + $params['gzLength']) > self::MEMORY_THRESHOLD)
			$this->_zipFlush();

	}

	/**
	 * Called by superclass when specialised action is needed
	 * at the end of adding a file to the archive.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains gzData.
	 */
	public function onEndAddFile(array $params){

		$this->_zipWrite($params['gzData']);

	}

	/**
	 * Called by superclass when specialised action is needed
	 * at the start of sending a zip file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	public function onBeginSendZip(){

		if (!$this->isFinalized)
			$this->finalize();

	}

	/**
	 * Called by superclass when specialised action is needed
	 * at the end of sending a zip file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	public function onEndSendZip(){

		header('Connection: close');
		header('Content-Length: ' . $this->getArchiveSize());

		if (!is_resource($this->_zipFile)) {

			echo $this->_zipData;

		} else {

			rewind($this->_zipFile);

			while (!feof($this->_zipFile))
				echo fread($this->_zipFile, $this->streamChunkSize);

		}

	}

	/**
	 * Called by superclass when specialised action is needed
	 * while opening a stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	public function onOpenStream(){

		$this->_zipFlush();

	}

	/**
	 * Called by superclass when specialised action is needed
	 * while processing a file.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param array $params Array that contains data.
	 */
	public function onProcessFile(array $params){

		$this->_zipWrite($params['data']);

	}

}