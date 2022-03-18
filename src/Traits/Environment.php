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

trait Environment {
    
    /**
     * Returns the DOCUMENT_ROOT path.
     * @return string  Returns the DOCUMENT_ROOT path or NULL if not found.
     */
    protected function getDocumentRoot() {
        $var = 'DOCUMENT_ROOT';
        if(\defined($var)) {
            return DOCUMENT_ROOT;
        }
        return $this->getServerVar($var);
    }

    /**
     * Returns a server variable value.
     * @param string $var   The variable name.
     * @param int    $type  A PHP 'INPUT_*' constant input type.
     * @return mixed  Returns the value or NULL if not found.
     */
    protected function getServerVar($var, $type = INPUT_SERVER, $default = null) {
        if(\filter_has_var($type, $var)) {
            $value = \filter_input($type, $var, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        }
        elseif(isset($_SERVER[$var])) {
            $value = \filter_var($_SERVER[$var], FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        }
        else {
            $value = $default;
        }
        return $value;
    }

}
