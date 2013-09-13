<?php

class WarpdriveCdn {

    public function __construct() {
        // If we can do ob, do it
        if ($this->can_ob()) {
            // Register CDN ob functions
            // Header
            add_action('wp_head',   array($this, 'start'), -999999998);
            add_action('wp_head',   array($this, 'end'),    999999998);
            // Body (start after wp_head, end before wp_footer)
            add_action('wp_head',   array($this, 'start'),  999999999);
            add_action('wp_footer', array($this, 'end'),   -999999999);
            // Footer
            add_action('wp_footer', array($this, 'start'), -999999998);
            add_action('wp_footer', array($this, 'end'),    999999998);
        }
    }

    public function start() {
        ob_start(array($this, 'process'));
    }

    public function end() {
        ob_end_flush();
    }

    public function process(&$buffer) {
        $regexps = array();
        $site_path = $this->get_site_path();
        $domain_regexp = $this->get_url_regexp($this->get_domain_url());
        $general_mask = "*.css;*.js;*.gif;*.png;*.jpg;*.xml;*.ico;*.ttf;*.otf,*.woff";

        // TODO: Upload links

        // Everything from wp-content
        $regexps[] = $regexps[] = '~(["\'(])\s*(('.$domain_regexp.')?('.$this->preg_quote($site_path.'wp-content').'/('.$this->get_regexp_by_mask($general_mask).')))~';

        // Include files
        $regexps[] = $regexps[] = '~(["\'(])\s*(('.$domain_regexp.')?('.$this->preg_quote($site_path.WPINC).'/('.$this->get_regexp_by_mask($general_mask).')))~';

        // Theme links
        $theme_dir = preg_replace('~'.$domain_regexp.'~i', '', get_theme_root_uri());
        $regexps[] = '~(["\'(])\s*(('.$domain_regexp.')?('.$this->preg_quote($theme_dir).'/('.$this->get_regexp_by_mask($general_mask).')))~';

        // TODO: Minify processing

        foreach ($regexps as $regexp) {
            $buffer = preg_replace_callback($regexp, array($this, 'process_link'), $buffer);
        }

        return $buffer;
    }

    public function process_link($matches) {
        list($match, $quote, $url, , , , $path) = $matches;
        $path = ltrim($path, '/');
        return $this->process_link_replace($match, $quote, $url, $path);
    }

    private $replaced_urls = array();

    public function process_link_replace($match, $quote, $url, $path) {

        // Check if url was already replaced
        if (isset($this->replaced_urls[$url])) {
            return $quote.$this->replaced_urls[$url];
        }

        // Get site name
        static $site_name = null;
        if (is_null($site_name)) {
            $site_name = Warpdrive::get_option('warpdrive.site_name');
        }

        // Try to create a CDN link    
        if ($site_name) {
            $cdn_index = rand(0, 9);
            $new_url = $quote.sprintf('%s://cdn%d.%s.savviihq.com/%s', $this->get_scheme(), $cdn_index, $site_name, $path);

            // Save url for repeated requests
            $this->replaced_urls[$url] = $new_url;

            return $new_url;
        }

        // No replacement, return original link
        return $match;

    }

    private function local_uri_to_cdn_uri($local_uri) {
        if (Warpdrive::is_network() && defined('DOMAIN_MAPPING') && DOMAIN_MAPPING) {
            $local_uri = str_replace($this->get_site_url(), '', $local_uri);
        }

        return ltrim($local_uri, '/');
    }

    /**
     * Get scheme of link
     * @return string
     */
    private function get_scheme() {
        // SSL cases
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'])
            return 'https';
        if (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) == 443)
            return 'https';
        // Default case
        return 'http';
    }

    /**
     * Check if we can modify contents
     * @return bool True if we can
     */
    private function can_ob() {
        // Skip in certain cases
        if (defined('WP_ADMIN')                    // Skip if admin
            || defined('DOING_AJAX')               // Skip if ajax
            || defined('DOING_CRON')               // Skip if cron
            || defined('APP_REQUEST')              // Skip if APP request
            || defined('XMLRPC_REQUEST')           // Skip if xmlrpc request
            || (defined('SHORTINIT') && SHORTINIT) // Skip if WPMU's and WP's 3.0 short init is detected
            // TODO: Should SSL return false as well?
        ) {
            return false;
        }

        // True in all other cases
        return true;
    }

    /**
     * Get site url from options
     * @return string Site url
     */
    private function get_site_url() {
        static $site_url = null;

        if (is_null($site_url)) {
            $site_url = Warpdrive::get_option('siteurl');
            $site_url = rtrim($site_url, '/');
        }

        return $site_url;
    }

    /**
     * Get path part of site url
     * @return string Path of site
     */
    private function get_site_path() {
        $site_url = $this->get_site_url();
        $parse_url = @parse_url($site_url);

        // Get path part
        if (is_array($parse_url) && isset($parse_url['path'])) {
            $site_path = '/'.ltrim($parse_url['path'], '/');
        } else {
            $site_path = '/';
        }

        // Add trailing /
        $site_path = rtrim($site_path, '/').'/';

        return $site_path;

    }

    /**
     * Get domain url
     * @return string Domain url
     */
    private function get_domain_url() {
        $home_url = get_home_url(); // TODO: Might cause problems on multisite instances
        $parse_url = @parse_url($home_url);

        if (is_array($parse_url) && isset($parse_url['scheme']) && isset($parse_url['host'])) {
            $port = isset($parse_url['port']) && $parse_url['port'] != 80 ? ':'.(int)$parse_url['port'] : '';
            return sprintf('%s://%s%s', $parse_url['scheme'], $parse_url['host'], $port);
        }

        return false;
    }

    /**
     * Get url regexp
     * @param string $url
     * @return string
     */
    private function get_url_regexp($url) {
        $url = preg_replace('~(https?:)//~i', '', $url);
        $url = preg_replace('~^www\.~i', '', $url);

        return '(https?:)?//(www\.)?'.$this->preg_quote($url);
    }

    /**
     * Quote regular expression string
     * @param string $string
     * @param string $delimiter
     * @return string
     */
    private function preg_quote($string, $delimiter=null) {
        $string = preg_quote($string, $delimiter);
        $string = strtr($string, array(
            ' ' => '\ '
        ));
        return $string;
    }

    /**
     * Return a regexp by mask
     * @param string $mask
     * @return string
     */
    private function get_regexp_by_mask($mask) {
        $mask = trim($mask);
        $mask = $this->preg_quote($mask);

        $mask = str_replace(
            array(
                '\*',
                '\?',
                ';'
            ),
            array(
                '@ASTERISK@',
                '@QUESTION@',
                '|'
            ),
            $mask
        );

        return str_replace(
            array(
                '@ASTERISK@',
                '@QUESTION@'
            ),
            array(
                '[^\\?\\*:\\|\'"<>]*',
                '[^\\?\\*:\\|\'"<>]'
            ),
            $mask
        );
    }

}

new WarpdriveCdn();
