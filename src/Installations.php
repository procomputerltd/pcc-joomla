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

use ArrayObject, Throwable;

use Procomputer\Joomla\Drivers\Files\FileDriver;

use Procomputer\Pcclib\Types;

class Installations {
    
    use Traits\Messages;
    use Traits\Environment;
    // use Traits\ErrorHandling;
    use Traits\Files;
    
    /**
     * Folder under which local Joomla! installs exist.
     * @var string
     */
    protected $_webRoot;
    
    /**
     * List of Joomla installations.
     * @var ArrayObject
     */
    protected $_installations = null;

    /**
     * 
     * @var \Procomputer\Joomla\Drivers\Files\System
     */
    protected $_fileDriver = null;
    
    /**
     * 
     * @var PDO
     */
    protected $_dbAdapter = null;
    
    /**
     * Constructor
     * @param string     $webRoot    Root directory under which Joomla install exist.
     * @param FileDriver $fileDriver The file driver object.
     * @param mixed      $dbAdapter  (optional) DB adapter.
     * @return boolean
     */
    public function __construct(string $webRoot, FileDriver $fileDriver, $dbAdapter = null) {
        $root = trim($webRoot, " \n\r\t\v\x00;");
        if(! strlen($root)) {
            $msg = "Missing web root parameter";
            throw new \RuntimeException($msg);
        }
        $this->_webRoot = preg_split('/[\\n\\r;]+/', $root);
        $this->_fileDriver = $fileDriver;
        $this->_dbAdapter = $dbAdapter;
    }
    
    /**
     * Finds Joomla! installs in the local web server e.g. inetpub on windows.
     *   Wnd: C:/inetpub/procomputer/public_html
     *   Nix: /home/procompu/public_html
     * 
     * @return ArrayObject
     */
    public function getInstallations() : mixed {
        if(null === $this->_installations) {
            $installs = $this->findInstallations();
            if(false === $installs) {
                return false;
            }
            $this->_installations = $installs;
        }
        return $this->_installations;
    }
    
    /**
     * Finds Joomla! installs in the local web server e.g. inetpub on windows.
     *   Wnd: C:/inetpub/procomputer/public_html
     *   Nix: /home/procompu/public_html
     * 
     * @return array
     */
    public function getNameList(string $sort = null) {
        $installs = $this->getInstallations();
        if(false === $installs) {
            return false;
        }
        $nameList = [];
        foreach($installs as $obj) {
            /** @var Installation $obj */
            $nameList[$obj->element] = $obj->name;
        }
        if(null !== $sort) {
            $nameList = $this->_sort($nameList, $sort);
        }
        return $nameList;
    }
    
    /**
     * Return Joomla! installation for the specified Joomla! installation name.
     * @param string $idOrName
     * @return Installation
     * @return boolean|\Procomputer\Joomla\Installation
     */
    public function getInstallation(string $idOrName) : mixed {
        $installs = $this->getInstallations();
        if(false === $installs) {
            return false;
        }
        foreach($installs as $installation) {
            /* @var $installation Installation */
            if($installation->element === $idOrName || $installation->name === $idOrName) {
                return $installation;
            }
        }
        return false;
    }
    
    /**
     * Finds Joomla! installs in the local web server e.g. inetpub on windows.
     *   Wnd: C:/inetpub/procomputer/public_html
     *   Nix: /home/procompu/public_html
     * 
     * @return ArrayObject|boolean
     */
    public function findInstallations() : mixed {
        $driver = $this->_fileDriver;
        $items = [];
        foreach($this->_webRoot as $dir) {
            $details = $driver->getDirectoryDetails($dir);
            if(false === $details) {
                $this->saveError($driver->getErrors());
                return false;
            }
            $items[$dir] = $details;
        }
        $folders = [];
        foreach($items as $dir => $details) {
            foreach($details as $info) {
                /* Directory details (all string type):
                    [chmod] => lrwxrwxrwx
                    [num]   => 1
                    [owner] => 787
                    [group] => u288-62k0k
                    [size]  => 13
                    [month] => Jan
                    [day]   => 26
                    [time]  => 2021
                    [name]  => chelanclassic.com -> pccglobal.com
                    [type]  => link
                    [path]  => chelanclassic.com -> pccglobal.com
                */
                // Include only directories.
                $type = $info['type'] ?? null;
                if('dir' !== $type) {
                    continue;
                }
                $folders[] = $this->joinOsPath($dir, $info['path']);
            }
        }
        $hashes = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        $installs = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        foreach($folders as $fullPath) {
            $config = $this->_findConfiguration($fullPath, $hashes);
            if(false === $config) {
                return false;
            }
            if(null === $config) {
                continue;
            }
            
            $names = [];
            if(! empty($config->sitename)) {
                $names[] = $config->sitename;
            }
            $version = $this->_getJoomlaVersion($fullPath);
            if($version) {
                $names[] = 'v' . $version;
            }
            if(count($names) > 1) {
                $names[] = 'source folder: ' . pathinfo($config->__path__, PATHINFO_FILENAME);
            } 
            $name = implode(' - ', $names);
            if(! $installs->offsetExists($name)) {
                $obj = new Installation($name, $config, $config->__path__, $this->_fileDriver, $this->_dbAdapter);
                $installs->offsetSet($name, $obj);
            }
        }
        return $installs;
    }
    
