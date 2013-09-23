<?php
/**
 * Limit login attempts
 * Limit rate of login attempts, including by way of cookies, for each IP.
 * Based on: limit-login-attempts 1.7.1
 * 
 * @author Ferdi van der Werf <ferdi@savvii.nl>
 */

define('WarpdriveLLAOptions', 'warpdrive-limit-login-attempts');
define('WarpdriveLLAOptionsAutoLoad', 'lockouts');
define('WarpdriveLLANotifyMethods', 'log,email');
define('WarpdriveLLADirectAddr', 'REMOTE_ADDR');
define('WarpdriveLLAProxyAddr', 'HTTP_X_FORWARDED_FOR');
define('WarpdriveLLAErrorIdentifier', 'too_many_attempts');

// Load options at runtime
$GLOBALS['WarpdriveLimitLoginAttemptsOptionsDefault'] = array(
    // Plugin stored version (for safe upgrades)
    'version' => 1
    // Lock out after x amount of attempts
    , 'allowed_attempts' => 5
    // Lock out for x seconds
    , 'lockout_duration' => 1200 // 20 minutes
    // Long lock out after x lock outs
    , 'allowed_lockouts' => 3
    // Long lock out duration
    , 'lockout_long_duration' => 86400 // 24 hours
    // Reset failed attempts after x seconds
    , 'valid_duration' => 43200 // 12 hours
    // Limit malformed/forged cookies?
    , 'handle_cookies' => true
    // Notify on lockout, valid values: '', 'log', 'email', 'log,email'
    , 'lockout_notify' => 'log'
    // If notify by email, send email after x lock outs
    , 'email_after' => 3
    // Enforce limit on new user registration for IP
    , 'limit_register' => true
    // Allow x new user registrations for IP
    , 'register_amount' => 3
    // Above, during x seconds
    , 'register_duration' => 86400 // 24 hours
    // Notify on register lockout, valid values: '', 'log', 'email', 'log,email'
    , 'register_notify' => 'log'
    // Disable password reset using login name
    , 'reset_pwd_by_username_disable' => true
    // Above for capability level_x or higher
    , 'reset_pwd_by_username_level' => 1
    // Disable all password resets?
    , 'reset_pwd_disable' => false
    // Above for capability level_x or higher
    , 'reset_pwd_level' => 1
);


class WarpdriveLimitLoginAttempts {

    /**
     * Have we shown our error?
     * @var bool
     */
    private $feErrorShown = false;
    /**
     * Locked-out this pageload?
     * @var bool
     */
    private $feJustLockedout = false;
    /**
     * User and password non-empty
     * @var bool
     */
    private $feNonEmptyCredentials = false;
    /**
     * Statistics, stored in option table
     * @var object
     */
    private $feStatistics = null;
    /**
     * Options of limit login attempts module
     * @var null
     */
    private $options = null;
    /**
     * Statistics of limit login attempts module
     * @var null
     */
    private $statistics = null;

    /**
     * Initialize class
     */
    public static function init() {
        static $instance = null;

        if (!$instance) {
            $instance = new WarpdriveLimitLoginAttempts;
        }
    }

    /**
     * Constructor
     */
    public function WarpdriveLimitLoginAttempts() {
        // Language file loaded by main plugin

        // Load options
        $this->initOptions();
        // Load statistics
        $this->initStatistics();

        // Filters and actions
        add_action('wp_login_failed', array($this, 'wpLoginFailed'));
        add_action('login_head', array($this, 'errorMessageAdd'));
        add_action('login_errors', array($this, 'errorMessagesFix'));
        add_filter('wp_authenticate_user', array($this, 'wpAuthenticateUser'), 999, 2);
        add_filter('shake_error_codes', array($this, 'shakeFailure'));

        // Handle auth cookies?
        if ($this->getOption('handle_cookies')) {
            add_action('plugins_loaded', array($this, 'handleCookie'), 999);
            add_action('auth_cookie_bad_hash', array($this, 'wpCookieFailed'));

            global $wp_version;

            // Only add action if WP version is >= 3.0
            if (version_compare($wp_version, '3.0', '>=')) {
                add_action('auth_cookie_bad_hash', array($this, 'wpCookieFailedHash'));
                add_action('auth_cookie_valid', array($this, 'cookieValid'), 10, 2);
            }
        }

        // TODO: Change action to authenticate filter, this probably will be deprecated
        add_action('wp_authenticate', array($this, 'trackCredentials'), 10, 2);

        // Should registration be enforced?
        if ($this->getOption('limit_register')) {
            add_filter('registration_errors', array($this, 'filterRegistration'));
            add_filter('login_message', array($this, 'filterLoginMessageRegistration'));
            add_action('login_head', array($this, 'addErrorMessageRegister'), 9);
        }

        // Disable password reset?
        if ($this->getOption('reset_pwd_disable') || $this->getOption('reset_pwd_by_username_disable')) {
            add_filter('allow_password_reset', array($this, 'filterPasswordReset'), 10, 2);
        }

        // Are we in admin?
        if (is_admin()) {
            // Add submenu
            add_action('admin_menu', array($this, 'initAdminMenu'));
            //$GLOBALS['warpdriveLLAOptionsPage'] = admin_url('')
            // TODO: add admin hooks
        }
    }

    /**************************************************
     * Options
     **************************************************/

    /**
     * Initialize options
     * @return mixed
     */
    public function initOptions() {
        // Load from database
        $this->options = $this->isMultisite() ?
            get_site_option(WarpdriveLLAOptions) : get_option(WarpdriveLLAOptions);

        // If there are no options, use default options
        $defaults = &$GLOBALS['WarpdriveLimitLoginAttemptsOptionsDefault'];
        if (!is_array($this->options)) {
            $this->options = $defaults;
        }
    }

