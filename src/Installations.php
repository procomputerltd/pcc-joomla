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
     * 
     * @param Procomputer\Joomla\Drivers\Files\FileDriver $fileDriver
     */
    public function __construct(FileDriver $fileDriver, $dbAdapter = null) {
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
    public function getInstallations() {
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
    public function getNameList($sort = null) {
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
     * 
     * @return Installation
     */
    public function getInstallation($idOrName) {
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
    public function findInstallations() {
        $driver = $this->_fileDriver;
        $webRoot = $driver->getWebServerRootDir();
        if(false === $webRoot) {
            $this->saveError($driver->getErrors());
            return false;
        }
        $details = $driver->getDirectoryDetails($webRoot);
        if(false === $details) {
            return false;
        }
        $folders = [];
        foreach($details as $info) {
            /* $info elements:
                [chmod] => (string) lrwxrwxrwx
                [num] => (string) 1
                [owner] => (string) 787
                [group] => (string) u288-62k0k
                [size] => (string) 13
                [month] => (string) Jan
                [day] => (string) 26
                [time] => (string) 2021
                [name] => (string) chelanclassic.com -> pccglobal.com
                [type] => (string) link
                [path] => (string) chelanclassic.com -> pccglobal.com
            */
            $type = $info['type'] ?? null;
            if('dir' !== $type) {
                continue;
            }
            $folders[] = $info['path'];
        }
        $hashes = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        $installs = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        foreach($folders as $folder) {
            $fullPath = $this->joinPath($webRoot, $folder);
//            $details = $driver->getDirectoryDetails($fullPath);
//            if(false === $details) {
//                continue;
//            }
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
            $names[] = pathinfo($config->__path__, PATHINFO_FILENAME);
            if(count($names) > 1) {
                $names[1] = '(' . $names[1] . ')';
            } 
            $name = implode(' ', $names);
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
        $keys = array_keys($folders);
        $hash = md5(implode('_', $keys));
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

