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

/**
 * A basic runtime cacher. When an object of this class if destroyed the cache contents are destroyed.
 */
class Cacher {
    private $_cache = [];
    
    /**
     * Create an md5 cache key.
     * @param array|string $values
     * @return string
     */
	public function createKey(array|string $values): string {
        return md5(is_array($values) ? implode('_', $values) : (string)$values);
	}

    /**
     * Returns a cache value if it exists else $default.
     * @param string $key
     * @param type $default
     * @return mixed
     */
	public function get(string $key, $default = null): mixed {
        if(isset($this->_cache[$key])) {
            return $this->_cache[$key];
        }
        return $default;
	}

    /**
     * Sets a cache value.
     * @param string $key
     * @param mixed  $content
     * @return $this
     */
	public function set(string $key, mixed $content) {
        $this->_cache[$key] = $content;
        return $this;
	}
}
