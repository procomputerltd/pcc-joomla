<?php
namespace Procomputer\Joomla\Drivers\Files;
/* 
 * Copyright (C) 2022 Pro Computer James R. Steel <jim-steel@pccglobal.com>
 * Pro Computer (pccglobal.com)
 * Tacoma Washington USA 253-272-4243
 *
 * This program is distributed WITHOUT ANY WARRANTY; without 
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR 
 * A PARTICULAR PURPOSE. See the GNU General Public License 
 * for more details.
 */
use RuntimeException;
use InvalidArgumentException;
use BadMethodCallException;
use Throwable;
use Closure;

use Procomputer\Pcclib\Types;

use Procomputer\Joomla\Cacher;

use Lazzard\FtpClient\Connection\FtpSSLConnection;
use Lazzard\FtpClient\Connection\FtpConnection;
use Lazzard\FtpClient\Config\FtpConfig;
use Lazzard\FtpClient\FtpClient;
use Lazzard\FtpClient\Exception\FtpClientException;

class Remote extends FileDriver {

    const DRIVER_ID = 'remote';
    const DRIVER_NAME = 'Lazzard/php-ftp-client package';
    const DEVELOPER_WEBSITE = 'https://www.amranich.dev/';

    // DIR_TYPE|FILE_TYPE|FILE_DIR_TYPE
    public $FILE_DIR_TYPE = FtpClient::FILE_DIR_TYPE;
    public $FILE_TYPE     = FtpClient::FILE_TYPE;
    public $DIR_TYPE      = FtpClient::DIR_TYPE;
    
    use \Procomputer\Joomla\Traits\Files;
    
    /**
     * 
     * @var Lazzard\FtpClient\Connection\FtpSSLConnection
     */
    protected $_connection = null;
    
    /**
     * 
     * @var \Lazzard\FtpClient\FtpClient
     */
    protected $_client = null;

    protected $_cache = [];
    
    /**
     * Opens FTP client connection using the specified connection properties.
     * @return boolean
     */
    public function open(string $host, string $login, string $password, bool $ssl = false, int $port = 21, int $timeout = 20, bool $passive = true) :bool {
        $this->host = $host;
        $this->login = $login;
        $this->password = $password;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->passive = $passive;
        $this->ssl = $ssl;
        $this->_cache = new Cacher();
        return $this->_open();
    }

    /**
     * RE-opens FTP client connection using the stored connection properties.
     * @return boolean
     */
    public function reopen() :bool {
        return $this->_open();
    }
    
