<?php

/* 
 * Copyright (C) 2022 Pro Computer James R. Steel <jim-steel@pccglobal.com>
 * Pro Computer (pccglobal.com)
 * Tacoma Washington USA 253-272-4243
 *
 * This program is distributed WITHOUT ANY WARRANTY; without 
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR 
 * A PARTICULAR PURPOSE. See the GNU General Public License 
 * for more details.
 */
namespace Procomputer\Joomla\Traits;

trait Database {
    
    protected $_dbAdapter = null;

    public $lastDatabaseError = '';
    
    /**
     * Reads extension data from the Joomla! database.
     * @param stdClass $joomlaConfig
     * @return boolean|array
     * @throws \RuntimeException
     */
    protected function dbReadExtensionData($joomlaConfig) {
        /*
        CREATE TABLE `josmg_extensions` (
          `extension_id`     int(11) NOT NULL,
          `package_id`       int(11) NOT NULL DEFAULT '0' COMMENT 'Parent package ID for extensions installed as a package.',
          `name`             varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `type`             varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
          `element`          varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `folder`           varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `client_id`        tinyint(3) NOT NULL,
          `enabled`          tinyint(3) NOT NULL DEFAULT '0',
          `access`           int(10) UNSIGNED NOT NULL DEFAULT '1',
          `protected`        tinyint(3) NOT NULL DEFAULT '0',
          `manifest_cache`   mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
          `params`           mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
          `custom_data`      mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
          `system_data`      mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
          `checked_out`      int(10) UNSIGNED NOT NULL DEFAULT '0',
          `checked_out_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
          `ordering`         int(11) DEFAULT '0',
          `state`            int(11) DEFAULT '0'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        array(18) (
          [extension_id] => (string) 21
          [package_id] => (string) 0
          [name] => (string) com_weblinks
          [type] => (string) component
          [element] => (string) com_weblinks
          [folder] => (string)
          [client_id] => (string) 1
          [enabled] => (string) 1
          [access] => (string) 1
          [protected] => (string) 0
          [manifest_cache] => (string)
            {
                "name":         "com_pccoptionselector",
                "type":         "component",
                "creationDate": "November 2017",
                "author":       "Pro Computer",
                "copyright":    "Copyright (c) 2017 Pro Computer. All rights reserved",
                "authorEmail":  "pcc@pccglobal.com",
                "authorUrl":    "pccglobal.com",
                "version":      "1.0",
                "description":  "Pcc Option Selector lets you create and manage lists of events for clubs and associations",
                "group":        "",
                "filename":     "pccoptionselector"
            }
            {
                "name":         "com_weblinks",
                "type":         "component",
                "creationDate": "2017-03-08",
                "author":       "Joomla! Project",
                "copyright":    "(C) 2005 - 2017 Open Source Matters. All rights reserved.",
                "authorEmail":  "admin@joomla.org",
                "authorUrl":    "www.joomla.org",
                "version":      "3.6.0",
                "description":  "COM_WEBLINKS_XML_DESCRIPTION",
                "group":        "",
                "filename":     "weblinks"

            }
          [params] => (string)
            {
                "target":                       "0",
                "save_history":                 "1",
                "history_limit":                5,
                "count_clicks":                 "1",
                "icons":                         1,
                "link_icons":                   "",
                "float_first":                  "right",
                "float_second":                 "right",
                "show_tags":                    "1",
                "category_layout":              "_:default",
                "show_category_title":          "1",
                "show_description":             "1",
                "show_description_image":       "1",
                "maxLevel":                     "-1",
                "show_empty_categories":        "0",
                "show_subcat_desc":             "1",
                "show_cat_num_links":           "1",
                "show_cat_tags":                "1",
                "show_base_description":        "1",
                "maxLevelcat":                  "-1",
                "show_empty_categories_cat":    "0",
                "show_subcat_desc_cat":         "1",
                "show_cat_num_links_cat":       "1",
                "filter_field":                 "1",
                "show_pagination_limit":        "1",
                "show_headings":                "0",
                "show_link_description":        "1",
                "show_link_hits":               "1",
                "show_pagination":              "2",
                "show_pagination_results":      "1",
                "show_feed_link":               "1"
            }

          [custom_data] => (string)
          [system_data] => (string)
          [checked_out] => (string) 0
          [checked_out_time] => (string) 0000-00-00 00:00:00
          [ordering] => (string) 0
          [state] => (string) 0
        )

        public $dbtype = 'mysqli';
        public $host = 'localhost';
        public $user = 'root';
        public $password = 'trash';
        public $db = 'procompu_joomla';
        public $dbprefix = 'josmg_';
        */
        $dbAdapter = $this->getDbAdapter();
        if(! is_object($dbAdapter)) {
            // Adapter not available.
            return false;
        }
        if(! is_object($joomlaConfig)) {
            $joomlaConfig = (object)$joomlaConfig;
        }
        $schema = $joomlaConfig->db;
        $tableName = $joomlaConfig->dbprefix . 'extensions';
        $sql = "SELECT * FROM {$schema}.{$tableName} WHERE type IN('package', 'component', 'module')";
        try {
            /* @var $result \Laminas\Db\Adapter\Driver\Pdo\Result */
            $statement = $dbAdapter->createStatement($sql);
            $statement->prepare();
            $result = $statement->execute();
        } catch (\Throwable $ex) {
            // Laminas\Db\Adapter\Exception\InvalidQueryException
            // Statement could not be executed (42S02 - 1146 - Table 'procompu_joomla.josmg_extensionsx' doesn't exist)
            $msg = $ex->getMessage();
            $this->_saveError($msg);
            return false;
        }
        
        $return = [[],[],[]];
        foreach($result as $row) {
            foreach(['manifest_cache', 'params'] as $key) {
                if(isset($row[$key]) && ! empty($row[$key])) {
                    $value = $this->jsonDecode($row[$key], true);
                    if(false === $value) {
                        $this->_saveError($this->lastXmlJsonError);
                        return false;
                    }
                    $row[$key] = $value;
                }
            }
            $index = null;
            switch($row['type']) {
            case 'component':
                $index = 0;
                break;
            case 'module':
                $index = 1;
                break;
            case 'package':
                $index = 2;
                break;
            default:
                $var = Types::getVartype($row['type']);
                throw new \RuntimeException("Unknown component type '{$var}' in table {$schema}.{$tableName}");
            }
            $return[$index][] = (array)$row;
        }
        return array_merge($return[0], $return[1], $return[2]);
    }

    /**
     * Returns the default DB adapter.
     * @return Adapter
     */
    public function setDbAdapter($adapter) {
        $this->_dbAdapter = $adapter;
        return $this;
    }
    
    /**
     * Returns the default DB adapter.
     * @return Adapter
     */
    public function getDbAdapter() {
        return $this->_dbAdapter;
    }
    
    /**
     * Returns the default DB adapter.
     * @return boolean
     */
    public function haveDatabaseAdapter() {
        return is_object($this->_dbAdapter);
    }
    
    /**
     * Saves an error message.
     * @param string $errorMsg
     * @return $this
     */
    protected function _saveError($errorMsg) {
        $this->lastDatabaseError = $errorMsg;
        if(method_exists($this, 'saveError')) {
            $this->saveError($errorMsg);
        }
        return $this;
    }
}
