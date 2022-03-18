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
use ArrayObject;
// use BadMethodCallException;
use Throwable;
use SplFileInfo;
use Closure;

use Procomputer\Pcclib\Types;

class System extends FileDriver {

    use \Procomputer\Joomla\Traits\Environment;
    use \Procomputer\Joomla\Traits\Files;

    const DRIVER_ID = 'system';
    const DRIVER_NAME = 'Local server file system';
    const DEVELOPER_WEBSITE = 'https://procomputer.biz';
    
    /**
     * Returns the name of this file driver driver.
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
     * Returns list of information arrays for files and sub-directories under the directory.
     * 
     * @param string $directory
     * @param bool   $recursive  (optional) Recurse (drill-down) through sub-directories.
     * @param bool   $ignoreDots (optional) Ignore '.' and '..'
     * @param int    $filter     (optional) File filter used by Lazzard\FtpClient\FtpClient
     * 
     * @return array
     * 
     * @throws FtpClientException
     * @throws Throwable
     */
    public function getDirectoryDetails(string $directory, bool $recursive = false, bool $ignoreDots = true, int $filter = 0) {
        /* $info elements:
            [chmod] => (string) lrwxrwxrwx
            [num] => (string) 1
            [owner] => (string) 787
            [group] => (string) u288-62k0k
            [size] => (string) 13
            [month] => (string) Jan
            [day] => (string) 26
            [time] => (string) 2021
            [name] => (string) chelanclassic.com -> pccglobal.com
            [type] => (string) link
            [path] => (string) chelanclassic.com -> pccglobal.com

           FILEINFO_NONE (int)
               No special handling. 
           FILEINFO_SYMLINK (int)
               Follow symlinks. 
           FILEINFO_MIME_TYPE (int)
               Return the mime type. 
           FILEINFO_MIME_ENCODING (int)
               Return the mime encoding of the file. 
           FILEINFO_MIME (int)
               Return the mime type and mime encoding as defined by RFC 2045. 
           FILEINFO_COMPRESS (int)
               Decompress compressed files. Disabled due to thread safety issues. 
           FILEINFO_DEVICES (int)
               Look at the contents of blocks or character special devices. 
           FILEINFO_CONTINUE (int)
               Return all matches, not just the first. 
           FILEINFO_PRESERVE_ATIME (int)
               If possible preserve the original access time. 
           FILEINFO_RAW (int)
               Don't translate unprintable characters to a \ooo octal representation. 
           FILEINFO_EXTENSION (int) 
        */
        $storage = new ArrayObject();
        $res = $this->iterateFiles($directory, function($isDir, $path, $fileInfo) use($storage, $ignoreDots) {
            /** @var \SplFileInfo $fileInfo */
            if(is_object($fileInfo)) {
                if($ignoreDots && $fileInfo->isDot()) {
                    return true;
                }
                $fileInfo->getRealPath();
                $info = $fileInfo;
            }
            else {
                if($ignoreDots && ('..' === $path || '.' === $path)) {
                    return true;
                }
                $info = $path;
            }
            $fileStats = $this->_stat($info);
            if(false === $fileStats) {
                return false;
            }
            $storage->append($fileStats);
            return true;
        }, $recursive);    
        if(false === $res) {
            return false;
        }
        return (array)$storage;
    }
    
    /**
     * 
     * @return string
     */
    public function getWebServerRootDir() :string {
        // C:/inetpub/joomlapcc
        $path = $this->fixSlashes($this->getDocumentRoot());
        $pattern = '~^([a-z]\\:\\\\inetpub)\\\\~i';
        if(preg_match($pattern, $path, $m)) {
            // Find Joomla installs under inetpub directory
            $path = $m[1];
        }
        return $path;
    }

    /**
     * Returns true when the path exists.
     * @param string $path
     * @return string 
     */
    public function fileExists(string $path) : bool {
        if(! is_string($path) || Types::isBlank($path)) {
            return false;
        }
        return file_exists($path);
    }
    
    /**
     * Returns true when the path is a file.
     * @param string $path
     * @return string 
     */
    public function isFile(string $path) : bool  {
        if(! is_string($path) || Types::isBlank($path)) {
            return false;
        }
        return is_file($path);
    }
    
    /**
     * Returns the current directory.
     * @return boolean Success if the directory changed else false.
     */
    public function getCurrentDir() :string {
        return getcwd();
    }
    
