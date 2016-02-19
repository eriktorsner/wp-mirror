<?php

namespace Wpmirror;

/**
 * Class Settings
 * @package Wpmirror
 */
class Settings
{
    /**
     * The internal representation of the parsed json file
     *
     * @var \stdClass
     */
    private $obj;

    /**
     * Settings constructor.
     * @param null $type
     */
    public function __construct($type = null)
    {
        $file = '';
        switch ($type) {
            case 'remote':
                $file = '/remotesettings.json';
                break;
        }

        if (strlen($file) == 0 || !file_exists(BASEPATH.$file)) {
            return;
        }

        $this->obj = json_decode(file_get_contents(BASEPATH.$file));
        if (!$this->obj) {
            return;
        }

        if (isset($this->obj->environment) && $this->obj->environment == 'test') {
            if (!defined('TESTMODE')) {
                define('TESTMODE', true);
            }
        }

        if (defined('TESTMODE') && TESTMODE) {
            $this->obj->environment = 'test';
            foreach ($this->obj as $key => $param) {
                $parts = explode('_', $key);
                $name = $parts[0];
                if (isset($parts[1]) && $parts[1] == 'test') {
                    $this->obj->$name = $param;
                }
            }
        }
    }

    /**
     * Is the settings file valid or not
     *
     * @return bool
     */
    public function isValid()
    {
        return ($this->obj != false);
    }

    /**
     * Returns a json string of the internal obj
     *
     * @return mixed|string|void
     */
    public function toString()
    {
        return json_encode($this->obj);
    }

    /**
     * Magic function to return a member from the internal obj
     *
     * @param string $name
     * @return mixed|bool
     */
    public function __get($name)
    {
        if (isset($this->obj->$name)) {
            return $this->obj->$name;
        }

        return false;
    }

    /**
     * Magic method to set a member in the internal obj
     *
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $this->obj->$name = $value;
    }

    /**
     * Magic function to check if a member is set in the internal obj
     *
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->obj->$name);
    }
}
