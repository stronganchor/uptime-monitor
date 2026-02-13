<?php
/*
Plugin Name: Uptime Monitor
Plugin URI: https://github.com/stronganchor/uptime-monitor/
Description: A plugin to monitor URLs and report their HTTP status and display server stats.
Version: 1.1.6
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
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['save_settings'])) {
        check_admin_referer('uptime_monitor_save_settings', 'uptime_monitor_settings_nonce');

        // WHM settings
        $whm_user       = isset($_POST['whm_user']) ? sanitize_text_field(wp_unslash($_POST['whm_user'])) : '';
        $whm_api_token  = isset($_POST['whm_api_token']) ? sanitize_text_field(wp_unslash($_POST['whm_api_token'])) : '';
        $whm_server_url = isset($_POST['whm_server_url']) ? sanitize_text_field(wp_unslash($_POST['whm_server_url'])) : '';

        update_option('uptime_monitor_whm_user', $whm_user);
        update_option('uptime_monitor_whm_api_token', $whm_api_token);
        update_option('uptime_monitor_whm_server_url', $whm_server_url);

        // MainWP From Email override
        $mainwp_from_email = isset($_POST['mainwp_from_email']) ? sanitize_email(wp_unslash($_POST['mainwp_from_email'])) : '';
        update_option('uptime_monitor_mainwp_from_email', $mainwp_from_email);

        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    $whm_user          = get_option('uptime_monitor_whm_user', '');
    $whm_api_token     = get_option('uptime_monitor_whm_api_token', '');
    $whm_server_url    = get_option('uptime_monitor_whm_server_url', '');
    $mainwp_from_email = get_option('uptime_monitor_mainwp_from_email', '');

    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor Settings</h1>';
    echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=uptime-monitor-settings')) . '">';
    wp_nonce_field('uptime_monitor_save_settings', 'uptime_monitor_settings_nonce');
    echo '<table class="form-table">';

    // WHM fields
    echo '<tr>';
    echo '<th scope="row"><label for="whm_user">WHM User (e.g. root)</label></th>';
    echo '<td><input type="text" id="whm_user" name="whm_user" value="' . esc_attr($whm_user) . '" class="regular-text"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="whm_api_token">WHM API Token</label></th>';
    echo '<td>';
    echo '<input type="text" id="whm_api_token" name="whm_api_token" value="' . esc_attr($whm_api_token) . '" class="regular-text">';
    echo '<p class="description">Recommended: use a least-privilege WHM token. Owner/reseller-scoped tokens are supported; accounts outside token scope will show bandwidth/inode metrics as N/A.</p>';
    echo '</td>';
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
    $whm_user      = get_option('uptime_monitor_whm_user'); // Your WHM username
    $whm_api_token = get_option('uptime_monitor_whm_api_token'); // Your WHM API token
    $server_url    = get_option('uptime_monitor_whm_server_url');

    $stats = [
        'error'                  => '',
        'warning'                => '',
        'account_metrics_warning' => '',
        'account_metrics_coverage' => [],
        'load_warning'           => '',
        'load_average'           => null,
        'load_trend'             => [],
        'cpu_cores'              => null,
        'cpu_source'             => 'unknown',
        'server_disk'            => null,
        'total_accounts_used_mb' => 0.0,
        'accounts'               => [],
    ];

    if (empty($whm_user) || empty($whm_api_token) || empty($server_url)) {
        $stats['error'] = 'Please configure the WHM credentials in the Uptime Monitor settings.';
        return $stats;
    }

    // Retrieve the list of accounts
    $accounts = get_whm_account_list($whm_user, $whm_api_token, $server_url);
    if (!is_array($accounts)) {
        $stats['error'] = $accounts; // Return the error message
        return $stats;
    }

    $cpu_info = uptime_monitor_get_cpu_core_info();
    $stats['cpu_cores'] = $cpu_info['cores'];
    $stats['cpu_source'] = $cpu_info['source'];

    $load_average = get_whm_load_average($whm_user, $whm_api_token, $server_url);
    if (is_array($load_average)) {
        $stats['load_average'] = $load_average;
        uptime_monitor_record_load_sample($load_average);
    } else {
        $stats['load_warning'] = (string) $load_average;
    }

    $stats['load_trend'] = uptime_monitor_get_load_trend_data(24 * HOUR_IN_SECONDS, 48);

    $bandwidth_by_user = [];
    $bandwidth_warning = '';
    $bandwidth_source = [];

    $showbw_bandwidth = get_whm_account_bandwidth_usage($whm_user, $whm_api_token, $server_url);
    if (is_array($showbw_bandwidth)) {
        $bandwidth_by_user = $showbw_bandwidth;
        if (!empty($showbw_bandwidth)) {
            $bandwidth_source[] = 'showbw';
        }
    } else {
        $bandwidth_warning = (string) $showbw_bandwidth;
    }

    $showres_bandwidth = get_whm_account_bandwidth_usage_showres($whm_user, $whm_api_token, $server_url);
    if (is_array($showres_bandwidth)) {
        if (!empty($showres_bandwidth)) {
            $bandwidth_source[] = 'showres';
        }
        foreach ($showres_bandwidth as $key => $metric) {
            if (!isset($bandwidth_by_user[$key]) && is_array($metric)) {
                $bandwidth_by_user[$key] = $metric;
            }
        }
    } elseif ($bandwidth_warning === '') {
        $bandwidth_warning = (string) $showres_bandwidth;
    }

    $inode_by_user = get_whm_account_inode_usage($whm_user, $whm_api_token, $server_url);
    $inode_warning = '';
    if (!is_array($inode_by_user)) {
        $inode_warning = (string) $inode_by_user;
        $inode_by_user = [];
    }

    if ($bandwidth_warning !== '' && empty($bandwidth_by_user)) {
        $stats['account_metrics_warning'] = $bandwidth_warning;
    }
    if ($inode_warning !== '' && empty($inode_by_user)) {
        if (!empty($stats['account_metrics_warning'])) {
            $stats['account_metrics_warning'] .= ' ' . $inode_warning;
        } else {
            $stats['account_metrics_warning'] = $inode_warning;
        }
    }

    $total_account_disk_usage = 0.0;
    $accounts_data = [];
    $total_accounts = count($accounts);
    $bandwidth_matched = 0;
    $inode_matched = 0;
    $bandwidth_missing_accounts = [];
    $inode_missing_accounts = [];

    foreach ($accounts as $account) {
        $username       = $account['user'] ?? '';
        $domain         = $account['domain'] ?? '';
        $disk_used_raw  = isset($account['diskused']) ? $account['diskused'] : '0';
        $disk_limit_raw = isset($account['disklimit']) ? $account['disklimit'] : '';
        $suspended      = isset($account['suspended']) && $account['suspended'] ? 'Yes' : 'No';

        $disk_used_mb  = uptime_monitor_parse_size_to_mb($disk_used_raw);
        $disk_limit_mb = uptime_monitor_parse_size_to_mb($disk_limit_raw, true);

        $has_disk_limit = ($disk_limit_mb !== null && $disk_limit_mb > 0);
        $disk_free_mb   = $has_disk_limit ? max(0.0, $disk_limit_mb - $disk_used_mb) : null;
        $disk_used_pct  = $has_disk_limit ? min(100.0, ($disk_used_mb / $disk_limit_mb) * 100.0) : null;

        $total_account_disk_usage += $disk_used_mb;

        $bandwidth_info = uptime_monitor_get_metric_entry_for_account($bandwidth_by_user, $username, $domain);
        $inode_info = uptime_monitor_get_metric_entry_for_account($inode_by_user, $username, $domain);

        $bandwidth_used_mb  = isset($bandwidth_info['used_mb']) && is_numeric($bandwidth_info['used_mb'])
            ? max(0.0, (float) $bandwidth_info['used_mb'])
            : null;
        $bandwidth_limit_mb = isset($bandwidth_info['limit_mb']) && is_numeric($bandwidth_info['limit_mb'])
            ? max(0.0, (float) $bandwidth_info['limit_mb'])
            : null;
        $inodes_used = isset($inode_info['used']) && is_numeric($inode_info['used'])
            ? max(0, (int) $inode_info['used'])
            : null;
        $inodes_limit = isset($inode_info['limit']) && is_numeric($inode_info['limit'])
            ? max(0, (int) $inode_info['limit'])
            : null;

        $account_label = $username !== '' ? $username : ($domain !== '' ? $domain : 'Unknown account');
        if ($bandwidth_used_mb !== null || $bandwidth_limit_mb !== null) {
            $bandwidth_matched++;
        } else {
            $bandwidth_missing_accounts[] = $account_label;
        }

        if ($inodes_used !== null || $inodes_limit !== null) {
            $inode_matched++;
        } else {
            $inode_missing_accounts[] = $account_label;
        }

        $accounts_data[] = [
            'username'       => $username,
            'domain'         => $domain,
            'disk_used_mb'   => $disk_used_mb,
            'disk_limit_mb'  => $has_disk_limit ? $disk_limit_mb : null,
            'disk_free_mb'   => $disk_free_mb,
            'disk_used_pct'  => $disk_used_pct,
            'bandwidth_used_mb' => $bandwidth_used_mb,
            'bandwidth_limit_mb' => $bandwidth_limit_mb,
            'inodes_used'    => $inodes_used,
            'inodes_limit'   => $inodes_limit,
            'disk_used_raw'  => $disk_used_raw,
            'disk_limit_raw' => $disk_limit_raw,
            'suspended'      => $suspended,
        ];
    }

    $total_space_bytes = disk_total_space('/');
    $free_space_bytes  = disk_free_space('/');

    if ($total_space_bytes !== false && $free_space_bytes !== false && $total_space_bytes > 0) {
        $used_space_bytes = max(0, $total_space_bytes - $free_space_bytes);

        $stats['server_disk'] = [
            'total_mb'    => $total_space_bytes / (1024 * 1024),
            'used_mb'     => $used_space_bytes / (1024 * 1024),
            'free_mb'     => $free_space_bytes / (1024 * 1024),
            'used_pct'    => min(100.0, ($used_space_bytes / $total_space_bytes) * 100.0),
            'total_gb'    => $total_space_bytes / (1024 * 1024 * 1024),
            'used_gb'     => $used_space_bytes / (1024 * 1024 * 1024),
            'free_gb'     => $free_space_bytes / (1024 * 1024 * 1024),
        ];
    } else {
        $stats['warning'] = 'Unable to retrieve local server disk usage information.';
    }

    usort($accounts_data, function($a, $b) {
        return ($b['disk_used_mb'] <=> $a['disk_used_mb']);
    });

    $stats['total_accounts_used_mb'] = $total_account_disk_usage;
    $stats['accounts'] = $accounts_data;
    $stats['account_metrics_coverage'] = [
        'total_accounts' => $total_accounts,
        'bandwidth_matched' => $bandwidth_matched,
        'inode_matched' => $inode_matched,
        'bandwidth_missing_accounts' => $bandwidth_missing_accounts,
        'inode_missing_accounts' => $inode_missing_accounts,
        'bandwidth_sources' => array_values(array_unique($bandwidth_source)),
    ];

    return $stats;
}

function uptime_monitor_parse_size_to_mb($value, $allow_unlimited = false) {
    if (!is_scalar($value)) {
        return $allow_unlimited ? null : 0.0;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return $allow_unlimited ? null : 0.0;
    }

    $normalized = strtolower(str_replace(',', '', $value));
    if ($allow_unlimited && in_array($normalized, ['unlimited', 'infinity', '∞', '0', '0m', '0mb'], true)) {
        return null;
    }

    if (!preg_match('/^([0-9]*\.?[0-9]+)\s*([kmgtp]?)(b)?$/i', $normalized, $matches)) {
        return $allow_unlimited ? null : 0.0;
    }

    $amount = (float) $matches[1];
    $unit   = strtolower($matches[2]);

    switch ($unit) {
        case 'k':
            return $amount / 1024.0;
        case 'g':
            return $amount * 1024.0;
        case 't':
            return $amount * 1024.0 * 1024.0;
        case 'p':
            return $amount * 1024.0 * 1024.0 * 1024.0;
        case 'm':
        default:
            return $amount;
    }
}

function uptime_monitor_format_mb($size_mb) {
    if ($size_mb === null) {
        return 'Unlimited';
    }

    $size_mb = max(0.0, (float) $size_mb);

    if ($size_mb >= 1024.0 * 1024.0) {
        return number_format($size_mb / (1024.0 * 1024.0), 2) . ' TB';
    }

    if ($size_mb >= 1024.0) {
        return number_format($size_mb / 1024.0, 2) . ' GB';
    }

    return number_format($size_mb, 0) . ' MB';
}

function uptime_monitor_parse_load_value($value) {
    if (!is_scalar($value) || $value === '') {
        return null;
    }

    if (!is_numeric((string) $value)) {
        return null;
    }

    return max(0.0, (float) $value);
}

function uptime_monitor_format_load_value($value) {
    return ($value === null) ? 'N/A' : number_format((float) $value, 1);
}

function uptime_monitor_format_load_core_text($value, $cpu_cores) {
    $cpu_cores = (int) $cpu_cores;
    if ($cpu_cores <= 0 || $value === null) {
        return '';
    }

    return '(' . uptime_monitor_format_load_value(((float) $value) / $cpu_cores) . '/core)';
}

function uptime_monitor_parse_non_negative_number($value) {
    if (!is_scalar($value) || $value === '') {
        return null;
    }

    if (!is_numeric((string) $value)) {
        return null;
    }

    $number = (float) $value;
    if ($number < 0) {
        return null;
    }

    return $number;
}

function uptime_monitor_normalize_lookup_key($value) {
    if (!is_scalar($value)) {
        return '';
    }

    $key = strtolower(trim((string) $value));
    if ($key === '') {
        return '';
    }

    if (strpos($key, '://') !== false) {
        $host = parse_url($key, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $key = $host;
        }
    }

    $key = preg_replace('#^https?://#i', '', $key);
    $key = preg_replace('#/.*$#', '', $key);
    $key = preg_replace('/:\d+$/', '', $key);
    $key = preg_replace('/^www\./', '', $key);

    return trim((string) $key, ". \t\n\r\0\x0B");
}

function uptime_monitor_get_account_lookup_keys($username = '', $domain = '') {
    $keys = [];

    $user_key = uptime_monitor_normalize_lookup_key($username);
    if ($user_key !== '') {
        $keys[] = $user_key;
    }

    $domain_key = uptime_monitor_normalize_lookup_key($domain);
    if ($domain_key !== '' && !in_array($domain_key, $keys, true)) {
        $keys[] = $domain_key;
    }

    return $keys;
}

function uptime_monitor_add_metric_entry(&$map, $keys, $metric) {
    if (!is_array($map) || !is_array($keys) || !is_array($metric)) {
        return;
    }

    foreach ($keys as $key) {
        $normalized_key = uptime_monitor_normalize_lookup_key($key);
        if ($normalized_key === '') {
            continue;
        }

        if (!isset($map[$normalized_key])) {
            $map[$normalized_key] = $metric;
        }
    }
}

function uptime_monitor_get_metric_entry_for_account($map, $username = '', $domain = '') {
    if (!is_array($map)) {
        return [];
    }

    $keys = uptime_monitor_get_account_lookup_keys($username, $domain);
    foreach ($keys as $key) {
        if (isset($map[$key]) && is_array($map[$key])) {
            return $map[$key];
        }
    }

    return [];
}

function uptime_monitor_extract_account_domain_from_row($row) {
    if (!is_array($row)) {
        return '';
    }

    $domain_fields = ['domain', 'main_domain', 'userdomain', 'domainname'];
    foreach ($domain_fields as $field) {
        if (isset($row[$field]) && is_scalar($row[$field])) {
            $domain = trim((string) $row[$field]);
            if ($domain !== '') {
                return $domain;
            }
        }
    }

    return '';
}

function uptime_monitor_format_account_name_list($names, $max_items = 3) {
    if (!is_array($names) || empty($names)) {
        return '';
    }

    $clean = [];
    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $clean[] = $name;
    }

    if (empty($clean)) {
        return '';
    }

    $clean = array_values(array_unique($clean));
    $max_items = max(1, (int) $max_items);
    $shown = array_slice($clean, 0, $max_items);
    $remaining = count($clean) - count($shown);

    $text = implode(', ', $shown);
    if ($remaining > 0) {
        $text .= ' +' . $remaining . ' more';
    }

    return $text;
}

function uptime_monitor_format_bandwidth_summary($used_mb, $limit_mb = null) {
    if ($used_mb === null) {
        return 'Bandwidth (MTD): N/A';
    }

    $used_mb = max(0.0, (float) $used_mb);
    $summary = 'Bandwidth (MTD): ' . uptime_monitor_format_mb($used_mb);

    if ($limit_mb !== null && is_numeric($limit_mb) && (float) $limit_mb > 0.0) {
        $limit_mb = (float) $limit_mb;
        $used_pct = min(100.0, ($used_mb / $limit_mb) * 100.0);
        $summary .= ' / ' . uptime_monitor_format_mb($limit_mb) . ' (' . number_format($used_pct, 0) . '%)';
    } else {
        $summary .= ' / Unlimited';
    }

    return $summary;
}

function uptime_monitor_format_inode_summary($used, $limit = null) {
    if ($used === null) {
        return 'Inodes: N/A';
    }

    $used = max(0, (int) $used);
    $summary = 'Inodes: ' . number_format($used);

    if ($limit !== null && is_numeric($limit) && (int) $limit > 0) {
        $limit = (int) $limit;
        $used_pct = min(100.0, ($used / $limit) * 100.0);
        $summary .= ' / ' . number_format($limit) . ' (' . number_format($used_pct, 0) . '%)';
    } else {
        $summary .= ' / Unlimited';
    }

    return $summary;
}

function uptime_monitor_get_load_level($load_value, $cpu_cores = null) {
    if ($load_value === null) {
        return 'unknown';
    }

    $cpu_cores = (int) $cpu_cores;
    if ($cpu_cores > 0) {
        $load_per_core = $load_value / $cpu_cores;
        if ($load_per_core < 0.70) {
            return 'healthy';
        }
        if ($load_per_core < 1.00) {
            return 'elevated';
        }
        return 'high';
    }

    if ($load_value < 2.0) {
        return 'healthy';
    }

    if ($load_value < 4.0) {
        return 'elevated';
    }

    return 'high';
}

function uptime_monitor_get_load_label($load_level) {
    switch ($load_level) {
        case 'healthy':
            return 'Normal';
        case 'elevated':
            return 'Elevated';
        case 'high':
            return 'High';
        default:
            return 'Unknown';
    }
}

function uptime_monitor_detect_cpu_cores() {
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if (is_string($cpuinfo) && $cpuinfo !== '') {
        $count = preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
        if ($count > 0) {
            return (int) $count;
        }
    }

    $procstat = @file_get_contents('/proc/stat');
    if (is_string($procstat) && $procstat !== '') {
        $count = preg_match_all('/^cpu[0-9]+\s/m', $procstat, $matches);
        if ($count > 0) {
            return (int) $count;
        }
    }

    return null;
}

function uptime_monitor_get_cpu_core_info() {
    $manual_cores = absint(get_option('uptime_monitor_cpu_cores_override', 0));
    if ($manual_cores > 0) {
        return [
            'cores'  => $manual_cores,
            'source' => 'manual',
        ];
    }

    $detected_cores = uptime_monitor_detect_cpu_cores();
    if ($detected_cores !== null && $detected_cores > 0) {
        return [
            'cores'  => (int) $detected_cores,
            'source' => 'auto',
        ];
    }

    return [
        'cores'  => null,
        'source' => 'unknown',
    ];
}

function uptime_monitor_record_load_sample($load_average, $min_interval_seconds = 300) {
    if (!is_array($load_average)) {
        return;
    }

    $sample_five = uptime_monitor_parse_load_value($load_average['five'] ?? null);
    if ($sample_five === null) {
        return;
    }

    $now = time();
    $last_sample = (int) get_option('uptime_monitor_load_history_last', 0);
    if ($min_interval_seconds > 0 && ($now - $last_sample) < $min_interval_seconds) {
        return;
    }

    $history = get_option('uptime_monitor_load_history', []);
    if (!is_array($history)) {
        $history = [];
    }

    $history[] = [
        't'       => $now,
        'one'     => uptime_monitor_parse_load_value($load_average['one'] ?? null),
        'five'    => $sample_five,
        'fifteen' => uptime_monitor_parse_load_value($load_average['fifteen'] ?? null),
    ];

    $cutoff = $now - (7 * DAY_IN_SECONDS);
    $history = array_values(array_filter($history, function($item) use ($cutoff) {
        if (!is_array($item) || !isset($item['t'])) {
            return false;
        }
        return ((int) $item['t']) >= $cutoff;
    }));

    if (count($history) > 4000) {
        $history = array_slice($history, -4000);
    }

    update_option('uptime_monitor_load_history', $history);
    update_option('uptime_monitor_load_history_last', $now);
}

function uptime_monitor_build_sparkline_points($values, $width = 180, $height = 28) {
    if (!is_array($values) || count($values) < 2) {
        return '';
    }

    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    if ($range < 0.0001) {
        $range = 1.0;
    }

    // Keep the sparkline stroke comfortably inside the SVG viewport.
    $padding = 2.0;
    $x_min = $padding;
    $x_max = max($padding, ((float) $width) - $padding);
    $y_min = $padding;
    $y_max = max($padding, ((float) $height) - $padding);
    $draw_width = max(0.0001, $x_max - $x_min);
    $draw_height = max(0.0001, $y_max - $y_min);

    $points = [];
    $count = count($values);
    foreach ($values as $index => $value) {
        $x = $count > 1
            ? ($x_min + (($index / ($count - 1)) * $draw_width))
            : (($x_min + $x_max) / 2.0);
        $y = $y_max - ((($value - $min) / $range) * $draw_height);
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    return implode(' ', $points);
}

function uptime_monitor_get_load_trend_data($window_seconds = DAY_IN_SECONDS, $target_points = 48) {
    $trend = [
        'has_data'         => false,
        'window_label'     => round($window_seconds / HOUR_IN_SECONDS) . 'h',
        'samples'          => 0,
        'avg_five'         => null,
        'peak_five'        => null,
        'latest_five'      => null,
        'delta_pct'        => null,
        'sparkline_points' => '',
    ];

    $history = get_option('uptime_monitor_load_history', []);
    if (!is_array($history) || empty($history)) {
        return $trend;
    }

    $now = time();
    $cutoff = $now - max(300, (int) $window_seconds);

    $samples = [];
    foreach ($history as $item) {
        if (!is_array($item) || !isset($item['t'])) {
            continue;
        }

        $ts = (int) $item['t'];
        if ($ts < $cutoff || $ts > ($now + 60)) {
            continue;
        }

        $five = uptime_monitor_parse_load_value($item['five'] ?? null);
        if ($five === null) {
            continue;
        }

        $samples[] = [
            't'    => $ts,
            'five' => $five,
        ];
    }

    if (empty($samples)) {
        return $trend;
    }

    usort($samples, function($a, $b) {
        return ((int) $a['t']) <=> ((int) $b['t']);
    });

    $raw_values = array_column($samples, 'five');
    $sample_count = count($raw_values);

    $avg_five = array_sum($raw_values) / $sample_count;
    $peak_five = max($raw_values);
    $latest_five = $raw_values[$sample_count - 1];
    $delta_pct = $avg_five > 0 ? (($latest_five - $avg_five) / $avg_five) * 100.0 : null;

    $render_values = $raw_values;
    $target_points = max(8, (int) $target_points);
    if (count($render_values) > $target_points) {
        $bucket_size = (int) ceil(count($render_values) / $target_points);
        $downsampled = [];
        for ($i = 0; $i < count($render_values); $i += $bucket_size) {
            $chunk = array_slice($render_values, $i, $bucket_size);
            if (!empty($chunk)) {
                $downsampled[] = array_sum($chunk) / count($chunk);
            }
        }
        $render_values = $downsampled;
    }

    $trend['has_data'] = count($render_values) > 1;
    $trend['samples'] = $sample_count;
    $trend['avg_five'] = $avg_five;
    $trend['peak_five'] = $peak_five;
    $trend['latest_five'] = $latest_five;
    $trend['delta_pct'] = $delta_pct;
    $trend['sparkline_points'] = uptime_monitor_build_sparkline_points($render_values, 180, 28);

    return $trend;
}

function uptime_monitor_get_account_color($index) {
    $palette = [
        '#2563eb',
        '#7c3aed',
        '#db2777',
        '#ea580c',
        '#0ea5e9',
        '#ca8a04',
        '#dc2626',
        '#8b5cf6',
        '#0891b2',
        '#be123c',
        '#9333ea',
        '#1d4ed8',
        '#b91c1c',
        '#c2410c',
        '#4f46e5',
        '#0284c7',
    ];

    return $palette[$index % count($palette)];
}

function uptime_monitor_compact_label($text, $max_length = 14) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    if (strlen($text) <= $max_length) {
        return $text;
    }

    return substr($text, 0, $max_length - 3) . '...';
}

function uptime_monitor_build_disk_graph_data($stats) {
    $data = [
        'segments' => [],
        'note'     => '',
    ];

    if (empty($stats['server_disk']) || empty($stats['server_disk']['total_mb'])) {
        return $data;
    }

    $disk = $stats['server_disk'];

    $total_mb       = max(0.0001, (float) $disk['total_mb']);
    $server_used_mb = max(0.0, min((float) $disk['used_mb'], $total_mb));
    $free_mb        = max(0.0, $total_mb - $server_used_mb);

    $accounts = isset($stats['accounts']) && is_array($stats['accounts']) ? $stats['accounts'] : [];

    $raw_accounts_total_mb = 0.0;
    foreach ($accounts as $account) {
        $raw_accounts_total_mb += max(0.0, (float) $account['disk_used_mb']);
    }

    $account_target_mb = min($raw_accounts_total_mb, $server_used_mb);
    $account_scale     = $raw_accounts_total_mb > 0 ? ($account_target_mb / $raw_accounts_total_mb) : 0.0;

    $segments = [];

    $segments[] = [
        'id'          => 'server-used',
        'type'        => 'system',
        'name'        => 'Server (non-cPanel)',
        'inline_name' => 'Server',
        'mb'          => max(0.0, $server_used_mb - $account_target_mb),
        'value_mb'    => max(0.0, $server_used_mb - $account_target_mb),
        'color'       => '#334155',
    ];

    $account_index = 0;
    foreach ($accounts as $account) {
        $account_mb = max(0.0, (float) $account['disk_used_mb']);
        if ($account_mb <= 0) {
            continue;
        }

        $account_user = !empty($account['username']) ? (string) $account['username'] : '';
        $name = $account_user !== '' ? $account_user : 'Account ' . ($account_index + 1);
        $domain = !empty($account['domain']) ? $account['domain'] : '';
        $scaled_mb = $account_mb * $account_scale;
        $bandwidth_used_mb = isset($account['bandwidth_used_mb']) && is_numeric($account['bandwidth_used_mb'])
            ? max(0.0, (float) $account['bandwidth_used_mb'])
            : null;
        $bandwidth_limit_mb = isset($account['bandwidth_limit_mb']) && is_numeric($account['bandwidth_limit_mb'])
            ? max(0.0, (float) $account['bandwidth_limit_mb'])
            : null;
        $inodes_used = isset($account['inodes_used']) && is_numeric($account['inodes_used'])
            ? max(0, (int) $account['inodes_used'])
            : null;
        $inodes_limit = isset($account['inodes_limit']) && is_numeric($account['inodes_limit'])
            ? max(0, (int) $account['inodes_limit'])
            : null;

        $segments[] = [
            'id'          => 'account-' . ($account_index + 1),
            'type'        => 'account',
            'name'        => $name,
            'inline_name' => uptime_monitor_compact_label($name),
            'domain'      => $domain,
            'mb'          => $scaled_mb,
            'value_mb'    => $account_mb,
            'bandwidth_used_mb'  => $bandwidth_used_mb,
            'bandwidth_limit_mb' => $bandwidth_limit_mb,
            'inodes_used'  => $inodes_used,
            'inodes_limit' => $inodes_limit,
            'color'       => uptime_monitor_get_account_color($account_index),
        ];
        $account_index++;
    }

    $segments[] = [
        'id'          => 'free-space',
        'type'        => 'free',
        'name'        => 'Free Space',
        'inline_name' => 'Free',
        'mb'          => $free_mb,
        'value_mb'    => $free_mb,
        'color'       => '#22c55e',
    ];

    $running_pct = 0.0;
    $last_index  = count($segments) - 1;

    foreach ($segments as $index => $segment) {
        if ($index === $last_index) {
            $pct = max(0.0, 100.0 - $running_pct);
        } else {
            $pct = max(0.0, min(100.0, ($segment['mb'] / $total_mb) * 100.0));
            $running_pct += $pct;
        }

        $segments[$index]['pct'] = $pct;
        $segments[$index]['show_inline_label'] = $pct >= 7.0;
    }

    if ($account_scale < 0.9999) {
        $data['note'] = 'cPanel totals were larger than local server-used space, so account sections were normalized to fit the full disk line.';
    }

    $data['segments'] = $segments;
    return $data;
}

function uptime_monitor_render_server_stats($stats) {
    if (!empty($stats['error'])) {
        echo '<p>' . esc_html($stats['error']) . '</p>';
        return;
    }

    $load_average = (isset($stats['load_average']) && is_array($stats['load_average'])) ? $stats['load_average'] : null;
    $load_trend = (isset($stats['load_trend']) && is_array($stats['load_trend'])) ? $stats['load_trend'] : [];
    $cpu_cores = isset($stats['cpu_cores']) ? (int) $stats['cpu_cores'] : 0;
    $cpu_cores = $cpu_cores > 0 ? $cpu_cores : null;
    $cpu_source = isset($stats['cpu_source']) ? sanitize_key($stats['cpu_source']) : 'unknown';
    $manual_core_override = absint(get_option('uptime_monitor_cpu_cores_override', 0));

    $load_one = $load_average ? uptime_monitor_parse_load_value($load_average['one'] ?? null) : null;
    $load_five = $load_average ? uptime_monitor_parse_load_value($load_average['five'] ?? null) : null;
    $load_fifteen = $load_average ? uptime_monitor_parse_load_value($load_average['fifteen'] ?? null) : null;
    $load_level = uptime_monitor_get_load_level($load_five, $cpu_cores);

    $trend_avg = isset($load_trend['avg_five']) ? uptime_monitor_parse_load_value($load_trend['avg_five']) : null;
    $trend_peak = isset($load_trend['peak_five']) ? uptime_monitor_parse_load_value($load_trend['peak_five']) : null;
    $trend_delta = isset($load_trend['delta_pct']) && is_numeric($load_trend['delta_pct']) ? (float) $load_trend['delta_pct'] : null;
    $trend_has_graph = !empty($load_trend['has_data']) && !empty($load_trend['sparkline_points']);

    $cpu_source_text = ($cpu_source === 'manual') ? 'manual' : (($cpu_source === 'auto') ? 'auto' : 'unknown');
    $cpu_status_text = $cpu_cores ? ($cpu_cores . ' cores (' . $cpu_source_text . ')') : 'CPU cores unknown';
    $cpu_input_value = $manual_core_override > 0 ? $manual_core_override : ($cpu_cores ?: '');

    $load_warning_text = !empty($stats['load_warning']) ? (string) $stats['load_warning'] : '';
    $trend_delta_text = '';
    if ($trend_delta !== null) {
        $delta_prefix = $trend_delta >= 0 ? '+' : '';
        $trend_delta_text = $delta_prefix . number_format($trend_delta, 0) . '%';
    }

    echo '<div class="uptime-monitor-load-strip" role="group" aria-label="Server load averages" data-load-refresh="1">';
    echo '<span class="uptime-monitor-load-label">Load Avg</span>';
    echo '<span class="uptime-monitor-load-status uptime-monitor-load-status-' . esc_attr($load_level) . '" data-load-status>';
    echo '<span class="uptime-monitor-load-dot" aria-hidden="true"></span>';
    echo '<span class="uptime-monitor-load-status-text" data-load-status-text>' . esc_html(uptime_monitor_get_load_label($load_level)) . '</span>';
    echo '</span>';

    echo '<div class="uptime-monitor-load-metrics">';

    $load_items = [
        'one' => [
            'label' => '1m',
            'value' => $load_one,
        ],
        'five' => [
            'label' => '5m',
            'value' => $load_five,
        ],
        'fifteen' => [
            'label' => '15m',
            'value' => $load_fifteen,
        ],
    ];

    foreach ($load_items as $key => $item) {
        $label = $item['label'];
        $value = $item['value'];
        $value_level = uptime_monitor_get_load_level($value, $cpu_cores);
        $value_text = uptime_monitor_format_load_value($value);
        $core_text = uptime_monitor_format_load_core_text($value, $cpu_cores);
        $core_class = $core_text === '' ? 'uptime-monitor-load-pill-core is-hidden' : 'uptime-monitor-load-pill-core';

        echo '<span class="uptime-monitor-load-pill uptime-monitor-load-pill-' . esc_attr($value_level) . '" data-load-pill="' . esc_attr($key) . '">';
        echo '<span class="uptime-monitor-load-pill-label">' . esc_html($label) . '</span>';
        echo '<span class="uptime-monitor-load-pill-value">' . esc_html($value_text) . '</span>';
        echo '<span class="' . esc_attr($core_class) . '" data-load-pill-core>' . esc_html($core_text) . '</span>';
        echo '</span>';
    }

    $trend_avg_hidden = $trend_avg === null ? ' style="display:none;"' : '';
    $trend_avg_core_text = uptime_monitor_format_load_core_text($trend_avg, $cpu_cores);
    $trend_avg_core_class = $trend_avg_core_text === '' ? 'uptime-monitor-load-pill-core is-hidden' : 'uptime-monitor-load-pill-core';
    echo '<span class="uptime-monitor-load-pill" data-load-pill="trend_avg"' . $trend_avg_hidden . '>';
        echo '<span class="uptime-monitor-load-pill-label">24h avg</span>';
    echo '<span class="uptime-monitor-load-pill-value">' . esc_html(uptime_monitor_format_load_value($trend_avg)) . '</span>';
    echo '<span class="' . esc_attr($trend_avg_core_class) . '" data-load-pill-core>' . esc_html($trend_avg_core_text) . '</span>';
    echo '</span>';

    $trend_peak_hidden = $trend_peak === null ? ' style="display:none;"' : '';
    $trend_peak_core_text = uptime_monitor_format_load_core_text($trend_peak, $cpu_cores);
    $trend_peak_core_class = $trend_peak_core_text === '' ? 'uptime-monitor-load-pill-core is-hidden' : 'uptime-monitor-load-pill-core';
    echo '<span class="uptime-monitor-load-pill" data-load-pill="trend_peak"' . $trend_peak_hidden . '>';
        echo '<span class="uptime-monitor-load-pill-label">24h peak</span>';
    echo '<span class="uptime-monitor-load-pill-value">' . esc_html(uptime_monitor_format_load_value($trend_peak)) . '</span>';
    echo '<span class="' . esc_attr($trend_peak_core_class) . '" data-load-pill-core>' . esc_html($trend_peak_core_text) . '</span>';
    echo '</span>';

    echo '</div>';

    $trend_window_label = !empty($load_trend['window_label']) ? (string) $load_trend['window_label'] : '24h';
    $trend_title = $trend_window_label . ' trend (5m load)';
    $trend_style = $trend_has_graph ? '' : ' style="display:none;"';
    echo '<div class="uptime-monitor-load-trend uptime-monitor-load-trend-' . esc_attr($load_level) . '" data-load-trend title="' . esc_attr($trend_title) . '"' . $trend_style . '>';
    echo '<span class="uptime-monitor-load-trend-label" data-load-window-label>' . esc_html($trend_window_label) . '</span>';
    echo '<svg class="uptime-monitor-load-sparkline" viewBox="0 0 180 28" role="img" aria-label="' . esc_attr($trend_title) . '" data-load-trend-svg>';
    echo '<polyline points="' . esc_attr($trend_has_graph ? $load_trend['sparkline_points'] : '') . '" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-load-trend-line></polyline>';
    echo '</svg>';
    $delta_style = $trend_delta_text === '' ? ' style="display:none;"' : '';
    echo '<span class="uptime-monitor-load-trend-delta" data-load-delta' . $delta_style . '>' . esc_html($trend_delta_text) . '</span>';
    echo '</div>';

    echo '<span class="uptime-monitor-load-cpu" data-load-cpu>' . esc_html($cpu_status_text) . '</span>';

    echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=uptime-monitor')) . '" class="uptime-monitor-core-form">';
    wp_nonce_field('uptime_monitor_save_cpu_cores', 'uptime_monitor_save_cpu_cores_nonce');
    echo '<label for="uptime-monitor-cpu-cores-input" class="screen-reader-text">CPU cores override</label>';
    echo '<input id="uptime-monitor-cpu-cores-input" type="number" min="1" step="1" name="cpu_cores_override" value="' . esc_attr($cpu_input_value) . '" class="small-text">';
    echo '<button type="submit" name="save_cpu_cores" class="button button-secondary button-small">Save Cores</button>';
    if ($manual_core_override > 0) {
        echo '<button type="submit" name="clear_cpu_cores" class="button button-link-delete button-small">Auto</button>';
    }
    echo '</form>';

    $warning_class = 'uptime-monitor-load-warning';
    if ($load_warning_text === '') {
        $warning_class .= ' is-hidden';
    }
    echo '<span class="' . esc_attr($warning_class) . '" data-load-warning>' . esc_html($load_warning_text) . '</span>';

    echo '</div>';

    echo '<h2>Server Disk Usage</h2>';

    if (empty($stats['server_disk']) || empty($stats['server_disk']['total_gb'])) {
        echo '<p>Server disk usage is currently unavailable.</p>';
        return;
    }

    $disk = $stats['server_disk'];
    $graph_data = uptime_monitor_build_disk_graph_data($stats);
    $segments = $graph_data['segments'];

    if (!empty($stats['warning'])) {
        echo '<p class="uptime-monitor-disk-warning">' . esc_html($stats['warning']) . '</p>';
    }
    if (!empty($stats['account_metrics_warning'])) {
        echo '<p class="uptime-monitor-disk-warning">' . esc_html($stats['account_metrics_warning']) . '</p>';
    }

    $metrics_coverage = (isset($stats['account_metrics_coverage']) && is_array($stats['account_metrics_coverage']))
        ? $stats['account_metrics_coverage']
        : [];
    $coverage_total = isset($metrics_coverage['total_accounts']) ? (int) $metrics_coverage['total_accounts'] : 0;
    if ($coverage_total > 0) {
        $bw_matched = isset($metrics_coverage['bandwidth_matched']) ? (int) $metrics_coverage['bandwidth_matched'] : 0;
        $inode_matched = isset($metrics_coverage['inode_matched']) ? (int) $metrics_coverage['inode_matched'] : 0;
        $bw_missing = isset($metrics_coverage['bandwidth_missing_accounts']) && is_array($metrics_coverage['bandwidth_missing_accounts'])
            ? $metrics_coverage['bandwidth_missing_accounts']
            : [];
        $inode_missing = isset($metrics_coverage['inode_missing_accounts']) && is_array($metrics_coverage['inode_missing_accounts'])
            ? $metrics_coverage['inode_missing_accounts']
            : [];
        $bw_sources = isset($metrics_coverage['bandwidth_sources']) && is_array($metrics_coverage['bandwidth_sources'])
            ? $metrics_coverage['bandwidth_sources']
            : [];

        if ($bw_matched === $coverage_total && $inode_matched === $coverage_total) {
            $coverage_message = 'Account metrics coverage: bandwidth and inode data matched all ' . $coverage_total . ' cPanel accounts.';
        } else {
            $coverage_message = 'Account metrics coverage: bandwidth data matched ' . $bw_matched . ' of ' . $coverage_total . ' cPanel accounts';
            if ($bw_matched < $coverage_total) {
                $bw_missing_text = uptime_monitor_format_account_name_list($bw_missing, 3);
                if ($bw_missing_text !== '') {
                    $coverage_message .= ' (missing: ' . $bw_missing_text . ')';
                }
            }

            $coverage_message .= '; inode data matched ' . $inode_matched . ' of ' . $coverage_total . ' cPanel accounts';
            if ($inode_matched < $coverage_total) {
                $inode_missing_text = uptime_monitor_format_account_name_list($inode_missing, 3);
                if ($inode_missing_text !== '') {
                    $coverage_message .= ' (missing: ' . $inode_missing_text . ')';
                }
            }
            $coverage_message .= '. Accounts without matched metrics show as N/A in the cards below. This usually means the WHM API token is owner/reseller scoped. That is expected with least-privilege tokens; the plugin can only show bandwidth and inode metrics for accounts visible to this token.';
        }

        if (!empty($bw_sources)) {
            $coverage_message .= ' Bandwidth sources used: ' . implode(', ', $bw_sources) . '.';
        }

        echo '<p class="uptime-monitor-disk-warning">' . esc_html($coverage_message) . '</p>';
    }

    echo '<p class="uptime-monitor-disk-summary">';
    echo 'Total: <strong>' . esc_html(number_format($disk['total_gb'], 2)) . ' GB</strong> ';
    echo '| Used: <strong>' . esc_html(number_format($disk['used_gb'], 2)) . ' GB</strong> ';
    echo '| Free: <strong>' . esc_html(number_format($disk['free_gb'], 2)) . ' GB</strong>';
    echo '</p>';

    echo '<div class="uptime-monitor-disk-visual">';
    echo '<div class="uptime-monitor-disk-line" role="img" aria-label="Server disk usage breakdown">';

    foreach ($segments as $segment) {
        if ($segment['pct'] <= 0) {
            continue;
        }

        $segment_style = 'width:' . number_format($segment['pct'], 4, '.', '') . '%;background:' . $segment['color'] . ';';
        $segment_title = $segment['name'] . ': ' . uptime_monitor_format_mb($segment['value_mb']) . ' (' . number_format($segment['pct'], 1) . '%)';

        echo '<div class="uptime-monitor-disk-segment uptime-monitor-disk-segment-' . esc_attr($segment['type']) . '" data-segment-id="' . esc_attr($segment['id']) . '" style="' . esc_attr($segment_style) . '" title="' . esc_attr($segment_title) . '">';
        if (!empty($segment['show_inline_label'])) {
            echo '<span class="uptime-monitor-disk-segment-label">' . esc_html($segment['inline_name']) . '</span>';
        }
        echo '</div>';
    }

    echo '</div>';
    echo '<div class="uptime-monitor-disk-key">';

    foreach ($segments as $segment) {
        $meta = uptime_monitor_format_mb($segment['value_mb']) . ' (' . number_format($segment['pct'], 1) . '%)';
        $domain = isset($segment['domain']) ? trim((string) $segment['domain']) : '';
        $bandwidth_text = '';
        $inode_text = '';

        if (isset($segment['type']) && $segment['type'] === 'account') {
            $bandwidth_used_mb = (isset($segment['bandwidth_used_mb']) && is_numeric($segment['bandwidth_used_mb']))
                ? (float) $segment['bandwidth_used_mb']
                : null;
            $bandwidth_limit_mb = (isset($segment['bandwidth_limit_mb']) && is_numeric($segment['bandwidth_limit_mb']))
                ? (float) $segment['bandwidth_limit_mb']
                : null;
            $inodes_used = (isset($segment['inodes_used']) && is_numeric($segment['inodes_used']))
                ? (int) $segment['inodes_used']
                : null;
            $inodes_limit = (isset($segment['inodes_limit']) && is_numeric($segment['inodes_limit']))
                ? (int) $segment['inodes_limit']
                : null;

            $bandwidth_text = uptime_monitor_format_bandwidth_summary($bandwidth_used_mb, $bandwidth_limit_mb);
            $inode_text = uptime_monitor_format_inode_summary($inodes_used, $inodes_limit);
        }

        echo '<div class="uptime-monitor-disk-key-item" data-segment-id="' . esc_attr($segment['id']) . '" tabindex="0">';
        echo '<span class="uptime-monitor-disk-key-swatch" style="background:' . esc_attr($segment['color']) . ';"></span>';
        echo '<div class="uptime-monitor-disk-key-text">';
        echo '<span class="uptime-monitor-disk-key-name">' . esc_html($segment['name']) . '</span>';
        if ($domain !== '') {
            echo '<span class="uptime-monitor-disk-key-domain">' . esc_html($domain) . '</span>';
        }
        echo '<span class="uptime-monitor-disk-key-meta">' . esc_html($meta) . '</span>';
        if ($bandwidth_text !== '') {
            echo '<span class="uptime-monitor-disk-key-meta">' . esc_html($bandwidth_text) . '</span>';
        }
        if ($inode_text !== '') {
            echo '<span class="uptime-monitor-disk-key-meta">' . esc_html($inode_text) . '</span>';
        }
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';

    if (!empty($graph_data['note'])) {
        echo '<p class="uptime-monitor-disk-note">' . esc_html($graph_data['note']) . '</p>';
    }
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
        return 'No accounts found for this token scope or insufficient permissions.';
    }

    return $data['data']['acct'];
}

function get_whm_account_bandwidth_usage($whm_user, $whm_api_token, $server_url) {
    $query = rtrim($server_url, '/') . '/json-api/showbw?api.version=1';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: whm ' . $whm_user . ':' . $whm_api_token]);
    curl_setopt($curl, CURLOPT_URL, $query);

    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        return 'Unable to retrieve account bandwidth usage from WHM: ' . curl_error($curl);
    }

    curl_close($curl);

    $data = json_decode($result, true);
    if (!is_array($data)) {
        return 'Unable to retrieve account bandwidth usage from WHM (invalid API response).';
    }

    if (isset($data['metadata']['result']) && (int) $data['metadata']['result'] === 0) {
        $reason = isset($data['metadata']['reason']) ? (string) $data['metadata']['reason'] : 'Unknown error';
        return 'Unable to retrieve account bandwidth usage from WHM: ' . $reason;
    }

    if (empty($data['data']['acct']) || !is_array($data['data']['acct'])) {
        return 'Account bandwidth usage data was not included in the WHM response. This can happen with owner/reseller-scoped tokens.';
    }

    $bandwidth_by_user = [];

    foreach ($data['data']['acct'] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $user = isset($row['user']) ? trim((string) $row['user']) : '';
        if ($user === '') {
            continue;
        }
        $domain = uptime_monitor_extract_account_domain_from_row($row);

        $used_bytes = uptime_monitor_parse_non_negative_number($row['totalbytes'] ?? null);
        if ($used_bytes === null && !empty($row['bwusage']) && is_array($row['bwusage'])) {
            $bucket_total = 0.0;
            $has_bucket_data = false;

            foreach ($row['bwusage'] as $bucket) {
                if (!is_array($bucket)) {
                    continue;
                }
                $usage_value = uptime_monitor_parse_non_negative_number($bucket['usage'] ?? null);
                if ($usage_value === null) {
                    continue;
                }
                $bucket_total += $usage_value;
                $has_bucket_data = true;
            }

            if ($has_bucket_data) {
                $used_bytes = $bucket_total;
            }
        }

        $limit_bytes = uptime_monitor_parse_non_negative_number($row['limit'] ?? null);

        $metric = [
            'used_mb'  => ($used_bytes === null) ? null : ($used_bytes / (1024.0 * 1024.0)),
            'limit_mb' => ($limit_bytes !== null && $limit_bytes > 0.0) ? ($limit_bytes / (1024.0 * 1024.0)) : null,
        ];
        $keys = uptime_monitor_get_account_lookup_keys($user, $domain);
        uptime_monitor_add_metric_entry($bandwidth_by_user, $keys, $metric);
    }

    return $bandwidth_by_user;
}

function get_whm_account_bandwidth_usage_showres($whm_user, $whm_api_token, $server_url) {
    $query = rtrim($server_url, '/') . '/json-api/showres?api.version=1';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: whm ' . $whm_user . ':' . $whm_api_token]);
    curl_setopt($curl, CURLOPT_URL, $query);

    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        return 'Unable to retrieve fallback account bandwidth usage from WHM (showres): ' . curl_error($curl);
    }

    curl_close($curl);

    $data = json_decode($result, true);
    if (!is_array($data)) {
        return 'Unable to retrieve fallback account bandwidth usage from WHM (showres, invalid API response).';
    }

    if (isset($data['metadata']['result']) && (int) $data['metadata']['result'] === 0) {
        $reason = isset($data['metadata']['reason']) ? (string) $data['metadata']['reason'] : 'Unknown error';
        return 'Unable to retrieve fallback account bandwidth usage from WHM (showres): ' . $reason;
    }

    $bandwidth_by_user = [];
    $rows = [];
    $collector = function($node, $depth = 0) use (&$collector, &$rows) {
        if ($depth > 6 || !is_array($node)) {
            return;
        }

        $has_identity = isset($node['user']) || isset($node['username']);
        $has_bandwidth = isset($node['totalbytes']) || isset($node['limit']) || isset($node['bwusage']) || isset($node['xferused']) || isset($node['xferlimit']);
        if ($has_identity && $has_bandwidth) {
            $rows[] = $node;
            return;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $collector($value, $depth + 1);
            }
        }
    };
    $collector(isset($data['data']) ? $data['data'] : []);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $user = '';
        if (isset($row['user']) && is_scalar($row['user'])) {
            $user = trim((string) $row['user']);
        } elseif (isset($row['username']) && is_scalar($row['username'])) {
            $user = trim((string) $row['username']);
        }
        if ($user === '') {
            continue;
        }

        $domain = uptime_monitor_extract_account_domain_from_row($row);

        $used_mb = null;
        $used_bytes = uptime_monitor_parse_non_negative_number($row['totalbytes'] ?? null);
        if ($used_bytes !== null) {
            $used_mb = $used_bytes / (1024.0 * 1024.0);
        } elseif (isset($row['xferused'])) {
            $xfer_used_raw = $row['xferused'];
            if (is_numeric((string) $xfer_used_raw)) {
                $xfer_used_num = (float) $xfer_used_raw;
                $used_mb = ($xfer_used_num > (4 * 1024.0 * 1024.0))
                    ? ($xfer_used_num / (1024.0 * 1024.0))
                    : $xfer_used_num;
            } else {
                $used_mb = uptime_monitor_parse_size_to_mb($xfer_used_raw, true);
            }
        }

        if ($used_mb === null && !empty($row['bwusage']) && is_array($row['bwusage'])) {
            $bucket_total_bytes = 0.0;
            $has_bucket_data = false;
            foreach ($row['bwusage'] as $bucket) {
                if (!is_array($bucket)) {
                    continue;
                }
                $usage_value = uptime_monitor_parse_non_negative_number($bucket['usage'] ?? null);
                if ($usage_value === null) {
                    continue;
                }
                $bucket_total_bytes += $usage_value;
                $has_bucket_data = true;
            }
            if ($has_bucket_data) {
                $used_mb = $bucket_total_bytes / (1024.0 * 1024.0);
            }
        }

        $limit_mb = null;
        $limit_bytes = uptime_monitor_parse_non_negative_number($row['limit'] ?? null);
        if ($limit_bytes !== null && $limit_bytes > 0.0) {
            $limit_mb = $limit_bytes / (1024.0 * 1024.0);
        } elseif (isset($row['xferlimit'])) {
            $xfer_limit_raw = $row['xferlimit'];
            if (is_numeric((string) $xfer_limit_raw)) {
                $xfer_limit_num = (float) $xfer_limit_raw;
                $limit_mb = ($xfer_limit_num > (4 * 1024.0 * 1024.0))
                    ? ($xfer_limit_num / (1024.0 * 1024.0))
                    : $xfer_limit_num;
            } else {
                $limit_mb = uptime_monitor_parse_size_to_mb($xfer_limit_raw, true);
            }
            if ($limit_mb !== null && $limit_mb <= 0.0) {
                $limit_mb = null;
            }
        }

        $metric = [
            'used_mb'  => $used_mb === null ? null : max(0.0, (float) $used_mb),
            'limit_mb' => $limit_mb === null ? null : max(0.0, (float) $limit_mb),
        ];
        $keys = uptime_monitor_get_account_lookup_keys($user, $domain);
        uptime_monitor_add_metric_entry($bandwidth_by_user, $keys, $metric);
    }

    return $bandwidth_by_user;
}

function get_whm_account_inode_usage($whm_user, $whm_api_token, $server_url) {
    $query = rtrim($server_url, '/') . '/json-api/get_disk_usage?api.version=1&cache_mode=on';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: whm ' . $whm_user . ':' . $whm_api_token]);
    curl_setopt($curl, CURLOPT_URL, $query);

    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        return 'Unable to retrieve inode usage from WHM: ' . curl_error($curl);
    }

    curl_close($curl);

    $data = json_decode($result, true);
    if (!is_array($data)) {
        return 'Unable to retrieve inode usage from WHM (invalid API response).';
    }

    if (isset($data['metadata']['result']) && (int) $data['metadata']['result'] === 0) {
        $reason = isset($data['metadata']['reason']) ? (string) $data['metadata']['reason'] : 'Unknown error';
        return 'Unable to retrieve inode usage from WHM: ' . $reason;
    }

    if (empty($data['data']['accounts']) || !is_array($data['data']['accounts'])) {
        return 'Inode usage data was not included in the WHM response. This can happen with owner/reseller-scoped tokens.';
    }

    $inode_by_user = [];

    foreach ($data['data']['accounts'] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $user = isset($row['user']) ? trim((string) $row['user']) : '';
        if ($user === '') {
            continue;
        }
        $domain = uptime_monitor_extract_account_domain_from_row($row);

        $inodes_used = uptime_monitor_parse_non_negative_number($row['inodes_used'] ?? null);
        $inodes_limit = uptime_monitor_parse_non_negative_number($row['inodes_limit'] ?? null);

        $metric = [
            'used'  => $inodes_used === null ? null : (int) round($inodes_used),
            'limit' => ($inodes_limit !== null && $inodes_limit > 0.0) ? (int) round($inodes_limit) : null,
        ];
        $keys = uptime_monitor_get_account_lookup_keys($user, $domain);
        uptime_monitor_add_metric_entry($inode_by_user, $keys, $metric);
    }

    return $inode_by_user;
}

function get_whm_load_average($whm_user, $whm_api_token, $server_url) {
    $query = rtrim($server_url, '/') . '/json-api/systemloadavg?api.version=1';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // For development; consider enabling in production
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: whm ' . $whm_user . ':' . $whm_api_token]);
    curl_setopt($curl, CURLOPT_URL, $query);

    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        return 'Unable to retrieve load averages from WHM: ' . curl_error($curl);
    }

    curl_close($curl);

    $data = json_decode($result, true);
    if (!is_array($data)) {
        return 'Unable to retrieve load averages from WHM (invalid API response).';
    }

    if (isset($data['metadata']['result']) && (int) $data['metadata']['result'] === 0) {
        $reason = isset($data['metadata']['reason']) ? (string) $data['metadata']['reason'] : 'Unknown error';
        return 'Unable to retrieve load averages from WHM: ' . $reason;
    }

    if (empty($data['data']) || !is_array($data['data'])) {
        return 'Load average data was not included in the WHM response.';
    }

    return [
        'one'     => uptime_monitor_parse_load_value($data['data']['one'] ?? null),
        'five'    => uptime_monitor_parse_load_value($data['data']['five'] ?? null),
        'fifteen' => uptime_monitor_parse_load_value($data['data']['fifteen'] ?? null),
    ];
}

function uptime_monitor_ajax_get_live_load() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to view this data.'], 403);
    }

    check_ajax_referer('uptime_monitor_live_load', 'nonce');

    $whm_user      = get_option('uptime_monitor_whm_user');
    $whm_api_token = get_option('uptime_monitor_whm_api_token');
    $server_url    = get_option('uptime_monitor_whm_server_url');

    if (empty($whm_user) || empty($whm_api_token) || empty($server_url)) {
        wp_send_json_error(['message' => 'WHM credentials are not configured.']);
    }

    $cpu_info = uptime_monitor_get_cpu_core_info();
    $cpu_cores = isset($cpu_info['cores']) ? (int) $cpu_info['cores'] : 0;
    $cpu_cores = $cpu_cores > 0 ? $cpu_cores : null;

    $load_average = get_whm_load_average($whm_user, $whm_api_token, $server_url);
    if (!is_array($load_average)) {
        wp_send_json_error(['message' => (string) $load_average]);
    }

    uptime_monitor_record_load_sample($load_average, 55);

    $load_one = uptime_monitor_parse_load_value($load_average['one'] ?? null);
    $load_five = uptime_monitor_parse_load_value($load_average['five'] ?? null);
    $load_fifteen = uptime_monitor_parse_load_value($load_average['fifteen'] ?? null);

    $load_level = uptime_monitor_get_load_level($load_five, $cpu_cores);
    $trend = uptime_monitor_get_load_trend_data(24 * HOUR_IN_SECONDS, 48);
    $trend_has_graph = !empty($trend['has_data']) && !empty($trend['sparkline_points']);
    $trend_window_label = !empty($trend['window_label']) ? (string) $trend['window_label'] : '24h';

    $trend_delta = isset($trend['delta_pct']) && is_numeric($trend['delta_pct']) ? (float) $trend['delta_pct'] : null;
    $trend_delta_text = '';
    if ($trend_delta !== null) {
        $delta_prefix = $trend_delta >= 0 ? '+' : '';
        $trend_delta_text = $delta_prefix . number_format($trend_delta, 0) . '%';
    }

    $cpu_source = isset($cpu_info['source']) ? sanitize_key($cpu_info['source']) : 'unknown';
    $cpu_source_text = ($cpu_source === 'manual') ? 'manual' : (($cpu_source === 'auto') ? 'auto' : 'unknown');
    $cpu_status_text = $cpu_cores ? ($cpu_cores . ' cores (' . $cpu_source_text . ')') : 'CPU cores unknown';

    wp_send_json_success([
        'status' => [
            'level' => $load_level,
            'label' => uptime_monitor_get_load_label($load_level),
        ],
        'metrics' => [
            'one' => [
                'display' => uptime_monitor_format_load_value($load_one),
                'level'   => uptime_monitor_get_load_level($load_one, $cpu_cores),
                'core_display' => uptime_monitor_format_load_core_text($load_one, $cpu_cores),
            ],
            'five' => [
                'display' => uptime_monitor_format_load_value($load_five),
                'level'   => uptime_monitor_get_load_level($load_five, $cpu_cores),
                'core_display' => uptime_monitor_format_load_core_text($load_five, $cpu_cores),
            ],
            'fifteen' => [
                'display' => uptime_monitor_format_load_value($load_fifteen),
                'level'   => uptime_monitor_get_load_level($load_fifteen, $cpu_cores),
                'core_display' => uptime_monitor_format_load_core_text($load_fifteen, $cpu_cores),
            ],
            'trend_avg' => [
                'display' => uptime_monitor_format_load_value($trend['avg_five'] ?? null),
                'level'   => uptime_monitor_get_load_level($trend['avg_five'] ?? null, $cpu_cores),
                'core_display' => uptime_monitor_format_load_core_text($trend['avg_five'] ?? null, $cpu_cores),
                'hidden'  => !isset($trend['avg_five']) || $trend['avg_five'] === null,
            ],
            'trend_peak' => [
                'display' => uptime_monitor_format_load_value($trend['peak_five'] ?? null),
                'level'   => uptime_monitor_get_load_level($trend['peak_five'] ?? null, $cpu_cores),
                'core_display' => uptime_monitor_format_load_core_text($trend['peak_five'] ?? null, $cpu_cores),
                'hidden'  => !isset($trend['peak_five']) || $trend['peak_five'] === null,
            ],
        ],
        'trend' => [
            'has_graph'       => $trend_has_graph,
            'window_label'    => $trend_window_label,
            'title'           => $trend_window_label . ' trend (5m load)',
            'sparkline_points'=> $trend_has_graph ? (string) $trend['sparkline_points'] : '',
            'delta_text'      => $trend_delta_text,
        ],
        'cpu' => [
            'text' => $cpu_status_text,
        ],
        'warning' => '',
    ]);
}
add_action('wp_ajax_uptime_monitor_get_live_load', 'uptime_monitor_ajax_get_live_load');

// Step 4: Display server stats at the top of the Uptime Monitor page
function uptime_monitor_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>Uptime Monitor</h1>';

    if (isset($_POST['save_cpu_cores']) || isset($_POST['clear_cpu_cores'])) {
        check_admin_referer('uptime_monitor_save_cpu_cores', 'uptime_monitor_save_cpu_cores_nonce');

        if (isset($_POST['clear_cpu_cores'])) {
            delete_option('uptime_monitor_cpu_cores_override');
            echo '<div class="notice notice-success"><p>CPU core override cleared. Auto-detection is now enabled.</p></div>';
        } else {
            $cpu_cores_override = isset($_POST['cpu_cores_override']) ? absint(wp_unslash($_POST['cpu_cores_override'])) : 0;
            if ($cpu_cores_override > 0) {
                update_option('uptime_monitor_cpu_cores_override', $cpu_cores_override);
                echo '<div class="notice notice-success"><p>CPU core override saved.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Please enter a valid CPU core count greater than zero.</p></div>';
            }
        }
    }

    // Fetch and display the server stats
    $server_stats = get_whm_server_stats();
    uptime_monitor_render_server_stats($server_stats);

    if (isset($_POST['check_all_sites'])) {
        check_admin_referer('uptime_monitor_check_all_sites', 'uptime_monitor_check_all_sites_nonce');

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
        check_admin_referer('uptime_monitor_update_keyword', 'uptime_monitor_update_keyword_nonce');

        $site    = isset($_POST['site']) ? esc_url_raw(wp_unslash($_POST['site'])) : '';
        $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';

        if (empty($site)) {
            echo '<div class="notice notice-error"><p>Unable to update keyword: invalid site URL.</p></div>';
        } else {
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
    }

    if (isset($_POST['recheck_site'])) {
        check_admin_referer('uptime_monitor_recheck_site', 'uptime_monitor_recheck_site_nonce');

        $site = isset($_POST['site']) ? esc_url_raw(wp_unslash($_POST['site'])) : '';
        if (!empty($site)) {
            uptime_monitor_perform_check($site);
        } else {
            echo '<div class="notice notice-error"><p>Unable to recheck: invalid site URL.</p></div>';
        }
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

    echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=uptime-monitor')) . '" style="display: inline;">';
    wp_nonce_field('uptime_monitor_check_all_sites', 'uptime_monitor_check_all_sites_nonce');
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
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=uptime-monitor')) . '" style="display: inline;">';
        wp_nonce_field('uptime_monitor_update_keyword', 'uptime_monitor_update_keyword_nonce');
        echo '<input type="hidden" name="site" value="' . esc_attr($site_url) . '">';
        echo '<input type="text" name="keyword" value="' . esc_attr($custom_keyword) . '">';
        echo '<input type="submit" name="update_keyword" class="button button-secondary" value="Update">';
        echo '</form>';
        echo '</td>';
        echo '<td>' . esc_html($last_checked_time) . '</td>';
        echo '<td>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=uptime-monitor')) . '" style="display: inline;">';
        wp_nonce_field('uptime_monitor_recheck_site', 'uptime_monitor_recheck_site_nonce');
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
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== 'uptime-monitor') {
        return;
    }

    echo '<style>
        .error {
            color: red !important;
            font-weight: bold;
        }
        .uptime-monitor-disk-summary {
            margin: 8px 0 10px;
        }
        .uptime-monitor-load-strip {
            margin: 10px 0 10px;
            padding: 8px 10px;
            border: 1px solid #dcdcde;
            border-radius: 10px;
            background: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            overflow-x: auto;
        }
        .uptime-monitor-load-label {
            font-size: 11px;
            font-weight: 700;
            color: #50575e;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .uptime-monitor-load-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
        }
        .uptime-monitor-load-status-healthy {
            color: #166534;
            background: #ecfdf3;
            border-color: #86efac;
        }
        .uptime-monitor-load-status-elevated {
            color: #9a3412;
            background: #fff7ed;
            border-color: #fdba74;
        }
        .uptime-monitor-load-status-high {
            color: #991b1b;
            background: #fef2f2;
            border-color: #fca5a5;
        }
        .uptime-monitor-load-status-unknown {
            color: #374151;
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        .uptime-monitor-load-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: currentColor;
            opacity: 0.9;
        }
        .uptime-monitor-load-metrics {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: nowrap;
        }
        .uptime-monitor-load-pill {
            min-width: 100px;
            padding: 4px 6px;
            border: 1px solid #dcdcde;
            border-radius: 7px;
            background: #f6f7f7;
            text-align: center;
            line-height: 1.1;
            flex: 0 0 auto;
        }
        .uptime-monitor-load-pill-label {
            display: block;
            font-size: 10px;
            color: #646970;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .uptime-monitor-load-pill-value {
            display: block;
            margin-top: 2px;
            font-size: 13px;
            font-weight: 700;
            color: #1d2327;
            line-height: 1.2;
        }
        .uptime-monitor-load-pill-core {
            display: block;
            margin-top: 1px;
            font-size: 10px;
            color: #50575e;
            line-height: 1.2;
            white-space: nowrap;
        }
        .uptime-monitor-load-pill-core.is-hidden {
            display: none;
        }
        .uptime-monitor-load-pill-elevated .uptime-monitor-load-pill-value {
            color: #9a3412;
        }
        .uptime-monitor-load-pill-high .uptime-monitor-load-pill-value {
            color: #991b1b;
        }
        .uptime-monitor-load-trend {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 6px;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            background: #f8fafc;
            flex: 0 0 auto;
        }
        .uptime-monitor-load-trend-label {
            font-size: 10px;
            color: #646970;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            line-height: 1;
        }
        .uptime-monitor-load-sparkline {
            width: 180px;
            height: 28px;
            display: block;
        }
        .uptime-monitor-load-trend polyline {
            stroke: #1d4ed8;
        }
        .uptime-monitor-load-trend-elevated polyline {
            stroke: #ea580c;
        }
        .uptime-monitor-load-trend-high polyline {
            stroke: #dc2626;
        }
        .uptime-monitor-load-trend-delta {
            font-size: 11px;
            font-weight: 600;
            color: #50575e;
            line-height: 1;
        }
        .uptime-monitor-load-cpu {
            font-size: 12px;
            color: #50575e;
            white-space: nowrap;
            margin-left: auto;
        }
        .uptime-monitor-core-form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            white-space: nowrap;
            flex: 0 0 auto;
        }
        .uptime-monitor-core-form .small-text {
            width: 58px;
            min-height: 28px;
        }
        .uptime-monitor-core-form .button-small {
            min-height: 28px;
            line-height: 26px;
            padding: 0 10px;
        }
        .uptime-monitor-load-warning {
            margin: 0 0 0 6px;
            font-size: 12px;
            color: #50575e;
            white-space: nowrap;
        }
        .uptime-monitor-load-warning.is-hidden {
            display: none;
        }
        .uptime-monitor-disk-warning,
        .uptime-monitor-disk-note {
            margin: 10px 0;
            color: #50575e;
        }
        .uptime-monitor-disk-visual {
            margin: 12px 0 18px;
        }
        .uptime-monitor-disk-line {
            display: flex;
            width: 100%;
            height: 42px;
            border: 1px solid #c3c4c7;
            border-radius: 999px;
            overflow: hidden;
            background: #f6f7f7;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08);
        }
        .uptime-monitor-disk-segment {
            position: relative;
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 160ms ease, transform 160ms ease, box-shadow 160ms ease;
        }
        .uptime-monitor-disk-segment-label {
            display: block;
            max-width: 100%;
            padding: 0 10px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: 12px;
            font-weight: 600;
            color: #ffffff;
            text-shadow: 0 1px 1px rgba(0, 0, 0, 0.35);
        }
        .uptime-monitor-disk-key {
            margin-top: 12px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 8px;
        }
        .uptime-monitor-disk-key-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 10px;
            background: #ffffff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            outline: none;
            transition: opacity 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
        }
        .uptime-monitor-disk-key-swatch {
            width: 12px;
            height: 12px;
            border-radius: 999px;
            flex: 0 0 auto;
            margin-top: 3px;
        }
        .uptime-monitor-disk-key-text {
            display: block;
            min-width: 0;
        }
        .uptime-monitor-disk-key-name {
            display: block;
            font-weight: 600;
            color: #1d2327;
            line-height: 1.3;
        }
        .uptime-monitor-disk-key-domain,
        .uptime-monitor-disk-key-meta {
            display: block;
            font-size: 12px;
            color: #50575e;
            line-height: 1.35;
            word-break: break-word;
        }
        .uptime-monitor-disk-visual.has-active .uptime-monitor-disk-segment:not(.is-hovered),
        .uptime-monitor-disk-visual.has-active .uptime-monitor-disk-key-item:not(.is-hovered) {
            opacity: 0.32;
        }
        .uptime-monitor-disk-segment.is-hovered {
            z-index: 1;
            box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.85);
            transform: scaleY(1.03);
        }
        .uptime-monitor-disk-key-item.is-hovered {
            border-color: #1d4ed8;
            box-shadow: 0 0 0 1px #1d4ed8;
        }
        .uptime-monitor-disk-key-item:focus-visible {
            border-color: #1d4ed8;
            box-shadow: 0 0 0 2px rgba(29, 78, 216, 0.3);
        }
        @media (max-width: 782px) {
            .uptime-monitor-load-strip {
                flex-wrap: wrap;
                overflow-x: visible;
            }
            .uptime-monitor-load-metrics {
                flex-wrap: wrap;
            }
            .uptime-monitor-load-cpu {
                margin-left: 0;
                width: 100%;
            }
            .uptime-monitor-load-warning {
                width: 100%;
                margin-left: 0;
                white-space: normal;
            }
            .uptime-monitor-load-sparkline {
                width: 130px;
            }
        }
    </style>';
}
add_action('admin_head', 'uptime_monitor_enqueue_styles');

function uptime_monitor_enqueue_disk_graph_script() {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== 'uptime-monitor') {
        return;
    }

    $ajax_url = wp_json_encode(admin_url('admin-ajax.php'));
    $load_nonce = wp_json_encode(wp_create_nonce('uptime_monitor_live_load'));

    echo '<script>
    (function() {
        var root = document.querySelector(".uptime-monitor-disk-visual");
        if (!root) {
            return;
        }

        var segments = root.querySelectorAll(".uptime-monitor-disk-segment[data-segment-id]");
        var keyItems = root.querySelectorAll(".uptime-monitor-disk-key-item[data-segment-id]");

        if (!segments.length || !keyItems.length) {
            return;
        }

        var clearAll = function() {
            root.classList.remove("has-active");
            segments.forEach(function(segment) {
                segment.classList.remove("is-hovered");
            });
            keyItems.forEach(function(item) {
                item.classList.remove("is-hovered");
            });
        };

        var setActive = function(segmentId) {
            root.classList.add("has-active");
            segments.forEach(function(segment) {
                segment.classList.toggle("is-hovered", segment.getAttribute("data-segment-id") === segmentId);
            });
            keyItems.forEach(function(item) {
                item.classList.toggle("is-hovered", item.getAttribute("data-segment-id") === segmentId);
            });
        };

        var bindNodes = function(nodes) {
            nodes.forEach(function(node) {
                var segmentId = node.getAttribute("data-segment-id");
                if (!segmentId) {
                    return;
                }
                node.addEventListener("mouseenter", function() {
                    setActive(segmentId);
                });
                node.addEventListener("focus", function() {
                    setActive(segmentId);
                });
            });
        };

        bindNodes(segments);
        bindNodes(keyItems);

        root.addEventListener("mouseleave", clearAll);
        root.addEventListener("focusout", function(event) {
            if (!root.contains(event.relatedTarget)) {
                clearAll();
            }
        });
    })();
    </script>';

    echo '<script>
    (function() {
        var strip = document.querySelector(".uptime-monitor-load-strip[data-load-refresh=\"1\"]");
        if (!strip) {
            return;
        }

        var ajaxUrl = ' . $ajax_url . ';
        var ajaxNonce = ' . $load_nonce . ';
        var refreshMs = 60000;
        var inFlight = false;
        var levels = ["healthy", "elevated", "high", "unknown"];

        var setLevelClass = function(node, prefix, level) {
            if (!node) {
                return;
            }
            levels.forEach(function(item) {
                node.classList.remove(prefix + "-" + item);
            });
            node.classList.add(prefix + "-" + (level || "unknown"));
        };

        var updateMetric = function(key, data) {
            var pill = strip.querySelector("[data-load-pill=\"" + key + "\"]");
            if (!pill || !data) {
                return;
            }

            if (typeof data.hidden === "boolean") {
                pill.style.display = data.hidden ? "none" : "";
            }

            setLevelClass(pill, "uptime-monitor-load-pill", data.level || "unknown");

            var valueNode = pill.querySelector(".uptime-monitor-load-pill-value");
            if (valueNode) {
                valueNode.textContent = data.display || "N/A";
            }

            var coreNode = pill.querySelector("[data-load-pill-core]");
            if (coreNode) {
                var coreText = data.core_display || "";
                coreNode.textContent = coreText;
                coreNode.classList.toggle("is-hidden", !coreText);
            }
        };

        var applyPayload = function(data) {
            if (!data) {
                return;
            }

            var statusNode = strip.querySelector("[data-load-status]");
            var statusTextNode = strip.querySelector("[data-load-status-text]");
            var statusLevel = data.status && data.status.level ? data.status.level : "unknown";
            var statusLabel = data.status && data.status.label ? data.status.label : "Unknown";

            setLevelClass(statusNode, "uptime-monitor-load-status", statusLevel);
            if (statusTextNode) {
                statusTextNode.textContent = statusLabel;
            }

            if (data.metrics) {
                updateMetric("one", data.metrics.one);
                updateMetric("five", data.metrics.five);
                updateMetric("fifteen", data.metrics.fifteen);
                updateMetric("trend_avg", data.metrics.trend_avg);
                updateMetric("trend_peak", data.metrics.trend_peak);
            }

            var trendNode = strip.querySelector("[data-load-trend]");
            var trendLabelNode = strip.querySelector("[data-load-window-label]");
            var trendLineNode = strip.querySelector("[data-load-trend-line]");
            var trendSvgNode = strip.querySelector("[data-load-trend-svg]");
            var trendDeltaNode = strip.querySelector("[data-load-delta]");

            if (data.trend && data.trend.has_graph && trendNode) {
                trendNode.style.display = "";
                setLevelClass(trendNode, "uptime-monitor-load-trend", statusLevel);
                trendNode.setAttribute("title", data.trend.title || "Load trend");
                if (trendLabelNode) {
                    trendLabelNode.textContent = data.trend.window_label || "24h";
                }
                if (trendLineNode) {
                    trendLineNode.setAttribute("points", data.trend.sparkline_points || "");
                }
                if (trendSvgNode && data.trend.title) {
                    trendSvgNode.setAttribute("aria-label", data.trend.title);
                }
                if (trendDeltaNode) {
                    var deltaText = data.trend.delta_text || "";
                    trendDeltaNode.textContent = deltaText;
                    trendDeltaNode.style.display = deltaText ? "" : "none";
                }
            } else if (trendNode) {
                trendNode.style.display = "none";
            }

            var cpuNode = strip.querySelector("[data-load-cpu]");
            if (cpuNode && data.cpu && data.cpu.text) {
                cpuNode.textContent = data.cpu.text;
            }

            var warningNode = strip.querySelector("[data-load-warning]");
            if (warningNode) {
                var warningText = data.warning || "";
                warningNode.textContent = warningText;
                warningNode.classList.toggle("is-hidden", !warningText);
            }
        };

        var showWarning = function(message) {
            var warningNode = strip.querySelector("[data-load-warning]");
            if (!warningNode) {
                return;
            }
            warningNode.textContent = message || "Unable to refresh load metrics.";
            warningNode.classList.remove("is-hidden");
        };

        var fetchLiveLoad = function() {
            if (inFlight) {
                return;
            }
            inFlight = true;

            var formData = new URLSearchParams();
            formData.append("action", "uptime_monitor_get_live_load");
            formData.append("nonce", ajaxNonce);

            fetch(ajaxUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
                },
                body: formData.toString()
            }).then(function(response) {
                return response.json();
            }).then(function(payload) {
                if (!payload || !payload.success || !payload.data) {
                    var message = payload && payload.data && payload.data.message ? payload.data.message : "Unable to refresh load metrics.";
                    throw new Error(message);
                }
                applyPayload(payload.data);
            }).catch(function(error) {
                showWarning(error && error.message ? error.message : "Unable to refresh load metrics.");
            }).finally(function() {
                inFlight = false;
            });
        };

        window.setInterval(function() {
            if (document.visibilityState === "visible") {
                fetchLiveLoad();
            }
        }, refreshMs);

        document.addEventListener("visibilitychange", function() {
            if (document.visibilityState === "visible") {
                fetchLiveLoad();
            }
        });
    })();
    </script>';
}
add_action('admin_footer', 'uptime_monitor_enqueue_disk_graph_script');

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

    if (!wp_next_scheduled('uptime_monitor_load_sample_check')) {
        wp_schedule_event(time() + 60, 'uptime_monitor_every_five_minutes', 'uptime_monitor_load_sample_check');
    }
}
add_action('init', 'uptime_monitor_schedule_task');

function uptime_monitor_add_cron_intervals($schedules) {
    if (!isset($schedules['uptime_monitor_every_five_minutes'])) {
        $schedules['uptime_monitor_every_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes (Uptime Monitor)',
        ];
    }

    return $schedules;
}
add_filter('cron_schedules', 'uptime_monitor_add_cron_intervals');

function uptime_monitor_capture_load_sample() {
    $whm_user      = get_option('uptime_monitor_whm_user');
    $whm_api_token = get_option('uptime_monitor_whm_api_token');
    $server_url    = get_option('uptime_monitor_whm_server_url');

    if (empty($whm_user) || empty($whm_api_token) || empty($server_url)) {
        return;
    }

    $load_average = get_whm_load_average($whm_user, $whm_api_token, $server_url);
    if (is_array($load_average)) {
        uptime_monitor_record_load_sample($load_average, 0);
    }
}
add_action('uptime_monitor_load_sample_check', 'uptime_monitor_capture_load_sample');

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
