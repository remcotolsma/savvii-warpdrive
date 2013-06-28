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
        add_action('admin_menu', array($this, 'admin_menu_init'), 999);
        add_filter('custom_menu_order', array($this, 'admin_custom_menu_order'));
        add_filter('menu_order', array($this, 'admin_menu_order'));
    }

    /**
     * Initialize various modules of Warpdrive
     */
    public function load_modules() {
        // Include purge cache module
        include(BASE."purge-cache/purge-cache.php");
    }

    /**
     * Create admin menu item
     */
    public function admin_menu_init() {
        add_menu_page( __('Savvii', 'warpdrive'), __('Savvii', 'warpdrive'), 'manage_options', 'warpdrive_dashboard', array($this, 'config_page'));
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
     * @param $menu_order Original order
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

    public function config_page() {

    }
}


add_action('init', array('Warpdrive', 'init'));
add_action('plugins_loaded', array('Warpdrive', 'load_modules'), 100);