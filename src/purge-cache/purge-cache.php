<?php
/**
 * Purge Cache
 * Uses W3 Total Cache plugin to empty cache
 *
 * @author Ferdi van der Werf <ferdi@savvii.nl>
 */

// Check if W3 Total Cache is installed and active
$w3tc_active = false;
if (in_array("w3-total-cache/w3-total-cache.php", get_option("active_plugins"))) {
    // W3 Total Cache installed and active
    $w3tc_active = true;
}

function admin_purge_bar_init() {
    // If the current user can't write posts, it is of no use to allow empty cache
    if (!current_user_can('activate_plugins')) {
        return;
    }

    global $wp_admin_bar;

    // Add purge button to admin bar
    $wp_admin_bar->add_menu(array(
        'id' => "warpdrive-purge",
        'title' => __("Purge cache", 'warpdrive'),
        'href' => wp_nonce_url(admin_url("admin.php?page=w3tc_general&w3tc_flush_all"), "w3tc")
    ));
}

// If W3 Total Cache is not active, no cache can be emptied
if ($w3tc_active) {
    add_action('admin_bar_menu', 'admin_purge_bar_init', 90);
}
