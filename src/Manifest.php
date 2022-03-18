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

use Procomputer\Pcclib\Types;

/**
 * Describes a Joomla! Extension manifest whos source is an XML file normally
 * stored in the Joomla! admin folder "administrator/components/com_extension_name"
 */
class Manifest {

    use Traits\Messages;
    use Traits\ExtractAttributes;
    use Traits\XmlJson;

    protected $_manifest = null;
    protected $_manifestFile;
    protected $_attributes;
    protected $lastXmlJsonError = '';
    
    protected $_missingData = "The extension manifest data is missing, has not been initialized. Use parseManifestFile(\$file)"
        . " to initialize the manifest object or specify the file in the constructor.";
    
    /**
     * Constructor.
     * @param string $manifestContents (optional) An XML string associated with a Joomla! extension.
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function __construct(string $manifestContents = null, string $xmlPath = null) {
        if(null !== $manifestContents) {
            $this->parseManifest($manifestContents, $xmlPath);
        }
    }
    
    /**
     * Returns the attributes described by the extension in the 'extension' tag
     * 
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getProperty($key, $default = null) {
        $data = $this->getData();
        return $data->$key ?? $default;
    }
    
    /**
     * Returns the attributes described by the extension in the 'extension' tag 
     * e.g. <extension type="component" version="3.1.0" method="upgrade">
     * @return array
     */
    public function getManifestAttributes() {
        if(null === $this->_manifest) {
            throw new RuntimeException($this->_missingData);
        }
        return $this->_attributes ?? [];
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
        if(null === $this->_manifest) {
            throw new RuntimeException($this->_missingData);
        }
        return $this->_manifestFile;
    }
    
    /**
     * 
     * @return stdClass
     */
    public function getData() {
        if(null === $this->_manifest) {
            throw new RuntimeException($this->_missingData);
        }
        return $this->_manifest;
    }
    
    /**
     * Load a Joomla install XML file into SimpleXMLElement, converts to stdObject and stores to property 'manifest'
     * @param string $manifestContents An XML string associated with a Joomla! extension.
     * @return boolean
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function parseManifest(string $manifestContents, string $xmlpath) {
        $this->_manifest = null;
        $this->_manifestFile = '';
        $this->_attributes = [];
        
        $manifest = $this->xmlLoadString($manifestContents);
        if(false === $manifest) {
            $base = basename($osPath);
            $msg = "Manifest XML file {$base} cannot be loaded: {$this->lastXmlJsonError}";
            if($throwException) {
                throw new \RuntimeException($msg);
            }
            $this->saveError($msg);
            return false;
        }
        
        // <extension type="package" version="2.5.0" method="upgrade">
        $attributes = ['type' => ''];
        $var = '@attributes';
        foreach($manifest->$var as $key => $child) {
            $attributes[$key] = (string)$child;
        }
        $extensionType = $attributes['type'];
        if(empty($extensionType)) {
            $msg = "The package XML file is missing the extension 'type' attribute: expecting a joomla extension type like 'component'";
            $this->saveError($msg);
            if($throwException) {
                throw new \RuntimeException($msg);
            }
            return false;
        }
        
        $this->_manifestFile = $xmlpath;
        $this->_attributes = $attributes;
        
        /* @var $manifest \SimpleXMLElement */
        $object = $this->xmlToObject($manifest);
        if(false !== $object) {
            $this->_manifest = $object;
            return true;
        }
        $this->saveError($this->lastXmlJsonError);
        if($throwException) {
            throw new \RuntimeException($this->lastXmlJsonError);
        }
        return false;
    }
    
    /**
     * Assemble a list of Joomla language files associated with the specified Joomla extension.
     */
    public function getLanguageFiles() {
        /*
            \joomlapcc\language\en-GB\en-GB.com_pccoptionselector.ini
            \joomlapcc\administrator\language\en-GB\en-GB.com_pccoptionselector.sys.ini
            \joomlapcc\administrator\language\en-GB\en-GB.com_pccoptionselector.ini
        */
        $manifestData = $this->getData();
        $langFiles = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
        $files = [
            'admin' => $manifestData->administration->languages ?? null,
            'site' => $manifestData->languages ?? null,
        ];
        foreach($files as $location => $node) { // $location may be 'site' or 'admin
            if(null !== $node) {
                // \joomlapcc\language
                $filenames = [];
                // $parentPath = $this->_resolveAbsPath($node, 'site/language', '');
                $language = $node->language ?? null;
                if($language) {
                    if(isset($language->_value)) {
                        $filenames[] = $this->_resolveFilePath($language->_value);
                    }
                    else {
                        foreach($language as $k => $file) {
                            if(is_string($file)) {
                                $filenames[] = $this->_resolveFilePath($language->_value);
                            }
                            else {
                                if(isset($file->_value)) {
                                    $filenames[] = $this->_resolveFilePath($file->_value);
                                }
                                else {
                                    foreach($file as $k => $path) {
                                        $filenames[] = $this->_resolveFilePath($path);
                                    }
                                }
                            }
                        }
                    }
                }
                $nodeAttrib = $this->extractAttributes($node, ['folder' => '']);
                $folder = $nodeAttrib['folder'] ?? null;
                if(empty($folder)) {
                    $folder = $location;
                }
                $langFiles[$folder]['files'] = $filenames;
            }
        }
        return $langFiles;
    }
    
    protected function _resolveFilePath($path) {
        // admin/languages/en-GB/en-GB.com_osmembership.sys.ini
        // site/languages/en-GB/en-GB.com_osmembership.ini
        $temp = strtolower(str_replace('\\', '/', $path));
        $findReplace = [
            'admin/languages/' => 'language/',
            'site/languages/' => 'language/',
        ];
        $pathLen = strlen($temp);
        foreach($findReplace as $find => $replace) {
            $len = strlen($find);
            if($pathLen > $len && $find === substr($temp, 0, $len)) {
                return $replace . substr($path, $len);
            }
        }
        return $path;
    }
}
