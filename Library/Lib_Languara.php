<?php

namespace Languara\Library;

class Lib_Languara {

    public $error_message;
    public $zip;
    public $language_location;
    public $endpoints = array();
    public $conf = array();
    public $arr_messages = array();
    public $config_files = array();
    public $origin_site = null;
    public $platform = null;
    public $arr_project_locales = array();
    public $arr_resource_groups = array();
    public $arr_resources = array();
    public $arr_translations = array();
    public $external_request_id = null;
    public $resource_groups = null;
    public $translations_count = 0;
    public $invalid_resource_cds = array();
    public $is_cli;
    
    function __construct() {
        $this->is_cli = (php_sapi_name() == "cli");
    }

    public function check_auth($external_request_id, $client_signature) {
        if ($client_signature != base64_ENCODE(hash_hmac('sha256', $external_request_id, $this->conf['project_api_secret'], true)))
            throw new \Exception('Authentication failed!');

        $this->external_request_id = $external_request_id;

        return true;
    }

    public function upload_local_translations() {
        // make sure the plugin has a account associated with it
        if (!$this->is_user_registered())
            return;

        // sanity checks
        if (!$this->language_location) {
            throw new \Exception($this->get_message_text('error_language_location'));
            return false;
        }

        if (!is_dir($this->language_location)) {
            $show_exception_ind = true;

            if ($this->is_cli) {
                $show_exception_ind = $this->create_language_dir();
            }

            if ($show_exception_ind) {
                throw new \Exception($this->get_message_text('error_language_dir_missing'));
                return false;
            }
        }

        // get local translations
        $this->print_message('notice_retrieve_data', 'NOTICE');
        
        $arr_data = $this->retrieve_local_translations();

        // make sure there is content to be pushed
        if (!$arr_data) {
            throw new \Exception($this->get_message_text('error_no_local_content') . $this->language_location);
            return;
        }

        // show error message if there are invalid codes
        if ($this->invalid_resource_cds) {
            $this->print_message('error_malformed_resource_cd', 'FAILURE');

            $i = 0;

            // show resource codes with errors in them
            foreach ($this->invalid_resource_cds as $resource_cd) {
                if ($i++ == 10) {
                    break;
                }
                $this->print_message($resource_cd['resource_group_name'] . '.' . $resource_cd['resource_cd']);
            }

            // if there are more then 10 resource codes
            if (count($this->invalid_resource_cds) > 10) {
                $this->print_message('...and ' . (count($this->invalid_resource_cds) - 10) . ' more!');
            }
                

            $this->print_message('notice_resource_cd_help_link', 'NOTICE');

            // proceed only if user confirms
            $proceed = strtolower(readline($this->get_message_text('prompt_proceed_with_upload')));
            if ($proceed != 'y')
                throw new \Exception($this->get_message_text('error_action_aborted'));
        }

        // get project locales
        $this->print_message("notice_pushing_content", 'NOTICE');

        $locales_count = count($arr_data);
        $resource_group_count = count($this->resource_groups);

        $data = $this->fetch_endpoint_data('upload_translations', array('local_data' => $arr_data), 'post', true);
        if ($this->is_cli) {
            $this->print_message();
            $this->print_message('notice_languages_pushed', 'NOTICE');
            $this->print_message(' [' . $locales_count . '/' . $locales_count . ']');
            $this->print_message('notice_resource_groups_pushed', 'NOTICE');
            $this->print_message(' [' . $data->resource_group_count . '/' . $resource_group_count . ']' );
            $this->print_message('notice_translations_pushed', 'NOTICE');
            $this->print_message(' [' . $data->translation_count . '/' . $this->translations_count . ']');
        }
    }

    protected function retrieve_local_translations() {
        // get local locales, resource groups, and translations
        $dir_iterator = new \DirectoryIterator($this->language_location);
        $arr_locales = array();

        foreach ($dir_iterator as $dir) {
            // skip the system files for navigation and language_backup directory
            if ($dir->getFilename() != '.' && $dir->getFilename() != '..' && $dir->getFilename() != 'language_backup') {
                if ($dir->isDir()) {
                    $arr_locales[$dir->getFilename()] = array();

                    $arr_locales[$dir->getFilename()] = $this->retrieve_resource_groups_and_translations($dir->getRealPath());
                }
            }
        }

        return $arr_locales;
    }

