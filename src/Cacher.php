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
namespace Procomputer\Joomla;

class Cacher {
    private $_cache = [];
    
	public function createKey($values) {
        return md5(is_array($values) ? implode('_', $values) : (string)$values);
	}

	public function get(string $key, $default = null) {
        if(isset($this->_cache[$key])) {
            return $this->_cache[$key];
        }
        return $default;
	}

	public function set(string $key, $content) {
        $this->_cache[$key] = $content;
        return $this;
	}

	public function setFromFile(string $key, string $file) {
        return $this;
	}
}
