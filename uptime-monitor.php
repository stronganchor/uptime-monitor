<?php
/*
Plugin Name: Uptime Monitor
Plugin URI: https://github.com/stronganchor/uptime-monitor/
Description: A plugin to monitor URLs and report their HTTP status and display server stats.
Version: 1.1.2
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com/
*/

// Step 1: Add the settings page to store whm credentials
function uptime_monitor_admin_menu() {
    add_menu_page(
        'Uptime Monitor',
        'Uptime Monitor',
        'manage_options',
        'uptime-monitor',
        'uptime_monitor_page',
        'dashicons-admin-links'
    );

    add_submenu_page(
        'uptime-monitor',
        'Uptime Monitor Settings',
        'Settings',
        'manage_options',
        'uptime-monitor-settings',
        'uptime_monitor_settings_page'
    );
}
add_action('admin_menu', 'uptime_monitor_admin_menu');

// Helper: get validated MainWP from-email override
function uptime_monitor_get_mainwp_from_email() {
    $email = get_option('uptime_monitor_mainwp_from_email', '');
    $email = sanitize_email($email);
    return is_email($email) ? $email : '';
}

// Step 2: Settings page form for whm credentials + MainWP from-email override
function uptime_monitor_settings_page() {
    if (isset($_POST['save_settings'])) {
        // WHM settings
        update_option('uptime_monitor_whm_user', sanitize_text_field($_POST['whm_user']));
        update_option('uptime_monitor_whm_api_token', sanitize_text_field($_POST['whm_api_token']));
        update_option('uptime_monitor_whm_server_url', sanitize_text_field($_POST['whm_server_url']));

        // MainWP From Email override
        $mainwp_from_email = isset($_POST['mainwp_from_email']) ? sanitize_email($_POST['mainwp_from_email']) : '';
        update_option('uptime_monitor_mainwp_from_email', $mainwp_from_email);

        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    $whm_user          = get_option('uptime_monitor_whm_user', '');
    $whm_api_token     = get_option('uptime_monitor_whm_api_token', '');
    $whm_server_url    = get_option('uptime_monitor_whm_server_url', '');
    $mainwp_from_email = get_option('uptime_monitor_mainwp_from_email', '');

    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor Settings</h1>';
    echo '<form method="post">';
    echo '<table class="form-table">';

    // WHM fields
    echo '<tr>';
    echo '<th scope="row"><label for="whm_user">WHM User (e.g. root)</label></th>';
    echo '<td><input type="text" id="whm_user" name="whm_user" value="' . esc_attr($whm_user) . '" class="regular-text"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="whm_api_token">WHM API Token</label></th>';
    echo '<td><input type="text" id="whm_api_token" name="whm_api_token" value="' . esc_attr($whm_api_token) . '" class="regular-text"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="whm_server_url">WHM Server URL (https://yourdomain.com:2087)</label></th>';
    echo '<td><input type="text" id="whm_server_url" name="whm_server_url" value="' . esc_attr($whm_server_url) . '" class="regular-text"></td>';
    echo '</tr>';

    // MainWP From Email override field
    echo '<tr>';
    echo '<th scope="row"><label for="mainwp_from_email">MainWP "From" Email Override</label></th>';
    echo '<td>';
    echo '<input type="email" id="mainwp_from_email" name="mainwp_from_email" value="' . esc_attr($mainwp_from_email) . '" class="regular-text">';
    echo '<p class="description">Optional. If set, MainWP emails and Uptime Monitor alerts will use this as the From address.</p>';
    echo '</td>';
    echo '</tr>';

    echo '</table>';
    echo '<p class="submit"><input type="submit" name="save_settings" class="button button-primary" value="Save Settings"></p>';
    echo '</form>';
    echo '</div>';
}

// Step 3: Fetch server stats from the whm API
function get_whm_server_stats() {
    $whm_user = get_option('uptime_monitor_cpanel_user'); // Your WHM username
    $whm_api_token = get_option('uptime_monitor_cpanel_api_token'); // Your WHM API token
    $server_url = get_option('uptime_monitor_cpanel_server_url');

    if (empty($whm_user) || empty($whm_api_token) || empty($server_url)) {
        return 'Please configure the WHM credentials in the Uptime Monitor settings.';
    }

    // Retrieve the list of accounts
    $accounts = get_whm_account_list($whm_user, $whm_api_token, $server_url);
    if (!is_array($accounts)) {
        return $accounts; // Return the error message
    }

    $total_account_disk_usage = 0;
    $accounts_data = [];

    foreach ($accounts as $account) {
        $username  = $account['user'] ?? '';
        $domain    = $account['domain'] ?? '';
        $disk_used = isset($account['diskused']) ? $account['diskused'] : '0';
        $suspended = isset($account['suspended']) && $account['suspended'] ? 'Yes' : 'No';

        $disk_used_clean = str_replace(['M', ','], '', $disk_used);
        $disk_used_float = floatval($disk_used_clean);
        $total_account_disk_usage += $disk_used_float;

        $accounts_data[] = [
            'username'  => $username,
            'domain'    => $domain,
            'disk_used' => $disk_used,
            'suspended' => $suspended,
        ];
    }

    $total_space_bytes = disk_total_space("/");
    $free_space_bytes  = disk_free_space("/");

    if ($total_space_bytes === false || $free_space_bytes === false) {
        $disk_usage_info = "Unable to retrieve total disk usage information.";
    } else {
        $used_space_bytes = $total_space_bytes - $free_space_bytes;

        $total_space_gb = round($total_space_bytes / (1024 * 1024 * 1024), 2);
        $free_space_gb  = round($free_space_bytes / (1024 * 1024 * 1024), 2);
        $used_space_gb  = round($used_space_bytes / (1024 * 1024 * 1024), 2);

        $disk_usage_info  = "Total Disk Space: {$total_space_gb} GB<br>";
        $disk_usage_info .= "Used Disk Space: {$used_space_gb} GB<br>";
        $disk_usage_info .= "Free Disk Space: {$free_space_gb} GB<br>";
    }

    $output = $disk_usage_info . "<br>";
    $output .= "Total cPanel Accounts Disk Usage: {$total_account_disk_usage} MB<br><br>";
    $output .= "Accounts:<br>";
    foreach ($accounts_data as $data) {
        $output .= "User: {$data['username']}, Domain: {$data['domain']}, Disk Used: {$data['disk_used']}, Suspended: {$data['suspended']}<br>";
    }

    return $output;
}

function get_whm_account_list($whm_user, $whm_api_token, $server_url) {
    $query = $server_url . '/json-api/listaccts?api.version=1';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: whm ' . $whm_user . ':' . $whm_api_token]);
    curl_setopt($curl, CURLOPT_URL, $query);

    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        return 'Error: ' . curl_error($curl);
    }

    curl_close($curl);

    $data = json_decode($result, true);

    if (isset($data['metadata']['result']) && $data['metadata']['result'] == 0) {
        return 'API Error: ' . $data['metadata']['reason'];
    }

    if (empty($data['data']['acct'])) {
        return 'No accounts found or insufficient permissions.';
    }

    return $data['data']['acct'];
}

