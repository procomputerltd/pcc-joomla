<?php
/* 
 * Joomla! extension handling functions.
 * 
 * Finds Joomla! component, package and module extensions in the Joomla! installation.
 * 
 * Copyright (C) 2022 Pro Computer James R. Steel <jim-steel@pccglobal.com>
 * Pro Computer (pccglobal.com)
 * Tacoma Washington USA 253-272-4243
 *
 * This program is distributed WITHOUT ANY WARRANTY; without 
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR 
 * A PARTICULAR PURPOSE. See the GNU General Public License 
 * for more details.
 */
namespace Procomputer\Joomla;

use Procomputer\Joomla\Drivers\Files\FileDriver;
use Procomputer\Pcclib\Types;

class Extensions {

    use Traits\Messages;
    use Traits\Database;
    use Traits\XmlJson;
    use Traits\Files;
    
    const FILTER_JOOMLA = 1;
    
    /**
     * Absolute path of joomla installaion.
     * @var string
     */
    protected $_absPath;
    
    /**
     * Configuration values.
     * @var array
     */
    protected $_joomlaConfig;
    
    /**
     * Joomla installation extensions.
     * @var array
     */
    protected $_extensions;
    
    /**
     * Joomla installation extensions.
     * @var array
     */
    protected $_extensionData;
    
    /**
     * 
     * @var \Procomputer\Joomla\Drivers\Files\System
     */
    protected $_fileDriver = null;
    
    /**
     * 
     * @param string     $absPath
     * @param object     $joomlaConfig
     * @param FileDriver $fileDriver
     * @param object     $dbAdapter
     */
    public function __construct($absPath, $joomlaConfig, FileDriver $fileDriver, $dbAdapter = null) {
        $this->_absPath = $absPath;
        $this->_joomlaConfig = $joomlaConfig;
        $this->_fileDriver = $fileDriver;
        if(null !== $dbAdapter) {
            $this->setDbAdapter($dbAdapter);
        }
        // parent::__construct(); ServiceCommon does not have a constructor.
    }
    
    /**
     * Returns a list of all Joomla! extensions.
     * @param array $options (optional) Process options.
     * @return array
     */
    public function getExtensions($options = null) {
        if(null === $this->_extensions) {
            $extensions = $this->_getExtensions($options);
            if(false=== $extensions) {
                return false;
            }
            $this->_extensions = $extensions;
        }
        return $this->_extensions;
    }
    
    /**
     * Returns the list of extension names for this installation.
     * @param array $options (optional) Process options.
     * @return array
     */
    public function getExtensionGroups($options = null) {
        $lcOptions = $this->_extendOptions($options);
        $extensions = $this->getExtensions($lcOptions);
        if(false === $extensions) {
            return false;
        }
        $extensionGroups = [];
        if(count($extensions)) {
            foreach($extensions as $type => $data) {
                foreach($data as $item) {
                    $extensionGroups[$type][$item['element']] = $item['name'];
                }
                if(! empty($lcOptions['sort'])) {
                    $extensionGroups[$type] = $this->_sort($extensionGroups[$type], $lcOptions['sort']);
                }
            }
        }
        return $extensionGroups;
    }

    /**
     * Finds a Joomla! extension and returns the data for the extension
     * @param string $spec The extension to return.
     * @return Extension|boolean
     */
    public function get($spec) {
        $extensionElement = is_array($spec) ? (isset($spec['element']) ? $spec['element'] : null) : $spec;
        if(! is_string($extensionElement) || Types::isBlank($extensionElement)) {
            $var = Types::getVartype($extensionElement);
            $msg = "Joomla! extension invalid: '{$var}'";
            $this->saveError($msg);
            return false;
        }
        $lowerName = strtolower($extensionElement);
        $extensions = $this->getExtensions();
        foreach($extensions as $type => $subArray) {
            foreach($subArray as $properties) {
                /* extensionArray elements: 
                    [type] => (string) component
                    [name] => (string) Event Booking
                    [element] => (string) com_eventbooking
                    [xmlpath] => (string) C:/inetpub\joomlapcc/administrator/components/com_eventbooking/eventbooking.xml
                 */
                if($extensionElement === $properties['element']) {
                    $xmlpath = $properties['xmlpath'];
                    try {
                        $manifestContents = $this->_fileDriver->getFileContents($xmlpath);
                    } catch (\Throwable $exc) {
                        $exc->getTraceAsString();
                        $manifestContents = false;
                    }
                    if(false === $manifestContents) {
                        $this->saveError($this->_fileDriver->getErrors());
                        return false;
                    }
                    $Extension = new Extension($extensionElement, $xmlpath, $this->_fileDriver);
                    if(false === $Extension->parseManifest($manifestContents, $xmlpath)) {
                        $this->saveError($Extension->getErrors());
                        return false;
                    }
                    return $Extension;
                }
            }
        }
        $var = Types::getVartype($spec);
        $msg = "Joomla! extension not found: '{$var}'";
        $this->saveError($msg);
        return false;
    }
    
