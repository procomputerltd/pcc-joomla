<?php

namespace Procomputer\Joomla\Traits;

use RuntimeException;
use Throwable;

use Procomputer\Pcclib\FileSystem;
use Procomputer\Pcclib\Types;

trait Files {

    protected $lastFileError = '';
    
    /**
     * Get the contents of a file.
     * 
     * @param string  $file           File path from which to get contents.
     * @param int     $limit (optional) Limit of bytes of data to read.
     * 
     * @return string|boolean Returns the file contents or false on error.
     * 
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
    protected function _getFileContents($file, $limit = null) {
        // Validate parameters, types.
        if(! is_string($file) || ! strlen($trimmedFile = trim($file))) {
            $var = Types::getVartype($file, 32);
            $msg = "'\$file' parameter '{$var}' is invalid: expecting a string file path";
            $this->lastFileError = $msg;
            // Ignore $throwException value.
            throw new \InvalidArgumentException($msg);
        }
        
        // Validate parameter is a real file.
        $osPath = str_replace(('/' === DIRECTORY_SEPARATOR) ? '\\' : '/', DIRECTORY_SEPARATOR, $trimmedFile);
        if(! file_exists($osPath) || ! is_file($realPath = realpath($osPath))) {
            $dir = dirname($osPath);
            $base = basename($osPath);
            if('.' === $dir || '..' === $dir) {
                $msg = "The file '{$base}' not found. It appears the directory name is missing";
            }
            else {
                $msg = "The file '{$base}' not found in directory:\n{$dir}";
            }
            $this->lastFileError = $msg;
            throw new RuntimeException($msg);
        }

        set_error_handler(
            static function(int $errno, string $errstr) use ($realPath, $limit): void {
                throw new RuntimeException("Cannot read contents from file $realPath: $errstr", $errno);
            }
        );
        try {
            $res = is_int($limit) ? file_get_contents($realPath, null, null, 0, $limit) : file_get_contents($realPath);
            return $res;
        } catch (Throwable $exception) {
            $msg = "Cannot read from $realPath: " . $exception->getMessage();
            $this->lastFileError = $msg;
            throw new RuntimeException($msg);
        } finally {
            restore_error_handler();
        }
        return false;
    }

    /**
     * Puts contents into a file.
     * 
     * @param string    $file       File to write.
     * @param string    $contents   Contents to write to file.
     * 
     * @return int|boolean The number of bytes written of false on error.
     * 
     * @throws RuntimeException
     */
    protected function putFileContents($file, $contents) {
        if(! is_string($file)) {
            $var = Types::getVartype($file);
            $msg = "Cannot get contents from file: invalid 'file' parameter. Expecting file name: '{$var}'";
            $this->lastFileError = $msg;
            throw new RuntimeException($msg);
        }
        set_error_handler(
            static function(int $errno, string $errstr) use ($file): void {
                throw new RuntimeException("Cannot write contents of file $file: $errstr",$errno);
            }
        );
        try {
            $res = file_put_contents($file, $contents); 
            return $res;
        } catch (Throwable $exception) {
            throw new RuntimeException("Cannot write to $file: " . $exception->getMessage());
        } finally {
            restore_error_handler();
        }
        return false;
    }
    
    /**
     * Creates a temp file in the current filesystem.
     * @param string  $directory
     * @param string  $filePrefix
     * @param boolean $keep
     * @param int     $fileMode
     * @return string 
     * @throws RuntimeException
     */
    protected function _createTemporaryFile($directory = null, $filePrefix = "pcc", $keep = false, $fileMode = null) {
        $throw = FileSystem::throwErrors(true);
        try {
            $file = FileSystem::createTempFile($directory, $filePrefix, $keep, $fileMode);
            if(is_string($file)) {
                return $file;
            }
            throw new RuntimeException("createTempFile() failed");
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to create temporary file in directory $directory using mode $fileMode: " . $exception->getMessage());
        } finally {
            FileSystem::throwErrors($throw);
        }
    }
    
    /**
     * Validates a file path
     *
     * @param string  $path      Filename to check.
     * @param boolean $noSpaces  (optional) Convert spaces to underscores.
     *
     * @return boolean
     */
    protected function _isValidPath($path, $noSpaces = false) {
        if(! is_string($path) || ! strlen($trimmed = trim($path))) {
            return false;
        }
        if(false !== strpos(strtolower(PHP_OS), "win")) {
            if(preg_match('/^[a-z]\:(.*)$/i', $trimmed, $m)) {
                $trimmed = $m[1];
            }
            $trimmed = str_replace('\\', '/', $trimmed);
        }
        $pattern = '\\x00-\\x1F\\x7F' . preg_quote('?:*"><|');
        if($noSpaces) {
            $pattern .= '\\t ';
        }
        return ! preg_match(chr(1) . '[' . $pattern . ']' . chr(1), $trimmed);
    }

    /**
     * Validate path is a file directory.
     * @param string $path
     * @return boolean
     */
    protected function _isDirectory($path) {
        if(! $this->_isValidPath($path)) {
            return false;
        }
        $trimmed = trim($path);
        if('.' === $trimmed || '..' === $trimmed) {
            return false;
        }
        $p = realpath($trimmed);
        return (false !== $p);
    }
    
    /**
     * Open a file for read or write.
     * @param string $path File for which to open stream.
     * @param string $mode Type of access you require to the stream.
     * @return resource|boolean
     */
    protected function _openFile($path, $mode = 'rb') {
        $handle = $this->callFuncAndSavePhpError(function()use($path, $mode){return fopen($path, $mode);});
        return $handle;
    }

    /**
     * Renames or moves a file or directory.
     * @param string $file     File or dir to rename.
     * @param string $newFile  Name of new file or dir.
     * @return boolean
     */
    protected function _rename($file, $newFile) {
        return $this->callFuncAndSavePhpError(function()use($file, $newFile){return rename($file, $newFile);});
    }

    /**
     * Returns the REAL path of a file.
     * @param string $file
     * @return string|boolean
     */
    protected function _realpath($file) {
        return $this->callFuncAndSavePhpError(function()use($file){return realpath($file);});
    }

    /**
     * Joins separate parts into a directory path.
     * @param mixed $path Initial path part. Specify additional arguments for additional path parts.
     * @return string
     */
    protected function joinPath($path) {
        $n = func_num_args();
        if(! $n) {
            return '';
        }
        $args = func_get_args();
        if(1 === $n && is_array($args[0])) {
            $args = $args[0];
        }
        return FileSystem::joinPath('/', $args);
    }
    
    /**
     * Joins separate parts into a directory path.
     * @param mixed $path Initial path part. Specify additional arguments for additional path parts.
     * @return string
     */
    protected function joinOsPath($path) {
        $n = func_num_args();
        if(! $n) {
            return '';
        }
        $args = func_get_args();
        if(1 === $n && is_array($args[0])) {
            $args = $args[0];
        }
        $newPath = FileSystem::joinPath(DIRECTORY_SEPARATOR , $args);
        $sep2 = DIRECTORY_SEPARATOR;
        $sep1 = ('\\' === $sep2) ? '/' : '\\';
        if(false !== strpos($newPath, $sep1)) {
            $newPath = str_replace($sep1, $sep2, $newPath);
        }
        return $newPath;
    }
}
