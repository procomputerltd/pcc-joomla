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
        $manifestElements = [
            'name' => true,
            'author' => true,
            'creationDate' => true,
            'copyright' => true,
            'license' => true,
            'authorEmail' => true,
            'authorUrl' => true,
            'version' => true,
            'description' => true,
            'files' => true,
            'install' => false,
            'uninstall' => false,
            'update' => false,
            // Optional
            //   languages
            //   media
        ];
        $missing = $this->checkRequirements($manifestElements);
        if(true !== $missing) {
            $this->_packageError("required element(s) missing: " . implode(", ", $missing));
            return false;
        }
        $this->manifest = $this->_extension->getManifest(); 
        $this->manifestFile = $this->manifest->getManifestFile(); 
        
        $filename = pathinfo($this->manifestFile, PATHINFO_FILENAME);
        $name = $this->_removeNamePrefix($filename);
        if(empty($name)) {
            $var = Types::getVartype($filename);
            $this->_packageError("the module name cannot be interpreted from the file name '{$var}'");
            return false;
        }
        $this->_extensionName = $this->_addNamePrefix($name);
        
        /*
         * Add the manifest XML descriptor file to list of files to copy.
         */
        if(false === $this->addFile($this->manifestFile, basename($this->manifestFile))) {
            return false;
        }
        
        $driver = $this->_fileDriver;
        $sections = [
            'files' => false,       // _processFiles
            'languages' => false,   // _processLanguages
            'media' => true         // _processMedia
            ];
        foreach($sections as $section => $isOptional) {
            $method = '_processSection' . ucfirst($section);
            if(false === $this->$method($this->manifest, $isOptional)) {
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