// Step 4: Display server stats at the top of the Uptime Monitor page
function uptime_monitor_page() {
    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor</h1>';

    // Fetch and display the server stats
    $server_stats = get_whm_server_stats();
    echo '<h2>Server Stats</h2>';
    echo '<p>' . wp_kses_post($server_stats) . '</p>';

    if (isset($_POST['check_all_sites'])) {
        $sites = uptime_monitor_get_mainwp_sites();
        $failed_sites = [];

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
        $site    = sanitize_text_field($_POST['site']);
        $keyword = sanitize_text_field($_POST['keyword']);
        $keywords = get_option('uptime_monitor_keywords', []);
        $keywords[$site] = $keyword;
        update_option('uptime_monitor_keywords', $keywords);

        uptime_monitor_perform_check($site);

        $result = uptime_monitor_check_status($site);
        if (strpos($result['status'], 'Error') !== false || $result['keyword_match'] === 'No match found') {
            $failed_sites = [
                $site => $result['status'] . ' - ' . $result['keyword_match'],
            ];
            uptime_monitor_send_notification($failed_sites);
        }
    }

    if (isset($_POST['recheck_site'])) {
        $site = sanitize_text_field($_POST['site']);
        uptime_monitor_perform_check($site);
    }

    $sites        = uptime_monitor_get_mainwp_sites(true);
    $results      = get_option('uptime_monitor_results', []);
    $keywords     = get_option('uptime_monitor_keywords', []);
    $last_checked = get_option('uptime_monitor_last_checked', []);

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

    echo '<form method="post" style="display: inline;">';
    echo '<input type="submit" name="check_all_sites" class="button button-primary" value="Check All Sites">';
    echo '</form>';

    echo '<h2>MainWP Child Sites</h2>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>URL</th><th>Site Title</th><th>Tags</th><th>Site Status</th><th>Keyword Match</th><th>Custom Keyword</th><th>Last Checked</th><th>Action</th></tr></thead>';
    echo '<tbody>';

    foreach ($sites as $site_info) {
        $site_url   = $site_info['site_url'];
        $tags       = $site_info['tags'];
        $status     = isset($results[$site_url]['status']) ? $results[$site_url]['status'] : 'N/A';
        $keyword_match = isset($results[$site_url]['keyword_match']) ? $results[$site_url]['keyword_match'] : 'N/A';
        $custom_keyword = isset($keywords[$site_url]) ? $keywords[$site_url] : '';
        $site_title  = isset($results[$site_url]['site_title']) ? $results[$site_url]['site_title'] : 'N/A';
        $last_checked_time = isset($last_checked[$site_url]) ? date_i18n('m/d H:i', $last_checked[$site_url]) : 'N/A';

        $status_class  = (strpos($status, 'Error') !== false) ? 'error' : '';
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
        echo '<td>' . esc_html($last_checked_time) . '</td>';
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

function uptime_monitor_get_mainwp_sites($get_tags = false) {
    global $wpdb;

    $mainwp_sites = [];

    $table_name = $wpdb->prefix . 'mainwp_wp';
    $query = "SELECT id, url FROM $table_name";

    if (!$get_tags) {
        $results = $wpdb->get_results($query);

        foreach ($results as $site) {
            $mainwp_sites[] = $site->url;
        }

        return $mainwp_sites;
    }

    $results = $wpdb->get_results($query, OBJECT_K);

    $group_table  = $wpdb->prefix . 'mainwp_group';
    $group_query  = "SELECT id, name FROM $group_table";
    $group_results = $wpdb->get_results($group_query);

    $group_names = [];
    foreach ($group_results as $group) {
        $group_names[$group->id] = $group->name;
    }

    $mapping_table  = $wpdb->prefix . 'mainwp_wp_group';
    $mapping_query  = "SELECT wpid, groupid FROM $mapping_table";
    $mapping_results = $wpdb->get_results($mapping_query);

    $site_tags = [];
    foreach ($mapping_results as $mapping) {
        $site_id  = $mapping->wpid;
        $group_id = $mapping->groupid;
        if (isset($results[$site_id]) && isset($group_names[$group_id])) {
            $site_url = $results[$site_id]->url;
            $tag_name = $group_names[$group_id];
            $site_tags[$site_id][] = $tag_name;
        }
    }

    foreach ($results as $site_id => $site) {
        $site_url = $site->url;
        $tags     = isset($site_tags[$site_id]) ? implode(', ', $site_tags[$site_id]) : '';
        $mainwp_sites[$site_url] = [
            'site_url' => $site_url,
            'tags'     => $tags,
        ];
    }

    return $mainwp_sites;
}

function uptime_monitor_check_status($url, $retry_count = 3, $retry_delay = 5) {
    $args = [
        'timeout' => 15,
    ];

    $keywords = get_option('uptime_monitor_keywords', []);
    $custom_keyword = isset($keywords[$url]) ? $keywords[$url] : '';

    if (strtoupper($custom_keyword) === 'N/A') {
        return [
            'status'        => 'OK (Keyword check skipped)',
            'keyword_match' => 'Skipped',
            'site_title'    => 'N/A',
        ];
    }

    for ($i = 0; $i < $retry_count; $i++) {
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $status        = "Error: $error_message";
            $keyword_match = 'N/A';
            $site_title    = 'N/A';
        } else {
            $status_code    = wp_remote_retrieve_response_code($response);
            $status_message = wp_remote_retrieve_response_message($response);

            $ssl_valid = uptime_monitor_check_ssl($url);
            $status = ($status_code === 200 && $ssl_valid) ? "OK ($status_code)" : "Error ($status_code): $status_message";

            if (!$ssl_valid) {
                $status .= " - SSL Certificate Error";
            }

            $keyword_match = 'No match found';
            $site_title    = 'N/A';

            if ($status_code === 200) {
                $page_content = wp_remote_retrieve_body($response);

                $visible_text = extract_visible_text($page_content);

                if (!empty($custom_keyword)) {
                    if (preg_match("/{$custom_keyword}/i", $visible_text, $matches)) {
                        $keyword_match = $matches[0];
                    }
                } else {
                    $domain      = parse_url($url, PHP_URL_HOST);
                    $base_domain = preg_replace('/^www\./', '', $domain);
                    $base_domain = preg_replace('/\.[^.]+$/', '', $base_domain);

                    $pattern = str_split($base_domain);
                    $pattern = implode('\s*', $pattern);

                    if (preg_match("/{$pattern}/i", $visible_text, $matches)) {
                        $keyword_match = $matches[0];
                    }
                }

                if (preg_match('/<title>(.*?)<\/title>/i', $page_content, $matches)) {
                    $site_title = $matches[1];
                }
            }
        }

        if (strpos($status, 'Error') === false && $keyword_match !== 'No match found' && $ssl_valid) {
            return [
                'status'        => $status,
                'keyword_match' => $keyword_match,
                'site_title'    => $site_title,
            ];
        }

        sleep($retry_delay);
    }

    return [
        'status'        => $status,
        'keyword_match' => $keyword_match,
        'site_title'    => $site_title,
    ];
}

function extract_visible_text($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $xpath = new DOMXPath($dom);

    $text_nodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style)]');

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
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $host,
        ],
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
    $failed_sites = [];

    foreach ($sites as $site) {
        uptime_monitor_perform_check($site);
        $result = uptime_monitor_check_status($site);

        if (strpos($result['status'], 'Error') !== false || $result['keyword_match'] === 'No match found') {
            $failed_sites[$site] = $result['status'] . ' - $result[\"keyword_match\"]';
        }
    }

    if (!empty($failed_sites)) {
        uptime_monitor_send_notification($failed_sites);
    }
}
add_action('uptime_monitor_hourly_check', 'uptime_monitor_perform_hourly_check');

