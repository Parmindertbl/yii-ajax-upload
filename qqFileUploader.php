<?php

/**
 * Handle file uploads via XMLHttpRequest
 */
class qqUploadedFileXhr
{

	/**
	 * Save the file to the specified path
	 * @return boolean TRUE on success
	 */
	function save($path)
	{
		$input = fopen('php://input', 'r');
		$temp = tmpfile();
		$realSize = stream_copy_to_stream($input, $temp);
		fclose($input);

		if ($realSize != $this->getSize())
			return false;

		$target = fopen($path, "w");
		fseek($temp, 0, SEEK_SET);
		stream_copy_to_stream($temp, $target);
		fclose($target);

		return true;
	}

	function getName()
	{
		return $_GET['qqfile'];
	}

	function getSize()
	{
		if (isset($_SERVER['CONTENT_LENGTH']))
			return (int) $_SERVER['CONTENT_LENGTH'];
		else
			throw new CException('Getting content length is not supported.');
	}

}

/**
 * Handle file uploads via regular form post (uses the $_FILES array)
 */
class qqUploadedFileForm
{

	/**
	 * Save the file to the specified path
	 * @return boolean TRUE on success
	 */
	function save($path)
	{
		if (!move_uploaded_file($_FILES['qqfile']['tmp_name'], $path))
			return false;
		return true;
	}

	function getName()
	{
		return $_FILES['qqfile']['name'];
	}

	function getSize()
	{
		return $_FILES['qqfile']['size'];
	}

}

class qqFileUploader
{
	private $allowedExtensions = array();
	private $sizeLimit = 10485760;
	private $file;

	public function __construct(array $allowedExtensions = array(), $sizeLimit = 10485760)
	{
		$allowedExtensions = array_map("strtolower", $allowedExtensions);

		$this->allowedExtensions = $allowedExtensions;
		$this->sizeLimit = $sizeLimit;

		$this->checkServerSettings();

		if (isset($_GET['qqfile']))
			$this->file = new qqUploadedFileXhr();
		elseif (isset($_FILES['qqfile']))
			$this->file = new qqUploadedFileForm();
		else
			$this->file = false;
	}

	protected function checkServerSettings()
	{
		$postSize = $this->toBytes(ini_get('post_max_size'));
		$uploadSize = $this->toBytes(ini_get('upload_max_filesize'));

		if ($postSize < $this->sizeLimit || $uploadSize < $this->sizeLimit) {
			$size = max(1, $this->sizeLimit / 1024 / 1024) . 'M';
			throw new Exception("{'error':'increase post_max_size and upload_max_filesize to $size'}");
		}
	}

	private function toBytes($str)
	{
		$val = trim($str);
		$last = strtolower($str[strlen($str) - 1]);
		switch ($last) {
			case 'g': $val *= 1024;
			case 'm': $val *= 1024;
			case 'k': $val *= 1024;
		}
		return $val;
	}

	/**
	 *
	 * @param string $uploadDirectory
	 * @param string $filename
	 * @param boolean $replaceOldFile
	 * @return array 
	 */
	protected function saveToFile($uploadDirectory, $filename, $replaceOldFile)
	{
		if (!is_writable($uploadDirectory))
			return array('error' => "Server error. Upload directory isn't writable.");

		if (!$replaceOldFile) {
			/// don't overwrite previous files that were uploaded
			while (file_exists($uploadDirectory . $filename))
				$filename .= rand(10, 99);
		}

		if ($this->file->save($uploadDirectory . $filename))
			return array('success' => true, 'filename' => $filename);
		else
			return array('error' => 'Could not save uploaded file.' .
				'The upload was cancelled, or server error encountered');
	}

	/**
	 *
	 * @param EMongoGridFS $mongoImage
	 * @param string $filename
	 * @param boolean $replaceOldFile 
	 */
	protected function saveToMongo(EMongoGridFS $mongoImage, $filename, $replaceOldFile)
	{
		$handle = fopen('php://input', 'rb');
		$bytes = stream_get_contents($handle);
		fclose($handle);

		$mongoImage->setBytes($bytes);

		$finfo = new finfo();
		$mongoImage->contentType = $finfo->buffer($bytes, FILEINFO_MIME_TYPE);

		$mongoImage->filename = $filename;

		if (!$replaceOldFile)
			$mongoImage->insert();
		else
			$mongoImage->save();

		return array(
			'success' => true,
			'filename' => $filename,
			'mongoId' => $mongoImage->_id->{'$id'}
		);
	}

	/**
	 *
	 * @param mixed $uploadTo String (upload directory) or EMongoGridFS object.
	 * @param string $filename Specify file name (do not include extension).
	 * @param string $replaceOldFile Whether to replace an existing file.
	 * @return array array('success'=>true) or array('error'=>'error message')
	 */
	public function handleUpload($uploadTo, $filename = null, $replaceOldFile = true)
	{
		if (!$this->file)
			return array('error' => 'No files were uploaded.');

		$size = $this->file->getSize();

		if ($size == 0)
			return array('error' => 'File is empty');

		if ($size > $this->sizeLimit)
			return array('error' => 'File is too large');

		$pathinfo = pathinfo($this->file->getName());

		if ($filename === null) {
			$filename = $pathinfo['filename'];
		}
		$ext = $pathinfo['extension'];

		if ($this->allowedExtensions && !in_array(strtolower($ext), $this->allowedExtensions)) {
			$these = implode(', ', $this->allowedExtensions);
			return array('error' => 'File has an invalid extension, it should be one of ' . $these . '.');
		}

		$filename = $filename . '.' . $ext;
		if (is_string($uploadTo))
			return $this->saveToFile($uploadTo, $filename, $replaceOldFile);
		else if ($uploadTo instanceof EMongoGridFS)
			return $this->saveToMongo($uploadTo, $filename, $replaceOldFile);
	}

}
