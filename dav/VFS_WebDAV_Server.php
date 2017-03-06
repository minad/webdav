<?php
require_once 'HTTP/WebDAV/Server.php';

class VFS_WebDAV_Server extends HTTP_WebDAV_Server {
	private $vfs;

	private $log;

	private $properties;

	public function __construct($vfs, $propStorage, $log = null) {
		parent::__construct();
		$this->vfs = $vfs;
		$this->properties = $propStorage;
		$this->log = $log ? $log : Log::singleton('null');
	}

	protected function GET(&$options) {
		$path = $options['path'];
		$dir = dirname($path);
		$name = basename($path);

		$this->log->info("GET $path");

		if (!$this->exists($dir, $name))
			return false;

		if ($this->isFolder($dir, $name))
			return $this->getDirListing($options);

		$options['mimetype'] = 'text/plain';
		$options['data'] = $this->vfs->read($dir, $name);
		return true;
	}

	protected function HEAD(&$options) {
		$path = $options['path'];
		$dir = dirname($path);
		$name = basename($path);

		$this->log->info("HEAD $path");
		return $this->exists($dir, $name);
	}

	protected function PUT(&$options) {
		$path = $options['path'];
		$dir = dirname($path);
		$name = basename($path);

		$this->log->info("PUT $path");
	
		if ($this->isFolder($dir, $name))
			return '409 Conflict';
	
		$options['new'] = !$this->exists($dir, $name);
		$this->vfs->writeData($dir, $name, stream_get_contents($options['stream']), true);
		return true;
	}

	protected function MOVE(&$options) {
		$src = $options['path'];
		$srcDir = dirname($src);
		$srcName = basename($src);

		$dst = $options['dest'];
		$dstDir = dirname($dst);
		$dstName = basename($dst);

		$this->log->info("MOVE $src TO $dst");

		if ($this->exists($dstDir, $dstName)) {
			//if (!$options['overwrite'])
			//	return '412 precondition failed';
			if ($this->isFolder($dstDir, $dstName))
				$this->vfs->deleteFolder($dstDir, $dstName, true);
			else
				$this->vfs->deleteFile($dstDir, $dstName);
		}

		$props =& $this->properties->get($srcDir, $srcName);
		if (!empty($props)) {
			$this->properties->remove($srcDir, $srcName);
			$this->properties->set($dstDir, $dstName, $props);
		}

		$this->vfs->rename($srcDir, $srcName, $dstDir, $dstName);
		return '201 Created';
	}

	protected function COPY(&$options) {
		$src = $options['path'];
		$srcDir = dirname($src);
		$srcName = basename($src);

		$dst = $options['dest'];
		$dstDir = dirname($dst);
		$dstName = basename($dst);

		$this->log->info("COPY $src TO $dst");
		$this->recursiveCopy($srcDir, $srcName, $dstDir, $dstName);
		return '201 Created';
	}

	protected function MKCOL(&$options) {
		$path       = $options['path'];
		$dir	= dirname($path);
		$name       = basename($path);
		$parentName = basename($dir);
		$parentDir  = dirname($dir);

		$this->log->info("MKCOL $path");

		if (!$this->exists($parentDir, $parentName))
			return '409 Conflict';

		if (!$this->isFolder($parentDir, $parentName))
			return '403 Forbidden';
	
		if ($this->exists($dir, $name))
			return '405 Method not allowed';
			
		$this->vfs->createFolder($dir, $name);
		return '201 Created';
	}

	protected function DELETE(&$options) {
		$path = $options['path'];
		$dir = dirname($path);
		$name = basename($path);

		$this->log->info("DELETE $path");

		if (!$this->exists($dir, $name))
			return '404 Not found';

		if ($this->isFolder($dir, $name))
			$this->vfs->deleteFolder($dir, $name, true);
		else
			$this->vfs->deleteFile($dir, $name);
		
		$this->properties->remove($dir, $name);

		return '204 No Content';
	}

	protected function PROPFIND(&$options, &$files) {
		$path = $this->_unslashify($options['path']);
		$dir  = dirname($path);
		$name = basename($path);

		$this->log->info("PROPFIND $path");

		if (!$this->exists($dir, $name))
			return false;

		$files['files'] = array();
		$files['files'][] = $this->fileInfo($dir, $name);

		if ($this->isFolder($dir, $name) && $options['depth']) {
			foreach ($this->vfs->listFolder($path) as $file) {
				if (stripos($file['name'], '.webdav') !== 0)
					$files['files'][] = $this->fileInfo($path, $file['name'], $file['date']);
			}
		}

		return true;
	}