function uptime_monitor_perform_check($site) {
    $result       = uptime_monitor_check_status($site);
    $results      = get_option('uptime_monitor_results', []);
    $results[$site] = $result;
    $last_checked = get_option('uptime_monitor_last_checked', []);
    $last_checked[$site] = current_time('timestamp');
    update_option('uptime_monitor_results', $results);
    update_option('uptime_monitor_last_checked', $last_checked);
}

function uptime_monitor_send_notification($failed_sites) {
    $admin_email = get_option('admin_email');
    $site_url    = get_site_url();

    // Use MainWP override if present; otherwise fall back to noreply@domain
    $override_email = uptime_monitor_get_mainwp_from_email();
    if ($override_email) {
        $from_email = $override_email;
    } else {
        $from_email = 'noreply@' . parse_url($site_url, PHP_URL_HOST);
    }

    $num_failed_sites = count($failed_sites);
    $subject = 'Uptime Monitor: ' . $num_failed_sites . ' Site' . ($num_failed_sites > 1 ? 's' : '') . ' Failed';

    $message = "The following site" . ($num_failed_sites > 1 ? 's' : '') . " failed the uptime monitoring check:\n\n";

    foreach ($failed_sites as $site => $error) {
        $error_parts = explode(' - ', $error);
        $status = $error_parts[0];
        $keyword = isset($error_parts[1]) ? $error_parts[1] : '';

        $message .= "Site: $site\n";
        $message .= "Site Status: $status\n";
        $message .= "Keyword: $keyword\n\n";
    }

    $headers = [
        'From: Uptime Monitor <' . $from_email . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    wp_mail($admin_email, $subject, $message, $headers);
}

// Hook to override MainWP email From header using the saved setting
add_filter('mainwp_send_mail_from_header', 'uptime_monitor_mainwp_send_mail_from_header', 10, 3);
function uptime_monitor_mainwp_send_mail_from_header($input, $email, $subject) {
    $custom_email = uptime_monitor_get_mainwp_from_email();
    if (!$custom_email) {
        return $input;
    }

    $from_name = isset($input['from_name']) && !empty($input['from_name'])
        ? $input['from_name']
        : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

    return [
        'from_name'  => $from_name,
        'from_email' => $custom_email,
    ];
}
?>
