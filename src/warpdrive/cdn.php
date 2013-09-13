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
        return
            "\n<!-- ---------------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- Start ---------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- ---------------------------------------------------------------------------------------------------------- -->\n\n".
            $buffer.
            "\n<!-- -------------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- End ---------------------------------------------------------------------------------------------------- -->\n".
            "\n<!-- -------------------------------------------------------------------------------------------------------- -->\n\n";
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
        ) {
            return false;
        }

        // True in all other cases
        return true;
    }

    /**
     * Output buffer callback
     * @param string $buffer Unprocessed output buffer
     * @return string Processed output buffer
     */
    public function ob_callback_cdn_filter(&$buffer) {
        // Check if we're dealing with xml or html
        if ($buffer == "" || !$this->is_buffer_xml_or_html($buffer)) {
            // Do not process buffer
            return $buffer;
        }

        return $buffer;
    }


    private function is_buffer_xml_or_html(&$buffer) {
        // Get first 1000 characters of buffer
        $check_buffer = substr($buffer, 0, 1000);

        // If comments are found, remove them
        if (strstr($check_buffer, '<!--') !== false) {
            // Remove comment
            $check_buffer = preg_replace('@<!--.*?-->@s', '', $check_buffer);
        }

        // Trim all whitespace characters
        $check_buffer = ltrim($check_buffer, "\x00\x09\x0A\x0D\x20\xBB\xBF\xEF");

        // Check if buffer starts with <?xml, <html or <!DOCTYPE
        return (
            stripos($check_buffer, '<?xml') === 0
                || stripos($check_buffer, '<html') === 0
                || stripos($check_buffer, '<!DOCTYPE') === 0
        );
    }

}

new WarpdriveCdn();
