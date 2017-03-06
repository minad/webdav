<?php
ini_set('include_path', '~/programme/pear/php' . PATH_SEPARATOR . ini_get('include_path'));

require_once 'Log.php';
require_once 'dav/BlowfishVFS.php';
require_once 'dav/PropertyStorage.php';
require_once 'dav/VFS_WebDAV_Server.php';

$SQL_PARAMS = array(
	'phptype'  => 'mysql',
	'username' => '',
	'password' => '',
	'hostspec' => 'mysql.rz.uni-karlsruhe.de',
	'database' => '',
	'table'    => 'vfs'
);

$log = Log::singleton('sql', 'log' /* tablename */, 'webdav' /* ident */, array('dsn' => $SQL_PARAMS));
$vfs = VFS::singleton('sql', $SQL_PARAMS);
$vfs->setLogger($log);

$vfs = new BlowfishVFS($vfs, 'Secret Key Blaaaaa', 'IVVVVVVV'); // 8 bytes IV

$propertyStorage = new VFS_PropertyStorage($vfs);

$server = new VFS_WebDAV_Server($vfs, $propertyStorage, $log);
$server->ServeRequest();
?>
