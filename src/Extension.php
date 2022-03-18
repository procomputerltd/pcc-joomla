<?php
/**
 * Describes a Joomla! Extension manifest whos source is an XML file normally
 * stored in the Joomla! admin folder "administrator/components/com_extension_name"
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

use Procomputer\Joomla\Manifest;

/**
 * Describes a Joomla! Extension and manifest whos source is an XML file normally
 * stored in the Joomla! admin folder "administrator/components/com_extension_name"
 */
class Extension  {
    
    use Traits\ExtractAttributes;
    use Traits\Messages;
    use Traits\Files;

    protected $_allowedFileTypes = ['php', 'phtml', 'xml', 'sql'];
    /**
     * The Joomla! element name e.g. 'com_banners'
     * @var string
     */
    public $element = '';
    
    /**
     * The Joomla! manifest data parse from its XML file.
     * @var \Procomputer\Joomla\Manifest
     */
    protected $_manifest = null;

    /**
     * The Joomla! manifest XML file.
     * @var string
     */
    protected $_xmlPath = null;

    protected $_missingData = "The extension manifest data is missing, has not been initialized. Use parseManifest(\$manifestContents)"
        . " to initialize this extension object or specify the file in the constructor.";

    /**
     * 
     * @var \Procomputer\Joomla\Drivers\Files\System
     */
    protected $_fileDriver = null;
    
    /**
     * Constructor.
     * @param string $elementName      An Joomla! element name e.g. 'com_banners'
     * @param string $fileDriver       An XML file associated with a Joomla! extension.
     * @param string $manifestContents (optional) An XML file associated with a Joomla! extension.
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function __construct(string $elementName, string $xmlPath, $fileDriver, string $manifestContents = null) {
        $this->element = $elementName;
        $this->_fileDriver = $fileDriver;
        $this->_xmlPath = $xmlPath;
        if(null !== $manifestContents) {
            $this->parseManifest($manifestContents, $xmlPath);
        }
    }
    
    /**
     * Returns the extension manifest data object.
     * @return \Procomputer\Joomla\Manifest
     */
    public function getManifest() {
        if(null === $this->_manifest) {
            throw new \RuntimeException($this->_missingData);
        }
        return $this->_manifest;
    }

    /**
     * Alias of getManifest()
     * @return \Procomputer\Joomla\Manifest
     */
    public function getManifestData() {
        return $this->getManifest();
    }
    
    /**
     * Parses data in a XML file to a Manifest object.
     * @param string $manifestContents
     * @param string $xmlpath
     * @return boolean|\Procomputer\Joomla\Manifest

     */
    public function parseManifest(string $manifestContents, string $xmlpath) {
        $this->_manifest = null;
        $manifest = new Manifest();
        $res = $manifest->parseManifest($manifestContents, $xmlpath);
        if(false === $res) {
            $this->saveError($manifest->getErrors());
            return false;
        }
        $this->_manifest = $manifest;
        $this->_xmlPath = $xmlpath;
        return $manifest;
    }
    
    /**
     * Returns the component type that can be 'component', 'module' or 'package'
     * @return string
     */
    public function getType() {
        $attr = $this->getManifestAttributes();
        return $attr['type'] ?? '';
    }
    
    /**
     * Returns the full path of the manifest file.
     * @return string
     */
    public function getManifestFile() {
        return $this->getManifest()->getManifestFile();
    }
    
    /**
     * Returns the attributes described by the extension in the 'extension' tag 
     * e.g. <extension type="component" version="3.1.0" method="upgrade">
     * @return array
     */
    public function getManifestAttributes() {
        return $this->getManifest()->getManifestAttributes();
    }
    
