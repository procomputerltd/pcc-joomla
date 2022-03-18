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

use RuntimeException;

use Procomputer\Pcclib\Types;

class DbTableExporter {
    
    use \Procomputer\Joomla\Traits\Messages;
    
    /**
     * 
     * @param Installation $installation
     * @param array        $dbTableNames
     * @return array
     */
    public function export(Installation $installation, array $dbTableNames) {
        /**
         * For debugging: write SQL string statements to local file.
         * Call with false to purge the file.
         */
        $this->_debugWriteSqlToFile(false);
        $return = [
            'drop' => $this->getDropStatements($installation, $dbTableNames),
            'create' => $this->getCreateTableSql($installation, $dbTableNames),
            'insert' => $this->getInsertStatements($installation, $dbTableNames)
        ];
        return $return;
    }

    /**
     * 
     * @param Installation $installation
     * @param array $dbTableNames
     * @return array|boolean
     * @throws RuntimeException
     */
    protected function getCreateTableSql(Installation $installation, array $dbTableNames){
        $dbAdapter = $installation->getDbAdapter();
        if(! is_object($dbAdapter)) {
            $msg = "Cannot create TABLE sql file: no database adapter is specified in the Joomla Installation object.";
            throw new RuntimeException($msg);
        }
        $schema = $installation->config->db;
        $tablePrefix = $installation->config->dbprefix;
        $createTables = [];
        foreach($dbTableNames as $tableName) {
            $dboTableName = $this->_replaceDbTablePrefix($tablePrefix, $tableName);
            $jmTableName = $this->_replaceDbTablePrefix($tablePrefix, $tableName, true);
            $schemaTable = $schema . '.' . $dboTableName;
            if(! $this->_tableExists($dbAdapter, $schemaTable)) {
                $msg = "WARNING: database table not found: '{$schemaTable}'";
                $this->saveError($msg);
            }
            else {
                $sqlStatement = "SHOW CREATE TABLE {$schemaTable}";
                $resultSet = $this->_fetchResultSet($dbAdapter, $sqlStatement);
                /* @var $resultSet \Laminas\Db\Adapter\Driver\Pdo\Result */
                foreach($resultSet as $data) {
                    break;
                }
                // ['table' => <tablename>, 'Create Table' => <create_table_sql>]
                $createTableSql = array_pop($data);
                // Use the following to lose the AUTO_INCREMENT specifier, start at 1 all tables:
                // $createTableSql = preg_replace('/[ \\t]*AUTO_INCREMENT[ \\t]*=[ \\t]*[0-9]+/i', '', $createTableSql)
                /*
                CREATE TABLE IF NOT EXISTS `josmg_pccopsel_items` (
                CREATE TABLE `josmg_pccopsel_items` (
                 `autoid`       int(11)         NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
                 `userid`       int(11)         DEFAULT NULL COMMENT 'User ID in users table',
                 `parentid`     int(11)         NOT NULL DEFAULT '0' COMMENT 'Parent table ID',
                 `id`           varchar(20)     NOT NULL COMMENT 'Item ID',
                 `name`         varchar(32)     NOT NULL COMMENT 'Short Name',
                 `image`        varchar(512)    DEFAULT NULL COMMENT 'Large image URL/file',
                 `thumbnail`    varchar(512)    DEFAULT NULL COMMENT 'Thumbnail image URL/file',
                 `description`  text            COMMENT 'Description',
                 `datecreated`  datetime NOT NULL COMMENT 'Date created',
                 `datemodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Date modified TIMESTAMP',
                 `sort`         int(11) NOT NULL DEFAULT '0' COMMENT 'Sort index used in hierarchical views',
                 PRIMARY KEY (`autoid`),
                 UNIQUE KEY `id` (`id`),
                 UNIQUE KEY `name` (`name`),
                 KEY `ix_username` (`userid`),
                 KEY `ix_parentid` (`parentid`)
                ) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8 COMMENT='Items'
                */            

                /**
                 * Replace the DB prefix with the Joomla! db prefix tag '#__'
                 */
                $pattern = '/(CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS|CREATE\\s+TABLE)\\s*(.+?)\\((.*)$';
                // `([^`]+)`/i';
                if(! preg_match($pattern, $createTableSql, $m)) {
                    $var = Types::getVartype($createTableSql, 80);
                    $msg = "Cannot parse CREATE TABLE SQL statement: {$var}";
                    throw new RuntimeException($msg);
                }
                $m[2] = " `{$jmTableName}` ";
                array_shift($m);
                $createTables[] = implode('', $m);
            }
            
        }
        $this->_debugWriteSqlToFile($createTables);
        return $createTables;
    }
    
