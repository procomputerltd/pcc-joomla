<?php
namespace Procomputer\Joomla;

use ZipArchive;

use Procomputer\Pcclib\FileSystem;
use Procomputer\Pcclib\Types;

use Procomputer\Joomla\Drivers\Files\FileDriver;
use Procomputer\Joomla\Model\Archiver;
use Procomputer\Joomla\Model\Progress;


class PackageCommon  {
    
    use Traits\Messages;
    use Traits\Files;

    const MISSING_FROM_JOOMLA_INSTALL = 0x100;
    
    /**
     * Extension manifest object.
     * @var \Procomputer\Joomla\Manifest
     */
    protected $manifest = null;
    
    /**
     * The manifest filename.
     * @var string
     */
    protected $manifestFile = null;
    
    /**
     * MUST OVERRIDE - The extension prefix.
     * @var string
     */
    protected $_namePrefix;

    /**
     * Name of the extension ex 'com_banners'
     * @var string
     */
    protected $_extensionName = null;
    
    /**
     * Fetched from the Joomla 'extensions' table; basic information about the extension 
     * @var \Procomputer\Joomla\Extension
     */
    protected $_extension = null; 
        
    /**
     *
     * @var Installation
     */
    protected $_installation = null;

    /**
     * The list of files and folder to copy to the package.
     * @var array
     */
    protected $_files = [];
    
    /**
     * The list of packages under this manifest.
     * @var array
     */
    protected $_packages = [];
    
    /**
     * The archiver object.
     * @var \Procomputer\Joomla\Model\Archiver $archiver
     */
    protected $_archiver = null;
    
    /**
     * The last error logged. Used to store error type like MISSING_FROM_JOOMLA_INSTALL.
     * @var mixed
     */
    protected $_lastError = 0;

    /**
     * 
     * @var \Procomputer\Joomla\Drivers\Files\FileDriver
     */
    protected $_fileDriver = null;

    /**
     * 
     * @var type
     */
    protected $_packageOptions = [];

    /**
     * 
     * @var array
     */
    public $missingFiles = [];
    
    /**
     * Callback function.
     * @var Closure
     */
    protected $_callback = null;
    
    /**
     * Progress data.
     * @var Procomputer\Joomla\Model\Progress
     */
    protected $_progress;
    
    /**
     * Constructor
     * @param \Procomputer\Joomla\Installation   $installation
     * @param \Procomputer\Joomla\Extension      $extension
     */
    public function __construct(Installation $installation, Extension $Extension, FileDriver $fileDriver) {
        $this->_installation = $installation;
        $this->_extension = $Extension;
        $this->_fileDriver = $fileDriver;
        $this->_progress = new Progress();
    }
    
    /**
     * Returns the progress object.
     * @return \Procomputer\Joomla\Model\Progress
     */
    public function getProgress() {
        return $this->_progress;
    }
    
    /**
     * @param \stdClass $node
     */
    protected function _processSectionFiles($node) {
        /*
        <files folder="site">
            <filename>controller.php</filename>
            <filename>index.html</filename>
            <filename>pccoptionselector.php</filename>
            <folder>controllers</folder>
            <folder>views</folder>
            <folder>models</folder>
        </files>
        */        
        return $this->_processFiles($node, 'files');
    }
    