    /**
     * Returns the code files described in the Joomla! manifest.
     * @return array
     */
    public function getCodeFiles() {
        $manifest = $this->getManifest();
        $data = $manifest->getData();
        if(! $data) {
            return false;
        }
        $nodes = [
            $data->scriptfile ?? null,
            $data->files ?? null,
            $data->administration->files ?? null
        ];
        // $componentDir expected to be like: 
        //    Win:     C:\inetpub\joomlapcc\administrator\components\com_pccoptionselector
        //    Non-win: /home/procompu/public_html/administrator/components/com_pccoptionselector
        $manifestPath = $manifest->getManifestFile();
        $componentDir = dirname($manifestPath);
        $extensionName = basename($componentDir); // E.g. com_pccoptionselector
        $dir = strtolower($componentDir);
        while(1) {
            // C:\inetpub\joomlapcc\administrator\components\com_pccoptionselector
            if('administrator' === basename($dir)) {
                $absPath = dirname($dir);
                break;
            }
            $dir = dirname($dir);
            if(empty($dir) || '.' === $dir || '.' === $dir) {
                break;
            }
        }
        if(empty($absPath)) {
            $msg = "Cannot get code files: the installation 'administrator' base directory cannot be resolved.";
            $this->saveError($msg);
            return false;
        }
        
        $storage = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
        // NOTE:
        //   Be sure to include the manifest XML file whose name (e.g. pccoptionselector.xml) 
        //   may not be listed in that manifest file.
        $storage['admin'][] = $manifestPath;
        
        $nameNoPrefix = preg_replace('/^com_(.*)$/i', '$1', trim($extensionName));
        // Add files in the component's plugin directory.
        // EXAMPLE: C:\inetpub\joomlapcc\plugins\pccoptionselector
        $pluginPath = $this->joinOsPath($absPath, 'plugins', $nameNoPrefix);
        if($this->_fileDriver->isDirectory($pluginPath)) {
            $this->_fileDriver->iterateFiles($pluginPath, function($isDir, $path, $fileInfo) use($storage) {
                $storage['admin'][] = $this->fixSlashes($path);
            }); 
        }
        
        $allowedFileTypes = $this->_allowedFileTypes;
        
        // Exclude .ini files, include only the following:
        foreach($nodes as $node) {
            if(is_string($node)) {
                $storage['admin'][] = $this->joinOsPath($componentDir, $node);
            }
            else {
                $attribs = $this->extractAttributes($node, ['folder' => '']);
                if(false === $attribs) {
                    return false;
                }
                if('site' === $attribs['folder']) {
                    $clientDir = '';
                    $clientKey = 'site';
                }
                else {
                    $clientDir = 'administrator';
                    $clientKey = 'admin';
                }
                // C:\inetpub\joomlapcc\components\com_pccevents\controller.php
                $sourceDir = $this->joinOsPath($absPath, $clientDir, 'components', $extensionName);
                $files = $node->filename ?? null;
                if($files) {
                    foreach($files as $k => $file) {
                        $paths = [];
                        if(is_string($file)) {
                            $paths[] = $file;
                        }
                        else {
                            if(isset($file->_value)) {
                                $paths[] = $file->_value;
                            }
                            else {
                                foreach($file as $k => $path) {
                                    $paths[] = $path;
                                }
                            }
                        }
                        foreach($paths as $path) {
                            if(false !== array_search(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $this->_allowedFileTypes)) {
                                $storage[$clientKey][] = $this->joinOsPath($sourceDir, $path);
                            }
                        }
                    }
                }
                $folders = $node->folder ?? null;
                if($folders) {
                    foreach($folders as $k => $folder) {
                        $sourcePath = $this->joinPath($sourceDir, $folder);
                        $this->_fileDriver->iterateFiles($sourcePath, function($isDir, $path, $fileInfo) use($clientKey, $storage, $allowedFileTypes) {
                            if(! $isDir) {
                                // $clientKey, $storage, $this->_allowedFileTypes);
                                $storage[$clientKey][] = $this->_fileDriver->fixSlashes($path);
                            }
                        });    
                    }
                }
            }
        }        
        return $storage;
    }
    
    /**
     * Returns the language files described in the 'language' and 'administrator/language' tags
     * @return array
     */
    public function getLanguageFiles() {
        return $this->getManifest()->getLanguageFiles();
    }
    
}