    /**
     * Finds Joomla! extensions for the specified Joomla! installation.
     * @param array $options (optional) Process options.
     * @return array
     */
    protected function _getExtensions($options = null) {
        // $lcOptions = $this->_extendOptions($options);
        if(null === $this->_extensionData) {
            if($this->haveDatabaseAdapter() && false) {
                $extensions = $this->_getExtensionDataFromDatabase($options = null);
            }
            else {
                $extensions = $this->_getExtensionDataFromAdminFolder($options = null);
            }
            if($this->_extensionData = $extensions);
        }
        return $this->_extensionData;
    }

    /**
     * 
     * @return boolean|string
     */
    protected function _getExtensionDataFromDatabase($options = null) {
        if(! $this->haveDatabaseAdapter()) {
            return false;
        }
        $adapter = $this->getDbAdapter();
        $data = $this->dbReadExtensionData($this->_joomlaConfig);
        if(false === $data) {
            return false;
        }
        $this->_extensionData = $data;
        $extensions = [];
        foreach($this->_extensionData as $extensionArray) {
            $include = true;
            // $extensionArray = array_merge($defaults, $extensionArray);
            // FILTER_JOOMLA means ignore authors matching 'joomla! project'
            if(self::FILTER_JOOMLA === $lcOptions['filter']) {
                $author = $extensionArray['manifest_cache']['author'] ?? null;
                if(! Types::isBlank($author)) {
                    $include = (false === strpos(strtolower($author), 'joomla! project'));
                }
            }
            if($include) {
                /* extensionArray elements:
                    [type] => (string) component
                    [name] => (string) Event Booking
                    [element] => (string) com_eventbooking
                    [xmlpath] => (string) C:/inetpub\joomlapcc/administrator/components/com_eventbooking/eventbooking.xml
                */
                $filename = $extensionArray['manifest_cache']['filename'] ?? null;
                if(! Types::isBlank($filename)) {
                    $element = $extensionArray['element'] ?? null;
                    $type = $extensionArray['type'] ?? null;
                    $name = $extensionArray['name'] ?? null;
                    if(! Types::isBlank($type) && ! Types::isBlank($element) && ! Types::isBlank($name)) {
                        switch($type) {
                        case 'package':
                            $file = 'administrator/manifests/packages/' . $element . '.xml';
                            break;
                        case 'module':
                            $file = 'modules/' . $element . '/' . $element . '.xml';
                            break;
                        case 'component':
                            if(Types::isBlank($filename)) {
                                $filename = substr($element, 4);
                            }
                            $file = 'administrator/components/' . $element . '/' . $filename . '.xml';
                            break;
                        default:
                            $file = null;
                        }
                        if(! Types::isBlank($file)) {
                            // C:\inetpub\joomlapcc\administrator\components\com_pccoptionselector
                            $xmlpath = $this->_absPath . '/' . $file;
                            
                            if($this->_fileDriver->isFile($xmlpath)) {
                                $data = [
                                    'type' => $type,
                                    'name' => $name,
                                    'element' => $element,
                                    'xmlpath' => $xmlpath
                                ];
                                $extensions[$type][] = $data;
                            }
                        }
                    }
                }
            }
        }
        $this->_extensions = $extensions;
        return $extensions;
    }    
    
