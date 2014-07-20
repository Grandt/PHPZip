<?php
/**
 *
 * @author A. Grandt <php@grandt.com>
 * @author Greg Kappatos
 *
 * This class serves as a concrete zip stream archive.
 *
 */

namespace PHPZip\Zip\Stream;

class ZipArchive extends \PHPZip\Zip\Core\AbstractZipArchive {

	const STREAM_CHUNK_SIZE = 16384; // 16 KB

	/**
	 * Constructor.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 *
	 * @param String $fileName The name of the Zip archive, in ISO-8859-1 (or ASCII) encoding, ie. "archive.zip". Optional, defaults to NULL, which means that no ISO-8859-1 encoded file name will be specified.
	 * @param String $contentType Content mime type. Optional, defaults to "application/zip".
	 * @param String $utf8FileName The name of the Zip archive, in UTF-8 encoding. Optional, defaults to NULL, which means that no UTF-8 encoded file name will be specified.
	 * @param bool   $inline Use Content-Disposition with "inline" instead of "attached". Optional, defaults to FALSE.
	 *
	 * @throws \PHPZip\Zip\Exception\BufferNotEmpty, HeadersSent, IncompatiblePhpVersion, InvalidPhpConfiguration In case of errors
	 */
	public function __construct($fileName = '', $contentType = self::CONTENT_TYPE, $utf8FileName = null, $inline = false) {

		parent::__construct(self::STREAM_CHUNK_SIZE);

		$this->sendZip($fileName, $contentType, $utf8FileName, $inline);

	}

	/**
	 * Destructor.
	 * Perform clean up actions.
	 *
	 * @author A. Grandt <php@grandt.com>
	 */
	public function __destruct(){

		$this->isFinalized = true;
		$this->cdRec = null;

		// TODO: does this really need to be here?
		// If this is a library, and people are using it inside their projects,
		// some frameworks like Yii perform their logging etc at the end of the request,
		// so exiting here, will prevent those mechanisms from working.
		exit(0);

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

			$cdRecSize = pack("v", sizeof($this->cdRec));

			$cd = implode("", $this->cdRec);

			print($cd);
			print(self::ZIP_END_OF_CENTRAL_DIRECTORY);
			print($cdRecSize.$cdRecSize);
			print(pack("VV", strlen($cd), $this->offset));

			if (!empty($this->zipComment)) {
				print(pack("v", strlen($this->zipComment)));
				print($this->zipComment);
			} else {
				print("\x00\x00");
			}

			flush();

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

		print($params['zipEntry']);

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

		// Do nothing.

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

		print($params['gzData']);

	}

	/**
	 * Called by superclass when specialised action is needed
	 * at the start of sending a zip stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	public function onBeginSendZip(){

		// Do nothing.

	}

	/**
	 * Called by superclass when specialised action is needed
	 * at the end of sending a zip stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	public function onEndSendZip(){

		//header("Connection: Keep-Alive");
		flush();

	}

	/**
	 * Called by superclass when specialised action is needed
	 * while opening a stream.
	 *
	 * @author A. Grandt <php@grandt.com>
	 * @author Greg Kappatos
	 */
	public function onOpenStream(){

		// Do nothing.

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

		print($params['data']);
		flush();

	}

}