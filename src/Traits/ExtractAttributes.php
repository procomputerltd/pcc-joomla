<?php
namespace Procomputer\Joomla\Traits;

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

trait ExtractAttributes {
    
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
            if(isset($node->$var)) {
                $list = $node->$var;
            }
        }
        else {
            if(is_object($node)) {
                if(method_exists($node, 'attributes')) {
                    $list = $node->attributes();
                }
            }
        }
        if(isset($list)) {
            foreach($list as $key => $child) {
                $defaults[$key] = (string)$child;
            }
        }
        return $defaults;
    }
    
}