    protected function retrieve_resource_groups_and_translations($dir_path) {
        $dir_iterator = new \DirectoryIterator($dir_path);
        $arr_resource_groups = array();

        foreach ($dir_iterator as $file) {
            $lang = null;

            // skip system files
            if ($file->getFilename() == '.' || $file->getFilename() == '..' || $file->getFilename() == 'language_backup')
                continue;

            $arr_path_parts = pathinfo($file->getRealPath());
            if ($arr_path_parts['extension'] != 'php')
                continue;

            if ($this->conf['storage_engine'] == 'php_assoc_array')
                include ($file->getRealPath());
            if ($this->conf['storage_engine'] == 'php_array')
                $lang = include ($file->getRealPath());

            // if file empty
            if (!isset($lang))
                continue;

            // remove the suffix of the file
            $dir_name_filtered = $file->getBasename('.php');
            if ($this->conf['file_suffix']) {
                $dir_name_filtered = strrev(preg_replace('/' . strrev($this->conf['file_suffix']) . '/', '', strrev($file->getBasename('.php')), 1));
            }

            // add resource groups as keys
            $arr_resource_groups[$dir_name_filtered] = $lang;

            // count resource groups and translations
            if (!isset($this->resource_groups[$dir_name_filtered]))
                $this->resource_groups[$dir_name_filtered] = $dir_name_filtered;
            $this->translations_count += count($lang);

            // validate resource_cd
            foreach ($lang as $resource_cd => $translation) {
                if (!$this->validate_resource_code($resource_cd) && !isset($this->invalid_resource_cds[$resource_cd]))
                    $this->invalid_resource_cds[$resource_cd] = array('resource_cd' => $resource_cd, 'resource_group_name' => $dir_name_filtered);
            }
        }

        return $arr_resource_groups;
    }

    public function download_and_process() {
        // make sure the plugin has a account associated with it
        if (!$this->is_user_registered())
            return;

        // sanity checks
        if (!$this->language_location) {
            throw new \Exception($this->get_message_text('error_language_location'));
            return false;
        }

        if (!is_dir($this->language_location)) {
            $show_exception_ind = true;

            if ($this->is_cli) {
                $show_exception_ind = $this->create_language_dir();
            }

            if ($show_exception_ind) {
                throw new \Exception($this->get_message_text('error_language_dir_missing'));
                return false;
            }
        }

        if (!is_writable($this->language_location)) {
            throw new \Exception($this->get_message_text('error_language_dir_not_writable'));
            return false;
        }

        // get project locales
        //        $this->print_message("notice_retrieve_languages", 'NOTICE');

        $this->arr_project_locales = $this->fetch_endpoint_data('project_locale', null, 'get', true);

        if (!$this->arr_project_locales) {
            throw new \Exception($this->get_message_text('error_project_no_languages'));
            return false;
        }

        if ($this->is_cli) {
            $language_list_message = count((array) $this->arr_project_locales);

            $this->print_message('notice_languages_downloaded', 'NOTICE',false);
            $this->print_message($language_list_message);
        }

        // get project resource groups
//        if ($this->is_cli) {
//            $this->print_message("notice_retrieve_resource_groups", 'NOTICE');
//            echo PHP_EOL;
//            sleep(2);
//        }
        
        $this->arr_resource_groups = $this->fetch_endpoint_data('resource_group', null, 'get', true);

        $this->print_message('notice_resource_groups_downloaded', 'NOTICE',false);
        $this->print_message(count($this->arr_resource_groups));

        // get project translations
//        if ($this->is_cli) {
//            $this->print_message("notice_retrieve_translations", 'NOTICE');
//            echo PHP_EOL;
//            sleep(2);
//        }
        
        $this->arr_translations = $this->fetch_endpoint_data('translation', null, 'get', true);

        $this->print_message('notice_translations_downloaded', 'NOTICE',false);
        $this->print_message(count($this->arr_translations));

        // back up local data
        $this->print_message("notice_backing_up_data", 'NOTICE');

        // create back dir if it doesn't exist
        $this->create_dir($this->language_location, 'language_backup');

        // create zip file
        $zip_name = date('Y-m-d-H:i:s') . '.zip';
        $zip_full_path = $this->language_location . '/language_backup/' . $zip_name;

        $this->zip = new \ZipArchive;

        $this->zip->open($zip_full_path, \ZipArchive::CREATE);

        $zipped_files_count = $this->add_folder_to_zip($this->language_location);

        $this->zip->close();

        // check if zip file exists, if it doesn't complain
        if (!is_file($zip_full_path) && $zipped_files_count > 0) {
            $continue_ind = strtolower(readline($this->get_message_text('prompt_no_backup_proceed')));
            if ($continue_ind != 'y')
                throw new \Exception($this->get_message_text('error_action_aborted'));
        }

        // remove local translations
        $this->print_message("notice_removing_files", 'NOTICE');
        $this->remove_local_translations($this->language_location);

        // get project translations
        $this->print_message("notice_adding_content", 'NOTICE');
        $this->add_translations_to_files();

        return true;
    }

