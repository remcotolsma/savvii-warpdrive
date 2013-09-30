<?php
/**
 * Read logs
 * Allows administrators to read the access and error log
 *
 * @author Ferdi van der Werf <ferdi@savvii.nl>
 */

class WarpdriveReadLogs {

    /**
     * Singleton
     */
    public function init() {
        static $instance = null;

        if (!$instance) {
            $instance = new WarpdriveReadLogs;
        }
    }

    /**
     * Constructor
     */
    public function WarpdriveReadLogs() {
        // Add module menu items to warpdrive menu
        add_action('admin_menu', array($this, 'admin_menu_init'));
    }

    public function admin_menu_init() {
        add_submenu_page('warpdrive_dashboard', __('Read server logs', 'warpdrive'), __('Read server logs', 'warpdrive'), 'manage_options', 'warpdrive_readlogs', array($this, 'readlogs_page'));
    }

    public function readlogs_page() {
        // Show contents
        printf('<h2>%s</h2>', __('Read logs', 'warpdrive'));
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
                <th><?php _e('Log', 'warpdrive') ?></th>
                <th><?php _e('Last lines in the file', 'warpdrive') ?></th>
            </tr>
            <tr>
                <td><?php _e('Access log', 'warpdrive') ?></td>
                <td>
                    <a href="<?php p_raw(admin_url('admin.php?page=warpdrive_readlogs&file=access&lines=10')) ?>'"><?php _e('10 lines', 'warpdrive') ?></a> &nbsp;
                    <a href="<?php p_raw(admin_url('admin.php?page=warpdrive_readlogs&file=access&lines=100')) ?>"><?php _e('100 lines', 'warpdrive') ?></a>
            </tr>
            <tr>
                <td><?php _e('Error log', 'warpdrive') ?></td>
                <td>
                    <a href="<?php p_raw(admin_url('admin.php?page=warpdrive_readlogs&file=error&lines=10')) ?>"><?php _e('10 lines', 'warpdrive') ?></a> &nbsp;
                    <a href="<?php p_raw(admin_url('admin.php?page=warpdrive_readlogs&file=error&lines=100')) ?>" ><?php _e('100 lines', 'warpdrive') ?></a>
            </tr>
        </table>
        <?php

        $file_regexp = null;
        $name = null;
        if ($_GET['file'] == "access") {
            $name = __('Access log', 'warpdrive');
            $file_regexp = ABSPATH . "../log/*.access.log";
        } else if($_GET['file'] == "error") {
            $name = __('Error log', 'warpdrive');
            $file_regexp = ABSPATH . "../log/*.error.log";
        }

        // No file selected, quit
        if (!$file_regexp)
            return;

        $lines = 10;
        switch($_GET['lines']) {
            case '10':
            case '100':
                $lines = $_GET['lines']+0;
        }

        $files = glob($file_regexp);
        if (is_array($files) && count($files) > 0 && @file_exists($files[0])) {
            // Get contents as array
            $file_lines = explode("\n", @file_get_contents($files[0]));
            // Remove last entry (it is empty)
            array_pop($file_lines);
            // Get slice of array we want, reverse it to have last line first in array
            $file_lines = array_reverse(array_slice($file_lines, -1 * $lines));
            $total_lines = count($file_lines);

            printf('<div style="font-size: 2em; margin-top: 1em;">%s</div>', h($name));
            if ($total_lines) {
                p_raw('<ol>');
                foreach ($file_lines as $line) {
                    printf("<li>%s</li>", h($line));
                }
                p_raw('</ol>');
            } else {
                printf('<p style="padding:  5px; color: #D00;">%s</p>', __('No content in log', 'warpdrive'));
            }

        } else {
            printf('<p style="color: #D00; font-weight: bold;">File not found! Please contact <a href="http://support.savvii.nl/" target="_blank">support</a>.</p>');
        }
    }
}

add_action('init', array('WarpdriveReadLogs', 'init'));