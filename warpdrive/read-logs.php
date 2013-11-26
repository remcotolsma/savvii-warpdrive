<?php
/**
 * Read logs
 * Allows administrators to read the access and error log
 *
 * @author Ferdi van der Werf <ferdi@savvii.nl>
 */

class WarpdriveReadLogs {

    static $LOG_LINE_SIZE = 1024;

    /**
     * Singleton
     */
    public static function init() {
        static $instance = null;

        if ( ! $instance ) {
            $instance = new WarpdriveReadLogs;
        }
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Add module menu items to warpdrive menu
        add_action( 'admin_menu', array( $this, 'admin_menu_init' ) );
    }

    public function admin_menu_init() {
        add_submenu_page(
            'warpdrive_dashboard', // Parent slug
            __( 'Read server logs', 'warpdrive' ), // Page title
            __( 'Read server logs', 'warpdrive' ), // Menu title
            'manage_options', // Capability
            'warpdrive_readlogs', // Menu slug
            array( $this, 'readlogs_page' ) // Callback
        );
    }

    public function readlogs_page() {
        // Show contents
        printf( '<h2>%s</h2>', __( 'Read logs', 'warpdrive' ) );
        ?>
        <style type="text/css">
            #warpdrive-readlogs-head th {
                text-align: left;
            }
            #warpdrive-readlogs-head th,
            #warpdrive-readlogs-head td {
                padding-right: 10px;
            }
        </style>
        <table id="warpdrive-readlogs-head">
            <tr>
                <th><?php _e( 'Log', 'warpdrive' ) ?></th>
                <th><?php _e( 'Last lines in the file', 'warpdrive' ) ?></th>
            </tr>
            <tr>
                <td><?php _e( 'Access log', 'warpdrive' ) ?></td>
                <td>
                    <a href="<?php p_raw( admin_url( 'admin.php?page=warpdrive_readlogs&file=access&lines=10' ) ) ?>'"><?php _e( '10 lines', 'warpdrive' ) ?></a> &nbsp;
                    <a href="<?php p_raw( admin_url( 'admin.php?page=warpdrive_readlogs&file=access&lines=100' ) ) ?>"><?php _e( '100 lines', 'warpdrive' ) ?></a>
            </tr>
            <tr>
                <td><?php _e( 'Error log', 'warpdrive' ) ?></td>
                <td>
                    <a href="<?php p_raw( admin_url( 'admin.php?page=warpdrive_readlogs&file=error&lines=10' ) ) ?>"><?php _e( '10 lines', 'warpdrive' ) ?></a> &nbsp;
                    <a href="<?php p_raw( admin_url( 'admin.php?page=warpdrive_readlogs&file=error&lines=100' ) ) ?>" ><?php _e( '100 lines', 'warpdrive' ) ?></a>
            </tr>
        </table>
        <?php

        // Get site name
        $site_name = Warpdrive::get_option( WARPDRIVE_OPT_SITE_NAME );
        if ( is_null( $site_name ) ) {
            ?><h2>Access token needs to be set before logs can be read.</h2><?php
            return;
        }

        // Create file regexps
        $file_location = null;
        $name = null;
        if ( isset( $_GET['file'] ) ) {

            if ( $_GET['file'] == 'access' ) {
                $name = __( 'Access log', 'warpdrive' );
                $file_location = "/var/www/{$site_name}/log/{$site_name}.access.log";
            } else if ( $_GET['file'] == 'error' ) {
                $name = __( 'Error log', 'warpdrive' );
                $file_location = "/var/www/{$site_name}/log/{$site_name}.error.log";
            }
        }

        // No file selected, quit
        if ( is_null( $file_location ) )
            return;

        // How many lines to read?
        $lines = 10;
        switch ( $_GET['lines'] ) {
            case '10':
            case '100':
                $lines = intval( $_GET['lines'] );
        }

        // How many bytes to read?
        $bytes = $lines * self::$LOG_LINE_SIZE;

        if ( file_exists( $file_location ) ) {
            // Get last $bytes bytes from file
            $file_size = filesize( $file_location );
            $offset    = $file_size - $bytes;
            if ( $offset < 0 ) {
                $offset = 0;
                $bytes  = $file_size;
            }

            // Get contents as array, split on line ending
            $file_lines = explode( "\n", @file_get_contents( $file_location, null, null, $offset, $bytes ) );

            // Remove last entry (it is empty)
            array_pop( $file_lines );

            // Get slice of array we want, reverse it to have last line first in array
            $file_lines  = array_reverse( array_slice( $file_lines, -1 * $lines ) );
            $total_lines = count( $file_lines );

            // Iterate over lines and print them
            printf( '<div style="font-size: 2em; margin-top: 1em;">%s</div>', h( $name ) );
            if ( $total_lines ) {
                p_raw( '<ol>' );
                foreach ( $file_lines as $line ) {
                    printf( '<li>%s</li>', h( $line ) );
                }
                p_raw( '</ol>' );
            } else {
                printf( '<p style="padding:  5px; color: #D00;">%s</p>', __( 'No content in log', 'warpdrive' ) );
            }
        } else {
            p_raw( '<p style="color: #D00; font-weight: bold;">File not found! Please contact <a href="http://support.savvii.nl/" target="_blank">support</a>.</p>' );
        }
    }
}

add_action( 'init', array( 'WarpdriveReadLogs', 'init' ) );