    protected function add_translations_to_files() {
        // process locale
        foreach ($this->arr_project_locales as $project_locale) {
            $this->create_dir($this->language_location, strtolower($project_locale->iso_639_1));

            // process translations
            foreach ($this->arr_resource_groups as $resource_group) {

                $resource_group_file_contents = $this->get_file_header();
                foreach ($this->arr_translations as $translation) {
                    if ($translation->resource_group_id == $resource_group->resource_group_id && $project_locale->locale_id == $translation->locale_id) {
                        $resource_group_file_contents .= $this->get_file_content($translation->resource_cd, $translation->translation_txt);
                    }
                }

                $resource_group_file_contents .= $this->get_file_footer();

                $file_path = strtolower($this->language_location . '/' . $project_locale->iso_639_1 . '/' . $this->conf['file_prefix'] . $resource_group->resource_group_name . $this->conf['file_suffix'] . '.php');
                file_put_contents($file_path, $resource_group_file_contents);
                chmod($file_path, 0777);
            }
        }
    }

    public function register($platform = null) {
        if (!isset($platform)) {
            throw new \Exception('error_plugin_problem');
        }
        
        $first_name = readline($this->get_message_text('prompt_enter_first_name'));
        while (!preg_match("/^[a-zA-Z]+$/", trim($first_name))) {
            $this->print_message("prompt_first_name_validation");
            $first_name = readline($this->get_message_text('prompt_enter_first_name'));
        }

        $last_name = readline($this->get_message_text('prompt_enter_last_name'));
        while (!preg_match("/^[a-zA-Z]+$/", trim($last_name))) {
            $this->print_message("prompt_last_name_validation");
            $last_name = readline($this->get_message_text('prompt_enter_last_name'));
        }

        $email = readline($this->get_message_text('prompt_enter_email'));
        while (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->print_message("prompt_email_validation");
            $email = readline($this->get_message_text('prompt_enter_email'));
        }

        $password = readline($this->get_message_text('prompt_enter_password'));
        while (!preg_match("/^([a-zA-Z0-9@*#]{6,15})$/", trim($password))) {
            $this->print_message("prompt_password_validation");
            $password = readline($this->get_message_text('prompt_enter_password'));
        }

        $config = $this->fetch_endpoint_data('register', array('user_first_name' => $first_name, 'user_last_name' => $last_name, 'user_email_address' => $email, 'user_password' => $password, 'platform' => $platform), 'post', true);

        // now update the config files
        $this->update_config_file($this->config_files['project_config'], $config->project_config);
    }