    /*
    <files folder="site">
        <filename>controller.php</filename>
        <filename plugin="Procomputer">loader.php</filename>
        <filename>index.html</filename>
        <filename>pccoptionselector.php</filename>
        <folder>controllers</folder>
        <folder>views</folder>
        <folder>models</folder>
    </files>
    <files folder="admin">
        <filename>access.xml</filename>
        <filename>autoloader.php</filename>
        <folder>Procomputer</folder>
        <folder>views</folder>
    </files>

    <languages folder="admin">
        <language tag="en-GB">language/en-GB/en-GB.com_pccoptionselector.ini</language>
        <language tag="en-GB">language/en-GB/en-GB.com_pccoptionselector.sys.ini</language>
    </languages>

    <languages folder="site">
        <language tag="en-GB">language/en-GB/en-GB.com_pccoptionselector.ini</language>
    </languages>
    */        
    /**
     * @param \stdClass $node
     */
    protected function _processFiles($node, string $tag = 'Manifest') {
        // Extract 'media' elements (there may be 0 to multiple)
        $groups = $this->manifest->extractGroups($node);
        if(! count($groups)) {
            $this->_packageMessage("{$tag} section is empty");
            return false;
        }
        if(count($groups) > 1) {
            $this->_packageMessage("multiple '{$tag}' elements", false);
        }
        $extensionName = basename(dirname($this->manifestFile));
        $joomlaDir = $this->_installation->webRoot;
        $return = true;
        foreach($groups as $properties) {
            $data = $attribs = null;
            if(is_array($properties) && 2 === count($properties)) {
                list($d, $attribs) = $properties;
                if(is_object($d)) {
                    $a = (array)$d;
                    if(count($a)) {
                        $data = $d;
                    }
                }
            }
            if(empty($data)) {
                $this->_packageMessage("a '{$tag}' element is empty", false);
                continue;
            }
            $destDir = trim($attribs['folder'] ?? '');
            $clientDir = (empty($destDir) || 'site' === $destDir) ? '' : 'administrator';
            // C:\inetpub\joomlapcc\components\com_pccevents\controller.php
            // C:\inetpub\joomlapcc\modules\mod_pccproducts\mod_pccproducts.php
            $extensionType = $this->_extension->getType() . 's';
            $sourceDir = $this->joinPath($joomlaDir, $clientDir, $extensionType, $extensionName);
            if(! is_dir($sourceDir)) {
                $this->_packageMessage("Source folder not found: {$sourceDir}");
                $return = false;
            }
            else {
                // Extract only the elemnts specified in filter and convert to array.
                $files = $this->manifest->extractElements($data, ['filename', 'folder']) ;
                if(empty($files)) {
                    $this->_packageMessage("SKIPPED: {$tag} section contains no file nor folder entries.", false);
                    continue;
                }
                $this->_updateProgress(__FUNCTION__);
                if(false === $this->_addFiles($files, $sourceDir, $destDir, $tag)) {
                    $return = false;
                }
            }
        }
        return $return;
    }
    
    /**
     * 
     * @param \stdClass $node
     * @return bool Returns true if success else false.
     */
    protected function _processSectionMedia($node) {
        /*
        <media destination="com_pccevents" folder="media">
            <filename>index.html</filename>
            <folder>images</folder>
            <folder>css</folder>
        </media>
        */        
        $tag = 'media';
        
        // Extract 'media' elements (there may be 0 to multiple)
        $groups = $this->manifest->extractGroups($node);
        if(! count($groups)) {
            $this->_packageMessage("{$tag} section is empty");
            return false;
        }
        if(count($groups) > 1) {
            $this->_packageMessage("multiple '{$tag}' elements", false);
        }
        $extensionName = basename(dirname($this->manifestFile));
        $joomlaDir = $this->_installation->webRoot;
        $return = true;
        foreach($groups as $properties) {
            if(empty($properties)) {
                continue;
            }
            list($data, $attr) = $properties;
            $attribs = $this->manifest->filterArray($attr, ['folder', 'destination']);
            $folder = $attribs['folder']; // Usually 'media' folder.
            $destination = Types::isBlank($attribs['destination']) ? $extensionName : trim($attribs['destination']) ;
            
            $dir = $this->joinPath($joomlaDir, $folder, $destination); 
            $sourceDir = realpath($dir);
            if(false === $sourceDir || ! is_dir($sourceDir)) {
                $this->_packageMessage("Media folder not found: {$dir}");
                $return = false;
            }
            
            $this->_updateProgress(__FUNCTION__);
            
            // Get just the file-type elements from which to impoer the folders/files.
            $files = $this->manifest->extractElements($data, ['filename', 'folder']) ;
            if(empty($files)) {
                $path = $folder . '/' . $destination;
                $this->_packageMessage("SKIPPED: media section having path '{$path}' contains no file nor folder entries.", false);
                continue;
            }
            if(false === $this->_addFiles($files, $sourceDir, $folder, $tag)) {
                $return = false;
            }
        }
        return $return;
    }

    /*
    <languages folder="admin">
        <language tag="en-GB">language/en-GB.com_pccoptionselector.ini</language>
        <language tag="en-GB">language/en-GB.com_pccoptionselector.sys.ini</language>
    </languages>

    <languages folder="site">
        <language tag="en-GB">language/en-GB.com_pccoptionselector.ini</language>
    </languages>

    <languages folder="site">
        <language tag="en-GB">language/en-GB.mod_pccproducts.ini</language>
        <language tag="en-GB">language/en-GB.mod_pccproducts.sys.ini</language>
    </languages>
    */

