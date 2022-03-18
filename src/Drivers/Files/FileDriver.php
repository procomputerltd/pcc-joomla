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
use Closure;

abstract class FileDriver {
    
    use \Procomputer\Joomla\Traits\Messages;
    use \Procomputer\Joomla\Traits\ErrorHandling;

    public $host     = '';
    public $login    = '';
    public $password = '';
    public $port     = '';
    public $timeout  = '';
    public $passive  = '';
    public $ssl      = '';
    
    public $FILE_DIR_TYPE = 0;
    public $FILE_TYPE     = 2;
    public $DIR_TYPE      = 1;
    
    protected $lastFileDriverError;
    
    /**
     * Returns the name of this file driver driver.
     * 
     * @return string
     * 
     */
    abstract public function getDriverName() :string;
    
    /**
     * Returns the ID of this file driver driver.
     * 
     * @return string
     * 
     */
    abstract public function getDriverId() :string;
    
    /**
     * Returns the developer website.
     * 
     * @return string
     * 
     */
    abstract public function getDeveloperWebsite() :string;

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
    abstract public function getDirectoryDetails(string $directory, bool $recursive = false, bool $ignoreDots = true, int $filter = 0) ;
    
    /**
     * Returns true when the path is a file.
     * @param string $path
     * @return boolean Returns true when the path is a file else false.
     */
    abstract public function isFile(string $path) :bool;
    
    /**
     * Returns true when the path exists.
     * @param string $path
     * @return boolean Returns true when the path exists else false.
     */
    abstract public function fileExists(string $path) :bool;
    
    /**
     * Returns true when the path is a directory.
     * @param string $path
     * @return boolean Returns true when the path is a directory else false.
     */
	abstract public function isDirectory(string $path) :bool;
    
    /**
     * Changes the current directory.
     * @param string $path
     * @return boolean Success if the directory changed else false.
     */
    abstract public function changeDir(string $path) :bool;
    
    /**
     * Returns the current directory.
     * @return boolean Success if the directory changed else false.
     */
    abstract public function getCurrentDir() :string;
    
    /**
     * Get folders in a file path.
     * @param string $path Path from which to get folders.
     * @return \ArrayObject
     * 
     */
    abstract public function getFolders(string $path, bool $ignoreDots = true, $sort = null);
    
    /**
     * Returns list of files and optionally folders in a directory.
     * 
     * @param string $directory  Directory to list. 
     * @param int    $filter     (optional) A 'FILE_*' specifier: FILE_DIR_TYPE, FILE_TYPE and DIR_TYPE
     * @param bool   $ignoreDots (optional) Ignore '.' and '..'
     * @param int    $sort       (optional) Sort results, -1 means descending.
     * 
     * @return array|boolean
     * 
     * @throws RuntimeException
     */
    abstract public function listDirectory(string $directory, int $filter = 0, bool $ignoreDots = true, $sort = null) :array;
    
    /**
     * Returns list of files and optionally folders in a directory.
     * 
     * @param string   $directory  Directory to list. 
     * @param callable $callback   Callback function.
     * @param boolean  $recursive  (optional) Sacn path recursively.
     * @param iterable $options    (optional) Options.
     * 
     * @return array|boolean
     * 
     * @throws RuntimeException
     */
	abstract public function iterateFiles(string $directory, Closure $callback, bool $recursive = true, array $options = []);
    
    /**
     * Get the contents of a file.
     * 
     * @param string  $file File path from which to get contents.
     * @param boolean $mode (optional) File mode. 0 = driver default e.g. binary
     * 
     * @return string|boolean Returns the file contents or false on error.
     * 
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
	abstract public function getFileContents(string $file, int $mode = 0);
    
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
	abstract public function download(string $remoteFile, string $localFile, int $mode = 0, bool $resume = true) :bool;
    
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
    abstract public function upload(mixed $localFile, string $remoteFile, int $mode = 0, bool $resume = true) :bool;
    
    /**
     * Returns connection information HTML
     * @return string
     */
    abstract public function getConnectionDescription() :string;
    
    /**
     * Converts slashes to OS system slashes specified in DIRECTORY_SEPARATOR
     * @param string $path
     * @return string 
     */
    public function fixSlashes(string $path) :string {
        // Just make them all
        $sep2 = DIRECTORY_SEPARATOR;
        $sep1 = ('\\' === $sep2) ? '/' : '\\';
        if(false !== strpos($path, $sep1)) {
            $path = str_replace($sep1, $sep2, $path);
        }
        return $path;
    }
    
    /**
     * Converts bitwise permissions to character equivalents
     * @param int    $perms
     * @param string $separator
     * @return string
     */
    public function formatPermissions(int $perms, string $separator = '') :string {
        $info = [];
        switch ($perms & 0xF000) {
            case 0xC000: // socket
                $info[] = 's';
                break;
            case 0xA000: // symbolic link
                $info[] = 'l';
                break;
            case 0x8000: // regular
                $info[] = 'r';
                break;
            case 0x6000: // block special
                $info[] = 'b';
                break;
            case 0x4000: // directory
                $info[] = 'd';
                break;
            case 0x2000: // character special
                $info[] = 'c';
                break;
            case 0x1000: // FIFO pipe
                $info[] = 'p';
                break;
            default: // unknown
                $info[] = 'u';
        }

        // Owner
        $info[] = (($perms & 0x0100) ? 'r' : '-');
        $info[] = (($perms & 0x0080) ? 'w' : '-');
        $info[] = (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));

        // Group
        $info[] = (($perms & 0x0020) ? 'r' : '-');
        $info[] = (($perms & 0x0010) ? 'w' : '-');
        $info[] = (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));

        // World
        $info[] = (($perms & 0x0004) ? 'r' : '-');
        $info[] = (($perms & 0x0002) ? 'w' : '-');
        $info[] = (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));
        return implode($separator, $info);
    }
    
    protected function _getFileType(string $char):string {
        $table = [
        's' => 'socket',
        'l' => 'link',
        'r' => 'regular',
        'b' => 'block',
        'd' => 'dir',
        'c' => 'character',
        'p' => 'pipe',
        'u' => 'unknown'
        ];
        return isset($table[$char]) ? $table[$char] : 'unknown';
    }
    
}