    /**
     * 
     */
    protected function _getExtensionDataFromAdminFolder($options = null) {
        $joomlaTypes = [
            'component' => [
                'prefix' => 'com_',
                'removeprefix' => true,
                'folders' => true,
                'path' => $this->joinPath($this->_absPath, 'administrator', 'components'),
            ],
            'module' => [
                'prefix' => 'mod_',
                'removeprefix' => false,
                'folders' => true,
                'path' => $this->joinPath($this->_absPath, 'modules'),
            ],
            'package' => [
                'prefix' => 'pkg_',
                'removeprefix' => true,
                'folders' => false,
                'path' => $this->joinPath($this->_absPath, 'administrator', 'manifests', 'packages'),
            ]
        ];
        $driver = $this->_fileDriver;
        $extensions = new \ArrayObject();
        foreach($joomlaTypes as $type => $properties) {
            $componentPath = $properties['path'];
            if(! $driver->isDirectory($componentPath)) {
                $msg = "WARNING: Joomla {$type}s directory not found in path {$componentPath}";
                $this->saveError($msg);
                continue;
            }
            if($properties['folders']) {
                $folders = $driver->getFolders($componentPath);
                if(false === $folders) {
                    $this->saveError($driver->getErrors());
                    return false;
                }
                foreach($folders as $path) {
                    /* extensionArray elements:
                        [type] => (string) component
                        [name] => (string) Event Booking
                        [element] => (string) com_eventbooking
                        [xmlpath] => (string) C:/inetpub\joomlapcc/administrator/components/com_eventbooking/eventbooking.xml

                        C:\inetpub\joomlapcc\administrator\components\com_actionlogs\actionlogs.xml
                    */
                    $filename = pathinfo($path, PATHINFO_FILENAME);
                    $component = preg_replace("/^{$properties['prefix']}(.*)$/i", '$1', $filename);
                    $itemName = $properties['removeprefix'] ? $component : $filename;
                    $xmlpath = $this->joinPath($componentPath, $path, $itemName . '.xml');
                    $extensions[$type][] = [
                        'type' => $component,
                        'name' => $component,
                        'element' => $filename,
                        'xmlpath' => $xmlpath
                    ];
                }
            }
            else {
                $files = $driver->listDirectory($componentPath, $driver->FILE_TYPE);
                if(false === $files) {
                    $this->saveError($driver->getErrors());
                    return false;
                }
                foreach($files as $path) {
                    if('xml' === pathinfo($path, PATHINFO_EXTENSION)) {
                        $basename = pathinfo($path, PATHINFO_BASENAME);
                        if(strlen($basename) > 4 && 'pkg_' === strtolower(substr($basename, 0,4))) {
                            $component = substr($basename, 4);
                            $extensions[$type][] = [
                                'type' => $component,
                                'name' => $component,
                                'element' => $filename,
                                'xmlpath' => $path
                            ];
                        }
                    }
                }
            }
        }
        return $extensions;
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
    
    /**
     * Returns the list of extension names for this installation.
     * 
     * @param array         $data Data to sort.
     * @param string|array  $sort (optional) Sort/prioritize on this string key.
     * @return type
     */
    protected function _sort($data, $sort) {
        if(Types::isBlank($sort)) {
            return $data;
        }
        $sortBy = $sort;
        if(is_array($sortBy)) {
            if(! count($sortBy)) {
                return $data;
            }
        }
        else {
            $sortBy = [$sortBy];
        }
        $sortBy = array_map('strtolower', $sortBy);
        $return = [[],[]];
        foreach($data as $key => $value) {
            if(is_object($value)) {
                /** @var \Procomputer\Joomla\Installation $value */
                $value = $value->name;
            }
            $lcValue = strtolower($value);
            $offset = 1;
            foreach($sortBy as $sort) {
                if(false !== strpos($lcValue, $sort)) {
                    $offset = 0;
                    break;
                }
            }
            if(is_numeric($key)) {
                $return[$offset][] = $value;
            }
            else {
                $return[$offset][$key] = $value;
            }
        }
        return array_merge($return[0], $return[1]);
    }

}