    /**
     * Opens FTP client connection using the stored connection properties.
     * @return boolean
     */
    protected function _open() {
        $this->close();
        try {
            /** @var \Lazzard\FtpClient\Connection\FtpConnection $connection */
            $connection = $this->ssl ? (new FtpSSLConnection($this->host, $this->login, $this->password, $this->port, $this->timeout)) 
                    : (new FtpConnection($this->host, $this->login, $this->password, $this->port, $this->timeout));
            $connection->open();
            $this->_connection = $connection;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
        try {
            $config = new FtpConfig($connection);
            $config->setPassive($this->passive);
            $client = new FtpClient($connection);
            $this->_client = $client;
            
            return true;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * 
     */
    public function close() {
        if(null !== $this->_connection) {
            $this->_connection->close();
        }
        $this->_connection = $this->_client = null;
    }
    
    /**
     * 
     * @return string
     */
    public function getWebServerRootDir() {
        return $this->getCurrentDir();
    }

    /**
     * Returns the ID of this file driver driver.
     * 
     * @return string
     * 
     */
    public function getDriverName() :string {
        return self::DRIVER_NAME;
    }
    
    /**
     * Returns the ID of this file driver driver.
     * 
     * @return string
     * 
     */
    public function getDriverId() :string {
        return self::DRIVER_ID;
    }
    
    /**
     * Returns the developer website.
     * 
     * @return string
     * 
     */
    public function getDeveloperWebsite() :string {
        return self::DEVELOPER_WEBSITE;
    }
    
    /**
     * Proxy to class methods
     *
     * @param  string $method
     * @param  array  $args
     * @return mixed
     * @throws FtpClientException     For no connection open
     * @throws BadMethodCallException For invalid method calls
     */
    public function __call($method, $args) {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        if(is_string($method)) {
            $l = strlen($method);
            if($l > 2) {
                try {
                    return call_user_func_array([$this->_client, $method], $args);
                } catch (FtpClientException $exc) {
                    $this->saveError($exc->getMessage());
                    return false;
                } catch (Throwable $exc) {
                    $this->saveError($exc->getMessage());
                    return false;
                }
            }
        }
        throw new BadMethodCallException('Call to undefined method ' . get_class($this) . '::' . $method . '()');
    }

    /**
     * Returns list of information arrays for files and sub-directories under the directory.
     * 
     * @param string $directory
     * @param bool   $recursive  (optional) Recurse (drill-down) through sub-directories.
     * @param bool   $ignoreDots (optional) Ignore '.' and '..'
     * @param int    $filter     (optional) A '$this->FILE_*' specifier: $this->FILE_DIR_TYPE, $this->FILE_TYPE and $this->DIR_TYPE
     * 
     * @return array
     * 
     * @throws FtpClientException
     * @throws Throwable
     */
    public function getDirectoryDetails(string $directory, bool $recursive = false, bool $ignoreDots = true, int $filter = null) {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        if(null === $filter) {
            // Include everything by default
            $filter = $this->FILE_DIR_TYPE;
        }
        $values = [
            'directory_details',
            $directory,
			($recursive ? 'true' : 'false'),
			strval($filter),
			($ignoreDots ? 'true' : 'false')
        ];
        $cacheKey = $this->_cache->createKey($values);
        $content = $this->_cache->get($cacheKey);
        if(null !== $content) {
            return $content;
        }
        try {
            $this->_client->keepAlive();
            $dirDetails = $this->_client->listDirDetails($directory, $recursive, $filter, $ignoreDots);
            $this->_cache->set($cacheKey, $dirDetails);
            return $dirDetails;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Returns list of files and optionally folders in a directory.
     * @param string   $directory Root source path for which to scan files.
     * @param boolean  $recursive (optional) Recurse directories.
     * @param iterable $options   (optional) Options.
     * @return void
     */
    public function iterateFiles(string $directory, Closure $callback, bool $recursive = true, array $options = []) {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        $files = $this->getDirectoryDetails($directory, $recursive);
        if(false === $files) {
            return false;
        }
        foreach($files as $detailsArray) {
            // $fullPath = $this->joinPath($directory, $file);
            $isDir = 'dir' === $detailsArray['type'];
            $fullPath = $detailsArray['path'];
            if(false === $callback($isDir, $fullPath, $detailsArray)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * 
     * @param string $directory
     * @return int
     * @throws FtpClientException
     */
    public function getCount(string $directory) :int {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        try {
            return $this->_client->getCount($directory);
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Get folders in a file path.
     * @param string $path Path from which to get folders.
     * @return string|boolean
     */
    public function getCurrentDir() :string {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        try {
            return $this->_client->getCurrentDir();
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Get folders in a file path.
     * @param string $directory Path from which to get folders.
     * @return array|boolean
     */
    public function getFolders(string $directory, bool $ignoreDots = true, $sort = null) {
        $return = $this->listDirectory($directory, $this->DIR_TYPE, $ignoreDots);
        return $return;
    }
    
    /**
     * Returns list of files and optionally folders in a directory.
     * 
     * @param string $directory
     * @param int    $filter     (optional) A 'FILE_*' specifier: FILE_DIR_TYPE, FILE_TYPE and DIR_TYPE
     * @param bool   $ignoreDots (optional) Ignore '.' and '..'
     * 
     * @return array|boolean
     * 
     * @throws RuntimeException
     */
    public function listDirectory(string $directory, int $filter = 0, bool $ignoreDots = true, $sort = null) :array {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        if(null === $filter) {
            $filter = $this->FILE_DIR_TYPE; // Return files and folders.
        }
        if(false !== strpos(strtolower($directory), 'zip')) {
            $break = 1;
        }
        $values = [
            'listDir',
            $directory,
			strval($filter),
			($ignoreDots ? 'true' : 'false')
        ];
        $cacheKey = $this->_cache->createKey($values);
        $content = $this->_cache->get($cacheKey);
        if(null !== $content) {
            return $content;
        }
        try {
            $list = $this->_client->listDir($directory, $filter, $ignoreDots);
            if(null !== $sort) {
                if(is_string($sort) && 'desc' === strtolower($sort)) {
                     rsort($list);
                }
                else {
                    sort($list);
                }
            }
            $this->_cache->set($cacheKey, $list);
            return $list;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Returns true when the path exists.
     * @param string $path
     * @return string 
     */
    public function fileExists(string $path) :bool {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        if(! is_string($path) || Types::isBlank($path)) {
            return false;
        }
        try {
            return $this->_client->isExists($path);
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Returns true when the path is a file.
     * @param string $path
     * @return boolean
     */
    public function isFile(string $path) :bool {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        if(! is_string($path) || Types::isBlank($path)) {
            return false;
        }
        try {
            return $this->_client->isFile($path);
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Returns true when the path is a directory.
     * @param string $path
     * @return boolean
     */
    public function isDirectory(string $path) :bool {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        if(! is_string($path) || Types::isBlank($path)) {
            return false;
        }
        try {
            return $this->_client->isDir($path);
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Changes the current directory.
     * @param string $path
     * @return boolean
     */
    public function changeDir(string $path) :bool {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        try {
            $this->_client->changeDir($path);
            return true;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
            
    /**
     * Get the contents of a file.
     * 
     * @param string  $file           File path from which to get contents.
     * @param boolean $throwException (optional) Throw exceptions on error.
     * 
     * @return string|boolean Returns the file contents or false on error.
     * 
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getFileContents(string $file, $mode = FTP_BINARY) {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        // Validate parameters, types.
        if(! strlen($srcFile = trim($file))) {
            $var = Types::getVartype($file, 32);
            $msg = "'\$file' parameter '{$var}' is invalid: expecting a string path to XML file or XML script";
            $this->saveError($msg);
            // Ignore $throwException value.
            throw new InvalidArgumentException($msg);
        }
        $cacheKey = $this->_cache->createKey(['file', $file]);
        $content = $this->_cache->get($cacheKey);
        if(null !== $content) {
            return $content;
        }
        try {
            $contents = $this->_client->getFileContent($srcFile, $mode);
            $this->_cache->set($cacheKey, $contents);
            return $contents;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }

    /**
     * Download a file into local file
     * 
     * @param string $remoteFile File path from which to get contents.
     * @param string $localFile  Local file to accept downloaded content.
     * @param int    $mode       (optional) File mode. 0 = driver default e.g. binary
     * @param bool   $resume     (optional) Specifies whether to resume the upload operation.
     * 
     * @return boolean Returns true or false on error.
     * 
     * @throws InvalidArgumentException
     */
	public function download(string $remoteFile, string $localFile, int $mode = 0, bool $resume = true) :bool {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        // Validate parameters, types.
        if(! strlen($srcFile = trim($remoteFile))) {
            $msg = 'remoteFile';
        }
        // Validate parameters, types.
        elseif(! strlen($dstFile = trim($localFile))) {
            $msg = 'localFile';
        }
        if(isset($msg)) {
            $var = Types::getVartype($$msg, 32);
            $msg = "'{$msg}' parameter '{$var}' is invalid: expecting a string file path";
            $this->saveError($msg);
            throw new InvalidArgumentException($msg);
        }
        $values = [
            'download',
            $srcFile,
			strval($mode),
        ];
        $cacheKey = $this->_cache->createKey($values);
        $content = $this->_cache->get($cacheKey);
        if(null !== $content) {
            return $content;
        }
        try {
            $this->_client->download($srcFile, $dstFile, $resume, $mode);
            $this->_cache->setFromFile($cacheKey, $dstFile);
            return true;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Writes data to a file.
     * @param string $localFile   Source file from which to get data.
     * @param string $remoteFile  Dstination file to which to write data.
     * @param int    $mode        (optional) Specifies the transfer mode.    
     * @param bool   $resume      (optional) Specifies whether to resume the upload operation.
     * @return type
     * 
     * @return \Procomputer\Joomla\Drivers\Files\type|boolean
     */
    public function upload(mixed $localFile, string $remoteFile, int $mode = 0, bool $resume = true) :bool {
        if(! $this->_client) {
            throw new RuntimeException("No connection: use open() method.");
        }
        // Validate parameters, types.
        if(is_resource($localFile)) {
            $srcFile = $localFile;
        }
        elseif(! strlen($srcFile = trim($localFile))) {
            $var = Types::getVartype($$msg, 32);
            $msg = "'{$msg}' parameter '{$var}' is invalid: expecting a string file path";
            $this->saveError($msg);
            throw new InvalidArgumentException($msg);
        }
        if(! isset($msg) && ! strlen($dstFile = trim($remoteFile))) {
            $var = Types::getVartype($$msg, 32);
            $msg = "'{$msg}' parameter '{$var}' is invalid: expecting a string file path";
            $this->saveError($msg);
            throw new InvalidArgumentException($msg);
        }
        try {
            return $this->_client->upload($srcFile, $dstFile, $resume, $mode);
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }
    
    /**
     * Returns connection information HTML
     * @return string
     */
    public function getConnectionDescription() :string {
        $information = [];
        if(! empty($this->host)) {
            $stats = [$this->passive ? 'passive' : ''];
            if($this->ssl) {
                $stats[] = 'SSL';
            }
            $information[] = "ftp://{$this->login}@{$this->host}:{$this->port} " . ' (' . implode(', ', $stats) . ')';
            $information[] = 'FTP ' . $this->getDriverName();
        }
        $devWebsite = $this->getDeveloperWebsite();
        if(! empty($devWebsite)) {
            $information[] = "<link>{$devWebsite}</link>";
        }
        // ftp: //pcc@pccglobal.com@pccglobal.com/pccglobal.com/public_html/index.php
        $connectionDescription = implode(" ", $information);
        return $connectionDescription;
    }
}