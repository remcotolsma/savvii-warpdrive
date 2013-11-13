<?php

class Evvii_Cache {

    private $register_events = array(
        // Run when a post is published, or if it is edited and its status is "published".
        'publish_post',
        // Run just after a post or page is trashed.
        'trashed_post',
        // Run when a page is published, or if it is edited and its status is "published".
        'publish_page',
        // Run just before an attached file is deleted from the database.
        'deleted_attachment',
        // Run when an attached file is edited/updated to the database.
        'edit_attachment',
        // Run when the blog's theme is changed.
        'switch_theme',
        // Run after the rewrite rules are generated.
        'generate_rewrite_rules',
        // Run after comment changed state (approved, unapproved, trash, spam)
        'transition_comment_status',
        // Run after comment is edited
        'edit_comment',
    );

    private $flush_required = false;
    private $flush_failed = false;
    private $flush_missing_token = false;

    public function __construct() {
        // Add initialization action
        add_action( 'init', array( $this, 'init' ) );
        // Add to top bar
        add_action( 'admin_bar_menu', array( $this, 'admin_bar_init' ), 90 );
        add_action( 'admin_notices', array( $this, 'admin_notice_widgets' ) );
    }

    public function init() {
        // Register flush event for required events
        foreach ( $this->register_events as $event ) {
            //add_action( $event, array( $this, 'prepare_flush' ), 10, 2 );
            add_action( $event, array( $this, 'prepare_flush_'.$event ), 10, 2 );
        }
        // Register execute on shutdown
        add_action( 'shutdown', array( $this, 'execute_flush' ) );
        // Do we need to do a cache flush now?
        if ( isset( $_REQUEST['savvii_flush_now'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'warpdrive' ) ) {
            // Flush now
            $this->execute_flush( true );
            // Did the flush fail?
            if ( $this->flush_failed ) {
                if ( $this->flush_missing_token ) {
                    // Show token failed message
                    add_action( 'admin_notices', array( $this, 'msg_flush_failed_token' ) );
                } else {
                    // Show general failed message
                    add_action( 'admin_notices', array( $this, 'msg_flush_failed' ) );
                }
            } else {
                // Show completed message
                add_action( 'admin_notices', array($this, 'msg_flushed_message' ) );
            }
        }
    }

    /**
     * Show notice regarding widgets and automatic flush
     */
    public function admin_notice_widgets() {
        $page = get_current_screen();
        if ( $page->id != 'widgets' ) {
            return;
        }

        ?>
        <div class="updated">
            <p><?php _e( '<strong>Note: </strong>When changing widgets, flush is not automatically cleared! Use Flush now in admin bar when you finished editing widgets.', 'warpdrive' ); ?></p>
        </div>
        <?php
    }

    public function admin_bar_init() {
        global $wp_admin_bar;
        // Add option to menu bar
        $wp_admin_bar->add_menu(
            array(
                'id' => 'evvii_cache_delete',
                'title' => __( 'Flush cache', 'warpdrive' ),
                'href' => wp_nonce_url( admin_url( 'admin.php?page=warpdrive_dashboard&savvii_flush_now' ), 'warpdrive' ),
            )
        );
    }

    public function prepare_flush() {
        do_action( 'warpdrive_prepare_flush' );
        $this->flush_required = true;
    }

    public function __call($name, $arguments) {
        if ( strncmp( 'prepare_flush_', $name, 14 ) === 0 ) {
            do_action( 'warpdrive_'.$name );
            error_log( 'warpdrive_'.$name );
            $this->flush_required = true;
        }
    }

    public function execute_flush( $forced = false ) {
        if ( $forced || $this->flush_required ) {
            do_action( 'warpdrive_execute_flush' );
            // Check if the token for Evvii is present
            $token = Warpdrive::get_option( WARPDRIVE_OPT_ACCESS_TOKEN );
            if ( is_null( $token ) ) {
                $this->flush_failed = true;
                $this->flush_missing_token = true;
                return;
            }

            // Build cache delete url
            $url = WARPDRIVE_EVVII_LOCATION.'/v1/caches/'.$token;

            // Call Evvii
            $http = new WP_Http();
            $result = $http->request(
                $url,
                array(
                    'method' => 'DELETE',
                    'httpversion' => '1.1',
                    'sslverify' => false,
                    'headers' => array(
                        'Authorization' => 'Token token="'.$token.'"',
                    ),
                )
            );

            // On WP_Error, set flush failed and return
            if ( $result instanceof WP_Error ) {
                $this->flush_failed = true;
                return;
            }

            // Get return code from result
            $code = isset( $result['response'] ) ? ( isset( $result['response']['code'] ) ? $result['response']['code'] : 500 ) : 500;

            // If return header has code 200 or 204, set cache as flushed
            if ( $code == 200 || $code == 204 ) {
                $this->flush_failed = false;
            } else {
                // TODO: [5] Show proper error message
                $this->flush_failed = true;
            }
        }
    }

    public function msg_flushed_message() {
        ?><div class="updated fade"><p><strong><?php _e( 'Cache flushed!', 'warpdrive' ) ?></strong></p></div><?php
    }

    public function msg_flush_failed() {
        ?><div class="updated fade"><p><strong><?php _e( 'Cache could not be flushed! Tech team has been notified!', 'warpdrive' ) ?></strong></p></div><?php
    }

    public function msg_flush_failed_token() {
        ?><div class="updated fade"><p><strong><?php _e( 'Cache could not be flushed! Administration token has not been set!', 'warpdrive' ) ?></strong></p></div><?php
    }

}

new Evvii_Cache();