    /**
     * 
     * @param Installation $installation
     * @param array              $dbTableNames
     * @return string
     * @throws RuntimeException
     */
    protected function getInsertStatements(Installation $installation, array $dbTableNames){
        $dbAdapter = $installation->getDbAdapter();
        if(! is_object($dbAdapter)) {
            $msg = "Cannot create TABLE sql file: no database adapter is specified in the Joomla Installation object.";
            throw new RuntimeException($msg);
        }
        $schema = $installation->config->db;
        $tablePrefix = $installation->config->dbprefix;
        $insertStatements = [];
        $emptyTables = [];
        $dbPlatform = $dbAdapter->getPlatform();
        /** @var \Laminas\Db\Adapter\Platform\Mysql $dbPlatform */
        foreach($dbTableNames as $tableName) {
            $dboTableName = $this->_replaceDbTablePrefix($tablePrefix, $tableName, false);
            $jmTableName = $this->_replaceDbTablePrefix($tablePrefix, $tableName, true);
            $schemaTable =  $schema . '.' . $dboTableName;
            if(! $this->_tableExists($dbAdapter, $schemaTable)) {
                $msg = "WARNING: database table not found: '{$schemaTable}'";
                $this->saveError($msg);
            }
            else {
                $sql = "SELECT * FROM {$schemaTable}";
                $resultSet = $this->_fetchResultSet($dbAdapter, $sql);
                $values = [];
                foreach($resultSet as $row) {
                    $colValues = [];
                    foreach($row as $value) {
                       $colValues[] = $dbPlatform->quoteValue($value);
                    }
                    $values[] = '(' . implode(',', $colValues) . ')';
                }
                $insertStatement = "INSERT INTO `{$jmTableName}` (`" . implode('`, `', array_keys($row)) . '`) VALUES';
                if(count($values)) {
                    // INSERT INTO `josmg_pccopsel_items` (`autoid`, `userid`, ...) VALUES
                    // (1, NULL, 0, 'Liberty', 'Liberty 148, 158', 'liberty-qr.jpg', 'liberty-qr.jpg', 'Description', '2019-02-05 19:17:13', '2019-02-06 03:17:13', 0),
                    $insertStatements[] = $insertStatement . "\n" . implode(",\n", $values) . ';';
                }
                else {
                    $emptyTables[] = "-- WARNING: NO TABLE ROWS: {$schemaTable}";
                }
            }
        }
        /**
         * DEBUG: remove the following
        */
        $return = array_merge($emptyTables, $insertStatements);
        $this->_debugWriteSqlToFile($return);
        return $return;
    }
    
    protected function _tableExists($dbAdapter, $schemaTable) {
        $sqlStatement = "SHOW CREATE TABLE {$schemaTable}";
        try {
            $resultSet = $this->_fetchResultSet($dbAdapter, $sqlStatement);
            return true;
        }
        catch(\Throwable $ex) {
            return false;
        }
    }
    
    protected function getDropStatements(Installation $installation, array $dbTableNames) {
        $tablePrefix = $installation->config->dbprefix;
        $dropStatements = [];
        foreach($dbTableNames as $tableName) {
            $jmTableName = $this->_replaceDbTablePrefix($tablePrefix, $tableName, true);
            $dropStatements[] = "DROP TABLE IF EXISTS `{$jmTableName}`;";
        }
        $this->_debugWriteSqlToFile($dropStatements);
        return $dropStatements;
    }
    
    protected function _replaceDbTablePrefix($prefix, $data, $insertJoomlaPrefix = false) {
        if($insertJoomlaPrefix) {
            return preg_replace("/\W({$prefix})\w/i", '#__', $data);
        }
        return preg_replace('/^#__(.*)$/', $prefix . '$1', $data);
    }
    
    protected function _fetchResultSet($dbAdapter, $sqlStatement) {
        $stmt = $dbAdapter->createStatement($sqlStatement);
        $stmt->prepare();
        /* @var $resultSet \Laminas\Db\Adapter\Driver\Pdo\Result */
        $resultSet = $stmt->execute();
        return $resultSet;
    }

    /**
     * For debugging, write SQL statement to a local file.
     * @param bool|string|array $text Data to write. If false the file is purged.
     */
    protected function _debugWriteSqlToFile($data) {
        if(defined('APPLICATION_LOCAL_SERVER') ? (bool)intval(APPLICATION_LOCAL_SERVER) : false) {
            $file = 'C:\inetpub\laminas\public_html\scratch.sql';
            if(false === $data || null === $data) {
                fclose(fopen($file, 'w'));                
                return;
            }
            if(is_array($data)) {
                $data = implode("\n", $data);
            }
            $data .= "\n\n";
            file_put_contents($file, $data, FILE_APPEND);
        }    
    }
}
