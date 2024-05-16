<?php
/*
Plugin Name: Uptime Monitor
Plugin URI: https://github.com/stronganchor/uptime-monitor/
Description: A plugin to monitor URLs and report their HTTP status.
Version: 1.0.7
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
        $failed_sites = array();
        
        foreach ($sites as $site) {
            uptime_monitor_perform_check($site);
            $result = uptime_monitor_check_status($site);
            
            if (strpos($result['status'], 'Error') !== false || $result['keyword_match'] === 'No match found') {
                $failed_sites[$site] = $result['status'] . ' - ' . $result['keyword_match'];
            }
        }
        
        if (!empty($failed_sites)) {
            uptime_monitor_send_notification($failed_sites);
        }
    }

    if (isset($_POST['update_keyword'])) {
        $site = sanitize_text_field($_POST['site']);
        $keyword = sanitize_text_field($_POST['keyword']);
        $keywords = get_option('uptime_monitor_keywords', array());
        $keywords[$site] = $keyword;
        update_option('uptime_monitor_keywords', $keywords);
        
        // Perform an immediate check for the site with the updated keyword
        uptime_monitor_perform_check($site);
        
        // Check if the site fails the checks after updating the keyword
        $result = uptime_monitor_check_status($site);
        if (strpos($result['status'], 'Error') !== false || $result['keyword_match'] === 'No match found') {
            $failed_sites = array(
                $site => $result['status'] . ' - ' . $result['keyword_match']
            );
            uptime_monitor_send_notification($failed_sites);
        }
    }

    if (isset($_POST['recheck_site'])) {
        $site = sanitize_text_field($_POST['site']);
        uptime_monitor_perform_check($site);
    }

    $sites = uptime_monitor_get_mainwp_sites();
    $results = get_option('uptime_monitor_results', array());
    $keywords = get_option('uptime_monitor_keywords', array());
    
    // Sort the sites based on status code, keyword match, and alphabetically by site URL
    usort($sites, function($a, $b) use ($results) {
        $site_url_a = $a['site_url'];
        $site_url_b = $b['site_url'];
        
        $status_a = isset($results[$site_url_a]['status']) ? $results[$site_url_a]['status'] : 'N/A';
        $status_b = isset($results[$site_url_b]['status']) ? $results[$site_url_b]['status'] : 'N/A';
        $keyword_a = isset($results[$site_url_a]['keyword_match']) ? $results[$site_url_a]['keyword_match'] : 'N/A';
        $keyword_b = isset($results[$site_url_b]['keyword_match']) ? $results[$site_url_b]['keyword_match'] : 'N/A';

        if (strpos($status_a, 'Error') !== false && strpos($status_b, 'Error') === false) {
            return -1;
        } elseif (strpos($status_a, 'Error') === false && strpos($status_b, 'Error') !== false) {
            return 1;
        } elseif ($keyword_a === 'No match found' && $keyword_b !== 'No match found') {
            return -1;
        } elseif ($keyword_a !== 'No match found' && $keyword_b === 'No match found') {
            return 1;
        } else {
            return strcasecmp($site_url_a, $site_url_b);
        }
    });

    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor</h1>';

    echo '<form method="post" style="display: inline;">';
    echo '<input type="submit" name="check_all_sites" class="button button-primary" value="Check All Sites">';
    echo '</form>';

    echo '<h2>Sites to Monitor</h2>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>URL</th><th>Site Title</th><th>Tags</th><th>Site Status</th><th>Keyword Match</th><th>Custom Keyword</th><th>Action</th></tr></thead>';
    echo '<tbody>';

    foreach ($sites as $site_info) {
        $site_url = $site_info['site_url'];
        $tags = $site_info['tags'];
        $status = isset($results[$site_url]['status']) ? $results[$site_url]['status'] : 'N/A';
        $keyword_match = isset($results[$site_url]['keyword_match']) ? $results[$site_url]['keyword_match'] : 'N/A';
        $custom_keyword = isset($keywords[$site_url]) ? $keywords[$site_url] : '';
        $site_title = isset($results[$site_url]['site_title']) ? $results[$site_url]['site_title'] : 'N/A';

        $status_class = (strpos($status, 'Error') !== false) ? 'error' : '';
        $keyword_class = ($keyword_match === 'No match found') ? 'error' : '';

        echo '<tr>';
        echo '<td><a href="' . esc_url($site_url) . '" target="_blank">' . esc_html($site_url) . '</a></td>';
        echo '<td>' . esc_html($site_title) . '</td>';
        echo '<td>' . esc_html($tags) . '</td>';
        echo '<td class="' . $status_class . '">' . esc_html($status) . '</td>';
        echo '<td class="' . $keyword_class . '">' . esc_html($keyword_match) . '</td>';
        echo '<td>';
        echo '<form method="post" style="display: inline;">';
        echo '<input type="hidden" name="site" value="' . esc_attr($site_url) . '">';
        echo '<input type="text" name="keyword" value="' . esc_attr($custom_keyword) . '">';
        echo '<input type="submit" name="update_keyword" class="button button-secondary" value="Update">';
        echo '</form>';
        echo '</td>';
        echo '<td>';
        echo '<form method="post" style="display: inline;">';
        echo '<input type="hidden" name="site" value="' . esc_attr($site_url) . '">';
        echo '<input type="submit" name="recheck_site" class="button button-secondary" value="Recheck">';
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
    $query = "SELECT id, url FROM $table_name";
    $results = $wpdb->get_results($query, OBJECT_K);

    // Retrieve the tags from the mainwp_group table
    $group_table = $wpdb->prefix . 'mainwp_group';
    $group_query = "SELECT id, name FROM $group_table";
    $group_results = $wpdb->get_results($group_query);

    // Create an array to map group IDs to their names
    $group_names = array();
    foreach ($group_results as $group) {
        $group_id = $group->id;
        $group_name = $group->name;
        $group_names[$group_id] = $group_name;
    }

    // Retrieve the site-group mappings from the mainwp_wp_group table
    $mapping_table = $wpdb->prefix . 'mainwp_wp_group';
    $mapping_query = "SELECT wpid, groupid FROM $mapping_table";
    $mapping_results = $wpdb->get_results($mapping_query);

    // Create an array to store the tags for each site
    $site_tags = array();
    foreach ($mapping_results as $mapping) {
        $site_id = $mapping->wpid;
        $group_id = $mapping->groupid;
        if (isset($results[$site_id]) && isset($group_names[$group_id])) {
            $site_url = $results[$site_id]->url;
            $tag_name = $group_names[$group_id];
            $site_tags[$site_id][] = $tag_name;
        }
    }

    // Combine the site information and their corresponding tags
    foreach ($results as $site_id => $site) {
        $site_url = $site->url;
        $tags = isset($site_tags[$site_id]) ? implode(', ', $site_tags[$site_id]) : '';
        $mainwp_sites[$site_url] = array(
            'site_url' => $site_url,
            'tags' => $tags
        );
    }

    return $mainwp_sites;
}

function uptime_monitor_check_status($url, $retry_count = 3, $retry_delay = 5) {
    $args = array(
        'timeout' => 15,
    );
    
    for ($i = 0; $i < $retry_count; $i++) {
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $status = "Error: $error_message";
            $keyword_match = 'N/A';
            $site_title = 'N/A';
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $status_message = wp_remote_retrieve_response_message($response);
            
            $ssl_valid = uptime_monitor_check_ssl($url);
            $status = ($status_code === 200 && $ssl_valid) ? "OK ($status_code)" : "Error ($status_code): $status_message";
            
            if (!$ssl_valid) {
                $status .= " - SSL Certificate Error";
            }
            
            $keyword_match = 'No match found';
            $site_title = 'N/A';
            
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
                
                // Extract the site title from the HTML content
                if (preg_match('/<title>(.*?)<\/title>/i', $page_content, $matches)) {
                    $site_title = $matches[1];
                }
            }
        }
        
        if (strpos($status, 'Error') === false && $keyword_match !== 'No match found' && $ssl_valid) {
            // All checks passed, return the result
            return array(
                'status' => $status,
                'keyword_match' => $keyword_match,
                'site_title' => $site_title
            );
        }
        
        // One or more checks failed, wait for the retry delay before trying again
        sleep($retry_delay);
    }
    
    // All retries failed, return the last failed result
    return array(
        'status' => $status,
        'keyword_match' => $keyword_match,
        'site_title' => $site_title
    );
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

function uptime_monitor_check_ssl($url) {
    $parsed_url = parse_url($url);
    $host = $parsed_url['host'];
    $port = isset($parsed_url['port']) ? $parsed_url['port'] : 443;
    
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host
        ]
    ]);
    
    $stream = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    
    if ($stream === false) {
        return false;
    } else {
        fclose($stream);
        return true;
    }
}

function uptime_monitor_schedule_task() {
    if (!wp_next_scheduled('uptime_monitor_hourly_check')) {
        wp_schedule_event(time(), 'hourly', 'uptime_monitor_hourly_check');
    }
}
add_action('init', 'uptime_monitor_schedule_task');

function uptime_monitor_perform_hourly_check() {
    $sites = uptime_monitor_get_mainwp_sites();
    $failed_sites = array();
    
    foreach ($sites as $site) {
        uptime_monitor_perform_check($site);
        $result = uptime_monitor_check_status($site);
        
        if (strpos($result['status'], 'Error') !== false || $result['keyword_match'] === 'No match found') {
            $failed_sites[$site] = $result['status'] . ' - ' . $result['keyword_match'];
        }
    }
    
    if (!empty($failed_sites)) {
        uptime_monitor_send_notification($failed_sites);
    }
}
add_action('uptime_monitor_hourly_check', 'uptime_monitor_perform_hourly_check');

function uptime_monitor_perform_check($site) {
    $result = uptime_monitor_check_status($site);
    $results = get_option('uptime_monitor_results', array());
    $results[$site] = $result;
    update_option('uptime_monitor_results', $results);
}
add_action('uptime_monitor_hourly_check', 'uptime_monitor_perform_check');

function uptime_monitor_send_notification($failed_sites) {
    $admin_email = get_option('admin_email');
    $site_url = get_site_url();
    $from_email = 'noreply@' . parse_url($site_url, PHP_URL_HOST);
    
    $num_failed_sites = count($failed_sites);
    $subject = 'Uptime Monitor: ' . $num_failed_sites . ' Site' . ($num_failed_sites > 1 ? 's' : '') . ' Failed';
    
    $message = "The following site" . ($num_failed_sites > 1 ? 's' : '') . " failed the uptime monitoring check:\n\n";
    
    foreach ($failed_sites as $site => $error) {
        $error_parts = explode(' - ', $error);
        $status = $error_parts[0];
        $keyword = $error_parts[1];
        
        $message .= "Site: $site\n";
        $message .= "Site Status: $status\n";
        $message .= "Keyword: $keyword\n\n";
    }
    
    $headers = array(
        'From: Uptime Monitor <' . $from_email . '>',
        'Content-Type: text/plain; charset=UTF-8'
    );
    
    wp_mail($admin_email, $subject, $message, $headers);
}
