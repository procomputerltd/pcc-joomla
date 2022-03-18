<?php
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types;
use Procomputer\Pcclib\PhpErrorHandler;
use Procomputer\Joomla\Drivers\Files\FileDriver;

class Languages {

    use Traits\ExtractAttributes;
    use Traits\Messages;
    use Traits\Files;
    
    /**
     *
     * @var array
     */
    protected $_fileTypes = ['php', 'phtml', 'xml'];

    /**
     *
     * @var array
     */
    protected $_rootDirs = [];

    /**
     *
     * @var array
     */
    protected $_langFiles = [];

    /**
     *
     * @var Installation
     */
    protected $_installation = null;

    /**
     * 
     * @var FileDriver
     */
    protected $_fileDriver = null;
    
    /**
     * Constructor.
     * 
     * @param Installation $installation
     * @param FileDriver   $fileDriver
     */
    public function __construct(Installation $installation, FileDriver $fileDriver) {
        $this->_installation = $installation;
        $this->_fileDriver = $fileDriver;
    }
    
    /**
     * Returns type extensions of files in which to search for language constants.
     * @return array
     */
    public function getFileTypes() {
        return $this->_fileTypes;
    }
    
    /**
     * Sets type extensions of files in which to search for language constants.
     * @param array $types Type extensions of files in which to search for language constants.
     * @return $this
     */
    public function setFileTypes(array $types) {
        $this->_fileTypes = $types;
        return $this;
    }
    
    /**
     * Finds language constants in a Joomla extension.
     *
     * @param string $extensionName The name of the Joomla extension for which to find language constants.
     *
     * @return array
     */
    public function findUnusedLanguageConstants($extensionName) {
        // Get the component extension in the Joomla! installation and parse the XML manifest file.
        $extension = $this->_installation->getExtension($extensionName);
        if(false === $extension) {
            $this->saveError($this->_installation->getErrors());
            return false;
        }
        $manifest = $extension->getManifest();
        if(false === $manifest) {
            $this->saveError($extension->getErrors());
            return false;
        }
        
        $languageFiles = $this->_parseLanguageFiles($manifest);
        if(false === $languageFiles) {
            $this->saveError($manifest->getErrors());
            return false;
        }
        
        $codeFiles = $extension->getCodeFiles();
        if(false === $codeFiles) {
            $this->saveError($extension->getErrors());
            return false;
        }
                
        $this->_findUnusedLanguageConstants($languageFiles, $codeFiles, $extension);
        return $languageFiles;
    }
    
    /**
     * Finds language constants in a Joomla extension.
     *
     * @param string $extensionName The name of the Joomla extension for which to find language constants.
     *
     * @return array
     */
    public function findOrphanedLanguageConstants($extensionName) {
        // Get the component extension in the Joomla! installation and parse the XML manifest file.
        $extension = $this->_installation->getExtension($extensionName);
        if(false === $extension) {
            $this->saveError($this->_installation->getErrors());
            return false;
        }
        $manifest = $extension->getManifest();
        if(false === $manifest) {
            $this->saveError($extension->getErrors());
            return false;
        }
        
        $languageFiles = $this->_parseLanguageFiles($manifest);
        if(false === $languageFiles) {
            $this->saveError($manifest->getErrors());
            return false;
        }
        
        $codeFiles = $extension->getCodeFiles();
        if(false === $codeFiles) {
            $this->saveError($extension->getErrors());
            return false;
        }
                
        $orphanedConstants = $this->_findOrphanedLanguageConstants($languageFiles, $codeFiles, $extension);
        return $orphanedConstants;
    }
    
