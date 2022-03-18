<?php
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types;

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
    public function import(array $options = null) {
        $this->setPackageOptions($options);
        $manifestElements = [
            'name' => true,
            'creationDate' => true,
            'author' => true,
            'authorEmail' => true,
            'authorUrl' => true,
            'copyright' => true,
            'license' => true,
            'version' => true,
            'description' => true,
            'files' => true,
            'administration' => true,
            'media' => true,
            'languages' => true,
            // Optional:
            'scriptfile' => false,
            'install' => false,
            'uninstall' => false,
            'update' => false,
        ];
        $missing = $this->checkRequirements($manifestElements);
        if(true !== $missing) {
            $this->_packageMessage("required element(s) missing: " . implode(", ", $missing));
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
         * Add the manifest XML descriptor file to list of files to copy.
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
     * <administration>
     */
    protected function _processSectionAdministration(Manifest $manifest, $isOptional = false) {
        $sectionName = 'administration';
        $section = $manifest->getProperty($sectionName);
        if(null === $section) {
            if($isOptional) {
                return true;
            }
            $this->_packageMessage("'{$sectionName}' section is missing");
            return false;
        }
        $files = $section->files ?? null;
        if(null === $files) {
            $this->_packageMessage("'{$sectionName}' section is empty");
            return false;    
        }
        if(false === $this->_processFiles($files)) {
            return false;
        }
        
        $languages = $section->languages ?? null;
        if(null === $languages) {
            // 'pccoptionselector.xml' manifest file error: 'files' is missing";
            // In XML package file 'mod_pccevent.xml': 'files' section is missing")
            $this->_packageMessage("WARNING: 'languages' section is missing from the admin section");
        }
        elseif(! $this->_processSectionLanguages($languages, true)) {
            return false;
        }
        return true;
    }

    /**
     * <scriptfile>script.php</scriptfile>
     */
    protected function _processSectionScriptfile(Manifest $manifest, $isOptional = false) {
        $sectionName = 'scriptfile';
        $data =  $manifest->getData();
        $scriptFile = $data->{$sectionName} ?? null;
        if(! is_string($scriptFile) || Types::isBlank($scriptFile)) {
            if($isOptional) {
                return true;
            }
            $this->_packageMessage("the manifest file is missing the '{$sectionName}' section");
            return false;
        }
        // C:\inetpub\joomlapcc\administrator\components\com_pccevents\script.php
        $sourceFile = $this->joinPath(dirname($this->manifestFile), $scriptFile);
        $this->addFile($sourceFile, $scriptFile);
        return true;
    }
}