    public function translate() {
        // make sure the plugin has a account associated with it
        if (!$this->is_user_registered()) {
            return false;
        }

        // prompt user to push
        $answer = strtolower(readline($this->get_message_text('prompt_push_content')));
        if ($answer == 'y') {
            $this->print_message();
            $this->upload_local_translations();
        }

        $result = $this->fetch_endpoint_data('get_translation_quote', array('project_id' => $this->conf['project_id']), 'post', true);
        if ($result->translation_count == 0) 
        {    
            throw new \Exception($this->get_message_text('error_add_more_languages'));
        }
        
        $this->print_message('notice_requested_translations', 'NOTICE',false);
        $this->print_message($result->word_count . ' word(s)');
        $this->print_message('notice_credits_remaining', 'NOTICE',false);
        $this->print_message($result->word_capacity . ' word(s)');

        // if there is no overage
        if ($result->overage_ind) {
            $this->print_message('notice_current_rate', 'NOTICE',false);
            $this->print_message('$' . $result->feature_batch_price . ' for ' . $result->feature_batch . ' word(s)');
            $this->print_message('notice_plans_and_pricing', 'NOTICE');
            $this->print_message(PHP_EOL);
            $this->print_message('notice_account_charge', 'NOTICE',false);
            $this->print_message('$' . $result->charge);
            $this->print_message('notice_credits_remain_after_transaction', 'NOTICE',false);
            $this->print_message($result->remaining_capacity_with_overage . ' word(s)');
        } else {
            $this->print_message('notice_no_charge', 'NOTICE');
            $this->print_message(PHP_EOL);
            $this->print_message('notice_credits_remain_after_transaction', 'NOTICE',false);
            $this->print_message($result->remaining_capacity . ' word(s)');
        }


        $continue_translating = strtolower(readline($this->get_message_text('prompt_continue_translating')));
        if ($continue_translating != 'y') {
            throw new \Exception($this->get_message_text('error_action_aborted'));
            return;
        }

        // translate project
        $result = $this->fetch_endpoint_data('translate_project', array('project_id' => $this->conf['project_id'], 'current_price' => $result->charge), 'post', true);

        $this->print_message('Order Confirmation Number: ' . $result->order_number);
        $this->print_message('success_content_translated_successfully', 'SUCCESS');

        
        // prompt user to pull
        $answer = strtolower(readline($this->get_message_text('prompt_pull_content')));
        if ($answer != 'y') {
            return true;
        }

        $this->print_message();
        $this->download_and_process();
    }

    protected function update_config_file($file, $config) {
        if (!file_put_contents($file, $config))
            throw new \Exception($this->get_message_text('error_config_file_error'));
    }

    private function fetch_endpoint_data($endpoint_name, $parameters = null, $method = 'get', $json_decode_ind = false) {
        if (!isset($this->endpoints[$endpoint_name])) {
            throw new \Exception($this->get_message_text('error_retrieving_endpoint' . $endpoint_name));
            return false;
        }

        if ($method == 'get') {
            $url = $this->prepare_request_url($endpoint_name, $parameters);
            $response = $this->curl_get($url);
        } else {
            $url = $this->prepare_request_url($endpoint_name, $parameters);

            $request_vars = http_build_query($parameters);

            $response = $this->curl_post($url, $request_vars);
        }

//		print "\n\nfetch_endpoint_data($endpoint_name)\n";
//		print "\naccessing endpoint URL: $url\n". PHP_EOL;
//		print "CLIENT: GOT RESPONSE\n". $response ."\n";

        $error = true;
        if ($json_decode_ind) {
            $result = json_decode($response);
            $error_message_suffix = '';

            // throw an error if the server doesn't return anything
            if (!is_object($result)) {
                throw new \Exception($this->get_message_text('error_general_request_error'));
            }

            // get the error messages if there are any
            $messages = isset($result->meta) ? $result->meta : array();
            $error = array_key_exists('errors', $messages);

            // if the server returns 1, then add a message sufix to clarify the error
            if (isset($result->data) && !is_object($result->data) && $result->data == 1) {
                $error_message_suffix = PHP_EOL . $this->get_message_text('error_config_file_help_info');
            }

            // if the request faild throw an exception
            if ($error) {
                throw new \Exception($this->get_message_text('error_languara_servers_respond') . PHP_EOL . current(current($messages->errors)) . $error_message_suffix);
            }
            
            // if no errors, we need to extract the data
            if (is_object($result)) {
                $result = current($result);
            }
        } else {
            $result = $response;
        }

        return $result;
    }

