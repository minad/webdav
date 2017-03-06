<?php
require_once 'VFS.php';
require_once 'Crypt/Blowfish.php';
require_once 'Decorator.php';

class BlowfishVFS extends Decorator {
	private $crypt;

	public function __construct($vfs, $key, $iv) {
		parent::__construct($vfs);
		$this->crypt = Crypt_Blowfish::factory('cbc', $key, $iv);
	}

	public function read($path, $name) {
		$data = $this->crypt->decrypt($this->__call('read', array($path, $name)));
		$i = strpos($data, ',');
		$size = intval(substr($data, 0, $i));
		return substr($data, $i + 1, $size);
	}

	public function writeData($path, $name, $data, $autocreate = true) {
		return $this->__call('writeData', array($path, $name, $this->crypt->encrypt(strlen($data) . ',' . $data), $autocreate));
	}

	public function readByteRange($path, $name, &$offset, $length, &$remaining) {
		return PEAR::raiseError("Not supported.");
	}

	public function write($path, $name, $tmpFile, $autocreate = true) {
		return PEAR::raiseError("Not supported.");
	}
}
?>
