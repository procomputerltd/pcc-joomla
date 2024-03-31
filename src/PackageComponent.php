<?php
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types;
use stdClass;

class PackageComponent extends PackageCommon {
    
    /**
     * OVERRIDDEN - The extension prefix.
     * @var string
     */
    protected $_namePrefix = 'com_';
    
    /**
     * OVERRIDDEN - The type of extension.
     * @var string
     */
    protected $_extensionType = 'package';

    /**
     * Imports a Joomla component and copies the files to an installable ZIP archive.
     * @param iterable $options (optional) Options
     * @return boolean
     */
    public function import(array $options = []): bool {
        $this->setPackageOptions($options);
        $this->manifest = $this->_extension->getManifest(); 
        $this->manifestFile = $this->manifest->getManifestFile(); 
        
        $manifestElements = [
            'name',
            'creationDate',
            'author',
            'authorEmail',
            'authorUrl',
            'copyright',
            'license',
            'version',
            'files',
            'administration',
            'media',
            // Optional:
            // description
            // languages
            // scriptfile
            // install
            // uninstall
            // update
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
        
        $sections = [
            'files' => true,           //_processSectionFiles
            'administration' => true,  //_processSectionAdministration
            'languages' => false,       //_processSectionLanguages
            'scriptfile' => false,      //_processSectionScriptfile
            'media' => false             //_processSectionMedia
            ];
        $progress = $this->getProgress();
        $driver = $this->_fileDriver;
        /** @var \Procomputer\Joomla\Drivers\Files\Remote $driver */
        foreach($sections as $section => $required) {
            $node = $this->manifest->getNode($section);
            if(null === $node) {
                $msg = "WARNING: required manifest XML '{$section}' is empty";
                $this->saveMessage($msg);
                if($required) {
                    return false;
                }
                continue;
            }
            $method = '_processSection' . ucfirst($section);
            if(false === $this->$method($node, 'admin')) {
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
        
        /**
         * 
         */
        if(false === $this->archive()) {
            return false;
        }
        
        return true;
    }

    /* Administrator section.
        <menu>OSM_MEMBERSHIP</menu>
        <submenu>
            <menu link="option=com_osmembership&amp;view=dashboard">OSM_DASHBOARD</menu>
            <menu link="option=com_osmembership&amp;view=configuration">OSM_CONFIGURATION</menu>
            <menu link="option=com_osmembership&amp;view=categories">OSM_PLAN_CATEGORIES</menu>
            <menu link="option=com_osmembership&amp;view=plans">OSM_SUBSCRIPTION_PLANS</menu>
            <menu link="option=com_osmembership&amp;view=subscriptions">OSM_SUBSCRIPTIONS</menu>
            <menu link="option=com_osmembership&amp;view=groupmembers">OSM_GROUP_MEMBERS</menu>
            <menu link="option=com_osmembership&amp;view=fields">OSM_CUSTOM_FIELDS</menu>
            <menu link="option=com_osmembership&amp;view=taxes">OSM_TAX_RULES</menu>
            <menu link="option=com_osmembership&amp;view=coupons">OSM_COUPONS</menu>
            <menu link="option=com_osmembership&amp;view=import">OSM_IMPORT_SUBSCRIBERS</menu>
            <menu link="option=com_osmembership&amp;view=plugins">OSM_PAYMENT_PLUGINS</menu>
            <menu link="option=com_osmembership&amp;view=message">OSM_EMAIL_MESSAGES</menu>
            <menu link="option=com_osmembership&amp;view=language">OSM_TRANSLATION</menu>
            <menu link="option=com_osmembership&amp;view=countries">OSM_COUNTRIES</menu>
            <menu link="option=com_osmembership&amp;view=states">OSM_STATES</menu>
        </submenu>
        <languages>
            <language tag="en-GB">admin/languages/en-GB/en-GB.com_osmembership.sys.ini</language>
            <language tag="en-GB">admin/languages/en-GB/en-GB.com_osmembership.ini</language>
            <language tag="en-GB">admin/languages/en-GB/en-GB.com_osmembershipcommon.ini</language>
        </languages>
        <files folder="admin">
            <filename>config.xml</filename>
            <filename>access.xml</filename>
            <filename>osmembership.php</filename>
            <filename>config.php</filename>
            <filename>loader.php</filename>
            <folder>assets</folder>
            <folder>model</folder>
            <folder>view</folder>
            <folder>controller</folder>
            <folder>libraries</folder>
            <folder>elements</folder>
            <folder>table</folder>
            <folder>sql</folder>
            <folder>updates</folder>
        </files>
    */        
    /**
     * @param stdClass $node
     * @return bool
     */
    protected function _processSectionAdministration(stdClass $node): bool {
        $tag = 'administration';
        $files = $node->files ?? null;
        if(null === $files) {
            $this->_packageMessage("WARNING: required {$tag} 'files' section is missing.");
            return false;    
        }
        if(false === $this->_processFiles($files, $tag)) {
            return false;
        }
        
        $languages = $node->languages ?? null;
        if(null === $languages) {
            $this->_packageMessage("WARNING: {$tag} 'languages' section is missing.", self::NAMESPACE_WARNING);
        }
        elseif(! $this->_processSectionLanguages($languages, 'admin')) {
            return false;
        }
        return true;
    }

    /**
     * @param string $filename
     */
    protected function _processSectionScriptfile(string $filename): bool {
        // C:\inetpub\joomlapcc\administrator\components\com_pccevents\script.php
        $sourceFile = $this->joinPath(dirname($this->manifestFile), $filename);
        if(! $this->_fileDriver->fileExists($sourceFile)) {
            $this->_packageMessage("The script file specified in 'scriptfile' section is missing: {$filename}");
            return false;    
        }
        $this->addFile($sourceFile, $filename);
        return true;
    }
}