    private function prepare_request_url($endpoint_name, $arr_params = null) {
        $url = $this->origin_site . $this->endpoints[$endpoint_name];
        $url_sign = '?';

        if ($this->conf) {
            $url .= "?project_api_key=" . $this->conf['project_api_key']
                    . "&project_id=" . $this->conf['project_id'];

            $url_sign = '&';
        }

        if ($this->conf) {
            $url .= $url_sign . 'project_deployment_id=' . $this->conf['project_deployment_id'];
            $url_sign = '&';
        }

        // if request id present add it
        if ($this->external_request_id) {
            $url .= $url_sign . 'external_request_id=' . $this->external_request_id;
            $url_sign = '&';
        }

        $request_data_serialized = "";
        if (count($arr_params)) {
            $request_data_serialized = http_build_query($arr_params);
        }

        // Create the request signature base and encode using the secret
        //
		$signature_base = $url . $request_data_serialized;
        $signature = 'randomString';

        if ($this->conf) {
            $signature = base64_ENCODE(hash_hmac('sha256', $signature_base, $this->conf['project_api_secret'], true));
        }

        // append request signature to request
        //
		$url .= $url_sign . '_rs=' . $signature;
//		print "CLIENT: signature base: $signature_base\n";
//		print "CLIENT: generated signature: $signature\n";
//		print "opening $endpoint_name: ";
        return $url;
    }

    private function curl_get($url, $callback = false) {
        return $this->curl_doRequest('GET', $url, 'NULL', $callback);
    }

    private function curl_post($url, $vars, $callback = false) {
        return $this->curl_doRequest('POST', $url, $vars, $callback);
    }

    private function curl_doRequest($method, $url, $vars, $callback = false) {
        if (!function_exists('curl_init'))
            throw new \Exception($this->get_message_text('error_curl_not_enabled'));

        // configure curl request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'languara_api_client');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $vars);
        }