    /**
     * @param \stdClass $node
     * @return bool
     */
    protected function _processSectionLanguages($node, string $defaultFolder) {
        $tag = 'languages';
        
        // Extract 'media' elements (there may be 0 to multiple)
        $groups = $this->manifest->extractGroups($node);
        if(! count($groups)) {
            $this->_packageMessage("{$tag} section is empty");
            return false;
        }
        if(count($groups) > 1) {
            $this->_packageMessage("multiple '{$tag}' elements", false);
        }
        $return = true;
        foreach($groups as $properties) {
            if(! is_array($properties) || 2 !== count($properties)) {
                $this->_packageMessage("an empty node encountered in '{$tag}' elements", false);
                continue;
            }
            list($data, $attribs) = $properties;
            $langFolder = strtolower(trim($attribs['folder'] ?? ''));
            if(Types::isBlank($langFolder)) {
                $langFolder = $defaultFolder;
            }
            switch($langFolder) {
            case 'admin':
                // administrator/language/en-GB
                $sourceFolder = 'administrator';
                break;
            case 'site':
                $sourceFolder = '';
                break;
            default:
                $var = Types::getVartype($langFolder);
                $msg = "Unsupported language folder attribute '{$var}': expecting 'admin' or 'site'";
                $this->saveError($msg);
                continue 2;
            }
            $files = $this->manifest->extractElements($data, ['language']);
            if(empty($files)) {
                $var = Types::getVartype($langFolder);
                $msg = "WARNING: No 'language' tags found in '{$tag}' element for folder '{$var}'";
                $this->saveError($msg);
                continue;
            }
            foreach($files as $node) {
                if(empty($node)) {
                    $var = Types::getVartype($langFolder);
                    $msg = "WARNING: a 'language' element in folder '{$langFolder}' is empty";
                    $this->saveError($msg);
                    continue;
                }
                $list = $this->manifest->extractGroups($node);
                foreach($list as $props) {
                    list($propData, $attr) = $props;
                    if(false === $this->_addLanguageFile($propData, $langFolder, $sourceFolder)) {
                        $return = false;
                    }                        
                }
            }
        }
        return $return;
    }
    
    /**
     * 
     * @param array  $fileList
     * @param string $sourceFolder
     * @param string $destFolder
     * @param string $tag
     * @return bool Return true if success else false.
     */
    protected function _addFiles(array $fileList, string $sourceFolder, string $destFolder, string $tag = 'Manifest') {
        $return = true;
        foreach($fileList as $type => $files) {
            if(is_object($files)) {
                $files = (array)$files;
            }
            elseif(is_string($files)) {
                $files = [$files];
            }
            foreach($files as $node) {
                if(is_string($node)) {
                    $file = $node;
                }
                elseif(is_object($node)) {
                    $file = $node->_value ?? '';
                }
                $file = trim($file);
                if(! strlen($file)) {
                    $this->_packageMessage("A {$tag} '{$type}' element has an empty value");
                    $return = false;
                }
                else {
                    $error = false;
                    $sourceFile = $this->joinPath($sourceFolder, $file);
                    if('folder' === $type) {
                        if(! is_dir($sourceFile)) {
                            $return = false;
                            $error = true;
                        }
                    }
                    else {
                        $realpath = realpath($sourceFile);
                        if(! is_file($realpath)) {
                            $return = false;
                            $error = true;
                        }
                    }
                    if($error) {
                        $this->_packageMessage("{$tag} {$type} '{$file}' not found: {$sourceFile}");
                    }
                    else {
                        $destFile = $this->joinPath($destFolder, $file);
                        $this->addFile($sourceFile, $destFile);
                    }
                }
            }
        }
        return $return;
    }
    
