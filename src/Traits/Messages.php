<?php
namespace Procomputer\Joomla\Traits;

trait Messages {

    /**
     * Messages and errors saved.
     * @var array
     */
    protected $_messages = [[], []];

    /**
     * Saves error or errors.
     * @param array|string $messages Error messages to save.
     * @return string
     */
    public function saveError($messages) {
        return $this->_saveMessage($messages, true);
    }

    /**
     * Saves message or messages.
     * @param array|string $messages Messages to save.
     * @return self
     */
    public function saveMessage($messages, $isError = false) {
        $this->_saveMessage($messages, $isError);
        return $this;
    }
    /**
     * Alias for saveMessage().
     * @param array|string $messages Messages to save.
     * @return self
     */
    public function addMessage($messages, $isError = false) {
        return $this->_saveMessage($messages, $isError);
    }

    /**
     * Saves message or messages.
     * @param array|string $messages Messages to save.
     * @return self
     */
    protected function _saveMessage($messages, $isError = false) {
        if(is_array($messages)) {
            if(empty($messages)) {
                return $this;
            }
            $messages = array_values($messages);
        }
        elseif(is_scalar($messages)) {
            $msg = trim((string)$messages);
            if(empty($msg)) {
                $msg = __FUNCTION__ . "() called with empty parameter";
            }
            $messages = [$msg];
        }
        elseif(is_object($messages) && method_exists($messages, 'getMessage')) {
            $messages = [$messages->getMessage()];
        }
        else {
            $messages = [$messages];
        }
        $index = $isError ? 1 : 0;
        $this->_messages[$index] = array_merge($this->_messages[$index], $messages);
        return $this;
    }

    /**
     * Returns saved messages.
     * @return array
     */
    public function getMessages() {
        return $this->_messages[0];
    }

    /**
     * Returns saved errors.
     * @return array
     */
    public function getErrors() {
        return $this->_messages[1];
    }

    /**
     * Clears messages.
     * @return ServiceCommon
     */
    public function clearMessages() {
        $this->_messages[0] = [];
        return $this;
    }

    /**
     * Clears errors.
     * @return ServiceCommon
     */
    public function clearErrors() {
        $this->_messages[1] = [];
        return $this;
    }

    /**
     * Returns the number of saved errors.
     * @return int
     */
    public function getErrorCount() {
        return count($this->_messages[1]);
    }

    /**
     * Returns the number of saved messages.
     * @return int
     */
    public function getMessageCount() {
        return count($this->_messages[0]);
    }

}