<?php
/**
 * @see https://docs.joomla.org/Deploying_an_Update_Server
 */
namespace Procomputer\Joomla;

use Procomputer\Pcclib\Types,
    Procomputer\Pcclib\FileSystem;

class ComponentManager {

    use Traits\Messages;
    use Traits\Files;
    
    const FLAG_IGNORE = 0;
    const FLAG_OVERWRITE = 1;
    const FLAG_PRESERVE = 2;

    const SORT_NONE = 0;
    const SORT_APLHA = 1;
    const SORT_LENGTH = 2;

    const ELM_NAME_SORT = 'chkSort';
    const ELM_NAME_SORT_DECEND = 'chkDescend';
    const ELM_NAME_ID = '__form_submit_indicator__';
    const ELM_NAME_SUBMIT = 'cmdSubmit';

    /**
     * Change these when you change the Joomla Components directory name(s).
     */
    const COMPONENTS_DIRECTORY = 'joomlaComponents';
    const TEMPLATE_DIRECTORY = 'template';
    const DEFAULT_TEMPLATE = 'component_template';

    const REGEX_TAG_PATTERN = '/\{\{(.*?)\}\}/';

    /**
     * Tag name=>value pairs.
     * @var array
     */
    protected $_tagProperties = null;

    /**
     * Tag replacement values temporary storage.
     * @var array
     */
    protected $_tagReplacements = null;

    protected $_temp;

    /**
     * Tags and associated requirements
     * @var array
     */
    protected $_properties = [
    'com_adapted_by' => false,
    'com_author' => true,
    'com_author_email' => true,
    'com_author_url' => true,
    'com_copyright' => true,
    'com_creation_date' => true,
    'com_description' => true,
    'com_display_name' => true,
    'com_gpl_license' => true,
    'com_name' => true,
    'com_name_sentencecase' => true,
    'com_since_version' => true,
    'com_update_server' => true,
    'com_version' => true,
    'com_name_joomla_version' => true,

    'com_update_1_version' => true,
    'com_update_1_info_url' => true,
    'com_update_1_maintainer' => true,
    'com_update_1_maintainer_url' => true,
    'com_update_1_target_platform' => true,
    'com_update_1_download_url' => true,
    'com_update_1_section' => false,
    'com_update_1_tags' => false,

    'com_update_2_version' => true,
    'com_update_2_info_url' => true,
    'com_update_2_maintainer' => true,
    'com_update_2_maintainer_url' => true,
    'com_update_2_target_platform' => true,
    'com_update_2_download_url' => true,
    'com_update_2_section' => false,
    'com_update_2_tags' => false,
    ];

    /**
     * Creates Joomla component directories and files and insert properties into embedded {{tags}}
     * @param array|\Traversable $formData
     * @param array|\Traversable $options
     * @return boolean
     */
    public function createComponent($formData, $options = null) {

        $lcOptions = (null === $options) ? [] : array_change_key_case((array)$options);

        /**
         * Check for required values.
         */
        foreach($this->_properties as $name => $required) {
            if(! isset($formData[$name]) || null === $formData[$name]) {
                $msg = "The '{$name}' form element name is missing from the form data.";
                $this->saveError($msg);
                return false;
            }
            else {
                $trimmed = trim((string)$formData[$name]);
                if($required && ! strlen($trimmed)) {
                    $msg = "The REQUIRED '{$name}' form element value is missing.";
                    $this->saveError($msg);
                    return false;
                }
            }
        }

        $extensionName = $this->validateExtensionName($formData['com_name'], true);
        if(false === $extensionName) {
            return false;
        }
        $displayExtensionName = $this->validateExtensionName($formData['com_display_name'], true);
        if(false === $displayExtensionName) {
            return false;
        }
        $sentenceExtensionName = $this->validateExtensionName($formData['com_name_sentencecase'], true);
        if(false === $sentenceExtensionName) {
            return false;
        }

        $joomlaExtensionName = $this->_addNamePrefix($extensionName);

        $outputDirectory = isset($lcOptions['outputdir']) ? trim((string)$lcOptions['outputdir']) : null;
        if(empty($outputDirectory)) {
            $outputDirectory = $this->getDefaultOutputDirectory();
        }
        if(! is_string($outputDirectory) || ! is_dir($outputDirectory)) {
            $var = Types::getVartype($outputDirectory);
            $msg = "Output directory parameter not a directory:\n'{$var}'";
            $this->saveError($msg);
            return false;
        }

        // Assemble the replacement expressions into $this->_tagReplacements
        $formData = $this->_prepareTagReplacements($formData) ;

        $overwrite = isset($lcOptions['overwrite']) ? (bool)$lcOptions['overwrite'] : false;

        if(! $this->_processTemplateFiles($outputDirectory, $joomlaExtensionName, $overwrite)) {
            return false;
        }

        $componentDir = $this->joinPath($outputDirectory, $joomlaExtensionName);

        if(false === $this->_replaceComponentTags($componentDir, $extensionName)) {
            return false;
        }

        // ToDo: write code to replace template tags.
        return $componentDir;
    }