    /**
     * 
     * @param string      $folder
     * @param ArrayObject $hashes
     * @return array|boolean
     */
    protected function _findConfiguration($folder, ArrayObject $hashes) {
        $driver = $this->_fileDriver;
        $folders = $driver->getDirectoryDetails($folder);
        if(false === $folders) {
            $this->saveError($driver->getErrors());
            return false;
        }
        if(! count($folders)) {
            return null;
        }
        $f = $folder;
        if($this->_isWinOs()) {
            $f = strtolower($f);
        }
        $hash = md5($f);
        if($hashes->offsetExists($hash)) {
            return null;
        }
        $hashes->offsetSet($hash, true);
        
        $file = $this->joinPath($folder, 'configuration.php');
        if($driver->fileExists($file)) {
            $config = $this->_loadJoomlaConfig($file);
            if($config) {
                $config->__path__ = $folder;
                return $config;
            }
        }

        foreach($folders as $details) {
            $path = $details['path'];
            // Omit dotfiles, files that begin with a dot.
            $base = ltrim(basename($path));
            if('.' === $base[0]) {
                continue;
            }
            $file = $this->joinPath($path, 'configuration.php');
            if(! $driver->fileExists($file)) {
                continue;
            }
            $config = $this->_loadJoomlaConfig($file);
            if(false === $config) {
                return false;
            }
            if(! empty($config)) {
                $config->__path__ = $path;
                return $config;
            }
            $config = $this->_findConfiguration($path, $hashes);
            if($config) {
                return $config;
            }
        }
        return null;
    }
    
    /**
     * Finds and loads the Joomla configuration file in the specified directory.
     * @param string $configFile The full path of the configuration file.
     * @return array|boolean Returns the Joomla config array or FALSE if not found.
     */
    protected function _loadJoomlaConfig($configFile) {
        if(! $this->_fileDriver->fileExists($configFile) || ! $this->_fileDriver->isFile($configFile)) {
            return null;
        }
        $newClass = "JConfig_" . md5($configFile);
        $contents = $this->_fileDriver->getFileContents($configFile);
        if(false === $contents) {
            $this->saveError($this->_fileDriver->getErrors());
            return false;
        }
        $phpCode = str_replace("class JConfig", "class $newClass", $contents);
        try {
            $newFile = $this->_createTemporaryFile();
        } catch (Throwable $exc) {
            $this->saveError($exc->getMessage());
            return false;
        }
        try {
            $res = $this->putFileContents($newFile, $phpCode);
            include $newFile;
            if(! class_exists($newClass, false)) {
                $this->saveError("Cannot create $newClass for configuration file $configFile");
                // log/sav  e/report error
                return false;
            }
            $config = new $newClass;
        } catch (Throwable $exc) {
            $msg = "Cannot create $newClass for for configuration file $configFile";
            $this->saveError($msg . ': ' . $exc->getMessage());
            return false;
        } finally {
            unlink($newFile);
        }
        // Ensure the config has required properties.
        $requiredProperties = [
            'dbtype',
            'host',
            'user',
            'password',
            'db',
            'dbprefix'
            ];
        foreach($requiredProperties as $key) {
            if(!isset($config->$key)) {
                return null;
            }
        }
        return $config;
    }   

    /**
     * 
     * @param string $rootPath
     */
    protected function _getJoomlaVersion(string $rootPath) {
        // F:\Business\Customers\NorthWing\public_html\libraries\src\Version.php        
        $path = $rootPath . '\libraries\src\Version.php';
        if(! file_exists($path)) {
            return false;
        }
        $contents = @file_get_contents($path);
        if(! is_string($contents) || ! strlen($contents = trim($contents))) { 
            return false;
        }
        $labels = ['MAJOR', 'MINOR', 'PATCH'];
        $ver = array_fill(0, count($labels), 0);
        $pattern = '/const[ \\t]+(' . implode('|', $labels) . ')_VERSION[ \\t]*=[ \\t]*([0-9\\.]+)+/i';
        $num = preg_match_all($pattern, $contents, $m, PREG_SET_ORDER);
        if(false === $num || $num < 2) {
            return false;
        }
        for($i = 0; $i < $num; $i++) {
            if(isset($m[$i])) {
                $num = $m[$i][2];
                if(is_numeric($num)) {
                    $name = strtoupper($m[$i][1]);
                    $index = array_search($name, $labels);
                    if(false !== $index) {
                        $ver[$index] = $num;
                    }
                }
            }
        }
        return implode('.', $ver);
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