    /**
     * 
     * @param type $node
     * @param type $langFolder
     * @param type $sourceFolder
     * @return boolean
     */
    protected function _addLanguageFile($node, $langFolder, $sourceFolder) {
        $file = $node->_value ?? null;
        if(Types::isBlank($file)) {
            $msg = "Language file '_value' property empty in {$langFolder} section";
            $this->saveError($msg);
            return false;
        }
        $pkgFile = $this->joinPath($langFolder, $file);
        
        $nodeAttribs = $this->manifest->extractAttributes($node, ['tag' => '']);
        $locale = trim($nodeAttribs['tag']);
        if(empty($locale)) {
            $msg = "WARNING: Missing 'tag' language attribute";
            $this->saveError($msg);
            $locale = $this->_resolveLocale($file, 'en-GB');
        }
        // C:\inetpub\joomlapcc\administrator\language\en-GB\en-GB.com_pccevents.ini
        // C:\inetpub\joomlapcc\language\en-GB\en-GB.com_pccevents.ini
        $joomlaDir = $this->_installation->webRoot;
        $source = $this->joinPath($joomlaDir, $sourceFolder, 'language', $locale, basename($file));
        if(false === $source || ! $this->_fileDriver->fileExists($source)) {
            $var = Types::getVartype(basename($source));
            $msg = "Language file not found for '{$var}': {$pkgFile}";
            $this->saveError($msg);
            return false;
        }
        
        $this->_updateProgress(__FUNCTION__);

        return $this->addFile($source, $pkgFile);
    }
    
    
    /**
     * 
     * @param type $groups
     * @param array $keys
     * @return bool|string
     */
    protected function _processSections($groups, array $keys) {        
        if(null === $groups) {
            return false;
        }
        $sections = $this->manifest->extractGroups($groups);
        $return = [];
        foreach($sections as $node) {
            foreach($keys as $key) {
                $value = isset($node->{$key}) ? $node->{$key} : null;
                if(! empty($value)) {
                    if(is_string($value)) {
                        $value = trim($value);
                        if(strlen($value)) {
                            $return[$key][] = $value;
                        }
                    }
                    else {
                        $list = $this->manifest->extractGroups($value);
                        foreach($list as $value) {
                            if(is_string($value)) {
                                $value = trim($value);
                                if(strlen($value)) {
                                    $return[$key][] = $value;
                                }
                            }
                            elseif(is_object($value) && isset($value->_value)) {
                                $value = trim($value->_value ?? '');
                                if(strlen($value)) {
                                    $return[$key][] = $value;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $return;
    }

    /**
     * Export tables used by the package into CREATE TABLE statements and INSERT INTO statements.
     * 
     * @return array|boolean Returns array or boolean.<br>
     *      'install'   => array,<br>
     *      'uninstall' => array
     */
    protected function _exportTablesAndData(array $options = null) {
        $dbAdapter = $this->_installation->getDbAdapter();
        if(! is_object($dbAdapter)) {
            $msg = "WARNING: Cannot import data tables as no database adapter is specified in the Joomla Installtion object." 
                 . " The manifest file is: {$this->manifestFile}";
            $this->saveMessage($msg);
            return true;
        }
        /*
        <install>
            <sql><file driver="mysql" charset="utf8">sql/install.mysql.utf8.sql</file></sql>
        </install>
        */
       /* @var $manifest \SimpleXMLElement */
        /** @var Manifest $manifest */
        $manifest = $this->_extension->getManifest()->getData();
        $install = $manifest->install ?? null;
        if($install && isset($install->sql) && isset($install->sql->file)) {
            $installNode = $manifest->install->sql;
        }
        else {
            $installNode = null;
        }
        if(empty($installNode)) {
            $msg = "WARNING: The manifest XML script has no database 'install/sql/file' section." 
                 . " Normally files having CREATE TABLE scripts are specified in this section."
                 . " The manifest file is: {$this->manifestFile}";
            $this->saveMessage($msg);
        }
        $unInstall = $manifest->uninstall ?? null;
        if($unInstall && isset($unInstall->sql) && isset($unInstall->sql->file)) {
            $unInstallNode = $manifest->install->sql;
        }
        else {
            $unInstallNode = null;
            if(! empty($installNode)) {
                $msg = "WARNING: The manifest XML script has no database 'uninstall/sql/file' section." 
                     . " Normally a file having an install section also has an uninstall section"
                     . " The manifest file is: {$this->manifestFile}";
                $this->saveMessage($msg);
            }
        }
        $tablesAndData = [];
        if($installNode) {
            $data = $this->_getDbTablesDropCreateInsert($installNode, 'install');
            if(false === $data) {
                return false;
            }
            if(empty($data)) {
                $data = [];
            }
            $tablesAndData['install'] = $data;
        }
        if($unInstallNode) {
            $data = $this->_getDbTablesDropCreateInsert($unInstallNode, 'uninstall');
            if(false === $data) {
                return false;
            }
            if(empty($data)) {
                $data = [];
            }
            $tablesAndData['uninstall'] = $data;
        }
        return $tablesAndData;
    }

    /**
     * @param \stdClass $node
     * @param string    $label
     * @return array|boolean Return array of SQL [file=>sql] arrays:<br>
     *      'drop' => [uninstallFile => dropTables],<br>
     *      'create' => [$installFile => createTables],<br>
     *      'data' => [dataFile => sampleData]
     * 
     */
    protected function _getDbTablesDropCreateInsert($node, $label) {
        // 
        $obj = isset($node->file) ? $node->file : $node;
        $attr = $this->manifest->extractAttributes($obj);
        $file = isset($obj->_value) ? $obj->_value : null;
        if(empty($file)) {
            $msg = "The {$label} file specified in the manifest missing or the data is corrupt";
            $this->saveError($msg);
            return false;
        }
        $type = $this->_extension->getType();
        // C:\inetpub\joomlapcc\administrator\components\com_osmembership\sql\config.invoice.sql
        $path = $this->joinPath($this->_installation->webRoot, 'administrator', $type . "s", $this->_extensionName, $file);

        if(! $this->_fileDriver->fileExists($path)) {
            $msg = "The database installation file specified in the manifest is not found in the extension: '{$path}'";
            $this->saveError($msg);
            return false;
        }

        $dbTableNames = $this->_parseCreateTableStatements($path);
        if(false === $dbTableNames) {
            return false;
        }
        /**
         * Check for the 'NO DATA' specifier. That is '# __no_data__' in the top of the SQL file.
         */
        if(is_string($dbTableNames) && '__no_data__' === $dbTableNames) {
            return true;
        }
        if(! is_array($dbTableNames) || empty($dbTableNames)) {
            $msg = "WARNING: database table install file has no CREATE TABLE statements: '{$file}'";
            $this->saveError($msg);
            return true;
        }
        
        $installation = $this->getInstallation();
        $exporter = new DbTableExporter();
        $sqlArray = $exporter->export($installation, $dbTableNames);
        if(false === $sqlArray) {
            $this->saveError($exporter->getErrors());
            return false;
        }

        $exportedTablesAndData = [
            'file'   => $path,
            'drop' => isset($sqlArray['drop']) ? $sqlArray['drop'] : [], 
            'create' => isset($sqlArray['create']) ? $sqlArray['create'] : [], 
            'insert' => isset($sqlArray['insert']) ? $sqlArray['insert'] : [], 
        ];
        $statementSeparator = "\n\t\t\t\t\n";
        $installFile = $exportedTablesAndData['file'];
        $dataFile = dirname($installFile) . '/sampledata.mysql.utf8.sql';
        $uninstallFile = dirname($installFile) . '/uninstall.mysql.utf8.sql';
        $dropTables = implode($statementSeparator, $exportedTablesAndData['drop']);
        $createTables = implode($statementSeparator, $exportedTablesAndData['create']);
        $sampleData = implode($statementSeparator, $exportedTablesAndData['insert']);
        $filesAndData = [
            'drop' => [$uninstallFile => $dropTables],
            'create' => [$installFile => $createTables],
            'data' => [$dataFile => $sampleData]
            ];
        return $filesAndData;
    }
    
    /**
     * Writes CREATE TABLE statements and INSERT INTO statements into files in the Joomla! 
     * extension sql folder
     * 
     * @param array $filesAndData 3-element array (see: _exportTablesAndData() above)
     *   [
     *     'file'   => '(full path of install file that initializes the database, creates tables etc.)'
     *     'create' => [array of CREATE TABLE statements], 
     *     'insert' => [array of INSERT INTO statements], 
     *   ]
     */
    public function writeFilesAndData(array $filesAndData) {
        
        foreach($filesAndData as $file => $data) {
            if(empty($data)) {
                // Create empty file.
                fclose(fopen($file, 'w'));
            }
            else {
                try {
//                    if(! FileSystem::filePutContents($data, $file, true)) {
//                        $msg = "Method FileSystem::filePutContents() returned an empty return value.";
//                        throw new \RuntimeException($msg);
//                    }
                } catch (\Throwable $ex) {
                    $this->saveError("Cannot write SQL data to file: " . $ex->getMessage());
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Extract the names of tables having a CREATE TABLE statement in the specified file.
     * 
     * @param string $file The file containing CREATE TABLE statements.
     * 
     * @return array|string|boolean  Returns an array of database tables or '__no_data__' if '__no_data__' 
     *                               found in the file or false on error.
     */
    protected function _parseCreateTableStatements($file) {
        // C:\inetpub\joomlapcc\administrator\components\com_pccoptionselector
        if(! $this->_fileDriver->fileExists($file)) {
            $msg = "File not found: {$file}";
            $this->saveError($msg);
            return false; // Not found.
        }

        $contents = $this->_fileDriver->getFileContents($file);
        if(! strlen(trim($contents))) {
            return [];
        }
        
        if(preg_match('/^#[ \\t]*__no_data__/s', $contents)) {
            return '__no_data__' ;
        }
        elseif(! preg_match_all('/(?:CREATE[ \\t]+TABLE[ \\t]*|CREATE[ \\t]+TABLE[ \\t]+IF[ \\t]+NOT[ \\t]+EXISTS[ \\t]*)`([^`]+)`/s', $contents, $m)
            && ! preg_match_all('(?:CREATE[ \\t]+TABLE[ \\t]*|CREATE[ \\t]+TABLE[ \\t]+IF[ \\t]+NOT[ \\t]+EXISTS[ \\t]*)([^ \\t`\\(]+)/s', $contents, $m)
            ) {
            $msg = "No CREATE TABLES statements found in file: {$file}";
            $this->saveError($msg);
            return false; // Not found.
        }
        if(empty($m[1])) {
            $msg = "WARNING: cannot extract database table name in file: '{$file}'";
            $this->saveError($msg);
            return false; // Not found.
        }
        return $m[1];
    }
 
    /**
     * Extract the names of tables having a DROP TABLE statement in the specified file.
     * 
     * @param string $file The file containing DROP TABLE statements.
     * 
     * @return array|string|boolean  Returns an array of database tables or '__no_data__' if '__no_data__' 
     *                               found in the file or false on error.
     */
    protected function _parseDropTableStatements($file) {
        // C:\inetpub\joomlapcc\administrator\components\com_pccoptionselector
        if(! $this->_fileDriver->fileExists($file)) {
            $msg = "File not found: {$file}";
            $this->saveError($msg);
            return false; // Not found.
        }

        $contents = $this->_fileDriver->getFileContents($file);
        if(! strlen(trim($contents))) {
            return [];
        }
        
        if(preg_match('/^#[ \\t]*__no_data__/s', $contents)) {
            return '__no_data__' ;
        }
        // DROP TABLE IF EXISTS `#__banners`;
        elseif(! preg_match_all('/(?:DROP[ \\t]+TABLE[ \\t]*|DROP[ \\t]+TABLE[ \\t]+IF[ \\t]+EXISTS[ \\t]*)`([^`]+)`/s', $contents, $m)
            && ! preg_match_all('(?:DROP[ \\t]+TABLE[ \\t]*|DROP[ \\t]+TABLE[ \\t]+IF[ \\t]+EXISTS[ \\t]*)([^ \\t`\\(]+)/s', $contents, $m)
            ) {
            // $msg = "No DROP TABLES statements found in file: {$file}";
            // $this->saveError($msg);
            return []; // Not found.
        }
        if(empty($m[1])) {
            $msg = "WARNING: cannot extract database table name in file: '{$file}'";
            $this->saveError($msg);
            return []; // Not found.
        }
        return $m[1];
    }
 
    /**
     * Copies the collected files to a ZIP archive in temporary file.
     * 
     * @param string $file       (optional) ZIP file to open.
     * @param int    $zipOptions (optional) One or mode ZipArchive:: options.
     * @param array  $options    (optional) Option. May include 'callback' => \Closure function.
     * 
     * @return Archiver|boolean Returns the archive object or FALSE on error.
     */
    public function archive($file = null, $zipOptions = null, array $options = null) {
        if(! is_array($options)) {
            $options = [];
        }
        $options['callback'] = function($properties) {
            $this->_updateProgress(__FUNCTION__);
            return true;
        };
        $archiver = $this->getArchiver($file, $zipOptions, $options);
        if(false === $archiver) {
            return false;
        }
        $fileList = $this->getFiles();
        if(! $archiver->addFromFileList($fileList)) {
            $this->saveError($archiver->getErrors());
            return false;
        }
        return true;
    }
    
    /**
     * Extracts the locale from the file basename.
     * @param string $file
     * @param string $defaultLocale
     */
    protected function _resolveLocale($file, $defaultLocale = 'en-GB') {
        // language/en-GB.com_pccoptionselector.ini
        $d = dirname($file);
        $base = ('.' === $d || '..' === $d) ? $file : basename($file);
        if(preg_match('~(.*?)\\.(.*?)\\.ini$~i', $base, $m)) {
            return $m[1];
        }
        return $defaultLocale;
    }
    
    /**
     * Returns this package objects archiver.
     * 
     * @param string $file       (optional) ZIP file to open.
     * @param int    $zipOptions (optional) One or mode ZipArchive:: options.
     * @param array  $options    (optional) Option. May include 'callback' => \Closure function.
     * 
     * @return Archiver|boolean
     */
    public function getArchiver($file = null, $zipOptions = ZipArchive::CREATE | ZipArchive::OVERWRITE, array $options = null) {
        if(null === $this->_archiver) {
            $archiver = new Archiver($this->_installation->getFileDriver(), $options);
            unset($options['callback']);
            if(! $archiver->open($file, $zipOptions, $options)) {
                return false;
            }
            $this->_archiver = $archiver;
        }
        return $this->_archiver;
    }
    
    /**
     * Returns the Joomla installation object.
     * @return Installation
     */
    public function getInstallation() {
        return $this->_installation;
    }
    
    /**
     * Returns the list of files and folder to copy to the package.
     *
     * @return array
     */
    public function getFiles() {
        return $this->_files ;
    }

    /**
     * Adds file or folder to the list of files and folder to copy to the package.
     *
     * @param string $source Source spec.
     * @param string $dest   Dest spec. 
     *
     * @return self|\Procomputer\Joomla\PackageCommon
     */
    public function addFile($source, $dest) {
        // WARNING: Overwrites exising source, dest!
        $this->_files[md5($source . '_' . $dest)] = [$source, $dest];
        return $this ;
    }
    
    /**
     * Returns list of packages under this manifest.
     * @return array
     */
    public function getPackages() {
        return $this->_packages;
    }
    
    /**
     * Returns the last error logged.
     * @return mixed
     */
    public function getLastError() {
        return $this->_lastError;
    }
    
    /**
     * 
     * @param array $manifestElements
     * @return array|true
     */
    public function checkRequiredElementsExist(array $manifestElements) {
        if(empty($manifestElements)) {
            return true;
        }
        /** @var Manifest $manifest */
        $data = $this->manifest->getData();
        $diff = [];
        foreach($manifestElements as $name) {
            $obj = $data->{$name} ?? null;
            $valid = false;
            if(! empty($obj)) {
                if(is_object($obj)) {
                    $groups = $this->manifest->extractGroups($obj);
                    foreach($groups as $group) {
                        if(is_object($group[0])) {
                            $a = (array)$group[0];
                            if(! empty($a)) {
                                $valid = true;
                            }
                        }
                    }
                }
                elseif(strlen(trim($obj))) {
                    $valid = true;
                }
            }
            if(! $valid) {
                $diff[] = $name;
            }
        }
        return empty($diff) ? true : $diff;
    }

    /**
     * Inspect the prepared packages for further processing.
     * @return boolean
     */
    protected function _checkPackage() {
        $valid = true;
        foreach($this->getFiles() as $pair) {
            list($sourceFile, $destFile) = $pair;
            if(! file_exists($sourceFile)) {
                $this->saveError("Source file not found: \n{$sourceFile}");
                $valid = false;
            }
        }
        return $valid;
    }
   
    /**
     * Resolves the component source XML manifest file.
     * @param \SimpleXMLElement $node
     * @param type $errorSource
     * @return boolean
     */
    protected function _resolveSource(\SimpleXMLElement $node) {
        
        $joomlaDir = $this->_installation->webRoot;
        $errorSource = basename($joomlaDir);
        
        // <file type="module" id="pcceventslist" client="site">mod_pcceventslist.zip</file>
        // <file type="component" id="osmembership">com_osmembership.zip</file>
        $attribs = $this->manifest->extractAttributes($node, ['type' => '', 'client' => '', 'id' => '']);
        if(empty($attribs['type'])) {
            $this->_packageMessage("missing 'type' attribute: {$node->asXML()}", true, $errorSource);
            return false;
        }
        $file = (string)$node;
        $extensionName = $filename = pathinfo($file, PATHINFO_FILENAME);
        switch($attribs['type']) {
        case 'component':
            $folder = 'administrator';
            $subFolder = $attribs['type'] . 's';
            $filename = $this->_removeNamePrefix($filename);
            break;
        case 'module':
            $folder = ('admin' === $attribs['client']) ? 'administrator' : '';
            $subFolder = $attribs['type'] . 's';
            break;
        default:
            $this->_packageMessage("unsupported or misspelled 'type' attribute '{$attribs['type']}': \n{$node->asXML()}", true, $errorSource);
            return false;
        }
        $sourceDir = $this->joinPath($joomlaDir, $folder, $subFolder, $extensionName);
        $sourceFile = $this->joinPath($sourceDir, $filename . '.xml');
        if(file_exists($sourceFile) && is_file($sourceFile)) {
            return $sourceFile;
        }
        $this->_packageMessage("{$attribs['type']} manifest file not found in Joomla installation '{$extensionName}':" 
            . " \n{$sourceFile} \n{$node->asXML()}", true, $errorSource);
        $this->_lastError = self::MISSING_FROM_JOOMLA_INSTALL;    
        return false;
    }
    
    /**
     * Add a prefix to a string if not already.
     * @param string $name    Name to which to prepend prefix.
     * @param string $prefix  Prefix to prepend.
     * @return string
     */
    protected function _addNamePrefix($name, $prefix = null) {
        if(! is_string($name) || ! strlen(trim($name))) {
            return $name;
        }
        $pfx = (null === $prefix) ? null : trim((string)$prefix);
        if(empty($pfx)) {
            $pfx = $this->_namePrefix;
        }
        $pattern = '/^[\\s]*' . $pfx . '(.*)$/i';
        return preg_match($pattern, $name) ? $name : ($pfx . $name);
    }

    /**
     * Removes prefix from a string if not already.
     * @param string $name    Name from which to remove prefix.
     * @param string $prefix  Prefix to remove.
     * @return string
     */
    protected function _removeNamePrefix($name, $prefix = null) {
        $pfx = (null === $prefix) ? null : trim((string)$prefix);
        if(empty($pfx)) {
            $pfx = $this->_namePrefix;
        }
        $pattern = '/^[\\s]*' . $pfx . '(.*)$/i';
        $m = [];
        if(preg_match($pattern, $name, $m)) {
            $name = trim($m[1]);
        }
        return $name;
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
                $errorMsg = ': ' . implode(": ",$filesystem->getErrors());
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
            $option = $this->_packageOptions['callback'] ?? null;
            $this->_callback = (! empty($option) && is_callable($option)) ? $option : false;
        }
        return $this->_callback;
    }
    
    /**
     * Set the package options.
     * @param iterable $options
     * @return mixed Returns the option value or the default value.
     */
    protected function getPackageOption($key, $default = null) {
        return isset($this->_packageOptions[$key]) ? $this->_packageOptions[$key] : $default;
    }
    /**
     * Set the package options.
     * @param iterable $options
     * @return $this
     */
    protected function setPackageOptions($options) {
        $opts = $options ?? null;
        if(is_iterable($opts) && count($opts)) {
            $this->_packageOptions = (array)$opts;
        }
        return $this;
    }
    
    /**
     * 
     */
    protected function _updateProgress(string $function) {
        // If X number of seconds elapsed re-open the file driver FTP connection and reset the timer.
        $elapsed = $this->_progress->getInterval(false, __CLASS__ . '::' . $function);
        if($elapsed >= 10 && method_exists($this->_fileDriver, 'reopen')) {
            $this->_fileDriver->reopen();
            $this->_progress->getInterval(); // Reset the timer.
        }
    }
    
    /**
     * Saves a package assembly error.
     * @param string $message The message to store.
     * @return PackageCommon
     */
    protected function _packageMessage($message, bool $isError = true, string $errorSource = null) {
        $source = (null === $errorSource) ? null : trim($errorSource);
        if(empty($source)) {
            $source = empty($this->_extensionName) ? null : $this->_extensionName;
        }
        $source = empty($source) ? '' : " ($source)";
        $file = basename($this->manifestFile);
        if(strlen($file)) {
            $file = ' file ' . $file;
        }
        $msg = "In XML package manifest{$file}{$source}: {$message}";
        $this->saveMessage($msg, $isError);
        return $this;
    }
    
}