    /**
     * Creates Joomla component directories and files and insert properties into embedded {{tags}}
     * @param string  $outputDirectory     Directory to accept the component files.
     * @param string  $joomlaExtensionName The Joomla component name like 'com_somecomponent'
     * @param boolean $overwrite           When true files and directories are overwritten.
     * @return boolean
     */
    protected function _processTemplateFiles($outputDirectory, $joomlaExtensionName, $overwrite = false) {

        $componentDir = $this->joinPath($outputDirectory, $joomlaExtensionName);
        if(file_exists($componentDir) && ! is_dir($componentDir)) {
            $var = Types::getVartype($componentDir);
            $this->saveError("Output file is not a director:\n'{$var}'");
            return false;
        }

        // C:/inetpub/framework/joomlaComponents/component_template
        $templateDir = $this->getComponentsTemplateDirectory();
        // Output dir must not be inside the template dir.
        $isInside = $this->comparePaths($templateDir, $outputDirectory);
        if(false === $isInside) {
            return false;
        }
        if($isInside) {
            $var = Types::getVartype($outputDirectory);
            $this->saveError("Output directory must not be inside source directory:\n'{$var}'");
            return false;
        }
        if(file_exists($componentDir)) {
            if(! $overwrite) {
                $var = Types::getVartype($componentDir);
                $this->saveError("Component '{$joomlaExtensionName}' exists in the component directory and overwrite option is FALSE\n'{$var}'");
                return false;
            }
            if(! $this->_removeDir($componentDir, true)) {
                return false;
            }
        }
        if(false === $this->_copyDirectory($templateDir, $componentDir)) {
            return false;
        }
        return true;
    }

