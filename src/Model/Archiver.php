<?php
namespace Procomputer\Joomla\Model;

use Procomputer\Pcclib\Types;
use Procomputer\Pcclib\FileSystem;
use Procomputer\Joomla\Traits;
use Procomputer\Joomla\Model\Progress;
use RuntimeException;
use Throwable;
use ZipArchive;
use Closure;
use stdClass;

class Archiver {

    use Traits\Files;
    
    /**
     * ZipArchive object.
     * @var ZipArchive
     */
    protected $_zip = null;
    
    /**
     * Archive file is stored here.
     * @var string
     */
    protected $_zipFile = null;
    
    /**
     * 
     * @var \Procomputer\Joomla\Drivers\Files\FileDriver
     */
    protected $_fileDriver = null;
    
    /**
     * Callback Closure.
     * @var Closure
     */
    protected $_callback = null;
    
    /**
     * Archiver options.
     * @var array
     */
    protected $_archiverOptions = [];
    
    /**
     * Errors saved.
     * @var array
     */
    protected $_errors = [];

    /**
     * Progress data.
     * @var \Procomputer\Joomla\Model\Progress
     */
    protected $_progress;
    
    /**
     * Constructor
     * @param FileDriver $fileDriver Archiver needs the file driver to download remote/local files.
     * @param array      $options    (optional) Options. 
     */
    public function __construct($fileDriver, array $options = null) {
        $this->_fileDriver = $fileDriver;
        $this->_archiverOptions = $options;
        $this->_progress = new Progress();
    }
    
    /**
     * Returns the archive after successful addFromFileList().
     * @return string
     */
    public function getZipFile() {
        return $this->_zipFile;
    }
    
    /**
     * Returns the PHP ZipArchive object or NULL if none was created.
     * @return ZipArchive|boolean
     */
    public function getZipArchive() {
        return $this->_zip;
    }
    
    /**
     * Returns the PHP ZipArchive object of FALSE on error.
     * @param string $file       (optional) The target file. NULL cause a temporary file to be used.
     * @param int    $zipOptions (optional) One or more 'ZipArchive::' options.
     * @param array  $options    (optional) Other options.
     * @return ZipArchive|boolean
     */
    public function open($file = null, $zipOptions = ZipArchive::CREATE | ZipArchive::OVERWRITE, array $options = null) {
        if(null !== $this->_zip) {
            $this->close();
        }
        if(null === $file) {
            $zipFile = $this->createTempFile();
            if(false === $zipFile) {
                return false;
            }
        }
        else {
            $zipFile = $file;
        }
        if(! is_int($zipOptions)) {
            $zipOptions = ZipArchive::CREATE | ZipArchive::OVERWRITE;
        }
        //create the archive
        $zip = new ZipArchive();
        $res = $zip->open($zipFile, $zipOptions);
        if($res !== true) {
            $msg = $this->_getZipArchiveErrorMessage($zip, $res);
            $this->saveError("Cannot open ZIP archive: {$msg}");
            return false;
        }
        $this->_zip = $zip;
        $this->_zipFile = $zipFile;
        return $zip;
    }
    
    /**
     * Closes the ZIP archive.
     * @return boolean Returns TRUE if success else FALSE.
     */
    public function close() {
        if($this->_zip instanceof ZipArchive) {
            $success = true;
//            $tempFile = $this->createTempFile();
//            if(false === $tempFile) {
//                $success = false;
//            }
//            else {
//                $zipFile = $this->getZipFile();
//                if(! FileSystem::copyFile($zipFile, $tempFile, true)) {
//                    $msg = "cannot copy zip file {$zipFile} to temporary file {$tempFile}";
//                    $this->saveError($msg);
//                    $success = false;
//                }
//                else {
//                    $this->_zipFile = $tempFile;
//                    $success = true;
//                }
//            }
            if(! $this->_zip->close()) {
                $msg = $this->_getZipArchiveErrorMessage($this->_zip);
                $this->saveError($msg);
                $success = false;
            }
            return $success;
        }
        else {
            $return = true;
        }
        $this->_zip = null;
        return $return;
    }   
    
