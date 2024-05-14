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
    if (isset($_POST['add_site'])) {
        $new_site = sanitize_text_field($_POST['new_site']);
        if (!empty($new_site)) {
            $sites = get_option('uptime_monitor_sites', array());
            if (!in_array($new_site, $sites)) {
                $sites[] = $new_site;
                update_option('uptime_monitor_sites', $sites);
                uptime_monitor_perform_check($new_site);
            }
        }
    }

    if (isset($_POST['remove_site'])) {
        $site_to_remove = sanitize_text_field($_POST['remove_site']);
        $sites = get_option('uptime_monitor_sites', array());
        $sites = array_diff($sites, array($site_to_remove));
        update_option('uptime_monitor_sites', $sites);
        uptime_monitor_delete_result($site_to_remove);
    }

    if (isset($_POST['edit_site'])) {
        $old_site = sanitize_text_field($_POST['old_site']);
        $new_site = sanitize_text_field($_POST['new_site']);
        if (!empty($new_site)) {
            $sites = get_option('uptime_monitor_sites', array());
            $index = array_search($old_site, $sites);
            if ($index !== false) {
                $sites[$index] = $new_site;
                update_option('uptime_monitor_sites', $sites);
                uptime_monitor_update_result($old_site, $new_site);
            }
        }
    }

    if (isset($_POST['check_all_sites'])) {
        $sites = get_option('uptime_monitor_sites', array());
        foreach ($sites as $site) {
            uptime_monitor_perform_check($site);
        }
    }

    $sites = get_option('uptime_monitor_sites', array());

    // Check if MainWP is installed and active
    if (is_plugin_active('mainwp/mainwp.php')) {
        // Retrieve the list of child sites from MainWP
        $mainwp_sites = uptime_monitor_get_mainwp_sites();
        $new_sites = array_diff($mainwp_sites, $sites);
        $sites = array_unique(array_merge($sites, $mainwp_sites));
        update_option('uptime_monitor_sites', $sites);
        foreach ($new_sites as $site) {
            uptime_monitor_perform_check($site);
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor</h1>';

    echo '<form method="post">';
    echo '<label for="new_site">Add New Site:</label>';
    echo '<input type="text" name="new_site" id="new_site">';
    echo '<input type="submit" name="add_site" class="button button-primary" value="Add Site">';
    echo '</form>';

    echo '<form method="post" style="display: inline;">';
    echo '<input type="submit" name="check_all_sites" class="button button-secondary" value="Check All Sites">';
    echo '</form>';

    echo '<h2>Sites to Monitor</h2>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>URL</th><th>Status Code</th><th>Keyword Match</th><th>Actions</th></tr></thead>';
    echo '<tbody>';

    $results = get_option('uptime_monitor_results', array());

    foreach ($sites as $site) {
        $status = isset($results[$site]['status']) ? $results[$site]['status'] : 'N/A';
        $keyword_match = isset($results[$site]['keyword_match']) ? $results[$site]['keyword_match'] : 'N/A';

        echo '<tr>';
        echo '<td>' . esc_url($site) . '</td>';
        echo '<td>' . esc_html($status) . '</td>';
        echo '<td>' . esc_html($keyword_match) . '</td>';
        echo '<td>';
        echo '<form method="post" style="display: inline;">';
        echo '<input type="hidden" name="remove_site" value="' . esc_attr($site) . '">';
        echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'Are you sure you want to remove this site?\')">Remove</button>';
        echo '</form>';
        echo ' ';
        echo '<form method="post" style="display: inline;">';
        echo '<input type="hidden" name="old_site" value="' . esc_attr($site) . '">';
        echo '<input type="text" name="new_site" value="' . esc_attr($site) . '">';
        echo '<button type="submit" name="edit_site" class="button button-secondary">Update</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';
}

function uptime_monitor_get_mainwp_sites() {
    global $wpdb;

    $mainwp_sites = array();

    // Retrieve the list of child sites from MainWP
    $table_name = $wpdb->prefix . 'mainwp_wp';
    $query = "SELECT url FROM $table_name";
    $results = $wpdb->get_results($query);

    foreach ($results as $result) {
        $mainwp_sites[] = $result->url;
    }

    return $mainwp_sites;
}

function uptime_monitor_check_status($url) {
    $response = wp_remote_get($url);
    
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        return array(
            'status' => "Error: $error_message",
            'keyword_match' => 'N/A'
        );
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $status_message = wp_remote_retrieve_response_message($response);
        
        $keyword_match = 'No match found';
        if ($status_code === 200) {
            $page_content = wp_remote_retrieve_body($response);
            
            // Extract visible text from the HTML content
            $visible_text = extract_visible_text($page_content);
            
            // Extract the base domain name from the URL
            $domain = parse_url($url, PHP_URL_HOST);
            $base_domain = preg_replace('/^www\./', '', $domain);
            $base_domain = preg_replace('/\.[^.]+$/', '', $base_domain);
            
            // Convert the base domain name to a regex pattern
            $pattern = str_split($base_domain);
            $pattern = implode('\s*', $pattern);
            
            // Search for the pattern in the visible text (case-insensitive)
            if (preg_match("/{$pattern}/i", $visible_text, $matches)) {
                $keyword_match = $matches[0];
            }
        }
        
        return array(
            'status' => ($status_code === 200) ? "OK ($status_code)" : "Error ($status_code): $status_message",
            'keyword_match' => $keyword_match
        );
    }
}

function extract_visible_text($html) {
    // Create a new DOMDocument object
    $dom = new DOMDocument();

    // Load the HTML content into the DOMDocument object
    @$dom->loadHTML($html);

    // Create a new DOMXPath object
    $xpath = new DOMXPath($dom);

    // Query for all visible text nodes
    $text_nodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style)]');

    // Extract the visible text from the text nodes
    $visible_text = '';
    foreach ($text_nodes as $text_node) {
        $visible_text .= ' ' . trim($text_node->nodeValue);
    }

    return $visible_text;
}

function uptime_monitor_schedule_task() {
    if (!wp_next_scheduled('uptime_monitor_hourly_check')) {
        wp_schedule_event(time(), 'hourly', 'uptime_monitor_hourly_check');
    }
}
add_action('init', 'uptime_monitor_schedule_task');

function uptime_monitor_perform_check() {
    $urls = get_option('uptime_monitor_urls', '');
    $url_list = explode("\n", $urls);

    $results = array();

    foreach ($url_list as $url) {
        $url = trim($url);
        if (!empty($url)) {
            $status_code = uptime_monitor_check_status($url);
            $results[$url] = $status_code;
        }
    }

    update_option('uptime_monitor_results', $results);
}
add_action('uptime_monitor_hourly_check', 'uptime_monitor_perform_check');
