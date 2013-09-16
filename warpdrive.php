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
define('WARPDRIVE_OPT_ACCESS_TOKEN',  'warpdrive.access_token');
define('WARPDRIVE_OPT_SITE_NAME',     'warpdrive.site_name');
define('WARPDRIVE_EVVII_LOCATION',    'https://evvii.savviihq.com');
define('WARPDRIVE_FORM_TOKEN',        'warpdrive_evvii_token_field');

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
        // Menu filters
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
     * Returns true if it's WPMU
     * @static
     * @return bool
     */
    public static function is_wpmu() {
        static $wpmu = null;

        if (is_null($wpmu)) {
            $wpmu = file_exists(ABSPATH.'wpmu-settings.php');
        }

        return $wpmu;
    }

    /**
     * Returns true if there is multisite mode
     * @static
     * return bool
     */
    public static function is_network() {
        return self::is_wpmu() || self::is_multisite();
    }

    /**
     * Get an option from the database
     * @param $name string Name of the option
     * @param null $default mixed Default value of the option
     * @return mixed|void Value of the option if it exists, else $default
     */
    public static function get_option($name, $default=null) {
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
     * Delete an option in the database
     * @static
     * @param string $name Name of the option to remove
     */
    public static function delete_option($name) {
        if (Warpdrive::is_multisite()) {
            delete_site_option($name);
        } else {
            delete_option($name);
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
            include(BASE."warpdrive/evvii-cache.php");
        }

        // Include read logs module
        include(BASE."warpdrive/read-logs.php");
        // Include limit login attempts
        include(BASE."warpdrive/limit-login-attempts.php");
        // Include CDN module
        include(BASE."warpdrive/cdn.php");
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

    /**
     * Test site token with Evvii to request site information
     * @param string $token Access token from Wallii
     * @return array|null Null if token is invalid, else site information
     */
    private function get_site_from_evvii($token) {
        if (empty($token)) {
            return null;
        }

        // Build cache delete url
        $url = WARPDRIVE_EVVII_LOCATION.'/v1/site?access_token='.$token;

        // Call Evvii
        $http = new WP_Http();
        $result = $http->request($url, array(
            'method' => 'GET',
            'httpversion' => '1.1',
            'sslverify' => false,
            'headers' => array(
                'Authorization' => 'Token token="'.$token.'"',
            ),
        ));

        // Get return code from result
        $code = isset($result['response']) ? (isset($result['response']['code']) ? $result['response']['code'] : 500) : 500;

        // If return header has code 200 or 204, set cache as flushed
        if ($code == 200 || $code == 204) {
            $body = json_decode($result['body'], true);
            if (is_null($body)) {
                return array();
            } else {
                return $body;
            }
        } else {
            return null;
        }
    }

    public function dashboard_page() {
        if (isset($_POST[WARPDRIVE_FORM_TOKEN])) {
            // Get token from field
            $token = $_POST[WARPDRIVE_FORM_TOKEN];
            // Check token with Evvii
            $site = $this->get_site_from_evvii($token);
            if (is_array($site)) {
                // Save token and site name
                echo '<h2>'._('Access token saved!').'</h2>';
                $this->add_option(WARPDRIVE_OPT_ACCESS_TOKEN, $token);
                $this->add_option(WARPDRIVE_OPT_SITE_NAME, $site['system_name']);
            } else {
                // Token failed, show error
                echo '<h2>'._('Incorrect access token provided!').'</h2>';
                // Remove old token and site name from database
                $this->delete_option(WARPDRIVE_OPT_ACCESS_TOKEN);
                $this->delete_option(WARPDRIVE_OPT_SITE_NAME);
            }
        }


        // Load waprdive.evviii-token
        $token = $this->get_option(WARPDRIVE_OPT_ACCESS_TOKEN, '');
?>
        <div class="wrap">
            <h3><?php _e('Savvii access token') ?></h3>
            <div><?php _e('This is the token obtained from the site overview page in the administration.'); ?></div>
            <form method="post">
                <table>
                    <tr>
                        <td><?php _e('Access token:', 'warpdrive'); ?></td>
                        <td><input type="text" name="<?php echo WARPDRIVE_FORM_TOKEN; ?>" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" /></td>
                    </tr>
                    <tr>
                        <td>&nbsp;</td>
                        <td><input type="submit" value="<?php _e('Save token') ?>"></td>
                    </tr>
                </table>
            </form>
        </div>
<?php
    }
}


add_action('init', array('Warpdrive', 'init'));
add_action('plugins_loaded', array('Warpdrive', 'load_modules'));