    /**
     * Creates a ZIP archive of the specified directory files and sub-folder and files and stores to a temporary file.
     * @param array|\Traversable $fileList   List of file source=>location pairs to add to archive.
     * @return boolean Returns true if success else FALSE.
     */
    public function addFromFileList(iterable $fileList) {
        $fileCount = $this->_getFileCount($fileList);
        if(! $fileCount) {
            $var = Types::getVartype($fileList);
            $errMsg = "WARNING: invalid list parameter '{$var}' is empty in " . __FUNCTION__ . "() line " . __LINE__;
            $this->saveError($errMsg);
        }
        $zip = $this->_getZipArchive();
        if(false === $zip) {
            return false;
        }
        $success = true;
        foreach($fileList as $values) {
            if(! is_array($values) || 2 !== count($values)) {
                $var = Types::getVartype($fileList);
                $errMsg = "list parameter '{$var}' must be list of 2-element arrays of file";
                $this->saveError($errMsg);
                $success = false;
                break;
            }
            list($fullPath, $entryName) = $values;
            if($this->_fileDriver->isDirectory($fullPath)) {
                if(! $this->_addFromDirectory($fullPath, $entryName)) {
                    $success = false;
                    break;
                }
            }
            else {
                if(false === $this->addFileToZip($zip, $fullPath, $entryName)) {
                    return false;
                }
                if($zip->status) {
                    $msg = $this->_getZipArchiveErrorMessage($zip);
                    $this->saveError("Cannot add file to ZIP archive: {$msg}");
                    $success = false;
                    break;
                }
            }
        }
        return $success;
    }
    
    /**
     * Creates a ZIP archive of the specified directory files and sub-folder and files and stores to a temporary file.
     * @param string   $directory  Directory from which to add files and folders.
     * @param string   $location   (optional) Relative path location in the archive. If omitted the root is used.
     * 
     * @return boolean Returns TRUE if success else FALSE.
     */
    protected function _addFromDirectory(string $directory, string $location) {
        $dirOffset = strlen($directory) + 1;
        $subDir = trim($location);
        if(empty($subDir)) {
            $subDir = false;
        }
        $zip = $this->_getZipArchive();
        $valid = $this->_fileDriver->iterateFiles($directory, function($isDir, $fullPath/*, $fileInfo */) 
                use($zip, $dirOffset, $subDir) {
            // Skip directories (they would be added automatically)
            if($isDir) {
                return true;
            }
//            if(! $this->_fileDriver->isDirectory($fullPath) && ! $this->_fileDriver->isFile($fullPath)) {
//                $break = 1;
//            }
            // Get real and relative path for current file
            $entryName = substr($fullPath, $dirOffset);
            if(false !== $subDir) {
                $entryName = $this->joinPath($subDir, $entryName);
            }
            try {
                $res = $this->addFileToZip($zip, $fullPath, $entryName);
                if($res) {
                    // It's a zip error.
                    $msg = $this->_getZipArchiveErrorMessage($zip);
                    $this->saveError("Cannot add file to ZIP archive: {$msg}");
                    return false;
                }
            } catch (Throwable $exc) {
                $this->saveError($exc->getMessage());
                return false;
            }
            return true;
        });
        return $valid;
    }
    
    /**
     * 
     * @param iterable $fileList
     * @return int
     */
    protected function _getFileCount(iterable $fileList, $fileCount = null) {
        if(null === $fileCount) {
            $fileCount = new stdClass();
            $fileCount->count = 0;
        }
        $driver = $this->_fileDriver;
        foreach($fileList as $values) {
            $filePath = is_array($values) ? reset($values) : $values;
            if($driver->isDirectory($filePath)) {
                $files = new \ArrayObject; ;
                $driver->iterateFiles($filePath, function($isDir, $file, $fileInfo) use($filePath, $fileCount, $files) {
                    // Skip directories (they would be added automatically)
                    if($isDir) {
                        $files->append($this->joinPath($filePath, $file));
                    }
                });
                if($files->count()) {
                    $this->_getFileCount($files, $fileCount);
                }
            }
            else {
                $fileCount->count++;
            }
        }
        return $fileCount->count;
    }

