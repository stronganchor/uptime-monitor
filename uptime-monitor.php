<?php
/*
Plugin Name: Uptime Monitor
Plugin URI: https://github.com/stronganchor/uptime-monitor/
Description: A plugin to monitor URLs and report their HTTP status.
Version: 1.0.0
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com/
*/

function uptime_monitor_menu() {
    add_menu_page(
        'Uptime Monitor',
        'Uptime Monitor',
        'manage_options',
        'uptime-monitor',
        'uptime_monitor_page',
        'dashicons-admin-links'
    );
}
add_action('admin_menu', 'uptime_monitor_menu');

function uptime_monitor_page() {
    if (isset($_POST['submit'])) {
        update_option('uptime_monitor_urls', sanitize_textarea_field($_POST['urls']));
    }

    $urls = get_option('uptime_monitor_urls', '');
    $url_list = explode("\n", $urls);

    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor</h1>';
    echo '<form method="post">';
    echo '<label for="urls">Enter URLs to monitor (one per line):</label><br>';
    echo '<textarea name="urls" rows="10" cols="50">' . esc_textarea($urls) . '</textarea><br>';
    echo '<input type="submit" name="submit" class="button button-primary" value="Save URLs">';
    echo '</form>';

    echo '<h2>URL Status Report</h2>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>URL</th><th>Status Code</th></tr></thead>';
    echo '<tbody>';
    foreach ($url_list as $url) {
        $url = trim($url);
        if (!empty($url)) {
            $status_code = uptime_monitor_check_status($url);
            echo '<tr><td>' . esc_url($url) . '</td><td>' . esc_html($status_code) . '</td></tr>';
        }
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

function uptime_monitor_check_status($url) {
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return 'Error';
    } else {
        return wp_remote_retrieve_response_code($response);
    }
}

function uptime_monitor_schedule_task() {
    if (!wp_next_scheduled('uptime_monitor_hourly_check')) {
        wp_schedule_event(time(), 'hourly', 'uptime_monitor_hourly_check');
    }
}
add_action('init', 'uptime_monitor_schedule_task');

function uptime_monitor_perform_check() {
    // Perform the URL monitoring task here
    // You can store the results in the database or send notifications if needed
}
add_action('uptime_monitor_hourly_check', 'uptime_monitor_perform_check');