    /**
     *
     * @param string $outputDirectory
     * @param string $extensionName
     * @return boolean|\ArrayObject
     */
    public function _replaceComponentTags($outputDirectory, $extensionName) {
        $fs = new FileSystem();
        $scanFiles = $fs->scanFiles($outputDirectory, FileSystem::INCLUDE_DIRECTORY_CHILDREN);
        if(false === $scanFiles) {
            $this->saveError($fs->getMessages());
            return false;
        }
        if(! is_array($scanFiles) || empty($scanFiles)) {
            $this->saveError("No files found in directory: '{$outputDirectory}'");
            return false;
        }
        $componentTag = 'component_name';
        $directories = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS) ;
        if(false === $this->_renameComponentFilesRecurse($scanFiles, $extensionName, $componentTag, $directories, $outputDirectory)) {
            return false;
        }
        foreach($directories as $paths) {
            list($oldPath, $newPath) = $paths;
            $res = $this->_rename($oldPath, $newPath);
            if(false === $res) {
                return false;
            }
        }
    }
    protected function _renameComponentFilesRecurse(array $sourceFiles, $extensionName, $componentTag,
        \ArrayObject $directories, $parentDir) {
        foreach($sourceFiles as $name => $file) {
            if(is_array($file)) {
                if(count($file)) {
                    if(false === $this->_renameComponentFilesRecurse($file, $extensionName, $componentTag,
                        $directories, $this->joinPath($parentDir, $name))) {
                        return false;
                    }
                    if(false !== strpos($name, $componentTag)) {
                        $newName = str_replace($componentTag, $extensionName, $name);
                        $oldPath = $this->joinPath($parentDir, $name);
                        $newPath = $this->joinPath($parentDir, $newName);
                        $directories[] = [$oldPath, $newPath];
                    }
                }
            }
            else {
                $basename = basename($file);
                if(false !== strpos($basename, $componentTag)) {
                    $newBase = str_replace($componentTag, $extensionName, $basename);
                    $newFile = $this->joinPath(dirname($file), $newBase);
                    $res = $this->_rename($file, $newFile);
                    if(false === $res) {
                        return false;
                    }
                    $file = $newFile;
                }
                $data = $this->_getFileContents($file);
                if(false === $data) {
                    return false;
                }
                if(false !== strpos($data, '{{')) {
                    $newData = preg_replace($this->_tagReplacements[0], $this->_tagReplacements[1], $data);
                    if(false === $newData) {
                        $this->saveError("preg_replace() failed in line " . __LINE__ . " file " . __FILE__);
                        return false;
                    }
                    if(strcmp($data, $newData)) {
                        $res = $this->callFuncAndSavePhpError(function()use($file, $newData){return file_put_contents($file, $newData);});
                        if(false === $res) {
                            return false;
                        }
                    }
                }
            }
        }
        return true;
    }

    protected function _prepareTagReplacements(array $formData) {
        /**
         * Prepare the special tags.
         */
        $extensionName = trim($formData['com_name']);
        $lowerName = strtolower($extensionName);
        $sentenceName = trim($formData['com_name_sentencecase']);
        $formData['com_name_ucfirst'] = ucfirst($extensionName);
        $formData['com_name_lowercase'] = $lowerName;
        $formData['com_name_uppercase'] = strtoupper($extensionName);
        $formData['com_name_camelcased'] = lcfirst($sentenceName);
        $formData['com_phpdoc_file_header'] = '';
        $tableName = trim($formData['com_name_db_table']);
        if(0 !== strpos($tableName, $lowerName . '_')) {
            $tableName = strtolower($extensionName) . '_' . $tableName;
        }
        $formData['com_name_db_table'] = $tableName;

        // List the phpDocumentor Tags
        $phpDocTags = [
            'package' => $formData['com_display_name'],
            // 'subpackage' => '',
            'author' => $formData['com_author'],
            'copyright' => $formData['com_copyright'],
            'license' => $formData['com_gpl_license']
        ];
        foreach($phpDocTags as $tag => $value) {
            $phpDocTags[$tag] = str_pad(" * @" . $tag, 18) . $value;
        }
        $formData['com_phpdoc_file_header'] = PHP_EOL . "/**" . PHP_EOL . implode(PHP_EOL, $phpDocTags) . PHP_EOL . " */" . PHP_EOL ;

        $this->_tagReplacements = [];
        foreach($formData as $name => $value) {
            $this->_tagReplacements[0][] = '/\{\{' . preg_quote($name, '/') . '\}\}/';
            $this->_tagReplacements[1][] = $value;
        }
        return $formData;
    }

    /**
     * Check a component for missing folders and/or files.
     * @param string $extensionName
     * @return boolean|array
     */
    public function checkForMissingFoldersAndFiles($extensionName) {
        //
        if(! is_string($extensionName) || ! strlen(trim($extensionName))) {
            $var = Types::getVartype($extensionName);
            $msg = "The componet name parameter '{$var}' is invalid.";
            $this->saveError($msg);
            return false;
        }
        $componentDir = $this->getComponentsDirectory();
        $templateDir = $this->getComponentsTemplateDirectory();
        $items = [
            $componentDir . '/' . $extensionName,
            $templateDir . '/component_template'
            ];
        if(! is_dir($componentDir)) {
            $var = Types::getVartype($extensionName);
            $msg = "Directory for component '{$var}' not found\n{$componentDir}";
            $this->saveError($msg);
            return false;
        }
        if(! is_dir($templateDir)) {
            $var = Types::getVartype($templateDir);
            $msg = "Template directory not found:\n{$var}";
            $this->saveError($msg);
            return false;
        }
        $dirsOnly = [];
        $fullPaths = [];
        $fs = new FileSystem();
        foreach($items as $key => $dir) {
            $scanFiles = $fs->scanFiles($dir, FileSystem::INCLUDE_DIRECTORY_CHILDREN);
            if(false === $scanFiles) {
                return false;
            }
            $list = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
            $this->_getScanFilesListRecurse($scanFiles, $list);
            $array = array_values((array)$list);
            $fullPaths[$key] = $array;

            $this->_temp = strlen($dir);
            array_walk($array, function(&$v){
                $v = str_replace('{{com_name}}', 'helloworld', substr($v, $this->_temp));
            });

            $dirsOnly[$key] = $array;
        }

        foreach($dirsOnly as $key => $array) {
            $dirKey = 'Files in [' . basename($items[$key]) . '] not found in [' . basename($items[1-$key]) . ']';
            $diff[$dirKey] = [];
            foreach($array as $subKey => $dir) {
                if('index.html' !== basename($dir)) {
                    if(false === array_search($dir, $dirsOnly[1-$key])) {
                        $diff[$dirKey][] = $fullPaths[$key][$subKey];
                    }
                }
            }
        }

        $results = [];
        $emptyFilesKey = 'Empty files found';
        foreach($fullPaths[1] as $file) {
            if(! is_file($file) || ! filesize($file)) {
                $empty = true;
            }
            else {
                $data = file_get_contents($file, false, null, 0, 8192);
                $empty = ! strlen(trim($data));
            }
            if($empty) {
                $results[$emptyFilesKey][] = $file;
            }
        }
        if(empty($results[$emptyFilesKey])) {
            $results['Empty files'] = "No empty files found";
        }
        foreach($diff[$dirKey] as $array) {
            if(! empty($array)) {
                $results[$dirKey] = $diff[$dirKey];
            }
        }
        return $results;
    }
    /**
     *
     * @param array        $dirs
     * @param \ArrayObject $list
     */
    protected function _getScanFilesListRecurse($dirs, $list)  {
        foreach($dirs as $name => $item) {
            if(is_array($item)) {
                foreach($item as $key => $file) {
                    if(is_array($file)) {
                        $this->_getScanFilesListRecurse($file, $list);
                    }
                    else {
                        $list[$file] = $file;
                    }
                }
            }
            else {
                $list[$item] = $item;
            }
        }
    }

    public function createTmplFoldersAndFiles($options = null) {
        if(is_bool($options)) {
            $overwrite = $options;
        }
        else {
            $lcOptions = (null === $options) ? [] : array_change_key_case((array)$options);
            $overwrite = (isset($lcOptions['overwrite']) && $lcOptions['overwrite']) ? true : false;
        }
        $indexHtml = '<html><body bgcolor="#FFFFFF"></body></html>';
        $rootDir = $this->getComponentsTemplateDirectory() .  '/com_helloworld';
        if(is_dir($rootDir)) {
            if(! $overwrite) {
                $msg = "Cannot create template from Joomla documentation: directory exists and overwrite-allow option is OFF";
                $this->saveError($msg);
                return false;
            }
            if(! $this->rrmdir($rootDir)) {
                return false;
            }
        }

        $res = _mkdir($rootDir, 0777, true);
        if(! $res) {
            return false;
        }

        $rootDir .= '/';
        $imageDir = $this->joinPath($this->getComponentsTemplateDirectory(), 'media/images');
        $templateFiles = $this->_getJoomlaTutorialFiles();
        $downloadErrors = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
        foreach($templateFiles as $file => $properties) {
            list($id, $url) = $properties;
            $path = $rootDir . $file;
            $basename = basename($path);
            $dir = dirname($path);
            if($this->_isImageType($basename)) {
                copy($this->joinPath($imageDir, $basename), $path);
            }
            else {
                if(! is_dir($dir)) {
                    $res = $this->_mkdir($rootDir, 0777, true);
                    if(! $res) {
                        return false;
                    }
                }
                $isIndexHtml = false;
                if(false !== strpos(strtolower($basename), 'html')) {
                    if('index.html' === $basename) {
                        file_put_contents($path, $indexHtml);
                        $isIndexHtml = true;
                    }
                }
                if(! $isIndexHtml) {
                    //
                    // https://docs.joomla.org
                    // helloworld.xml
                    $fullUrl = 'https://docs.joomla.org' . $url;
                    $code = $this->_downloadTutorialCode($fullUrl, $id, $downloadErrors);
                    if(false === $code) {
                        return false;
                    }
                    if(null !== $code) {
                        file_put_contents($path, $code);
                    }
                    else {
                        $handle = $this->_openFile($path, 'w');
                        if(! $handle) {
                            return false;
                        }
                        $this->callFuncAndSavePhpError(function()use($handle){return fclose($handle);});
                    }
                }
            }
        }
        $this->_downloadErrors = (array)$downloadErrors;
        return true;
    }

    /**
     * Removes PhpDOC headers from PHP files under the specified directory and sub-dirs.
     * @param string $directory Root directory under which to process files.
     * @return \ArrayObject|boolean Returns list of files or FALSE on error.
     */
    public function removeHeaders($directory) {
        $fs = new FileSystem();
        $scanFiles = $fs->scanFiles($directory, FileSystem::INCLUDE_DIRECTORY_CHILDREN);
        if(false === $scanFiles) {
            $this->saveError($fs->getMessages());
            return false;
        }
        if(! is_array($scanFiles) || empty($scanFiles)) {
            $this->saveError("No files found in directory: '{$directory}'");
            return false;
        }
        $pattern = '~^[\s]*\<\?php[\s]+/\*\*.*@package.*?\*/[\s]*(.*)~s';
        $list = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS) ;
        if(false === $this->_removeHeadersRecurse($scanFiles, $list, $pattern)) {
            return false;
        }
        return $list;
    }
    protected function _removeHeadersRecurse(array $sourceFiles, \ArrayObject $list, $pattern) {
        foreach($sourceFiles as $name => $file) {
            if(is_array($file)) {
                $this->_removeHeadersRecurse($file, $list, $pattern);
            }
            else {
                $data = $this->_getFileContents($file, 0x1ffff);
                if(false === $data) {
                    return false;
                }
                if(preg_match($pattern, $data, $m)) {
                    $data = "<?php\n" . $m[1];
                    if(false === $this->_putFileContents($file, $data)) {
                        return false;
                    }
                    $list[] = $file;
                }
            }
        }
        return true;
    }

    protected function _downloadTutorialCode($url, $id, $downloadErrors) {
        // 'helloworld.xml' => "/Special:MyLanguage/J3.2:Developing_an_MVC_Component/Adding_a_front-end_form#helloworld.xml",
        $data = $this->_getFileContents($url);
        if(false === $data) {
            return false;
        }
        $offset = strpos($data, "<span id=\"{$id}\">");
        if($offset) {
            $partial = substr($data, $offset, 512);
        }
        else {
            $break = 1;
        }
        // <span id="site.2Fhelloworld.php"><b>site/helloworld.php</b></span>
        $template =
              '<span__SPC_PLUS__id="__ID__"__SPC__>'
            . '__ANYF__<div__SPC_PLUS__class="mw-highlight mw-content-ltr" dir="ltr">'
            . '__SPC__<pre>__ANYFP__</pre>';
        $pattern = $this->_preparePattern($template, $id);

        $match = preg_match($pattern, $data, $m);
        if($match) {
            $preCode = $m[1];
            $len = strlen($preCode);
            $partial = substr($preCode, 0, 1024);
        }
        if(! $match) {
            $downloadErrors[] = "Code not found matching file ID '{$id}'";
            return null;
        }
        $code = $this->_decodeHtml($preCode);
        return $code;
    }

    protected function _decodeHtml($html) {
        $code = html_entity_decode(strip_tags($html));
        $code = str_replace('&#39;', '"', $code);
        return $code;

        $lines = $this->_parseLines($html);
        $replace = [
            // <span class="sd">
            '~\<span\s+class\s*=\s*"[\w*]"\s*\>\s*(.*?)\</span\>~i' => '$1',
            '~\<span class="nt"\>~i' => '',
            '~\</span\>~is' => ''
        ];
        foreach($lines as $k => $line) {
            foreach($replace as $pattern => $value) {
                $lines[$k] = preg_replace($pattern, $value, $line);
            }
        }
        $result = implode("\n", $lines);
        $code = html_entity_decode($html);
        return $code;
    }

    protected function _preparePattern($template, $id, $modifier = 's', $tail = '', $delimiter = '~') {
        $replace = [
            '__SPC__'       => '[\\s]*',
            '__SPC_PLUS__'  => '[\\s]+',
            '__ANY__'       => '.*',
            '__ANYF__'      => '.*?',
            '__ANYP__'      => '(.*)',
            '__ANYFP__'     => '(.*?)',
            '__ID__'        => preg_quote($id)
        ];
        $pattern = preg_quote($template, $delimiter);
        foreach($replace as $tag => $value) {
            $pattern = str_replace($tag, $value, $pattern);
        }
        $pattern = $delimiter . $pattern . $tail . $delimiter . $modifier;
        return $pattern;
    }

    protected function _parseLines($str) {
        $lines = explode("\n", str_replace("\r", "\n", str_replace("\r\n", "\n", $str)));
        return $lines;
    }

    /**
     * Finds unique '{{tags}}' in files in a directory and sub-directories.
     * @param string $directory Directory in which to find tags.
     * @return boolean
     */
    public function findTags($directory, $options = null) {
        $lcOptions = (null === $options) ? [] : array_change_key_case((array)$options);

        $fs = new FileSystem();
        $scanFiles = $fs->scanFiles($directory, FileSystem::INCLUDE_DIRECTORY_CHILDREN);
        if(false === $scanFiles) {
            $this->saveError($fs->getMessages());
            return false;
        }
        if(! is_array($scanFiles) || empty($scanFiles)) {
            $this->saveError("No files found in directory: '{$directory}'");
            return false;
        }

        $tagList = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
        $includeFile = (isset($lcOptions['includefile']) && $lcOptions['includefile'])
                    || (isset($lcOptions['includefiles']) && $lcOptions['includefiles']);
        if(false === $this->_findTagsRecurse($scanFiles, $tagList, $includeFile)) {
            return false;
        }

        /**
         * When including files in the result just return the files and associated tags.
         */
        if($includeFile) {
            return $tagList->count() ? $tagList : [];
        }

        if(! $tagList->count()) {
            return isset($lcOptions['list']) ? '' : [];
        }

        if(isset($lcOptions['sort'])) {
            switch($lcOptions['sort']) {
            case self::SORT_APLHA:
                $tagList->asort();
                break;
            case self::SORT_LENGTH:
                $tagList->uasort(function($a,$b){return strlen($b)-strlen($a);});
                break;
            // default: // SORT_NONE
            }
        }
        return (array)$tagList;
    }

    protected function _findTagsRecurse(array $sourceFiles, \ArrayObject $tagList, $includeFile = false) {
        foreach($sourceFiles as $name => $file) {
            if(is_array($file)) {
                $this->_findTagsRecurse($file, $tagList, $includeFile);
            }
            else {
                $data = $this->_getFileContents($file);
                if(false === $data) {
                    return false;
                }
                if(preg_match_all(self::REGEX_TAG_PATTERN, $data, $m)) {
                    $tags = array_map('trim', $m[1]);
                    foreach($tags as $tag) {
                        if(strlen($tag)) {
                            // Omit duplicates, unique items only
                            if($includeFile) {
                                $tagList[$file][$tag] = $tag;
                            }
                            else {
                                $tagList[$tag] = $tag;
                            }
                        }
                    }
                }
            }
        }
        return true;
    }

    /**
     * Finds files containing both tag open braces '{{' and closing braces '}}'
     * @param string $directory Root directory under which to scan files.
     * @return \ArrayObject Returns list of files.
     */
    public function findFilesWithTags($directory) {
        $fs = new FileSystem();
        $scanFiles = $fs->scanFiles($directory, FileSystem::INCLUDE_DIRECTORY_CHILDREN);
        if(false === $scanFiles) {
            $this->saveError($fs->getMessages());
            return false;
        }
        if(! is_array($scanFiles) || empty($scanFiles)) {
            $this->saveError("No files found in directory: '{$directory}'");
            return false;
        }
        $list = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS) ;
        if(false === $this->_findFilesWithTagsRecurse($scanFiles, $list)) {
            return false;
        }
        return $list;
    }
    protected function _findFilesWithTagsRecurse(array $sourceFiles, \ArrayObject $list) {
        foreach($sourceFiles as $name => $file) {
            if(is_array($file)) {
                $this->_findFilesWithTagsRecurse($file, $list);
            }
            else {
                $data = $this->_getFileContents($file, 0x1ffff);
                if(false === $data) {
                    return false;
                }
                if(false !== strpos($data, '{{') && false !== strpos($data, '}}')) {
                    $list[] = $file;
                }
            }
        }
        return true;
    }

    /**
     * Returns the component properties.
     * @return array
     */
    public function getProperties() {
        return $this->_properties;
    }

    /**
     * Validate a component name.
     * @param string  $name        Component name
     * @param boolean $stripPrefix (optional) Remove leading 'com_' prefix if found.
     * @throws \RuntimeException
     * @return string
     */
    public function validateExtensionName($name, $stripPrefix = true) {
        if(! is_string($name)) {
            $var = Types::getVartype($name);
            $this->saveError("Component name parameter '{$var}' is wrong type: expecting string");
            return false;
        }
        $component = trim($name);
        if($stripPrefix && ! empty($component)) {
            $component = $this->_removeNamePrefix($component);
        }
        if(empty($component)) {
            $this->saveError("Cannot validate component name: component name missing/empty");
            return false;
        }
        if(! preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]+$/', $component)) {
            $var = Types::getVartype($component);
            $this->saveError("Not a valid component name '{$var}'");
            return false;
        }
        return $component;
    }

    //const COMPONENTS_DIRECTORY = 'joomlaComponents';
    //const TEMPLATE_DIRECTORY = 'template';
    //const DEFAULT_TEMPLATE = 'component_template';

    public function getComponentsDirectory() {
        // C:/inetpub/framework/joomlaComponents
        return $this->joinPath(APPLICATION_PATH, self::COMPONENTS_DIRECTORY);
    }

    public function getComponentsTemplateDirectory() {
        // C:/inetpub/framework/joomlaComponents/component_template
        return $this->joinPath($this->getComponentsDirectory(), self::TEMPLATE_DIRECTORY, self::DEFAULT_TEMPLATE);
    }

    public function getDefaultOutputDirectory() {
        return $this->getComponentsDirectory();
    }

    protected function _getJoomlaTutorialFiles() {
        return include $this->joinPath(__DIR__, 'JoomlaTutorialFiles.phtml');
    }
}