    /**
     * 
     * @param ZipArchive    $zip
     * @param string        $filePath
     * @param string        $entryName
     * @return int
     */
    public function addFileToZip(ZipArchive $zip, string $filePath, string $entryName) {
        set_error_handler(
            static function(int $errno, string $errstr) : void {
                $msg = "Cannot get information for file: $errstr";
                throw new RuntimeException($msg, $errno);
            }
        );
        try {
            $callback = $this->_getCallbackFromOptions();
            if($callback) {
                if(false === $callback($this->_progress, basename(__CLASS__) . '::' . __FUNCTION__)) {
                    return false;
                }
            }
            $this->_progress->add(1);
            if('system' === $this->_fileDriver->getDriverId()) {
                $return = (false === $zip->addFile($filePath, $entryName, 0, 0, ZipArchive::FL_ENC_GUESS)) ? $zip->status : 0;
            }
            else {
                $tempFile = $this->_createTemporaryFile(sys_get_temp_dir()); // , "pcc", false, 0777);
                if(false === $tempFile) {
                    return false;
                }
                if(! is_writable($tempFile)) {
                    $msg = "Temporary file not writable: {$tempFile}";
                    throw new RuntimeError($msg);
                }
                $contents = $this->_fileDriver->getFileContents($filePath);
                if(false === $contents) {
                    $errors = $this->_fileDriver->getErrors();
                    $this->saveError($errors);
                    return false;
                }
                set_error_handler(
                    static function(int $errno, string $errstr) use ($filePath): void {
                        throw new RuntimeException("Cannot write to temporary file contents of file {$filePath}: $errstr", $errno);
                    }
                );
                try {
                    $numBytes = file_put_contents($tempFile, $contents);
                } catch (\Throwable $exc) {
                    throw new RuntimeException($exc);
                } finally {
                    restore_error_handler();
                }
//                if(false === $this->_fileDriver->download($filePath, $tempFile)) {
//                    $errors = $this->_fileDriver->getErrors();
//                    $this->saveError($errors);
//                    return false;
//                }
                $return = (false === $zip->addFile($tempFile, $entryName, 0, 0, ZipArchive::FL_ENC_GUESS)) ? $zip->status : 0;
            }
            return $return;
        } catch (Throwable $exc) {
            $msg = $exc->getMessage();
            if(empty($msg)) {
                $msg = "unknown exception thrown in " . basename(__FILE__) . '::' . __FUNCTION__ . ')';
            }
            throw new RuntimeException($msg);
        } finally {
            restore_error_handler();
            if(! empty($tempFile) && file_exists($tempFile)) {
                // unlink($tempFile);
            }
        }
    }
    
    /**
     * 
     * @param ZipArchive $zip       ZipArchive object.
     * @param string      $filePath  Source file path
     * @param string      $entryName Relative path inside the archive.
     * @return boolean
     * @throws RuntimeException
     */    
    public function addFile($filePath, $entryName) {
        if(! $this->_zip instanceof ZipArchive) {
            $msg = "Cannot add file to archive: no ZIP archive is open: use the 'open()' method to open an archive";
            throw new RuntimeException($msg);
        }
        // Add current file to archive
        if(! $this->_zip->addFile($filePath, $entryName)) {
            $msg = $this->_getZipArchiveErrorMessage($this->_zip);
            $this->saveError("Cannot add file to ZIP archive: {$msg}");
        }
        return false;
    }

    /**
     * Returns the filename from the currently opened ZIP archive.
     * @return string
     * @throws RuntimeException
     */    
    public function getFilename() {
        if(! $this->_zip instanceof ZipArchive) {
            $msg = "Cannot return ZIP archive filename: no ZIP archive is open: use the 'open()' method to open an archive";
            throw new RuntimeException($msg);
        }
        return $this->_zip->filename;
    }

    /**
     * Returns the PHP ZipArchive object or FALSE on error.
     * @param int   $zipOptions
     * @param array $options
     * @return ZipArchive|boolean
     */
    public function _getZipArchive($zipOptions = ZipArchive::CREATE | ZipArchive::OVERWRITE, $options = null) {
        return ($this->_zip instanceof ZipArchive) ? $this->_zip : $this->open(null, $zipOptions, $options);
    }
    
