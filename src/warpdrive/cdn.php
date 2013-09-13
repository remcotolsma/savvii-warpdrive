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

        // TODO: Upload links
        // TODO: Include links
        // TODO: Theme links
        // TODO: Minify processing

        foreach ($regexps as $regexp) {
            $buffer = preg_replace_callback($regexp, array($this, 'process_link'), $buffer);
        }

        $buffer =
            "\n<!-- ---------------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- Start ---------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- ---------------------------------------------------------------------------------------------------------- -->\n\n".
            $buffer.
            "\n<!-- -------------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- End ---------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- -------------------------------------------------------------------------------------------------------- -->\n\n";

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


    }

    private function local_uri_to_cdn_uri($local_uri) {
        if ($this->is_wpmu() || Warpdrive::is_multisite())
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

}

new WarpdriveCdn();
