<?php

namespace Languara\Driver;

interface DriverInterface
{    
    public function encode();
    public function decode();
    public function load($resource_group_name, $lang_name = null, $file_path = null);
    public function save($resource_group_name, $arr_translations, $lang_name = null, $file_path = null);
    public function get_translations();
}