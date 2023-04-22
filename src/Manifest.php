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
    use Traits\XmlJson;

    protected $_manifest = null;
    protected $_manifestFile;
    protected $_attributes;
    protected $lastXmlJsonError = '';
    protected $_throwException = false;
    
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
     * Alias for getProperty()
     * 
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getNode($key, $default = null) {
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
            $base = basename($xmlpath);
            $msg = "Manifest XML file {$base} cannot be loaded: {$this->lastXmlJsonError}";
            if($this->_throwException) {
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
            if($this->_throwException) {
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
        if($this->_throwException) {
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
    
    /**
     * 
     * @param type $data
     * @param array $filter
     * @return type
     */
    public function extractElements($data, array $filter) {
        $return = [];
        foreach($filter as $key) {
            $value = $data->{$key} ?? null;
            if($value) {
                $return[$key] = $value;
            }
        }
        return $return;
    }
    
    /**
     * Extracts groups of nodes from a parent node. If the node contains numeric 
     * index keys (0,1,2,...) it's a multiple node object else it a single object.
     * @param \stdClass $node
     * @return array
     */
    public function extractGroups($node) {
        if(empty($node)) {
            return [];
        }
        $i = '0';
        if(! is_object($node) || ! isset($node->{$i})) {
            $attr = $this->extractAttributes($node);
            return [[$node, $attr]];
        }
        $return = [];
        for($i = 0; $i < 100; $i++) {
            if(! isset($node->{$i})) {
                break;
            }
            $obj = $node->{$i};
            if(! empty($obj)) {
                $attr = $this->extractAttributes($obj);
                $return[] = [$obj, $attr];
            }
        }
        return $return;
    }
        
    /**
     * Extracts element attributes.
     * @param stdClass|array|\SimpleXMLElement $node
     * @param array $defaults
     * @return array
     */
    public function extractAttributes($node, array $defaults = []) {
        $var = '@attributes';
        if(is_array($node)) {
            if(isset($node[$var])) {
                $list = $node[$var];
            }
        }
        elseif($node instanceof \stdClass) {
            if(isset($node->{$var})) {
                $list = $node->{$var};
            }
        }
        elseif(is_object($node) && method_exists($node, 'attributes')) {
            $list = $node->attributes();
        }
        if(isset($list)) {
            foreach($list as $key => $child) {
                $defaults[$key] = (string)$child;
            }
        }
        return $defaults;
    }
    
    /**
     * Filter array elements.
     * @param array $attributes key=>value pairs of attributes.
     * @param array $filter     Key names to filter.
     * @return array
     */
    public function filterArray(array $attributes, array $filter) {
        $return = [];
        foreach($filter as $key) {
            $return[$key] = isset($attributes[$key]) ? $attributes[$key] : '';
        }
        return $return;
    }
    
    /**
     * Converts to an array.
     * @param mixed $node
     * @return array
     */
    protected function _toArray(mixed $node) {
        if(null === $node) {
            return [];
        }
        if(is_array($node)) {
            return $node;
        }
        if(is_scalar($node)) {
            if(! is_string($node)) {
                $node = strval((int)$node);
            }
            $node = trim($node);
            return strlen($node) ? [$node] : [];
        }
        return (array)$node;
    }
    
}