        // Don't check ssl peers for now
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $data = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data) {
            if ($callback) {
                return call_user_func($callback, $data);
            } else {
                return $data;
            }
        } else {
            throw new \Exception($error);
        }
    }

    protected function remove_local_translations($translations_path) {
        $translations_path = rtrim($translations_path, '/') . '/';
        $dir_iterator = new \DirectoryIterator($translations_path);

        foreach ($dir_iterator as $dir) {
            // skip the system files for navigation and language_backup directory
            if ($dir->getFilename() != '.' && $dir->getFilename() != '..' && $dir->getFilename() != 'language_backup') {
                // if it's a dir call this method on that directory and when it's empty
                // remove the dir 
                if ($dir->isDir()) {
                    $this->remove_local_translations($dir->getRealPath());
                    rmdir($dir->getRealPath());
                } else {
                    // remove file
                    unlink($dir->getRealPath());
                }
            }
        }
    }

    protected function add_folder_to_zip($dir_path, $zip_path = null) {
        $dir_path = rtrim($dir_path, '/') . '/';
        $dir_iterator = new \DirectoryIterator($dir_path);
        static $zipped_files_count = 0;

        // iterrate through the elements in the current dir
        foreach ($dir_iterator as $dir) {
            // if current element is file, add the file to the zip
            if ($dir->isFile()) {
                $zipped_files_count++;
                $this->zip->addFile($dir->getRealPath(), $zip_path . $dir->getFilename());
            } else if ($dir->getFilename() != '.' && $dir->getFilename() != '..' && $dir->getFilename() != 'language_backup') {
                // if current element is dir, add the dir to the zip and call this method
                // on that dir
                $dir_name = ($zip_path) ? $zip_path . $dir->getBasename() : $dir->getBasename();

                $this->zip->addEmptyDir($dir_name);

                $this->add_folder_to_zip($dir_path . $dir->getFilename() . '/', $zip_path . $dir->getFilename() . '/');
            }
        }

        return $zipped_files_count;
    }

    protected function create_language_dir() {
        $question = $this->color_text($this->get_message_text('prompt_create_language_dir'), 'SUCCESS');
        $lang_location = $this->color_text($this->language_location, 'NOTICE');

        $answer = strtolower(readline($question . ' [' . $lang_location . ']: '));
        while ($answer != 'y' && $answer != 'n') {
            $answer = strtolower(readline('[y/n]: '));
        }

        if ($answer == 'n') {
            return true;
        }

        $arr_language_dir_parts = explode('/', $this->language_location);
        $dir_name = array_pop($arr_language_dir_parts);
        $dir_path = implode('/', $arr_language_dir_parts);

        $this->create_dir($dir_path, $dir_name);

        return false;
    }

    /**
     * If a dir doesn't exists, it creates it
     * 
     * @param string $dir_path
     * @param string $dir_name
     * @return boolean
     */
    protected function create_dir($dir_path, $dir_name) {
        $dir_path = rtrim($dir_path, '/') . '/' . $dir_name;

        if (!file_exists($dir_path) && !is_dir($dir_path)) {
            mkdir($dir_path);
            chmod($dir_path, 0777);
        }

        return true;
    }

    protected function get_file_header() {
        $header = null;

        if ($this->conf['storage_engine'] == 'php_assoc_array')
            $header = '<?php' . PHP_EOL;

        if ($this->conf['storage_engine'] == 'php_array')
            $header = '<?php return array(' . PHP_EOL;

        if ($header === null)
            throw new \Exception($this->get_message_text('error_storage_engine'));

        return $header;
    }

    protected function get_file_footer() {
        $footer = null;

        if ($this->conf['storage_engine'] == 'php_assoc_array')
            $footer = '';

        if ($this->conf['storage_engine'] == 'php_array')
            $footer = ');';

        if ($footer === null)
            throw new \Exception($this->get_message_text('error_storage_engine'));

        return $footer;
    }

    protected function get_file_content($resource_cd, $translation_txt) {
        $content = null;

        if ($this->conf['storage_engine'] == 'php_assoc_array')
            $content = '$lang[\'' . $resource_cd . '\'] = \'' . str_replace("'", "\\'", $translation_txt) . '\';' . PHP_EOL;

        if ($this->conf['storage_engine'] == 'php_array')
            $content = '\'' . $resource_cd . '\' => \'' . str_replace("'", "\\'", $translation_txt) . '\',' . PHP_EOL;

        if ($content === null)
            throw new \Exception($this->get_message_text('error_storage_engine'));

        return $content;
    }

    /**
     * Checks if the provided resource code follows the official
     * languara guidelines for how a resource code should be formed
     * 
     * @param string $unsanitized_code
     * @return boolean
     */
    protected function validate_resource_code($unsanitized_code) {
        // Validate whitespace
        if (preg_match("/\s+/", $unsanitized_code))
            return false;

        // Validate anything thats not a number, letter, -, _, or .
        if (preg_match("/[^-_0-9A-Za-z_\.]/", $unsanitized_code))
            return false;

        // Validate duplicate - _ or .
        if (preg_match("/[-_\.]{2,}/", $unsanitized_code))
            return false;

        // Validate any trailing -_.
        if (in_array(mb_substr($unsanitized_code, -1), array('.', '_', '-')))
            return false;

        return true;
    }

    protected function is_user_registered() {
        // make sure the user is registered and has a config file
        if (!$this->conf) {
            $this->print_message('notice_no_account_associated', 'NOTICE');

            try {
                $this->register($this->platform);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
                return;
            }

            // if registration is successfull, tell the user and load the config
            $this->load_config_file();

            $this->print_message("success_registration_completed", 'SUCCESS');
        }

        return true;
    }

    protected function load_config_file() {
        
    }

    public function print_message($message_code, $message_status = "null", $output_eol = true) {
        if ($this->is_cli) {
            $message = $this->get_message_text($message_code);
            echo $this->color_text($message, $message_status);
            if ($output_eol) {
                echo PHP_EOL;
            }
        }
    }
    
    public function get_message_text($message_code) {
        return (isset($this->arr_messages[$message_code])) ? $this->arr_messages[$message_code] : $message_code;
    }

    public function color_text($text, $status = null) {
        $out = "";
        switch ($status) {
            case "SUCCESS":
                $out = "[0;32m"; //Green background
                break;
            case "FAILURE":
                $out = "[0;31m"; //Red background
                break;
            case "NOTICE":
                $out = "[1;33m"; //Red background
                break;
            default:
                $out = "[0m"; //White background
        }

        return chr(27) . "$out" . "$text" . chr(27) . "[0m";
    }

}

/* End of file Lib_languara.php */