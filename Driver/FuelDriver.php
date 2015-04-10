<?php

namespace Languara\Driver;

class FuelDriver implements DriverInterface
{
    private $arr_translations = null;
    
    public function decode()
    {
        return $this;
    }

    public function encode()
    {
        return $this;
    }

    public function load($resource_group_name, $lang_name = null, $file_path = null)
    {
        \Config::set('language', $lang_name);
        $this->arr_translations = \Fuel\Core\Lang::load($resource_group_name, $resource_group_name, $lang_name);
        
        return $this;
    }

    public function save($resource_group_name, $arr_translations, $lang_name = null, $file_path = null)
    {
        \Config::set('language', $lang_name);
        return Lang::save($resource_group_name, $arr_translations, $lang_name);
    }

    public function get_translations()
    {
        return $this->arr_translations;
    }

}