    /**
     * Save current options
     */
    private function saveOptions() {
        // Try to save options to database
        if ($this->isMultisite()) {
            if (get_site_option(WarpdriveLLAOptions) === false)
                add_site_option(WarpdriveLLAOptions, $this->options, '', true);
            else
                update_site_option(WarpdriveLLAOptions, $this->options);
        } else {
            if (get_option(WarpdriveLLAOptions) === false)
                add_option(WarpdriveLLAOptions, $this->options, '', true);
            else
                update_option(WarpdriveLLAOptions, $this->options);
        }
    }

    /**
     * Sanitize a given set of options
     * @param $options
     * @return mixed
     */
    public function sanitizeOptions($options) {
        $defaults = &$GLOBALS['WarpdriveLimitLoginAttemptsOptionsDefault'];

        // Sanitize options
        foreach ($this->options as $name=>$value) {
            // Does the option name exist?
            if (!isset($defaults[$name])) {
                unset($this->options[$name]);
                continue;
            }
            // Cast option value to correct type
            $this->options[$name] = $this->castOption($name, $value);
        }

        // Make sure all default options exist
        foreach ($defaults as $name=>$value) {
            if (!isset($this->options[$name]))
                $this->options[$name] = $value;
        }

        // Specific sanitation follows

        // Email after
        $emailAfter = max(1, intval($this->options['email_after']));
        $this->options['email_after'] = min($this->options['allowed_lockouts'], $emailAfter);

        // Lockout notify
        $allowed = explode(',', WarpdriveLLANotifyMethods);
        $args = explode(',', $this->options['lockout_notify']);
        $newArgs = array();
        foreach ($args as $arg) {
            if (in_array($arg, $allowed))
                $newArgs[] = $arg;
        }
        $this->options['lockout_notify'] = $newArgs;

        // Register notify
        $allowed = explode(',', WarpdriveLLANotifyMethods);
        $args = explode(',', $this->options['register_notify']);
        $newArgs = array();
        foreach ($args as $arg) {
            if (in_array($arg, $allowed))
                $newArgs[] = $arg;
        }
        $this->options['register_notify'] = $newArgs;

        return $options;
    }

    /**
     * Cast option to valid value type
     * @param $name string Name of the option
     * @param $value mixed Current value of the option
     * @return mixed Casted value
     */
    public function castOption($name, $value) {
        $defaults = &$GLOBALS['WarpdriveLimitLoginAttemptsOptionsDefault'];
        // Is this a valid option?
        if (!isset($defaults[$name]))
            return null;
        // Cast value to default type
        return $this->castOptionValue($value, $defaults[$name]);
    }

    /**
     * Cast a value to the same type as default value
     * @param $value mixed Current value
     * @param $default mixed Default value
     * @return mixed Casted value
     */
    public function castOptionValue($value, $default) {
        if (is_bool($default))
            return !!$value;
        if (is_numeric($default))
            return intval($value);
        return strval($value);
    }