    /**
     * 
     * @param type $languageFiles
     * @param type $codeFiles
     * @return boolean
     */
    protected function _findOrphanedLanguageConstants(\ArrayObject $languageFiles, \ArrayObject $codeFiles, $extension) {
        $extensionName = strtoupper(($extension instanceof Extension) ? $extension->element : $extension);
        $this->_organizeLanguageConstants($languageFiles, $extensionName);
        
        $m = [];
        $orphanedConstants = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
        foreach($codeFiles as $location => $fileList) {
            if(empty($fileList)) {
                $this->saveMessage("WARNING: \$codeFiles['{$location}'] contains no files");
                continue;
            }
            if(! isset($languageFiles[$location])) {
                $this->saveMessage("WARNING: unexpected location key '{$location}' in \$codeFiles['{$location}']: no language files have location '{$location}'");
                continue;
            }
            $constantList = [];
            foreach($languageFiles[$location] as $iniFile => $properties) {
                foreach($properties['data'][0] as $k => $v) {
                    // Overwrite duplicates.
                    $constantList[$v] = $v;
                }
            }
            if(! count($constantList)) {
                $this->saveMessage("WARNING: .ini file in location '{$location}' contain no constants");
                continue;
            }
            foreach($fileList as $codeFile) {
                if(false !== strpos($codeFile, 'config.xml')) {
                    $break = 1;
                }
                $contents = $this->_getFileContents($codeFile);
                if(false === $contents) {
                    return false;
                }
                if('xml' === strtolower(pathinfo($codeFile, PATHINFO_EXTENSION))) {
                    if(false !== strpos($contents, '<!--')) {
                       $contents = preg_replace('/<!--.*?-->/', '', $contents);
                    }
                }
                $lines = explode("\n", str_replace("\r", "\n", str_replace("\r\n", "\n", $contents)));
                foreach($lines as $lineIndex => $line) {
                    if(preg_match_all('/(?:COM|MOD)_[A-Z_]+/', $line, $m)) {
                        if(empty($m[0])) {
                            continue;
                        }
                        foreach($m[0] as $constant) {
                            if(! isset($constantList[$constant])) {
                                $orphanedConstants[$location][$codeFile][$lineIndex][] = $constant;
                            }
                        }
                    }
                }
            }
        }
        return $orphanedConstants;
    }
    
