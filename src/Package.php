<?php //
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types;
use stdClass;

class Package extends PackageCommon {

    /**
     * OVERRIDDEN - The extension prefix.
     * @var string
     */
    protected $_namePrefix = 'pkg_';
    
    /**
     * Creates a package from an existing joomla installation.
     * @return boolean Returns true if no errors encountered else false.
     */
    public function import(array $options = null) : bool {
        $this->setPackageOptions($options);
        $requiredElements = [
            'author',
            'authorEmail',
            'authorUrl',
            'copyright',
            'creationDate',
            'files',
            'license',
            'name',
            'packagename',
            'version'
            // 'description',
            // 'packager'
            // 'packagerurl'
            // 'scriptfile'
            // 'updateservers'
            // 'url'
            ];
        
        $missing = $this->checkRequiredElementsExist($requiredElements);
        if(true !== $missing) {
            $this->_packageMessage("required package element(s) missing: " . implode(", ", $missing));
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
            'files' => true,          //_processSectionFiles
            'administration' => true, //_processSectionAdministration
            'languages' => false,     //_processSectionLanguages
            'scriptfile' => false,    //_processSectionScriptfile
            'media' => false          //_processSectionMedia
            ];
        $progress = $this->getProgress();
        $driver = $this->_fileDriver;
        /** @var \Procomputer\Joomla\Drivers\Files\Remote $driver */
        foreach($sections as $section => $required) {
            $node = $this->manifest->{$section};
            if(null === $node) {
                $msg = "WARNING: required manifest XML '{$section}' is empty";
                $this->saveMessage($msg);
                if($required) {
                    return false;
                }
                continue;
            }
            $method = '_processSection' . ucfirst($section);
            if(false === $this->$method($node, 'site')) {
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
                $this->saveMessage($archiver->getMessages());
                $archiver->close();
                return false;
            }
        }
        
        /**
         * 
         */
        if(false === $this->archive()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Process the 'files' manifest section.
     * @return boolean
     */
    protected function _processFiles(stdClass $node, string $tag = 'Manifest'): bool {
        $valid = true;
        //<scriptfile>script.php</>
        //<files folder="packages">	   
        $files = $this->manifest->files ?? null;
        if(empty($files)) {
            return false;
        }
        foreach($files as $filesNode) {
            /* @var $filesNode \SimpleXMLElement */
            if(! $filesNode || ! $filesNode->count()) {
                $this->_packageMessage("a 'files' section is empty: expecting package ZIP file declarations");
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
     * Process a file in the 'files' manifest section.
     * @return boolean
     */
    protected function _processFile($file, string $tag = 'Manifest'): bool {
        
        // <file type="module" id="pcceventslist" client="site">mod_pcceventslist.zip</file>
        
        /* @var $file \SimpleXMLElement */
        $attribs = $this->manifest->extractAttributes($file, ['type' => '']);
        $type = $attribs['type'];
        if(empty($type)) {
            $filename = (string)$file;
            $this->_packageMessage("'file' node '{$filename}' in the 'files' section is missing the 'type' attribute");
            return false;
        }
        $class = __NAMESPACE__ . '\Package' . ucfirst($type); // PackageModule
        if(! class_exists($class)) {
            // Unsupported this version:
            // plugin
            // library
            $this->_packageMessage("package type '{$type}' not currently supported");
            return false;
        }
        /* @var $obj PackageModule */
        $obj = new $class($this->_installation);
        $obj->setParent($this);
        if(false === $obj->process($file)) {
            if(self::MISSING_FROM_JOOMLA_INSTALL === $obj->getLastError()) {
                $obj->clearErrors();
                $basename = pathinfo((string)$file, PATHINFO_FILENAME);
                $this->_packageMessage("extension '{$basename}' is not found in the Joomla installation. \n" 
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
    public function copy(string $destPath): bool {
        if(false === parent::copy($destPath)) {
            return false;
        }
        foreach($this->getPackages() as $item) {
            if(false === $item->copy($this->joinPath($destPath, 'packages'))) {
                return false;
            }
        }
        return true;
    }
}