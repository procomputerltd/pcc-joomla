<?php
namespace Procomputer\Joomla\Model;

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
use microtime;
use InvalidArgumentException;

class Progress {
    protected $_time = null;
    protected $_startTime = null;
    protected $_totalCount = 0;
    protected $_count = 0;
    protected $_data = [];
    protected $_callback = null;
            
    public function __construct($callback = null) {
        if(is_object($callback)  && is_callable($callback)) {
            $this->_callback = $callback;
        }
        $this->_time = $this->_startTime = microtime(true);
    }
    
    /**
     * 
     * @param bool   $reset
     * @param string $name
     * @return self
     * @throws InvalidArgumentException
     */
    public function setCallback($callback) {
        if(is_object($callback)  && is_callable($callback)) {
            $this->_callback = $callback;
            return $this;
        }
        throw new InvalidArgumentException("Invalid '\$callback' argument: expecting a callable object.");
    }
    
    /**
     * 
     * @param bool   $reset
     * @param string $name
     * @return float
     */
    public function getInterval(bool $reset = true, string $name = '') :float {
        $time = microtime(true);
        $elapsed = (float)($time - $this->_time);
        if(! empty($name)) {
            if(! isset($this->_data[$name])) {
                $this->_data[$name] = 0;
            }
            $this->_data[$name] += $elapsed;
        }
        if($reset) {
            $this->_time = microtime(true);
        }
        if($this->_callback) {
            $this->_callback($this, $name);
        }
        return $elapsed;
    }
    
    public function add($value) {
        if(is_numeric($value)) {
            $this->_count += intval($value);
        }
        return $this;
    }
    
    public function getCount() {
        return $this->_count;
    }
    
    public function addToTotal($value) {
        if(is_numeric($value)) {
            $this->_totalCount += intval($value);
        }
        return $this;
    }
    
    public function getTotal() {
        return microtime(true) - $this->_startTime;
    }
    
    public function getData() {
        return $this->_data;
    }
}