    /**
     * Returns a ZIP Archive error description.
     * @param int $code
     * @return string Returns message string.
     */
    protected function _getZipArchiveErrorMessage(ZipArchive $zip, $code = null) {
        $msg = method_exists($zip, 'getStatusString') ? $zip->getStatusString() : null;
        if(! Types::isBlank($msg)) {
            return $msg;
        }
        $errors = [
            ZipArchive::ER_OK          => 'No error.',
            ZipArchive::ER_MULTIDISK   => 'Multi-disk zip archives not supported.',
            ZipArchive::ER_RENAME      => 'Renaming temporary file failed.',
            ZipArchive::ER_CLOSE       => 'Closing zip archive failed',
            ZipArchive::ER_SEEK        => 'Seek error',
            ZipArchive::ER_READ        => 'Read error',
            ZipArchive::ER_WRITE       => 'Write error',
            ZipArchive::ER_CRC         => 'CRC error',
            ZipArchive::ER_ZIPCLOSED   => 'Containing zip archive was closed',
            ZipArchive::ER_NOENT       => 'No such file.',
            ZipArchive::ER_EXISTS      => 'File already exists',
            ZipArchive::ER_OPEN        => 'Can\'t open file',
            ZipArchive::ER_TMPOPEN     => 'Failure to create temporary file.',
            ZipArchive::ER_ZLIB        => 'Zlib error',
            ZipArchive::ER_MEMORY      => 'Memory allocation failure',
            ZipArchive::ER_CHANGED     => 'Entry has been changed',
            ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported.',
            ZipArchive::ER_EOF         => 'Premature EOF',
            ZipArchive::ER_INVAL       => 'Invalid argument',
            ZipArchive::ER_NOZIP       => 'Not a zip archive',
            ZipArchive::ER_INTERNAL    => 'Internal error',
            ZipArchive::ER_INCONS      => 'Zip archive inconsistent',
            ZipArchive::ER_REMOVE      => 'Can\'t remove file',
            ZipArchive::ER_DELETED     => 'Entry has been deleted',
        ];
        $errMsg = isset($errors[$zip->status]) ? $errors[$zip->status] : null;
        $msg = empty($errMsg) ? "UNKNOWN ZIP ARCHIVE ERROR #{$zip->status}" : ("ZIP archive error: " . $errMsg);
        return $msg;
    }

    /**
     * Creates a temporary file in the path specified or the path provided by sys_get_temp_dir() if no path specified.
     * @param string  $prefix  Optional temporary filename prefix;
     * @param string  $path    Optional path in which temp file is pplaced.
     * @param boolean $keep    Optional flag to preserve the temporary file else it's destroyed on PHP script close.
     * @return string|boolean
     */
    public function createTempFile($prefix = 'pcc', $path = null, $keep = false) {
        if(null === $path) {
            $path = sys_get_temp_dir();
        }
        $filesystem = new FileSystem();
        $file = $filesystem->createTempFile($path, $prefix, $keep);
        // $file = $this->callFuncAndSavePhpError(function()use($prefix, $path){return tempnam($path, $prefix);});
        if(false === $file || ! file_exists($file) || ! is_file($file)) {
            if($filesystem->getErrorCount()) {
                $errorMsg = ': ' . implode(": ", $filesystem->getErrors());
            }
            else {
                $errorMsg = '';
            }
            $this->saveError("Cannot create temporary file" . $errorMsg);
            return false;
        }
        return $file;
    }

    /**
     * 
     * @return /Closure|boolean
     */
    protected function _getCallbackFromOptions() {
        if(null === $this->_callback) {
            $option = $this->_archiverOptions['callback'] ?? null;
            $this->_callback = (is_object($option) && is_callable($option)) ? $option : false;
        }
        return $this->_callback;
    }
    
    /**
     *
     * @param array|string $messages Error messages to save.
     * @return self
     */
    public function saveError($messages) {
        if(is_array($messages)) {
            if(empty($messages)) {
                return $messages;
            }
        }
        else {
            $messages = trim((string)$messages);
            if(empty($messages)) {
                $messages = __FUNCTION__ . "() called with empty parameter";
            }
            $messages = [$messages];
        }
        $this->_errors = array_merge($this->_errors, array_values($messages));
        return $this;
    }

    /**
     * Returns saved errors.
     * @return array
     */
    public function getErrors() {
        return $this->_errors;
    }

    /**
     * Clears errors.
     * @return UtilitiesCommon
     */
    public function clearErrors() {
        $this->_errors = [];
        return $this;
    }
}