    /**
     * Changes the current directory.
     * @param string $path
     * @return string 
     */
    public function changeDir(string $path) :bool {
        return chdir($path);
    }
            
    /**
     * Returns true when the path is a directory.
     * @param string $path
     * @return string 
     */
    public function isDirectory(string $path) :bool {
        if(! is_string($path) || Types::isBlank($path)) {
            return false;
        }
        return is_dir($path);
    }
    
    /**
     * Get the contents of a file.
     * 
     * @param string  $file File path from which to get contents.
     * @param int     $mode File mode e.g. 'b' for binary.
     * 
     * @return string|boolean Returns the file contents or false on error.
     * 
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function getFileContents(string $file, int $mode = 0) {
        // Validate parameters, types.
        if(! is_string($file) || ! strlen($trimmedFile = trim($file))) {
            $var = Types::getVartype($file, 32);
            $msg = "'\$file' parameter '{$var}' is invalid: expecting a string path to XML file or XML script";
            $this->saveError($msg);
            throw new \InvalidArgumentException($msg);
        }
        
        // Validate parameter is a real file.
        $osPath = $this->fixSlashes($trimmedFile);
        if(! file_exists($osPath) || ! is_file($realpath = realpath($osPath))) {
            $dir = dirname($osPath);
            $base = basename($osPath);
            if('.' === $dir || '..' === $dir) {
                $msg = "The file '{$base}' not found. It appears the directory name is missing";
            }
            else {
                $msg = "The file '{$base}' not found in directory:\n{$dir}";
            }
            $this->saveError($msg);
            throw new \RuntimeException($msg);
        }

        // Get the contents of the file.
        $contents = $this->_getFileContents($realpath);
        if(false === $contents) {
            $errors = $this->getErrors();
            $msg = count($errors) ? reset($errors) : 'unknown error getting file contents';
            $this->saveError($msg);
            throw new \RuntimeException($msg);
        }
        
        return $contents;
    }

    /**
     * Read a file's contents.
     *
     * @param string $file  File to read.
     * @param int    $limit (optional) Limit of bytes of data to read.
     *
     * @return string|boolean
     */
    protected function _getFileContents($file, $limit = null) {
        if(! is_string($file) || ! file_exists($file) || ! is_file($file)) {
            $var = Types::getVartype($file);
            $this->saveError("cannot get contents from file: invalid 'file' parameter. Expecting existing file: '{$var}'");
            return false;
        }
        $fileData = $this->callFuncAndSavePhpError(
            function() use($file, $limit) {
                return is_int($limit) ? file_get_contents($file, null, null, 0, $limit) : file_get_contents($file);
            }                
        );
        return $fileData;
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
     * @throws \InvalidArgumentException
     */
	public function download(string $remoteFile, string $localFile, int $mode = 0, bool $resume = true) :bool {
        // Validate parameters, types.
        if(! strlen($srcFile = trim($remoteFile))) {
            $msg = '$remoteFile';
        }
        // Validate parameters, types.
        elseif(! strlen($dstFile = trim($localFile))) {
            $msg = '$localFile';
        }
        if(isset($msg)) {
            $var = Types::getVartype($$msg, 32);
            $msg = "'{$msg}' parameter '{$var}' is invalid: expecting a string file path";
            $this->saveError($msg);
            throw new \InvalidArgumentException($msg);
        }
        try {
            $this->_client->download($srcFile, $dstFile, $resume, $mode);
            return true;
        } catch (FtpClientException $exc) {
            $this->saveError($exc->getMessage());
            return false;
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
        
        return $contents;
    }
    
    /**
     * Upload a file to remore file.
     * @param string $localFile   Source file from which to get data.
     * @param string $remoteFile  Dstination file to which to write data.
     * @param int    $mode        (optional) Specifies the transfer mode.    
     * @param bool   $resume      (optional) Specifies whether to resume the upload operation.
     * @return type
     * 
     * @return boolean
     */
    public function upload(mixed $localFile, string $remoteFile, int $mode = 0, bool $resume = true) :bool {
        $flags = 0;
        return $this->callFuncAndSavePhpError(function()use($localFile, $remoteFile, $flags){
            return file_put_contents($remoteFile, file_get_contents($localFile), $flags);
        });
    }

    /**
     * Returns list of folders in a directory.
     * @param string $directory  Path from which to get folders. 
     * @param bool   $ignoreDots (optional) Ignore '.' and '..'
     * @param mixed  $sort       (optional) Sort list.
     * @return ArrayObject
     */
    public function getFolders(string $directory, bool $ignoreDots = true, $sort = null) :ArrayObject {
        $storage = new ArrayObject([]);
        $res = $this->iterateFiles($directory, function($isDir, $directory, $fileInfo) use($storage, $ignoreDots) {
            if(! $isDir) {
                return true;
            }
            /** @var \SplFileInfo $fileInfo */
            if(is_object($fileInfo)) {
                if($ignoreDots && $fileInfo->isDot()) {
                    return true;
                }
                $filename = $fileInfo->getFilename();
            }
            else {
                if($ignoreDots && ('..' === $directory || '.' === $directory)) {
                    return true;
                }
                $filename = basename($directory);
            }
            // $clientKey, $storage, $this->_allowedFileTypes);
            $storage[] = $filename;
            return true;
        }, false);    
        if(false === $res) {
            return false;
        }
        if($storage->count() && $sort) {
            $storage->natsort();
        }
        return $storage;
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
    public function listDirectory(string $directory, int $filter = null, bool $ignoreDots = true, $sort = null) :array {
        $storage = new ArrayObject();
        if(null === $filter) {
            // Include everything by default
            $filter = $this->FILE_DIR_TYPE;
        }
        try {
            $fileIterator = new \DirectoryIterator($directory);
        } catch(Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
        foreach($fileIterator as $fileInfo) { 
            if($ignoreDots && $fileInfo->isDot()) {
                continue;
            }
            if($filter) {
                switch($filter) {
                case $this->DIR_TYPE:
                    if(! $fileInfo->isDir()) {
                        continue 2;
                    }
                    break;
                case $this->FILE_TYPE:
                    if(! $fileInfo->isFile()) {
                        continue 2;
                    }
                    break;
                // default FILE_DIR_TYPE
                }
            }
            /** @var \SplFileInfo $fileInfo */
            $storage[] = $fileInfo->getFilename();
        }
        if($storage->count() && $sort) {
            $storage->natsort();
        }
        return (array)$storage;
    }

    /**
     * Iterates a directory and optionally sub-directories under a directory.
     * 
     * @param string   $directory  Directory to list. 
     * @param Closure  $callback   Callback function.
     * @param boolean  $recursive  (optional) Sacn path recursively.
     * @param iterable $options    (optional) Options.
     * 
     * @return array|boolean
     * 
     * @throws RuntimeException
     */
    public function iterateFiles(string $directory, Closure $callback, bool $recursive = true, array $options = []) {
        try {
            if($recursive) {
                $iterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
                $fileIterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
            }
            else {
                $fileIterator = new \DirectoryIterator($directory);
            }
        } catch(Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }

        /* Add the following to use RegexIterator regular expression to omit files not matching the allowed file extensions.
            $fileTypePattern = '/^.+\.(?:' . implode('|', $fileTypes) . ')$/i';
            $recurseIterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
            $fileIterator = new \RegexIterator($recurseIterator, $fileTypePattern, \RecursiveRegexIterator::GET_MATCH);
        */
        try {
            /**
             * WARNING: $fileInfo is a string when \RegexIterator() used, else \SplFileInfo.
             */
            foreach($fileIterator as $fileInfo) { 
                /** @var \SplFileInfo $fileInfo */
                // Skip directories.
                if($fileInfo instanceof \SplFileInfo) {
                    $isDir = $fileInfo->isDir();
                    if(false === $callback($isDir, $fileInfo->getRealPath(), $fileInfo)) {
                        return false;
                    }
                }
                else {
                    $object = is_array($fileInfo) ? $fileInfo : [$fileInfo];
                    foreach($object as $path) {
                        $dir = dirname($path);
                        if(empty($dir) || '.' === $dir  || '\\' === $dir || '/' === $dir) {
                            $path = $this->joinPath($directory, $path);
                        }
                        $isDir = is_dir($path);
                        if(false === $callback($isDir, $path, null)) {
                            return false;
                        }
                    }
                }
            }
            return true;
        } catch(Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
    }

    /**
     * Get file information.
     * @param string|SplFileInfo $source
     * @return array
     * @throws RuntimeException
     */
    protected function _stat($source) {
        if(is_object($source) && $source instanceof SplFileInfo) {
            /** @var \SplFileInfo $source */
            $mtime = $source->getMTime();
            $basename = $source->getBasename();
            $type = $source->getType(); // Returns a string representing the type of the file. May be one of file, link, or dir. 
            $fileperms = $source->getPerms();
            $perms = is_int($fileperms) ? $this->formatPermissions($fileperms) : '---------';
            $properties = [
                'chmod' => $perms,              // lrwxrwxrwx
                'num'   => 1,                   // 1
                'owner' => $source->getOwner(), // 787
                'group' => $source->getGroup(), // u288-62k0k
                'size'  => $source->getSize(), // 13
                'month' => date('M', $mtime),   // Jan
                'day'   => date('d', $mtime),   // 26
                'time'  => date('Y', $mtime),   // 2021
                'name'  => $basename,           // chelanclassic.com -> pccglobal.com
                'type'  => $type,               // link
                'path'  => $basename,           // chelanclassic.com -> pccglobal.com
                ];
            return $properties;
        }
        
        if(! is_string($source) || Types::isBlank($path) || ! $this->fileExists($source)) {
            $var = Types::getVartype($source);
            throw new RuntimeException("File not found: $var");
        }
        set_error_handler(
            static function(int $errno, string $errstr) use ($source): void {
                $this->lastFileDriverError = $msg;
                throw new RuntimeException("Cannot get information for file $source: $errstr", $errno);
            }
        );
        try {
            $stat = stat($source);
            if(! is_array($stat) || empty($stat)) {
                throw new RuntimeException("stat() did not return an array");
            }
            /*
              [dev] => (int) 2596226639       device number ***                   
              [ino] => (int) 2533274791560643 inode number ****                         
              [mode] => (int) 33206           inode protection mode *****               
              [nlink] => (int) 1              number of links            
              [uid] => (int) 0                userid of owner *          
              [gid] => (int) 0                groupid of owner *          
              [rdev] => (int) 0               device type, if inode device           
              [size] => (int) 7607            size in bytes              
              [atime] => (int) 1643479742     time of last access (Unix timestamp)                     
              [mtime] => (int) 1643479739     time of last modification (Unix timestamp)                     
              [ctime] => (int) 1642797223     time of last inode change (Unix timestamp)                     
              [blksize] => (int) -1           blocksize of filesystem IO **               
              [blocks] => (int) -1            number of 512-byte blocks allocated **                   
            */      
            $fileperms = fileperms($source);
            $perms = is_int($fileperms) ? $this->formatPermissions($fileperms) : '---------';
            $mtime = $stat['mtime'];
            $basename = basename($source);
            $type = $this->_getFileType($perms[0] ?? 'u');
            $properties = [
                'chmod' => $perms,              // lrwxrwxrwx
                'num'   => 1,                   // 1
                'owner' => fileowner($source),    // 787
                'group' => filegroup($source),    // u288-62k0k
                'size'  => $stat['size'] ?? '', // 13
                'month' => date('M', $mtime),   // Jan
                'day'   => date('d', $mtime),   // 26
                'time'  => date('Y', $mtime),   // 2021
                'name'  => $basename,           // chelanclassic.com -> pccglobal.com
                'type'  => $type,               // link
                'path'  => $basename,           // chelanclassic.com -> pccglobal.com
                ];
            return $properties;
        } catch (Throwable $exception) {
            throw new RuntimeException($exception);
        } finally {
            restore_error_handler();
        }
    }
    
    /**
     * Returns connection information HTML
     * @return string
     */
    public function getConnectionDescription() :string {
        $information = [];
        $varName = 'APPLICATION_LOCAL_SERVER';
        $var = defined($varName) ? APPLICATION_LOCAL_SERVER : getenv($varName);
        $devServer = (is_numeric($var) && intval($var)) ? ' developer' : '';
        $information[] = "Local{$devServer} server";
        $computerName = getenv('COMPUTERNAME');
        if(! empty($computerName)) {
            $information[] = "name {$computerName}";
        }
        $devWebsite = $this->getDeveloperWebsite();
        if(! empty($devWebsite)) {
            $information[] = "<link>{$devWebsite}</link>";
        }
        $connectionDescription = implode(" ", $information);
        return $connectionDescription;
    }
}