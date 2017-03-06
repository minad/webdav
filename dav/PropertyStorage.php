<?php
interface PropertyStorage {
	function get($dir, $name);
	function set($dir, $name, $props);
	function remove($dir, $name);
}

class VFS_PropertyStorage implements PropertyStorage {
	private $vfs;

	private $path;

	private $file;

	private $dirty = false;

	private $props = null;

	public function __construct($vfs, $path = '/', $file = '.WEBDAV.PROPERTIES') {
		$this->vfs = $vfs;
		$this->path = $path;
		$this->file = $file;
	}

	public function __destruct() {
		$this->save();
	}

	public function set($dir, $name, $newProps) {
		$this->load();
		if (!isset($this->props[$dir]))
			$this->props[$dir] = array();
		$this->props[$dir][$name] = $newProps;
		$this->dirty = true;
	}

	public function remove($dir, $name) {
		$props = $this->get($dir, $name);
		if (!empty($props)) {
			unset($this->props[$dir][$name]);
			if (empty($this->props[$dir]))
				unset($this->props[$dir]);
			$this->dirty = true;
		}
	}

	public function get($dir, $name) {
		$this->load();
		if (isset($this->props[$dir]) && isset($this->props[$dir][$name]))
			return $this->props[$dir][$name];
		return array();
	}

	private function load() {
		if ($this->props === null) {
			if ($this->vfs->exists($this->path, $this->file))
				$this->props = unserialize($this->vfs->read($this->path, $this->file));
			else
				$this->props = array();
		}
	}

	private function save() {
		if ($this->dirty)
			$this->vfs->writeData($this->path, $this->file, serialize($this->props), true);
	}
}
?>
