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
        add_action('admin_menu', array($this, 'admin_menu_init'), 1000);
    }

    public function admin_menu_init() {
        add_submenu_page('warpdrive_dashboard', __('Read server logs', 'warpdrive'), __('Read server logs', 'warpdrive'), 'manage_options', 'warpdrive_readlogs', array($this, 'readlogs_page'));
    }

    public function readlogs_page() {
        // Show contents
        echo '<h2>'.__('Read logs', 'warpdrive').'</h2>';
        echo '
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
                <th>'.__('Log', 'warpdrive').'</th>
                <th>'.__('Last lines in the file', 'warpdrive').'</th>
            </tr>
            <tr>
                <td>'.__('Access log', 'warpdrive').'</td>
                <td>
                    <a href="'.admin_url('admin.php?page=warpdrive_readlogs&file=access&lines=10').'">'.__('10 lines', 'warpdrive').'</a> &nbsp;
                    <a href="'.admin_url('admin.php?page=warpdrive_readlogs&file=access&lines=100').'" >'.__('100 lines', 'warpdrive').'</a>
            </tr>
            <tr>
                <td>'.__('Error log', 'warpdrive').'</td>
                <td>
                    <a href="'.admin_url('admin.php?page=warpdrive_readlogs&file=error&lines=10').'">'.__('10 lines', 'warpdrive').'</a> &nbsp;
                    <a href="'.admin_url('admin.php?page=warpdrive_readlogs&file=error&lines=100').'" >'.__('100 lines', 'warpdrive').'</a>
            </tr>
        </table>';

        $file = null;
        $name = null;
        if ($_GET['file'] == "access") {
            $name = __('Access log', 'warpdrive');
            $file = BASE . "../../../../log/access.log";
        } else if($_GET['file'] == "error") {
            $name = __('Error log', 'warpdrive');
            $file = BASE . "../../../../log/error.log";
        }

        // No file selected, quit
        if (!$file)
            return;

        $lines = 10;
        switch($_GET['lines']) {
            case '10':
            case '100':
                $lines = $_GET['lines']+0;
        }

        if (@file_exists($file)) {
            // Get contents as array
            $file_lines = explode("\n", @file_get_contents($file));
            // Remove last entry (it is empty)
            array_pop($file_lines);
            // Get slice of array we want
            $file_lines = array_slice($file_lines, -1 * $lines);
            $total_lines = count($file_lines);

            echo '<div style="font-size: 2em; margin-top: 1em;">'.$name.'</div>';
            if ($total_lines) {
                echo '<ol>';
                foreach ($file_lines as $line) {
                    echo "<li>$line</li>";
                }
                echo '</ol>';
            } else {
                echo '<p style="padding:  5px; color: #D00;">'.__('No content in log', 'warpdrive').'</p>';
            }

        } else {
            echo '<p style="color: #D00; font-weight: bold;">'.__('File not found! Please contact support.', 'warpdrive').'</p>';
        }
    }
}

add_action('init', array('WarpdriveReadLogs', 'init'));