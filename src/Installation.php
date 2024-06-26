<?php
/*
    Copyright (C) 2019 Pro Computer James R. Steel
    This program is distributed WITHOUT ANY WARRANTY; without
    even the implied warranty of MERCHANTABILITY or FITNESS FOR
    A PARTICULAR PURPOSE. See the GNU General Public License
    for more details.
*/
/*
    Created on  : Jan 30, 2019, 6:58:13 PM
    Organization: Pro Computer
    Author      : James R. Steel
    Description : PHP Software by Pro Computer
*/
namespace Procomputer\Joomla;

use Procomputer\Joomla\Drivers\Files\FileDriver;
use Procomputer\Pcclib\Messages\Messages;
use Procomputer\Pcclib\Types;
use Procomputer\Pcclib\FileSystem;

class Installation {

    use Messages;
    use Traits\XmlJson;
    
    const FILTER_JOOMLA = 1;

    /**
     * The name for this installation e.g. 'PCC Joomla' or 'Product Catalog(JMProductCatalog)'
     * @var string
     */
    public $name = null;

    /**
     * The element for this installation e.g. 'pccjoomla' or 'public_html'
     * @var string
     */
    public $element = null;

    /**
     * The JConfig configuration class object.
     * @var array
     */
    public $config = null;

    /**
     * The joomla installation directory. Examples:
     *   Wnd: C:\inetpub\joomlapcc
     *   Nix: /home/procompu/public_html
     * @var string
     */
    public $webRoot = null;

    /**
     * The joomla extension db data.
     * @var Manifest
     */
    public $manifest = null;

    /**
     * The joomla extension db data.
     * @var array
     */
    public $extensions = null;

    /**
     * 
     * @var Languages
     */
    protected $_languages = null;
    
    /**
     * 
     * @var \Procomputer\Joomla\Drivers\Files\FileDriver
     */
    protected $_fileDriver = null;
    
    /**
     * 
     * @var PDO
     */
    protected $_dbAdapter = null;
    
    /**
     * Constructor.
     * 
     * @param string      $name
     * @param mixed       $config     This may be a JConfig_{hash} object parsed from Joomla configuration.php
     * @param string      $webRoot
     * @param FileDriver  $fileDriver
     * @param mixed       $dbAdapter  (optional)
     */
    public function __construct(string $name, mixed $config, string $webRoot, FileDriver $fileDriver, mixed $dbAdapter = null) {
        $this->element = basename($webRoot);
        $this->name = $name;
        $this->config = $config;
        $this->webRoot = $webRoot;
        $this->_fileDriver = $fileDriver;
        $this->_dbAdapter = $dbAdapter;
    }

    /**
     * Imports a Joomla component into an install-able ZIP file.
     * @param string $extensionName The name of the extension to import e.g. com_procomputercomponent, mod_procomputermodule
     * @param array  $options       (optional) Option. May include 'callback' => \Closure function.
     * @return array|bool Returns array ['contenttype', 'zipfile', 'basename']
     */
    public function importJoomlaComponent(string $extensionName, array $options = []): array|bool {
        
        $package = $this->createPackage($extensionName);
        if(false === $package) {
            return false;
        }

        /** @var \Procomputer\Joomla\PackageComponent $package */
        $success = $package->import($options);
        // Add errors, warnings, messages.
        $this->saveMessage($package->getMessages());
        if(! $success) {
            return false;
        }
        
        $archiver = $package->getArchiver();
        $archiver->close();
        $tempZipFile = $archiver->getZipFile();
        if(! $tempZipFile) {
            $this->saveMessage($archiver->getMessages());
            return false;
        }
        
        $dir = ini_get('upload_tmp_dir');
        if(empty($dir) || ! is_dir($dir)) {
            $var = Types::getVarType($dir);
            $msg = "PHP 'upload_tmp_dir' ini property '{$var}' is not valid. Specify a valid directory in php.ini 'upload_tmp_dir' property.";
            $this->saveMessage($msg);
            return false;
        }
        
        // createTempFile($directory = null, $filePrefix = "pcc", $keep = false, $fileMode = null)
        try {
            $zipFile = FileSystem::createTempFile($dir, 'pcc', true);
            $success = (is_string($zipFile) && is_file($zipFile));
            if(! $success) {
                $msg = "cannot create temporary file in directory: {$dir}";
            }
            else {
                if(! FileSystem::copyFile($tempZipFile, $zipFile, true)) {
                    $msg = "cannot copy temporary file to directory: {$dir}";
                    $success = false;
                }
            }
        } catch (\Throwable $ex) {
            $msg = $ex->getMessage();
            $success = false;
        }
        if(! $success) {
            $this->saveMessage($msg);
            return false;
        }
        
        $extension = $this->getExtension($extensionName);
        $basename = $extension ? ($extension->element ?? null) : null;
        if(! Types::isBlank($basename)) {
            $basename = preg_replace('/^(?:com|mod|plg|pkg)_(.*)$/i', '$1', $basename);
        }
        if(Types::isBlank($basename)) {
            $basename = 'joomla_package';
        }
        $basename .= '.zip';

        $data = [
            'contenttype' => 'application/zip',
            'zipfile' => $zipFile,
            'basename' => $basename,
        ];
        return $data;
    }
    
