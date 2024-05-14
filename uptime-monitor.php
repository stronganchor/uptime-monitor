<?php
/*
Plugin Name: Uptime Monitor
Plugin URI: https://github.com/stronganchor/uptime-monitor/
Description: A plugin to monitor URLs and report their HTTP status.
Version: 1.0.1
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
    
    if (isset($_POST['check_all_sites'])) {
        $sites = uptime_monitor_get_mainwp_sites();
        foreach ($sites as $site) {
            uptime_monitor_perform_check($site);
        }
    }

    if (isset($_POST['update_keyword'])) {
        $site = sanitize_text_field($_POST['site']);
        $keyword = sanitize_text_field($_POST['keyword']);
        $keywords = get_option('uptime_monitor_keywords', array());
        $keywords[$site] = $keyword;
        update_option('uptime_monitor_keywords', $keywords);
    }

    $sites = uptime_monitor_get_mainwp_sites();
    $results = get_option('uptime_monitor_results', array());
    $keywords = get_option('uptime_monitor_keywords', array());

    // Sort the sites based on status code and keyword match
    usort($sites, function($a, $b) use ($results) {
        $status_a = isset($results[$a]['status']) ? $results[$a]['status'] : 'N/A';
        $status_b = isset($results[$b]['status']) ? $results[$b]['status'] : 'N/A';
        $keyword_a = isset($results[$a]['keyword_match']) ? $results[$a]['keyword_match'] : 'N/A';
        $keyword_b = isset($results[$b]['keyword_match']) ? $results[$b]['keyword_match'] : 'N/A';

        if (strpos($status_a, 'Error') !== false && strpos($status_b, 'Error') === false) {
            return -1;
        } elseif (strpos($status_a, 'Error') === false && strpos($status_b, 'Error') !== false) {
            return 1;
        } elseif ($keyword_a === 'No match found' && $keyword_b !== 'No match found') {
            return -1;
        } elseif ($keyword_a !== 'No match found' && $keyword_b === 'No match found') {
            return 1;
        } else {
            return strcasecmp($a, $b);
        }
    });

    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor</h1>';

    echo '<form method="post" style="display: inline;">';
    echo '<input type="submit" name="check_all_sites" class="button button-primary" value="Check All Sites">';
    echo '</form>';

    echo '<h2>Sites to Monitor</h2>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>URL</th><th>Status Code</th><th>Keyword Match</th><th>Custom Keyword</th></tr></thead>';
    echo '<tbody>';

    foreach ($sites as $site) {
        $status = isset($results[$site]['status']) ? $results[$site]['status'] : 'N/A';
        $keyword_match = isset($results[$site]['keyword_match']) ? $results[$site]['keyword_match'] : 'N/A';
        $custom_keyword = isset($keywords[$site]) ? $keywords[$site] : '';

        $status_class = (strpos($status, 'Error') !== false) ? 'error' : '';
        $keyword_class = ($keyword_match === 'No match found') ? 'error' : '';

        echo '<tr>';
        echo '<td><a href="' . esc_url($site) . '" target="_blank">' . esc_html($site) . '</a></td>';
        echo '<td class="' . $status_class . '">' . esc_html($status) . '</td>';
        echo '<td class="' . $keyword_class . '">' . esc_html($keyword_match) . '</td>';
        echo '<td>';
        echo '<form method="post" style="display: inline;">';
        echo '<input type="hidden" name="site" value="' . esc_attr($site) . '">';
        echo '<input type="text" name="keyword" value="' . esc_attr($custom_keyword) . '">';
        echo '<input type="submit" name="update_keyword" class="button button-secondary" value="Update">';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';
}

function uptime_monitor_enqueue_styles() {
    echo '<style>
        .error {
            color: red !important;
            font-weight: bold;
        }
    </style>';
}
add_action('admin_head', 'uptime_monitor_enqueue_styles');

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
            
            // Get the custom keyword for the site, if available
            $keywords = get_option('uptime_monitor_keywords', array());
            $custom_keyword = isset($keywords[$url]) ? $keywords[$url] : '';

            if (!empty($custom_keyword)) {
                // Search for the custom keyword in the visible text (case-insensitive)
                if (preg_match("/{$custom_keyword}/i", $visible_text, $matches)) {
                    $keyword_match = $matches[0];
                }
            } else {
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

function uptime_monitor_perform_check($site) {
    $result = uptime_monitor_check_status($site);
    $results = get_option('uptime_monitor_results', array());
    $results[$site] = $result;
    update_option('uptime_monitor_results', $results);
}
add_action('uptime_monitor_hourly_check', 'uptime_monitor_perform_check');
