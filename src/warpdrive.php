<?php
/**
 * Plugin name: Warpdrive
 * Plugin URI: https://github.com/Savvii/warpdrive
 * Description: Hosting plugin for Savvii
 * Version: 0.1
 * Author: Ferdi van der Werf <ferdi@savvii.nl>
 * Author URI:
 * License: All rights remain with Savvii
 */

define('BASE', plugin_dir_path(__FILE__));
define('WARPDRIVE_EVVII_TOKEN', 'warpdrive_evvii_token');

class Warpdrive {

    /**
     * Singleton
     * @static
     */
    public static function init() {
        static $instance = null;

        if (!$instance) {
            // Make sure language files are loaded
            if (did_action('plugins_loaded')) {
                Warpdrive::plugin_textdomain();
            } else {
                add_action('plugins_loaded', array(__CLASS__, 'plugin_textdomain'));
            }

            // Create new
            $instance = new Warpdrive;
        }
    }

    /**
     * Load language files
     */
    public static function plugin_textdomain() {
        load_plugin_textdomain('warpdrive', false, dirname(plugin_basename(__FILE__)).'/languages/');
    }

    /**
     * Constructor
     * Initializes WordPress hooks
     */
    function Warpdrive() {
        // Add plugin to menu and put it on top
        add_action('admin_menu', array($this, 'admin_menu_init'));
        add_filter('custom_menu_order', array($this, 'admin_custom_menu_order'));
        add_filter('menu_order', array($this, 'admin_menu_order'));
    }

    /**
     * @return bool True if site is running as multisite
     */
    public static function is_multisite() {
        return function_exists('get_site_option') && function_exists('is_multisite') && is_multisite();
    }

    /**
     * Get an option from the database
     * @param $name string Name of the option
     * @param null $default mixed Default value of the option
     * @return mixed|void Value of the option if it exists, else $default
     */
    public function get_option($name, $default=null) {
        return Warpdrive::is_multisite() ?
            get_site_option($name, $default) : get_option($name, $default);
    }

    /**
     * Save an option in the database
     * @param $name string Name of the option to set
     * @param $value mixed Value of the option to set
     */
    public static function add_option($name, $value) {
        if (Warpdrive::is_multisite()) {
            add_site_option($name, $value);
        } else {
            add_option($name, $value);
        }
    }

    /**
     * Initialize various modules of Warpdrive
     */
    public static function load_modules() {
        // Load waprdrive.evvii-token
        $token = Warpdrive::get_option(WARPDRIVE_EVVII_TOKEN);
        // If token exists, show Evvii menu options
        if (!is_null($token)) {
            // Include purge cache module
            include(BASE."warpdrive/purge-cache.php");
        }

        // Include read logs module
        include(BASE."warpdrive/read-logs.php");
        // Include limit login attempts
        include(BASE."warpdrive/limit-login-attempts.php");
    }

    /**
     * Create admin menu item
     */
    public function admin_menu_init() {
        add_menu_page( __('Savvii', 'warpdrive'), __('Savvii', 'warpdrive'), 'manage_options', 'warpdrive_dashboard', array($this, 'dashboard_page'));
    }

    /**
     * Signal we want a custom menu order
     * @return bool True
     */
    public function admin_custom_menu_order() {
        return true;
    }

    /**
     * Filter our plugin to top
     * @param $menu_order array Original order
     * @return array Modified order
     */
    public function admin_menu_order($menu_order) {
        $order = array();

        foreach($menu_order as $index=>$item) {
            if ($index == 0)
                $order[] = 'warpdrive_dashboard';

            if ($item != "warpdrive_dashboard")
                $order[] = $item;
        }

        return $order;
    }

    public function dashboard_page() {
        // Check postdata for evvii-token and check with Evvii whether this token is valid
        // Load waprdive.evviii-token
        // If token is empty, show form to set token
    }
}


add_action('init', array('Warpdrive', 'init'));
add_action('plugins_loaded', array('Warpdrive', 'load_modules'));