    /**
     *
     * @param string|array $extensionOrName
     * @return PackageCommon
     */
    public function createPackage($extensionOrName): mixed {
        /**
         * Find the Joomla! extension and return the extension data.
         */
        $jmExtension =  $this->getExtension($extensionOrName);
        if(false === $jmExtension) {
            return false;
        }
        /**
         * 3 Joomla! extension types supported: com_component, mod_module and pkg_package.
         */
        $type = $jmExtension->getType();
        switch($type) {
        case 'package':
            $packageType = '';
            break;
        default:
            $packageType = ucfirst($type);
        }

        $class = 'Procomputer\Joomla\Package' . $packageType;
        $package = new $class($this, $jmExtension, $this->_fileDriver);
        return $package;
    }

    /**
     * 
     * @return Languages
     */
    public function languages(): Languages {
        if(null === $this->_languages) {
            $this->_languages = new Languages($this, $this->_fileDriver);        
        }
        return $this->_languages;
    }
    
    /**
     * Returns the Joomla! extension having the specified name.
     * @param string|array  $spec     The extension name or array.
     * @param array         $options  (optional) Process options.
     * @return Extension|bool
     */
    public function getExtension(string|array $spec, array $options = []): Extension|bool {
        $extensions = $this->getExtensions($options);
        if(false === $extensions) {
            return false;
        }
        $extension = $extensions->get($spec);
        if(false !== $extension) {
            return $extension;
        }
        $this->saveMessage($extensions->getMessages());
        return false;
    }

    /**
     * Finds Joomla! extensions for the specified Joomla! installation.
     * @param array $options (optional) Process options.
     * @return Extensions
     */
    public function getExtensions(array $options = []): Extensions {
        // $lcOptions = $this->_extendOptions($options);
        if(null === $this->extensions) {
            $this->extensions = new Extensions($this->webRoot, $this->config, $this->_fileDriver, $this->_dbAdapter);
        }
        return $this->extensions;
    }

    /**
     * Returns the list of extension names for this installation.
     * @param array $options (optional) Process options.
     * @return array|bool
     */
    public function getExtensionGroups(array $options = []): array|bool {
        $extensions = $this->getExtensions($options);
        if(false === $extensions) {
            return false;
        }
        $extensionGroups = $extensions->getExtensionGroups($options);
        if(false === $extensionGroups) {
            $this->saveMessage($extensions->getMessages());
        }
        return $extensionGroups;
    }

    /**
     * Finds Joomla! package extensions for the specified Joomla! installation.
     * @param array $options (optional) Process options.
     * @return array
     */
    public function getPackages(array $options = []) {
        $lcOptions = $this->_extendOptions($options);
        $lcOptions['type'] = 'package';
        return $this->getExtensions($lcOptions);
    }

    /**
     * Returns the list of extension names for this installation.
     * @param array $options (optional) Process options.
     * @return array
     */
    public function getPackagesNames(array $options = []) {
        $lcOptions = $this->_extendOptions($options);
        $data = $this->getPackages($lcOptions);
        if(empty($lcOptions['sort'])) {
            return $data;
        }
        $return = $this->_sort($data, $lcOptions['sort'], 'packagename');
        return $return;
    }

    /**
     * Returns the file driver.
     * @return FileDriver
     */
    public function getFileDriver(): FileDriver {
        return $this->_fileDriver;
    }

    /**
     * Returns the database adapter.
     * @return \Laminas\Db\Adapter\Platform\Mysql
     */
    public function getDbAdapter(): mixed {
        return $this->_dbAdapter;
    }

    /**
     * Returns the list of extension names for this installation.
     * @param array  $data
     * @param string $sort     (optional) Sort/prioritize on this string key.
     * @param string $propName (optional) 
     * @return type
     */
    protected function _sort(array $data, string $sort, string $propName = '') {
        if(Types::isBlank($sort)) {
            return $data;
        }
        if(Types::isBlank($propName)) {
            $propName = null;
        }
        else {
            $propName = (string)$propName;
        }
        $sort = strtolower($sort);
        $return = [[],[]];
        foreach($data as $key => $item) {
            $value = ($propName && isset($item[$propName])) ? $item[$propName] : (string)$item;
            $index = (false !== strpos(strtolower($value), $sort)) ? 0 : 1;
            if(is_numeric($key)) {
                $return[$index][] = $value;
            }
            else {
                $return[$index][$key] = $value;
            }
        }
        return array_merge($return[0], $return[1]);
    }

    /**
     *
     * @param array $options
     * @return array
     */
    protected function _extendOptions($options) {
        $defaults = [
            'name' => null,
            'type' => null,
            'filter' => self::FILTER_JOOMLA
        ];
        if(! is_array($options)) {
            return $defaults;
        }
        $return = array_merge($defaults, array_change_key_case($options));
        return $return;
    }
}