    /**
     * Get option from class options
     * @param $name string Name of the option to get
     * @return mixed Value of the option, null if not existing
     */
    public function getOption($name) {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    /**************************************************
     * General
     **************************************************/

    /**
     * @return bool True if site is running as multisite
     */
    public function isMultisite() {
        return function_exists('get_site_option') && function_exists('is_multisite') && is_multisite();
    }

    /**
     * Get correct client IP address
     * @param string $typeName
     * @return string
     */
    private function getIpAddress($typeName='') {
        $type = $typeName;
        if (empty($type))
            $type = WarpdriveLLADirectAddr;

        // Get server value
        if (!empty($typeName) && isset($_SERVER[$type]))
            return $_SERVER[$type];

        // Not found, try direct address and then proxy address
        if (isset($_SERVER[WarpdriveLLADirectAddr])) {
            return $_SERVER[WarpdriveLLADirectAddr];
        } else if (isset($_SERVER[WarpdriveLLAProxyAddr])) {
            return $_SERVER[WarpdriveLLAProxyAddr];
        }

        // Failsafe
        return '';
    }

    /**
     * Get transformed name for saved option
     * @param $name string Name to convert
     * @return string
     */
    private function getName($name) {
        return 'WarpdriveLLA-'.$name;
    }

    /**
     * Check if current IP address is in lockout list
     * @return bool True if IP address is not in lockouts list
     */
    private function isLoginOk() {
        return !$this->ipInList($this->getList('lockouts'));
    }

    /**************************************************
     * Lists
     **************************************************/

    /**
     * Get list/array from options
     * @param $name string Name of list/array to lookup
     * @return array|mixed|void
     */
    private function getList($name) {
        // Get real option name
        $name = $this->getName($name);
        // Get option from database
        $list = $this->isMultisite() ? get_site_option($name) : get_option($name);
        // Is it a valid array?
        if (is_array($list))
            return $list;

        return array();
    }

    /**
     * Save list as option
     * @param $name string Name of the list
     * @param $list array The list to save
     */
    private function saveList($name, $list) {
        // Get real name
        $listName = $this->getName($name);

        // Does this options already exist?
        $exists = $this->isMultisite() ? get_site_option($listName) : get_option($listName);
        if ($exists === false) {
            // Create new option
            $autoload = in_array($name, explode(',', WarpdriveLLAOptionsAutoLoad));
            if ($this->isMultisite())
                add_site_option($listName, $list, '', $autoload);
            else
                add_option($listName, $list, '', $autoload);
        } else {
            if ($this->isMultisite())
                update_site_option($listName, $list);
            else
                update_option($listName, $list);
        }
    }

    /**
     * Check if ip address is in the list
     * @param $list
     * @param string $ip
     * @return bool True if array exists, ip key is in array, and value (time) is larger or equal than current time
     */
    private function ipInList($list, $ip=null) {
        // Get IP address if needed
        if (!$ip)
            $ip = $this->getIpAddress();
        // Is the ip in the list?
        return (is_array($list) && isset($list[$ip]))
            && time() <= $list[$ip];
    }

    /**
     * Clean login data when needed
     * @param null $attempts New attempts list (may be null)
     * @param null $lockouts New lockout list (may be null)
     * @param null $valid New valid list (may be null)
     */
    private function cleanup($attempts=null, $lockouts=null, $valid=null) {
        // Get current time
        $now = time();

        // Lock out list
        $forceSave = !is_null($lockouts);
        if (is_null($lockouts) || !is_array($lockouts))
            $lockouts = $this->getList('lockouts');

        $changed = false;
        foreach ($lockouts as $ip => $time) {
            // Should the entry be removed?
            if ($time >= $now)
                continue; // Not yet

            // Clear entry
            unset($lockouts[$ip]);
            $changed = true;
        }
        if ($changed || $forceSave)
            $this->saveList('lockouts', $lockouts);

        // Attempts and valid
        $forceSave = !(is_null($attempts) && is_null($valid));
        if (is_null($attempts) || !is_array($attempts)) {
            $attempts = $this->getList('attempts');
        }
        if (is_null($valid) || !is_array($valid))
            $valid = $this->getList('valid');

        $changed = false;
        foreach ($valid as $ip => $time) {
            // Should the entry be removed?
            if ($time >= $now)
                continue; // Not yet

            unset($attempts[$ip]);
            unset($valid[$ip]);
            $changed = true;
        }

        // Make sure attempts is sane as well
        foreach ($attempts as $ip => $amount) {
            // Is the IP set in valid?
            if (isset($valid[$ip]))
                continue; // Exists

            // Remove entry
            unset($attempts[$ip]);
            $changed = true;
        }

        if ($changed || $forceSave) {
            $this->saveList('attempts', $attempts);
            $this->saveList('attemptsValid', $valid);
        }

        // Do we have to clean register data as well?
        if ($this->getOption('limit_register')) {
            // Get lists
            $registrations = $this->getList('registrations');
            $valid = $this->getList('registrationsValid');

            $changed = false;
            foreach ($valid as $ip => $time) {
                // Should the entry be removed?
                if ($time >= $now)
                    continue; // Not yet

                unset($valid[$ip]);
                unset($registrations[$ip]);
                $changed = true;
            }

            // Make sure registrations is sane as well
            foreach ($registrations as $ip => $amount) {
                // Is the IP set in valid?
                if (isset($valid[$ip]))
                    continue; // Exists

                unset($registrations[$ip]);
                $changed = true;
            }

            if ($changed) {
                $this->saveList('registrations', $registrations);
                $this->saveList('registrationsValid', $valid);
            }
        }
    }

    /**************************************************
     * Notifies
     **************************************************/

    /**
     * Register lockout of $username
     * @param $username string Username to add
     */
    private function notifyLockout($username) {
        $notifies = explode(',', $this->getOption('lockout_notify'));

        // Log notify
        if (in_array('log', $notifies)) {
            $this->notifyLog($username);
        }
    }

    /**
     * Register lockout of $username in registration
     * @param $username string Username to add
     */
    private function notifyLockoutRegistration($username) {
        $notifies = explode(',', $this->getOption('register_notify'));

        // Log notify
        if (in_array('log', $notifies)) {
            $this->notifyLog($username, 'lockoutRegistrationLog');
        }
    }

    /**
     * Register username and last lockout time
     *
     * Log format:
     *  [ip][0] time of last attempt
     *  [ip][1] total lockouts on ip
     *  [ip][2][username] total lockouts on username
     *
     * @param $username string Username used in lockout
     * @param $logName string Name of the log to write to
     */
    private function notifyLog($username, $logName='lockoutLog') {
        // Get log
        $log = $this->getList($logName);
        // Get IP address
        $ip = $this->getIpAddress();

        if (isset($log[$ip])) {
            $entry = &$log[$ip];
            $entry[0] = time(); // Set last lockout time
            $entry[1]++; // Increase total lockouts of ip
            $entry[2][$username]++; // Increase total lockouts of username
        } else {
            // Create new log entry
            $log[$ip] = array(
                time(), // Last lockout time
                1, // Total number of lockouts
                array( $username => 1 ) // Total lockouts of username
            );
        }

        // Save log
        $this->saveList($logName, $log);
    }

    /**************************************************
     * Statistics
     **************************************************/

    /**
     * Initialize statistics
     */
    private function initStatistics() {
        // Get statistics from options
        $this->statistics = $this->getList('statistics');

        // Do we have a filled list?
        if (empty($this->statistics)) {
            // Default entries
            $this->statistics = array(
                'lockoutTotal' => 0,
                'lockoutRegistrationTotal' => 0
            );
        }
    }

    /**
     * Get a value of a statistic
     * @param $name string Name of the statistic to retrieve
     * @return int Value of the statistic
     */
    public function getStatistic($name) {
        if (isset($this->statistics[$name]))
            return $this->statistics[$name];
        return 0;
    }

    /**
     * Set a value of a given statistic
     * @param $name string Name of the statistic
     * @param $value int Value of the statistic
     */
    private function setStatistic($name, $value) {
        $this->statistics[$name] = $value;
        $this->saveList('statistics', $this->statistics);
    }

    /**
     * Increase a given statistic
     * @param $name string Name of the statistic
     */
    private function incStatistic($name, $value=1) {
        if (isset($this->statistics[$name]))
            $this->statistics[$name] += $value;
        else
            $this->statistics[$name] = $value;
        $this->saveList('statistics', $this->statistics);
    }

    /**************************************************
     * Messages
     **************************************************/

    /**
     * @return string Current login message
     */
    private function getMessage() {
        // Is the login ok?
        if (!$this->isLoginOk())
            return $this->getMessageError();
        // Return remaining message
        return $this->getMessageRemaining();
    }

    /**
     * Should we show a message in the login screen?
     * @return bool True if message should be shown
     */
    private function shouldLoginShowMessage() {
        // Are we trying to reset a password?
        if (isset($_GET['key'])) {
            return false;
        }

        // Get action
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

        return $action != 'lostpassword' && $action != 'retrievepassword'
            && $action != 'resetpass' && $action != 'rp' && $action != 'register';
    }

    /**
     * @param string $listName name of the list to lookup lock outs
     * @param string $msg Alternative message
     * @return string Error string
     */
    private function getMessageError($listName='lockouts', $msg='') {
        // Get IP address and list
        $ip = $this->getIpAddress();
        $list = $this->getList($listName);

        // Create message
        if ($msg == '')
            $msg = __('<strong>ERROR</strong>: Too many failed login attempts.', 'warpdrive').' ';

        if (!isset($list[$ip]) || time() >= $list[$ip]) {
            // No lock out? This should not happen
            $msg .= __('Please try again later.', 'warpdrive');
            return $msg;
        }

        $until = ceil(($list[$ip] - time()) / 60);
        if ($until > 60) {
            $until = ceil($until / 60);
            $msg .= sprintf(_n('Please try again in %d hour.', 'Please try again in %d hours.', $until, 'warpdrive'), $until);
        } else {
            $msg .= sprintf(_n('Please try again in %d minute.', 'Please try again in %d minutes.', $until, 'warpdrive'), $until);
        }

        return $msg;
    }

    /**
     * Construct remaining attempts message
     * @return string Remaining attempts message
     */
    public function getMessageRemaining() {
        // Get IP address, attempts list, valid list
        $ip = $this->getIpAddress();
        $attempts = $this->getList('attempts');
        $valid = $this->getList('attemptsValid');

        // Show we show remaining attempts
        if (!isset($attempts[$ip]) || !isset($valid[$ip]) || time() > $valid[$ip]) {
            // No: no valid entry
            return '';
        }

        // Are attempts exhausted?
        $allowed = $this->getOption('allowed_attempts');
        if (($attempts[$ip] % $allowed) == 0) {
            // No: already been locked out for these attempts
            return '';
        }

        $remaining = max(($allowed - ($attempts[$ip] % $allowed)), 0);
        return sprintf(_n('<strong>%d</strong> attempts remaining.', '<strong>%d</strong> attempts remaining.', $remaining, 'warpdrive'), $remaining);
    }

    /**************************************************
     * Login
     **************************************************/

    /**
     * Action when login attempt failed
     * - Increase number of attempts
     * - Reset valid value
     * - Setup lock out if attempts above threshold
     * @param $username string
     */
    public function wpLoginFailed($username) {
        // Get IP
        $ip = $this->getIpAddress();

        // Get lock outs setting
        $lockouts = $this->getList('lockouts');
        if ($this->ipInList($lockouts, $ip)) {
            // Already locked out, do not add attempts
            return;
        }

        // Not locked out
        // Get list of attempts
        $attempts = $this->getList('attempts');
        $valid = $this->getList('attemptsValid');

        // Check validity and add one to attempts
        if (isset($attempts[$ip]) && $this->ipInList($valid, $ip)) {
            $attempts[$ip]++;
        } else {
            $attempts[$ip] = 1;
        }
        $valid[$ip] = time() + $this->getOption('valid_duration');

        // Is attempts above threshold?
        if (($attempts[$ip] % $this->getOption('allowed_attempts')) != 0) {
            // No lock out (yet)
            // Clean attempts and valid values
            $this->cleanup($attempts, null, $valid);
            return;
        }

        // Lock out!
        $this->feJustLockedout = true;

        // Setup lock out, reset attempts if needed
        $attemptsLong = $this->getOption('allowed_attempts') * $this->getOption('allowed_lockouts');
        if ($attempts[$ip] >= $attemptsLong) {
            // Long lock out, reset attempts
            $lockouts[$ip] = time() + $this->getOption('lockout_long_duration');
            unset($attempts[$ip]);
            unset($valid[$ip]);
        } else {
            // Normal lock out, retries and valid are kept
            $lockouts[$ip] = time() + $this->getOption('lockout_duration');
        }

        // Clean attempts, lockouts and valid values
        $this->cleanup($attempts, $lockouts, $valid);

        // Send notify if needed
        $this->notifyLockout($username);

        // Increase statistics
        $this->incStatistic('lockoutTotal');
    }

    public function errorMessageAdd() {
        // Is the error message already shown?

        if (!$this->shouldLoginShowMessage() || $this->feErrorShown)
            return;

        // Setup the message
        $msg = $this->getMessage();

        if ($msg != '') {
            global $error;
            $this->feErrorShown = true;
            $error .= $msg;
        }
    }

    public function errorMessagesFix($content) {
        // Should a message be shown?
        if (!$this->shouldLoginShowMessage()) {
            return $content;
        }

        // During lock out we do not want to show any other error messages
        // like unknown user or empty password, unless this was the attempt
        // that locked us out
        if (!$this->isLoginOk() && !$this->feJustLockedout) {
            return $this->getMessageError();
        }

        // We want to filter the messages 'Invalid username' and 'Invalid
        // password' as that is an information leak regarding user account
        // names.
        // Also, if there are more than one error messages, put an extra
        // <br /> tag between them.
        $messages = explode("<br />\n", $content);

        // Remove last item if it's empty
        if (strlen(end($messages)) == 0) {
            array_pop($messages);
        }

        // Save array size
        $count = count($messages);
        $warnCount = $this->feErrorShown ? 1 : 0;

        if ($this->feNonEmptyCredentials && $count > $warnCount) {
            // Replace error message
            $content = __('<strong>ERROR</strong>: Incorrect username or password.', 'warpdrive')."<br />\n";
            if ($this->feErrorShown) {
                $content .= "<br />\n".$this->getMessage()."<br />\n";
            }
            return $content;
        } else if ($count <= 1) {
            return $content;
        }

        $new = '';
        while ($count-- > 0) {
            $new .= array_shift($messages)."<br />\n";
            if ($count > 0) {
                $new .= "<br />\n";
            }
        }

        return $new;
    }

    /**
     * Filter: allow login attempt? (Called from wp_authenticate())
     * @param $user
     * @param $password
     * @return WP_Error
     */
    public function wpAuthenticateUser($user, $password) {
        if (is_wp_error($user) || $this->isLoginOk())
            return $user;

        // Report error shown
        $this->feErrorShown = true;

        // Create a new error
        // This error should be the same as in "shake it" filter below
        $error = new WP_Error();
        $error->add(WarpdriveLLAErrorIdentifier, $this->errorMessageAdd());
        return $error;
    }

    /**
     * Filter: Add this failure to login page
     * @param $error_codes
     * @return array
     */
    public function shakeFailure($error_codes) {
        $error_codes[] = WarpdriveLLAErrorIdentifier;
        return $error_codes;
    }

    /**
     * Keep track of empty state of username and password
     * This is to filter errors correctly
     * @param $username
     * @param $password
     */
    public function trackCredentials($username, $password) {
        $this->feNonEmptyCredentials = !empty($username) && !empty($password);
    }

    /**************************************************
     * Cookie
     **************************************************/

    /**
     * Action: Called at plugins_loaded (very early) so we can be sure we
     * do not allow cookies to be authenticed when locked out
     * @return mixed
     */
    public function handleCookie() {
        if ($this->isLoginOk())
            return;
        // We're in lockout list, clear cookie
        $this->clearAuthCookie();
    }

    /**
     * Action: failed cookie login wrapper
     */
    public function wpCookieFailed($cookieElements) {
        $this->clearAuthCookie();
        $this->wpLoginFailed($cookieElements['username']);
    }

    /**
     * Action: failed cookie login hash
     * Make sure same invalid cookie does not get counted more than once
     * Requires WordPress version 3.0.0 or above
     * @param $cookieElements array Elements from cookie
     */
    public function wpCookieFailedHash($cookieElements) {
        // Clear current cookie contents
        $this->clearAuthCookie();

        // Extract variables from cookie
        $username = "";
        extract($cookieElements, EXTR_OVERWRITE);

        // Check if we have a valid user
        $user = get_user_by('login', $username); // Username comes from $cookieElements
        if (!$user) {
            // Should not happen for this action
            $this->wpLoginFailed($username);
            return;
        }

        // Check if the cookie matches the previous cookie
        $previousCookie = get_user_meta($user->ID, 'warpdriveLLA_prevCookie', true);
        if ($previousCookie && $previousCookie == $cookieElements) {
            // Identical cookies, ignore this attempt
            return;
        }

        // Store cookie
        if ($previousCookie) {
            update_user_meta($user->ID, 'warpdriveLLA_prevCookie', $cookieElements);
        } else {
            add_user_meta($user->ID, 'warpdriveLLA_prevCookie', $cookieElements, true);
        }

        // Report fail
        $this->wpLoginFailed($username);
    }

    /**
     * Action: successful cookie login
     * Clear any stored cookie in user meta
     * Requires WordPress version 3.0.0 or above
     * @param $cookieElement
     * @param $user
     */
    public function cookieValid($cookieElement, $user) {
        if (get_user_meta($user->ID, 'warpdriveLLA_prevCookie'))
            delete_user_meta($user->ID, 'warpdriveLLA_prevCookie');
    }

    /**
     * Make sure auth cookie is cleared (for current session as well)
     */
    private function clearAuthCookie() {
        wp_clear_auth_cookie();

        if (!empty($_COOKIE[AUTH_COOKIE])) {
            $_COOKIE[AUTH_COOKIE] = '';
        }
        if (!empty($_COOKIE[SECURE_AUTH_COOKIE])) {
            $_COOKIE[SECURE_AUTH_COOKIE] = '';
        }
        if (!empty($_COOKIE[LOGGED_IN_COOKIE])) {
            $_COOKIE[LOGGED_IN_COOKIE] = '';
        }
    }

    /**************************************************
     * Password reset
     **************************************************/

    /**
     * Check if user has level capability
     * @param $userId int User id
     * @param $level int Required level
     * @return bool True if capability is met
     */
    private function userHasLevel($userId, $level) {
        $userId = intval($userId);
        $level = intval($userId);

        if ($userId <= 0)
            return false;

        $user = new WP_User($userId);
        return ($user && $user->has_cap($level));
    }

    /**
     * Filter: enforce that password reset is allowed
     * @param $b
     * @param $userId
     */
    public function filterPasswordReset($b, $userId) {
        $limit = null;

        // What is the privilige level to use reset?
        if ($this->getOption('reset_pwd_disable')) {
            // Limit on all password resets from level
            $limit = $this->getOption('reset_pwd_level');
        }

        // Is there a limit on userid's?
        if ($this->getOption('reset_pwd_by_username_disable') && !strpos($_POST['user_login'], '@')) {
            // Limit on password reset using user level
            $limitUser = $this->getOption('reset_pwd_by_username_level');

            // Use lowest limit
            if (is_null($limit) || $limit > $limitUser)
                $limit = $limitUser;
        }

        // Is the current reset limited?
        if (is_null($limit)) {
            return $b;
        }

        // Test if the current user has this level
        if (!$this->userHasLevel($userId, $limit)) {
            return $b;
        }

        // Not allowed, use same error as retrieve_password()
        $error = new WP_Error();
        $error->add('invalidcombo', __('<strong>ERROR</strong>: Invalid username or e-mail.', 'warpdrive'));
        return $error;
    }

    /**************************************************
     * Registration
     **************************************************/

    /**
     * Filter: check if new registration os allowed, and filter error messages
     * to remove possibility to brute force user login
     */
    public function filterRegistration($errors) {
        // Set error shown
        $this->feErrorShown = true;

        // Is registration allowed?
        if (!$this->isRegistrationOk()) {
            $errors = new WP_Error();
            $errors->add('lockout', $this->errorMessageRegister());
            return $errors;
        }

        // Not locked out, enforce error message filter and count attempts
        // if there are no errors.
        if (!is_wp_error($errors)) {
            $this->registrationAdd();
            return $errors;
        }

        // If more than one error message (meaning both login and email was invalid)
        // we strip any 'username_exists' message.
        // This is to stop someone from trying different usernames with a known bad
        // / empty email address.
        $codes = $errors->get_error_codes();
        if (count($codes) <= 1) {
            if (count($codes) == 0)
                $this->registrationAdd();
            return $errors;
        }

        $key = array_search('username_exists', $codes);
        if ($key !== false) {
            // Entry exists
            unset($codes[$key]);

            $oldErrors = $errors;
            $errors = new WP_Error();
            foreach ($codes as $key=>$code)
                $errors->add($code, $oldErrors->get_error_message($code));
        }

        return $errors;
    }

    /**
     * Filter: remove other registration error messages
     * @param $content
     * @return string
     */
    public function filterLoginMessageRegistration($content) {
        if ($this->isRegistrationPage() && !$this->isRegistrationOk())
            return '';

        return $content;
    }

    public function addErrorMessageRegister() {
        global $error;

        if ($this->isRegistrationPage() && !$this->isRegistrationOk()
            && !$this->feErrorShown) {
            $error = $this->errorMessageRegistration();
            $this->feErrorShown = true;
        }
    }

    /**
     * Should we shouw errors and messages on this page?
     * @return bool
     */
    public function isRegistrationPage() {
        if (isset($_GET['key'])) {
            // Reset password
            return false;
        }

        $action = isset($_GET['action']) ? $_REQUEST['action'] : '';
        return $action == 'register';
    }

    /**
     * Construct message for registration lockout
     */
    private function errorMessageRegistration() {
        $msg = __('<strong>ERROR</strong>: Too many new user registrations.', 'warpdrive'). ' ';
        $this->getMessageError('registrationsValid', $msg);
    }

    /**
     * Handle bookkeeping when new user is registered
     * Increase nr of registrations and reset valid value
     */
    private function registrationAdd() {
        // Is limiting enabled?
        if (!$this->getOption('limit_register'))
            return;

        // Get IP address
        $ip = $this->getIpAddress();

        // Get lists with registrations and valid information
        $regs  = $this->getList('registrations');
        $valid = $this->getList('registrationsValid');

        // Check validity and add one registration
        if (isset($regs[$ip]) && isset($valid[$ip]) && time() < $valid[$ip])
            $regs[$ip]++;
        else
            $regs[$ip] = 1;
        $valid[$ip] = time() + $this->getOption('register_duration');

        // Save lists
        $this->saveList('registrations', $regs);
        $this->saveList('registrationsValid', $valid);

        // Registration lockout? Increase statistic
        if ($regs[$ip] >= $this->getOption('register_amount'))
            $this->incStatistic('lockoutRegisterTotal');

        $this->cleanup();
    }

    private function isRegistrationOk() {
        // Is registration limited?
        if (!$this->getOption('limit_register'))
            return true; // No restriction

        // Get IP address
        $ip = $this->getIpAddress();

        // Not too many (valid) registrations?
        $regs  = $this->getList('registrations');
        $valid = $this->getList('registrationsValid');
        $allowed = $this->getOption('register_amount');

        return !isset($regs[$ip]) || $regs[$ip] < $allowed || !$this->ipInList($valid, $ip);
    }

    /**************************************************
     * Admin
     **************************************************/

    /**
     * Add submenus to Warpdrive
     */
    public function initAdminMenu() {
        add_submenu_page('warpdrive_dashboard', __('Limit Login Attempts Options', 'warpdrive'), __('Limit Login Options', 'warpdrive'), 'manage_options', 'warpdrive_limitloginattempts', array($this, 'pageOptions'));
        add_submenu_page('warpdrive_dashboard', __('Limit Login Attempts Log', 'warpdrive'), __('Limit Login Log', 'warpdrive'), 'manage_options', 'warpdrive_limitloginattempts_log', array($this, 'pageLog'));
    }

    /**
     * Create a select field with $options and select $selected
     * @param $name
     * @param $options
     * @param null $selected
     */
    private function formSelect($name, $options, $selected=null) {
        // Start
        echo '<select name="'.$name.'">';
        // Print each option
        foreach ($options as $key=>$value) {
            if ($key == $selected)
                echo '<option selected="selected" value="'.$key.'">'.$value.'</option>';
            else
                echo '<option value="'.$key.'">'.$value.'</option>';
        }
        // End
        echo '</select>';
    }

    /**
     * Show a div with an admin message
     * @param $msg Message to show
     */
    public function adminMessage($msg) {
        echo '<div id="message" class="updated fade"><p>'.$msg.'</p></div>';
    }

    public function pageOptions() {
        // Clean module data
        $this->cleanup();

        if (!current_user_can('manage_options'))
            wp_die(__('Sorry, but you do not have permissions to change settings.', 'warpdrive'));

        // Check various GET fields for option resets
        // Should we reset lockoutTotal?
        if (isset($_GET['reset_lockout_statistic'])) {
            $this->setStatistic('lockoutTotal', 0);
            $this->adminMessage(__('Reset lockout total statistic', 'warpdrive'));
        }
        // Should we clear current lockout list?
        if (isset($_GET['reset_lockout_current'])) {
            $this->saveList('lockouts', array());
            $this->adminMessage(__('Reset current lockout list', 'warpdrive'));
        }
        // Should we reset lockoutRegisterTotal?
        if (isset($_GET['reset_lockout_register_statistic'])) {
            $this->setStatistic('lockoutRegisterTotal', 0);
            $this->adminMessage(__('Reset regiser lockout total statistic', 'warpdrive'));
        }
        // Should we reset lockoutRegister?
        if (isset($_GET['reset_lockout_register_current'])) {
            $this->saveList('lockoutsRegister', array());
            $this->adminMessage(__('Reset current register lockout list', 'warpdrive'));
        }

        // Make sure if post, that post was from this page
        if (count($_POST)) {
            // Are we allowed to edit options?
            check_admin_referer('warpdrive-limitloginattempts-options');

            // Set options with new values
            $this->options['allowed_attempts'] = $_POST['optAllowedAttempts']+0;
            $this->options['lockout_duration'] = ($_POST['optLockoutDuration']+0) * 60;
            $this->options['allowed_lockouts'] = $_POST['optAllowedLockouts']+0;
            $this->options['lockout_long_duration'] = ($_POST['optLockoutLongDuration']+0) * 3600;
            $this->options['valid_duration'] = ($_POST['optValidDuration']+0) * 3600;
            $this->options['handle_cookies'] = ($_POST['optHandleCookies']+0) == 1;
            $lockoutNotify = array();
            if ($_POST['optNotifyLockoutLog']+0)
                $lockoutNotify[] = 'log';
            $this->options['lockout_notify'] = join(',', $lockoutNotify);
            $this->options['limit_register'] = $_POST['optLimitRegister']+0 == 1;
            $this->options['register_amount'] = $_POST['optRegisterAmount']+0;
            $this->options['register_duration'] = ($_POST['optRegisterDuration']+0) * 3600;
            $lockoutNotify = array();
            if ($_POST['optNotifyLockoutRegisterLog']+0)
                $lockoutNotify[] = 'log';
            $this->options['register_notify'] = join(',', $lockoutNotify);
            $this->options['reset_pwd_by_username_disable'] = ($_POST['optDisablePwdResetByUsername']+0) == 1;
            $this->options['reset_pwd_by_username_level'] = $_POST['optDisablePwdResetByUsernameFrom']+0;
            $this->options['reset_pwd_disable'] = ($_POST['optDisablePwdReset']+0) == 1;
            $this->options['reset_pwd_level'] = $_POST['optDisablePwdResetFrom']+0;

            $this->options = $this->sanitizeOptions($this->options);
            $this->saveOptions();
            $this->adminMessage(__('Options changed', 'warpdrive'));
        }

        // Setup variables for admin page
        // Cookies
        $optHandleCookies = $this->getOption('handle_cookies') ? ' checked' : '';
        // Notify on lockout
        $notifyLockoutOptions = explode(',', $this->getOption('lockout_notify'));
        $optNotifyLockoutLog = in_array('log', $notifyLockoutOptions) ? ' checked' : '';
        // Password reset levels
        $userLevels = array(
            0 => __('Subscriber'),
            1 => __('Contributor'),
            2 => __('Author'),
            7 => __('Editor'),
            10 => __('Administrator')
        );
        $optDisablePwdResetByUsername = $this->getOption('reset_pwd_by_username_disable') ? ' checked' : '';
        $optDisablePwdReset = $this->getOption('reset_pwd_disable') ? ' checked' : '';
        // New registrations
        $notifyLockoutOptions = explode(',', $this->getOption('register_notify'));
        $optNotifyLockoutRegisterLog = in_array('log', $notifyLockoutOptions) ? ' checked' : '';

        ?>
        <div class="wrap">
            <h3><?php _e('Limit Login Attempts - Statistics', 'warpdrive'); ?></h3>
            <table>
                <tr>
                    <td></td>
                    <th align="left"><?php _e('Total'); ?></th>
                    <th align="left"><?php _e('Current'); ?></th>
                </tr>
                <tr>
                    <th align="left"><?php _e('Login lockouts', 'warpdrive'); ?></th>
                    <td align="right"><?php echo $this->getStatistic('lockoutTotal'); ?> <small><a href="<?php echo admin_url('admin.php?page=warpdrive_limitloginattempts&reset_lockout_statistic=1'); ?>">Reset</a></small></td>
                    <td align="right"><?php echo count($this->getList('lockouts')); ?> <small><a href="<?php echo admin_url('admin.php?page=warpdrive_limitloginattempts&reset_lockout_current=1'); ?>">Reset</a></small></td>
                </tr>
                <tr>
                    <th align="left"><?php _e('Registration lockouts', 'warpdrive'); ?></th>
                    <td align="right"><?php echo $this->getStatistic('lockoutRegisterTotal'); ?> <small><a href="<?php echo admin_url('admin.php?page=warpdrive_limitloginattempts&reset_lockout_register_statistic=1'); ?>">Reset</a></small>
                    </td>
                    <td align="right"><?php echo count($this->getList('lockoutsRegister')); ?> <small><a href="<?php echo admin_url('admin.php?page=warpdrive_limitloginattempts&reset_lockout_register_current=1'); ?>">Reset</a></small></td>
                </tr>
            </table>
            <h3><?php _e('Limit Login Attempts Options', 'warpdrive'); ?></h3>
            <form method="post" action="<?php echo admin_url('admin.php?page=warpdrive_limitloginattempts'); ?>">
                <?php wp_nonce_field('warpdrive-limitloginattempts-options'); ?>
                <table>
                    <tr><th align="left" style="font-size: 1.5em;"><?php _e('Lockout', 'warpdrive'); ?></th></tg></tr>
                    <tr>
                        <td><label for="optAllowedAttempts" title=""><?php _e('Allowed attempts', 'warpdrive'); ?></label></td>
                        <td><input type="text" name="optAllowedAttempts" id="optAllowedAttempts" value="<?php echo $this->getOption('allowed_attempts'); ?>"></td>
                    </tr>

                    <tr>
                        <td><label for="optLockoutDuration" title=""><?php _e('Lockout duration (minutes)', 'warpdrive'); ?></label></td>
                        <td><input type="text" name="optLockoutDuration" id="optLockoutDuration" value="<?php echo $this->getOption('lockout_duration') / 60; ?>"></td>
                    </tr>

                    <tr>
                        <td><label for="optAllowedLockouts" title=""><?php _e('Allowed lockouts', 'warpdrive'); ?></label></td>
                        <td><input type="text" name="optAllowedLockouts" id="optAllowedLockouts" value="<?php echo $this->getOption('allowed_lockouts'); ?>"></td>
                    </tr>

                    <tr>
                        <td><label for="optLockoutLongDuration" title=""><?php _e('Extended lockouts duration (hours)', 'warpdrive'); ?></label></td>
                        <td><input type="text" name="optLockoutLongDuration" id="optLockoutLongDuration" value="<?php echo $this->getOption('lockout_long_duration') / 3600; ?>"></td>
                    </tr>

                    <tr>
                        <td><label for="optValidDuration" title=""><?php _e('Time until attempts are reset (hours)', 'warpdrive'); ?></label></td>
                        <td><input type="text" name="optValidDuration" id="optValidDuration" value="<?php echo $this->getOption('valid_duration') / 3600; ?>"></td>
                    </tr>

                    <tr>
                        <td><label for="optHandleCookies"><?php _e('Handle cookie logins', 'warpdrive'); ?></label></td>
                        <td><input type="checkbox" name="optHandleCookies" id="optHandleCookies"<?php echo $optHandleCookies; ?> value="1" /></td>
                    </tr>

                    <tr><th align="left" style="font-size: 1.5em; padding-top: 1em;"><?php _e('Notify on lockout', 'warpdrive'); ?></th></tr>
                    <tr>
                        <td colspan="2">
                            <label>
                                <input type="checkbox" name="optNotifyLockoutLog"<?php echo $optNotifyLockoutLog; ?> value="1" />
                                <?php _e('Register in log', 'warpdrive'); ?>
                            </label><br />
                            <label>
                                <input type="text" name="optEmailAfter" size="3" maxlength="4" value="<?php echo $this->getOption('email_after'); ?>" />
                                <?php __('lockouts.', 'warpdrive'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr><th align="left" style="font-size: 1.5em; padding-top: 1em;"><?php _e('Password reset', 'warpdrive'); ?></th></tr>
                    <tr>
                        <td colspan="2">
                            <label>
                                <input type="checkbox" name="optDisablePwdResetByUsername"<?php echo $optDisablePwdResetByUsername; ?> value="1" />
                                <?php _e('Disable password reset using username for users of level', 'warpdrive'); ?>
                            </label>
                            <label>
                                <?php $this->formSelect('optDisablePwdResetByUsernameFrom', $userLevels, $this->getOption('reset_pwd_by_username_level')) ?>
                                <?php _e('and higher.', 'warpdrive'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <label>
                                <input type="checkbox" name="optDisablePwdReset"<?php echo $optDisablePwdReset; ?> value="1" />
                                <?php _e('Disable password reset for users of level', 'warpdrive'); ?>
                            </label>
                            <label>
                                <?php $this->formSelect('optDisablePwdResetFrom', $userLevels, $this->getOption('reset_pwd_level')); ?>
                                <?php _e('and higher.', 'warpdrive'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr><th align="left" style="font-size: 1.5em; padding-top: 1em;"><?php _e('New user registration', 'warpdrive'); ?></th></tr>
                    <tr>
                        <td colspan="2">
                            <label>
                                <input type="checkbox" name="optLimitRegister"<?php echo $optLimitRegister; ?> value="1" />
                                <?php _e('Limit new user registrations to', 'warpdrive'); ?>
                            </label>
                            <input type="text" name="optRegisterAmount" size="3" maxlength="4" value="<?php echo $this->getOption('register_amount'); ?>" />
                            <?php _e('registrations every', 'warpdrive'); ?>
                            <input type="text" name="optRegisterDuration" size="3" maxlength="4" value="<?php echo $this->getOption('register_duration') / 3600; ?>" />
                            <?php _e('hours per IP address', 'warpdrive'); ?>
                        </td>
                    </tr>
                    <tr><td style="font-weight: bold;"><?php _e('Notify on register lockout', 'warpdrive'); ?></td></tr>
                    <tr>
                        <td colspan="2">
                            <label>
                                <input type="checkbox" name="optNotifyLockoutRegisterLog"<?php echo $optNotifyLockoutRegisterLog; ?> value="1" />
                                <?php _e('Register in log', 'warpdrive'); ?>
                            </label><br />
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="updateOptions" class="button-primary" value="<?php _e('Save Options','warpdrive'); ?>" />
                </p>
            </form>
        </div>
        <?php
    }

    public function pageLog() {
        ?><h3><?php _e('Limit Login Attempts - Logs', 'warpdrive'); ?></h3>
        <div class="wrap">
        <table style="width: 100%;">
            <tr>
                <td valign="top">
                    <h3>Login lockout log</h3>
                    <table>
                        <tr>
                            <td style="font-weight: bold"><?php _e('IP address', 'warpdrive'); ?></td>
                            <td style="font-weight: bold"><?php _e('Attempts', 'warpdrive'); ?></td>
                            <td style="font-weight: bold"><?php _e('Last attempt', 'warpdrive'); ?></td>
                        </tr>
<?php   foreach ($this->getList('lockoutLog') as $ip=>$entry) { ?>
                        <tr>
                            <td><?php echo $ip ?></td>
                            <td align="right"><?php echo $entry[1]; ?></td>
                            <td align="right"><?php echo date("d-m-Y H:i", $entry[0]); ?></td>
                        </tr>
<?php       foreach ($entry[2] as $user=>$value) { ?>
                        <tr>
                            <td align="right">-></td>
                            <td align="right"><?php echo $value; ?></td>
                            <td><?php echo $user; ?></td>
                        </tr>
<?php       } ?>
<?php   } ?>
                    </table>
                    </dl>
                </td>
                <td valign="top">
                    <h3>Registration lockout log</h3>
                </td>
            </tr>
        </table>
        </div><?php
    }
}

function debug($var) {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
}

add_action('init', array('WarpdriveLimitLoginAttempts', 'init'));
