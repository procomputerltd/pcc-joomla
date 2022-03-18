<?php //
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types;

class Package extends PackageCommon {

    /**
     * OVERRIDDEN - The extension prefix.
     * @var string
     */
    protected $_namePrefix = 'pkg_';
    
    /**
     * Creates a package from an existing joomla installation.
     * @return string|boolean Returns the path of the temporary file holding the ZIP archive.
     */
    public function import(array $options = null) {
        $this->setPackageOptions($options);
        $manifestElements = [
            'author' => true,
            'authorEmail' => true,
            'authorUrl' => true,
            'copyright' => true,
            'creationDate' => true,
            'description' => true,
            'files' => true,
            'license' => true,
            'name' => true,
            'packagename' => true,
            'packager' => false,
            'packagerurl' => false,
            'scriptfile' => false,
            'updateservers' => false,
            'url' => false,
            'version' => true,
            ];
        
        $missing = $this->checkRequirements($manifestElements);
        if(true !== $missing) {
            $this->_packageError("required package element(s) missing: " . implode(", ", $missing));
            return false;
        }
        $this->manifest = $this->_extension->getManifest(); 
        $this->manifestFile = $this->manifest->getManifestFile(); 
        
        $filename = pathinfo($this->manifestFile, PATHINFO_FILENAME);
        $name = $this->_removeNamePrefix($filename);
        if(empty($name)) {
            $var = Types::getVartype($filename);
            $this->_packageMessage("the module name cannot be interpreted from the file name '{$var}'");
            return false;
        }
        $this->_extensionName = $this->_addNamePrefix($name);
        
        /*
         * Add the manifest install XML descriptor file to list of files to copy.
         */
        if(false === $this->addFile($this->manifestFile, basename($this->manifestFile))) {
            return false;
        }
        
        $sections = [
            'files' => false,           //_processSectionFiles
            'administration' => false,  //_processSectionAdministration
            'languages' => true,       //_processSectionLanguages
            'scriptfile' => true,      //_processSectionScriptfile
            'media' => true             //_processSectionMedia
            ];
        $progress = $this->getProgress();
        $driver = $this->_fileDriver;
        /** @var \Procomputer\Joomla\Drivers\Files\Remote $driver */
        foreach($sections as $section => $isOptional) {
            $method = '_processSection' . ucfirst($section);
            if(false === $this->$method($this->manifest, $isOptional)) {
                return false;
            }
            $seconds = $progress->getInterval(true, $method);
            if($seconds >= 10 && method_exists($driver, 'reopen')) {
                $driver->reopen();
            }
        }   

        if($this->getPackageOption('importdatabase', false)) {
            $tablesAndData = $this->_exportTablesAndData($options);
            if(false === $tablesAndData) {
                return false;
            }
            /*
             * ['install' => [
             *    'drop'   => [$uninstallFile => $dropTables],
             *    'create' => [$installFile => $createTables],
             *    'data'   => [$dataFile => $sampleData]
             *    ]
             * ];
            */
            if(isset($tablesAndData['install']) && isset($tablesAndData['install']['data'])) {
                $file = key($tablesAndData['install']['data']);
                $data = reset($tablesAndData['install']['data']);
                /*
                 * Add the manifest install XML descriptor file to list of files to copy.
                 */
                if(false === $this->addFile($file, basename($file))) {
                    return false;
                }
                return false;
            }
        }
        
        foreach($this->getPackages() as $package) {
            /* @var $package PackageCommon */
            $archive = $package->archive();
            if(false === $archive) {
                $archiver->close();
                return false;
            }
            if(! $archiver->addFile($archive, 'packages/' . $package->extensionName . '.zip')) {
                $this->saveError($archiver->getErrors());
                $archiver->close();
                return false;
            }
        }
        
        $filename = $archiver->getFilename();
        
        /**
         * 
         */
        if(false === $this->archive()) {
            return false;
        }
        
        return true;
        
        if(! $archiver->close()) {
            $this->saveError($archiver->getErrors());
            return false;
        }
        return $filename;
    }
    
    /**
     * 
     * @return boolean
     */
    protected function _processFiles() {
        $valid = true;
        //<scriptfile>script.php</>
        //<files folder="packages">	   
        foreach($this->manifest->files as $filesNode) {
            /* @var $filesNode \SimpleXMLElement */
            if(! $filesNode || ! $filesNode->count()) {
                $this->_packageError("a 'files' section is empty: expecting package ZIP file declarations");
                $valid = false;
            }
            else {
                /*                
                <files folder="packages">	   
                    <file type="module" id="pcceventslist" client="site">mod_pcceventslist.zip</file>
                    <file type="component" id="pccoptionselector">com_pccevents.zip</file>
                </files> */
                foreach($filesNode as $file) {
                    
                    // If X number of seconds elapsed re-open the file driver FTP connection and reset the timer.
                    $elapsed = $this->_progress->getInterval(false, __CLASS__ . '::' . __FUNCTION__);
                    if($elapsed >= 10 && method_exists($this->_fileDriver, 'reopen')) {
                        $this->_fileDriver->reopen();
                        $this->_progress->getInterval(); // Reset the timer.
                    }

                    if(false === $this->_processFile($file)) {
                        $valid = false;
                    }
                }
            }
        }
        return $valid;
    }
    
    /**
     * 
     * @return boolean
     */
    protected function _processFile($file) {
        
        // <file type="module" id="pcceventslist" client="site">mod_pcceventslist.zip</file>
        
        /* @var $file \SimpleXMLElement */
        $attribs = $this->extractAttributes($file, ['type' => '']);
        $type = $attribs['type'];
        if(empty($type)) {
            $filename = (string)$file;
            $this->_packageError("'file' node '{$filename}' in the 'files' section is missing the 'type' attribute");
            return false;
        }
        $class = __NAMESPACE__ . '\Package' . ucfirst($type); // PackageModule
        if(! class_exists($class)) {
            // Unsupported this version:
            // plugin
            // library
            $this->_packageError("package type '{$type}' not currently supported");
            return false;
        }
        /* @var $obj PackageModule */
        $obj = new $class($this->_installation);
        $obj->setParent($this);
        if(false === $obj->process($file)) {
            if(self::MISSING_FROM_JOOMLA_INSTALL === $obj->getLastError()) {
                $obj->clearErrors();
                $basename = pathinfo((string)$file, PATHINFO_FILENAME);
                $this->_packageError("extension '{$basename}' is not found in the Joomla installation. \n" 
                    . "Are you sure the '{$this->_extensionName}' extension in installed in the Joomla installation folder?");
                return false;
            }
        }
        $this->_packages[] = $obj;
        return true;
    }
    
    /**
     * Copies package components, modules from a Joomla installation to an install-able file.
     * @param string  $destPath
     * @return boolean
     */
    public function copy($destPath) {
        if(false === parent::copy($destPath)) {
            return false;
        }
        foreach($this->getPackages() as $item) {
            if(false === $item->copy($this->joinPath($destPath, 'packages'))) {
                return false;
            }
        }
    }
}