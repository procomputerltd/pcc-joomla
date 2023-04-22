<?php
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types;

class PackageModule extends PackageCommon {

    /**
     * OVERRIDDEN - The extension prefix.
     * @var string
     */
    protected $_namePrefix = 'mod_';
    
    /**
     * Prepares a package object for the given Joomla installation.
     * @param iterable $options (optional) Options
     * @return boolean
     */
    public function import(array $options = null) {
        $this->_packageOptions = $options;
        $this->manifest = $this->_extension->getManifest(); 
        $this->manifestFile = $this->manifest->getManifestFile(); 
        
        $manifestElements = [
            'files',
            'name',
            'author',
            'creationDate',
            'copyright',
            'license',
            'authorEmail',
            'authorUrl',
            'version',
            'description',
            // == Optional:
            // install
            // uninstall
            // update
            // languages
            // media
        ];
        $missing = $this->checkRequiredElementsExist($manifestElements);
        if(true !== $missing) {
            $this->_packageMessage("required element(s) missing: " . implode(", ", $missing));
            return false;
        }
        
        $filename = pathinfo($this->manifestFile, PATHINFO_FILENAME);
        $name = $this->_removeNamePrefix($filename);
        if(empty($name)) {
            $var = Types::getVartype($filename);
            $this->_packageMessage("the module name cannot be interpreted from the file name '{$var}'");
            return false;
        }
        $this->_extensionName = $this->_addNamePrefix($name);
        
        /*
         * Add the manifest XML descriptor file to list of files to copy.
         */
        if(false === $this->addFile($this->manifestFile, basename($this->manifestFile))) {
            return false;
        }
        
        $driver = $this->_fileDriver; // The local or remote file storage.
        // The manifest sections to scan for files to copy to the archive.
        $sections = [
            'files' => true,       // _processSectionFiles
            'languages' => true,   // _processSectionLanguages
            'media' => false       // _processSectionMedia
            ];
        foreach($sections as $tag => $required) {
            $node = $this->manifest->getProperty($tag);
            if(null === $node) {
                $msg = "required manifest XML '{$tag}' data is empty";
                $this->_packageMessage($msg, true);
                if($required) {
                    return false;
                }
                continue;
            }
            $method = '_processSection' . ucfirst($tag);
            if(false === $this->$method($node, 'site')) {
                return false;
            }
            $progress = $this->getProgress();
            $seconds = $progress->getInterval(true, $method);
            if($seconds >= 10 && method_exists($driver, 'reopen')) {
                $driver->reopen();
            }
        }        
        
        if(false === $this->archive()) {
            return false;
        }
        
        return true;
    }
}