    /**
     * 
     * @param type $languageFiles
     * @param type $codeFiles
     * @return boolean
     */
    protected function _findUnusedLanguageConstants(\ArrayObject $languageFiles, \ArrayObject $codeFiles, $extension) {
        $extensionName = strtoupper(($extension instanceof Extension) ? $extension->element : $extension);
        $this->_organizeLanguageConstants($languageFiles, $extensionName);
        
        $phpErrorHandler = new PhpErrorHandler();
        
        $found1 = false;
        foreach($languageFiles as $location => $files) {
            if(isset($codeFiles[$location]) && count($codeFiles[$location])) {
                foreach($files as $key => $properties) {
                    $languageDef = $properties['data'];
                    $pattern = chr(1) . '\\W(' . implode('|', $languageDef[0]) . ')\\W' . chr(1);
                    $found = false;
                    foreach($codeFiles[$location] as $file) {
                        $contents = $this->_getFileContents($file);
                        if(false === $contents) {
                            return false;
                        }

                        if('xml' === strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                            if(false !== strpos($contents, '<!--')) {
                               $contents = preg_replace('/<!--.*?-->/', '', $contents);
                            }
                        }
                        $m = [];
                        $res = $phpErrorHandler->call(function()use($pattern, $contents, &$m){
                            return preg_match_all($pattern, $contents, $m);
                        });
                        if(false === $res) {
                            $var = Types::getVartype($file);
                            $this->saveError($phpErrorHandler->getErrorMsg("cannot match language constants to file contents in file: '{$var}'", 'preg_match_all() failed'));
                            return false;
                        }
                        elseif($res > 0) {
                            foreach($m[1] as $constant)  {
                                $offset = array_search($constant, $languageDef[0]);
                                if(false !== $offset) {
                                    unset($languageDef[0][$offset], $languageDef[1][$offset], $languageDef[2][$offset]);
                                    $found = $found1 = true;
                                    if(! count($languageDef[0])) {
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    if($found) {
                        $properties['data'] = $languageDef;
                        $files[$key] = $properties;
                    }
                }
                if($found1) {
                    $languageFiles[$location] = $files;
                }
            }
        }
        return $languageFiles;
    }
    
    /**
     * 
     * @param type $languageFiles
     */
    protected function _organizeLanguageConstants(\ArrayObject $languageFiles, $extensionName) {
        // Remove the constant having the name of the extension, e.g. 'com_banners', as that constant is used by the system.
        foreach($languageFiles as $location => $files) {
            if('admin' !== $location) {
                continue;
            }
            $found = false;
            foreach($files as $key => $properties) {
                $data = $properties['data'];
                $offset = array_search($extensionName, $data[0]);
                if(false !== $offset) {
                    unset($data[0][$offset], $data[1][$offset], $data[2][$offset]);
                    $properties['data'] = $data;
                    $files[$key] = $properties;
                    $found = true;
                }
            }
            if($found) {
                $languageFiles[$location] = $files;
            }
        }
    }

    /**
     * Get a list of .INI language files listed in the component XML manifest.
     * @param Manifest $manifest
     * @return \ArrayObject|boolean
     */
    protected function _parseLanguageFiles(Manifest $manifest) {
        $langFiles = $manifest->getLanguageFiles();
        if(false === $langFiles) {
            return false;
        }
        $return = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
        foreach($langFiles as $location => $properties) {
            $folder = $this->joinPath($this->_installation->webRoot, ('site' === $location) ? '' : 'administrator');
            $fileData = [];
            foreach($properties['files'] as $key => $filename) {
                $path = $this->joinPath($folder, $filename);
                if(! $this->_fileDriver->fileExists($path)) {
                    $this->saveError("Cannot parse .ini file: file not found: {$path}");
                    return false;
                }
                $data = $this->_readIniFile($path);
                if(false === $data) {
                    return false;
                }
                $fileData[$filename]['path'] = $path;
                $fileData[$filename]['data'] = $data;
            }
            $return[$location] = $fileData;
        }
        return $return;
    }

    /**
     * 
     * @param type $results
     */
    protected function _removeUnusedLanguageConstants($results) {
        foreach($results as $filename => $properties) {
            if(count($properties['used'])) {
                $rows = [];
                foreach($properties['used'] as $k => $v) {
                    $rows[] = $k . '="' . $v . '"';
                }
                $data = implode($rows, "\n");
            }
            else {
                $data = "\n";
            }
            // file_put_contents($filename, $data);
        }
    }
    
    /**
     *
     * @param string  $file
     * @return array
     */
    protected function _readIniFile($file, $setOrder = true, $arrayObject = null) {
        $list = (null === $arrayObject) ? [] : $arrayObject;
        if($setOrder) {
            $list[0] = [];
            $list[1] = [];
            $list[2] = [];
        }
        $phpErrorHandler = new PhpErrorHandler();
        $fileData = $phpErrorHandler->call(function()use($file){
            return file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        });
        if(false === $fileData) {
            $this->saveError($phpErrorHandler->getErrorMsg('file read error', 'cannot read language file'));
            return false;
        }
        $lineCount = 1;
        foreach($fileData as $line) {
            // Remove space and chars left by 'file()' function
            $line = trim($line);
            if(! empty($line) && ! preg_match('/^;/', $line)) {
                $values = $phpErrorHandler->call(function()use($line){
                    return parse_ini_string($line);
                });
                if(false === $values) {
                    $this->saveError($phpErrorHandler->getErrorMsg("cannot read language file", "syntax error in line {$lineCount}"));
                    return false;
                }
                foreach($values as $const => $value) {
                    $trimmed = trim($const);
                    if($setOrder) {
                        $list[0][] = $trimmed;
                        $list[1][] = $value;
                        $list[2][] = $lineCount;
                    }
                    else {
                        $list[] = [$trimmed, $value, $lineCount];
                    }
                }
            }
            $lineCount++;
        }
        return $list;
    }
}