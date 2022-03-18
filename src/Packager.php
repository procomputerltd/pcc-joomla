<?php
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types;

/*  <name>PCC Option Selector Package</name>
    <packagename>pccoptionselector</packagename>
    <creationDate>January 2018</creationDate>
    <author>Jame R. Steel</author>
    <authorEmail>pcc@pccglobal.com</authorEmail>
    <authorUrl>https://pccglobal.com</authorUrl>
    <copyright>Copyright (C) 2018 Pro Computer</copyright>
    <license>http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL</license>
    <version>1.11.0</version>
    <description>Pcc Option Selector lets you create and manage lists of events for clubs and associations</description>
    <scriptfile>script.php</scriptfile>
    <files folder="packages">	   
        <file type="module" id="pcceventslist" client="site">mod_pcceventslist.zip</file>
        <file type="component" id="pccoptionselector">com_pccevents.zip</file>
    </files>	 
    <updateservers>	
        <server type="extension" priority="1" name="Pcc Option Selector Extension">https://pccglobal.com/updates/pccoptionselector.xml</server>
    </updateservers>
*/

class Packager {

    use Traits\Messages;
    use Traits\Files;
    
    /**
     * Joomla installation object.
     * @var Installation
     */
    protected $_installation;

    /**
     * Name of the extension ex 'com_banners'
     * @var string
     */
    protected $_extensionName = null;
    
    /**
     * Service manager.
     * @var \Laminas\ServiceManager\ServiceManager
     */
    protected $_container;

    /**
     * The archive file after assemble() successfully completes.
     * @var string
     */
    protected $_archiveFile = null;

    /**
     * The Package object.
     * @var \Procomputer\Joomla\Package
     */
    protected $_package = null;

    /**
     * 
     * @param Installation $installation
     * @param \Laminas\ServiceManager\ServiceManager $container
     */
    public function __construct(Installation $installation, $container) {
        $this->_installation = $installation;
        $this->_container = $container;
    }
    
    /**
     * Saves (moves) the archive temporary file to a new name and location.
     * @param string $destPath Path in which to move the temporary file.
     * @param array  $options  (optional) Options.
     * @return string|boolean Returns the full path of the ZIP file else FALSE on error.
     */
    public function saveArchiveFile($destPath, $options = null) {
        $tempFile = $this->getArchiveTempFile();
        if(empty($tempFile)) {
            $classFunction = __CLASS__ . '::assemble()' ;
            $this->_packageError("Cannot save archive. No archive temporary was created. Are you sure you had a successful call to {$classFunction}?");
            return false;
        }
        if(! file_exists($tempFile) || ! ($isFile = is_file($tempFile))) {
            $msg = isset($isFile) ? "is not a file" : "has disappeared";
            $var = Types::getVartype($tempFile);
            $this->_packageError("Cannot save archive. The archive temporary file {$msg}: \n{$var}");
            return false;
        }
        $lcOptions = (null === $options) ? [] : array_change_key_case((array)$options);
        $package = $this->getPackage();
        $destFile = $this->joinPath($destPath, $package->extensionName . '.zip');
        
        if(file_exists($destFile)) {
            $renameExisting = (isset($lcOptions['rename']) && $lcOptions['rename']);
            if(! $renameExisting) {
                $this->_packageError("Cannot save archive: the archive file exists and 'rename' option is FALSE: \n{$destFile}");
                return false;
            }
            $backupFile = $this->_getBackupFilename($destFile);
            if(false === $backupFile) {
                $this->_packageError("Cannot save archive: cannot create backup filename for existing file: \n{$destFile}");
                return false;
            }
            if(! $this->callFuncAndSavePhpError(function()use($destFile, $backupFile){return rename($destFile, $backupFile);})) {
                $this->_packageError("Cannot save archive: cannot create backup file for existing file: \n{$destFile}");
                return false;
            }
            else {
                $dest = basename($destFile);
                $backup = basename($backupFile);
                $this->saveMessage("The exiting archive file '{$dest}' is successfully backed up to '{$backup}'");
            }
        }
        if($this->callFuncAndSavePhpError(function()use($tempFile, $destFile){return rename($tempFile, $destFile);})) {
            $base = basename($destFile);
            $dir = dirname($destFile);
            $this->saveMessage("The archive file '{$base}' is successfully created in directory: \n{$dir}");
            return $destFile;
        }
        $this->_packageError("Cannot save archive: cannot move temporary file to the destination file: \n{$destFile}");
        return false;
    }

    /**
     * Attempts to create a unique backup filename for the given path/filename.
     * @param string $file  The file to backup.
     * @return string|boolean Returns the full path of the backup file else FALSE on error.
     */
    protected function _getBackupFilename($file) {
        $dirname = pathinfo($file, PATHINFO_DIRNAME); // dirname($file);
        $filename = $basename = pathinfo($file, PATHINFO_FILENAME);
        $ext = '.' . pathinfo($file, PATHINFO_EXTENSION);
        for($i = 1; $i < 10000; $i++) {
            $fullPath = $this->joinPath($dirname, $basename . $ext);
            if(!file_exists($fullPath)) {
                return $fullPath;
            }
            $basename = $filename . '_' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        }
        return false;
    }
    
    /**
     * Returns the archive file after assemble() successfully completes.
     * @return string|null
     */
    public function getArchiveTempFile() {
        return $this->_archiveFile;
    }
    
    /**
     * Returns the archive file after assemble() successfully completes.
     * @return string|null
     */
    public function deleteArchiveTempFile() {
        $tempFile = $this->getArchiveTempFile();
        if(empty($tempFile)) {
            return true;
        }
        if($this->callFuncAndSavePhpError(function()use($tempFile){return unlink($tempFile);})) {
            $this->_archiveFile = null;
            return true;
        }
        $this->_packageError("Cannot delete archive temporary file: \n{$tempFile}");
        return false;
    }
    
    /**
     * Saves a package assembly error.
     * @param string $msg The message to store.
     * @return Packager
     */
    protected function _packageError($msg, $errorSource = null) {
        $source = (null === $errorSource) ? null : trim((string)$errorSource);
        if(empty($source)) {
            $source = empty($this->_extensionName) ? null : $this->_extensionName;
        }
        $source = empty($source) ? '' : " '($source)'";
        $this->saveError("In XML package manifest{$source}: {$msg}");
        return $this;
    }
    
}