	protected function PROPPATCH(&$options) {
		$path = $options['path'];
		$dir  = dirname($path);
		$name = basename($path);

		$this->log->info("PROPPATCH $path");

		$props = $this->properties->get($dir, $name);
		foreach ($options['props'] as $key => $p) {
			if ($prop['ns'] == 'DAV:') {
				$options['props'][$key]['status'] = '403 Forbidden';
			} else {
				if (isset($p['val'])) {
					$props[] = $p;
				} else {
					foreach ($props as $i => $x) {
						if ($x['ns'] == $p['ns'] && $x['val'] == $p['val'])
							unset($props[$i]);
					}
				}
			}
		}
		$this->properties->set($dir, $name, $props);

		return ''; // Empty description
	}

	private function getDirListing(&$options) {
		$path = $options['path'];
		$parent = preg_replace('/\/[^\/]+$/', '', $_SERVER['PHP_SELF']);

		$data = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">' .
		     "\n<html xmlns=\"http://www.w3.org/1999/xhtml\"><head><title>WebDAV index of " . htmlspecialchars($path) .
		     "</title></head>\n  <body>\n    <h1>WebDAV index of " . htmlspecialchars($path) .
		     "</h1>\n    <table>\n      <tr><td><b>Filename</b></td><td><b>Size</b></td><td><b>Last modified</b></td></tr>\n".
		     "      <tr><td><a href=\"$parent\">..</a></td><td>&nbsp;</td><td>&nbsp;</td><td></tr>\n";
		foreach ($this->vfs->listFolder($path) as $file) {
			if (stripos($file['name'], '.webdav') === 0)
				continue;
			$link = $this->cleanPath($_SERVER['PHP_SELF'] . '/' . $file['name']);
			$data .= "      <tr><td><a href=\"$link\">{$file['name']}</a></td><td>" .
				($file['size'] < 0 ? '&nbsp' : number_format($file['size'])) .
				'</td><td>' . strftime("%Y-%m-%d %H:%M:%S", $file['date']) .
				"</td></tr>\n";
		}
		$data .= "    </table>\n  </body>\n</html>\n";

		$options['data'] = $data;
		$options['mimetype'] = 'text/html';
		return true;
	}

	private function recursiveCopy($srcDir, $srcName, $dstDir, $dstName) {
		if ($this->isFolder($srcDir, $srcName)) {
			if ($this->exists($dstDir, $dstName)) {
				if ($this->isFolder($dst, $dstName)) {
					$this->vfs->deleteFile($dstDir, $dstName);
					$this->vfs->createFolder($dstDir, $dstName);
				}
			} else {
				$this->vfs->createFolder($dstDir, $dstName);
			}

			$srcPath = $this->cleanPath($srcDir . '/' . $srcName);
			foreach ($this->vfs->listFolder($srcPath) as $file) {
				$dstPath = $this->cleanPath($dstDir . '/' . $dstName);
				$this->recursiveCopy($srcPath, $file['name'], $dstPath, $file['name']);
			}
		} else {
			if ($this->exists($dstDir, $dstName) && $this->isFolder($dst, $dstName))
				$this->vfs->deleteFolder($dstDir, $dstName, true);
			$this->vfs->writeData($dstDir, $dstName, $this->vfs->read($srcDir, $srcName), true);
		}

		$props =& $this->properties->get($srcDir, $srcName);
		if (!empty($props))
			$this->properties->set($dstDir, $dstName, $props);
	}

	private function fileInfo($dir, $name, $date = null) {
		$info = array();

		$info['path'] = $this->escapePath($this->cleanPath($dir . '/' . $name));
		if ($this->isFolder($dir, $name))
			$info['path'] = $this->_slashify($info['path']);

		$info['props'] = array();
		$props =& $info['props'];

		$props[] = $this->mkprop('displayname', $path);
		$props[] = $this->mkprop('getlastmodified', $date ? $date : time());
		$props[] = $this->mkprop('creationdate', $date ? $date : time());

		if ($this->isFolder($dir, $name)) {
			$props[] = $this->mkprop('resourcetype', 'collection');
			$props[] = $this->mkprop('getcontenttype', 'httpd/unix-directory');
		} else {
			$props[] = $this->mkprop('resourcetype', '');
			$props[] = $this->mkprop('getcontenttype', 'text/plain');
			$props[] = $this->mkprop('getcontentlength', $this->vfs->getFileSize($dir, $name));
		}

		foreach ($this->properties->get($dir, $name) as $p)
			$props[] = $p;

		return $info;
	}

	private function exists($dir, $name) {
		if ($dir == '/' && $name == ''  ||
		    $dir == '' && $name == '/'  ||
		    $dir == '/' && $name == '/' ||
		    $dir == '' && $name == '')
			return true;
		return $this->vfs->exists($dir, $name);
	}

	private function isFolder($dir, $name) {
		if ($dir == '/' && $name == ''  ||
		    $dir == '' && $name == '/'  ||
		    $dir == '/' && $name == '/' ||
		    $dir == '' && $name == '')
			return true;
		return $this->vfs->isFolder($dir, $name);
	}

	private function escapePath($path) {
		return join('/', array_map(rawurlencode, explode('/', $path)));
	}

	private function cleanPath($path) {
		return preg_replace('/\\/+/', '/', $path);
	}
}
?>
