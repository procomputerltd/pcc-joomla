<?php
namespace Procomputer\Joomla\Traits;

use Procomputer\Pcclib\PhpErrorHandler;

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
trait ErrorHandling {
    
    public $lastError = '';
    
    /**
     * Calls a closure function and captures error if one occurs. Use to capture PHP errors/notices
     * and prevent them from being displayed.
     *
     * @param function $callable     Callable function.
     * @param boolean  $recordError  (optional) Record error messages using saveMessage().
     * @param string   $defaultMsg   (optional) Default message when the message is missing/empty.
     * @param string   $msgPrefix    (optional) Error message prefix
     *
     * @return mixed Returns the result of the call to the callable function.
     * 
     * use Procomputer\Pcclib\PhpErrorHandler;
     */
    protected function callFuncAndSavePhpError($callable, $recordError = true, $defaultMsg = null, $msgPrefix = null) {
        $phpErrorHandler = new PhpErrorHandler();
        $res = $phpErrorHandler->call($callable);
        if(false === $res && $recordError) {
            if(null === $defaultMsg) {
                $defaultMsg = __CLASS__ . "::" . __FUNCTION__ . '(): unknown error';
            }
            $this->lastError = $phpErrorHandler->getErrorMsg($defaultMsg, $msgPrefix);
            if(method_exists($this, 'saveMessage')) {
                $this->saveMessage($msg);
            }
        }
        return $res;
    }
}