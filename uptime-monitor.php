<?php
/*
Plugin Name: Uptime Monitor
Plugin URI: https://github.com/stronganchor/uptime-monitor/
Description: A plugin to monitor URLs and report their HTTP status and display server stats.
Version: 1.1.17
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
        'Off-Directory Plugins',
        'Plugin Report',
        'manage_options',
        'uptime-monitor-plugin-report',
        'uptime_monitor_plugin_report_page'
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

function uptime_monitor_get_current_admin_page() {
    return isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
}

function uptime_monitor_normalize_plugin_file_key($plugin_file) {
    if (!is_scalar($plugin_file)) {
        return '';
    }

    $identity = uptime_monitor_get_plugin_canonical_identity($plugin_file);
    return isset($identity['plugin_file']) ? (string) $identity['plugin_file'] : '';
}

function uptime_monitor_decode_json_array($value) {
    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value) || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function uptime_monitor_clean_plugin_text($value) {
    if (!is_scalar($value)) {
        return '';
    }

    return trim(wp_strip_all_tags((string) $value));
}

function uptime_monitor_clean_plugin_url($value) {
    if (!is_scalar($value)) {
        return '';
    }

    $url = trim((string) $value);
    return $url === '' ? '' : esc_url_raw($url);
}

function uptime_monitor_get_plugin_file_from_record($key, $plugin) {
    if (is_string($key) && $key !== '') {
        return ltrim($key, '/');
    }

    if (is_array($plugin) && isset($plugin['slug']) && is_scalar($plugin['slug'])) {
        return ltrim((string) $plugin['slug'], '/');
    }

    return '';
}

function uptime_monitor_strip_plugin_slug_noise($value) {
    if (!is_scalar($value)) {
        return '';
    }

    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\.php$/i', '', $value);
    $value = preg_replace('/(?:[._-](?:main|master|disabled))$/i', '', $value);

    return trim((string) $value, " \t\n\r\0\x0B/._-");
}

function uptime_monitor_get_plugin_slug_tokens($value) {
    $value = sanitize_title($value);
    if ($value === '') {
        return [];
    }

    return array_values(array_unique(array_filter(explode('-', $value), 'strlen')));
}

function uptime_monitor_plugin_slug_is_subset($maybe_subset, $maybe_superset) {
    $maybe_subset = sanitize_title($maybe_subset);
    $maybe_superset = sanitize_title($maybe_superset);

    if ($maybe_subset === '' || $maybe_superset === '') {
        return false;
    }

    if ($maybe_subset === $maybe_superset) {
        return true;
    }

    $subset_tokens = uptime_monitor_get_plugin_slug_tokens($maybe_subset);
    $superset_tokens = uptime_monitor_get_plugin_slug_tokens($maybe_superset);
    if (empty($subset_tokens) || empty($superset_tokens) || count($subset_tokens) > count($superset_tokens)) {
        return false;
    }

    foreach ($subset_tokens as $token) {
        if (!in_array($token, $superset_tokens, true)) {
            return false;
        }
    }

    return true;
}

function uptime_monitor_get_plugin_canonical_identity($plugin_file) {
    if (!is_scalar($plugin_file)) {
        return [
            'plugin_file'     => '',
            'canonical_slug'  => '',
            'raw_plugin_file' => '',
        ];
    }

    $raw_plugin_file = ltrim(trim((string) $plugin_file), '/');
    if ($raw_plugin_file === '') {
        return [
            'plugin_file'     => '',
            'canonical_slug'  => '',
            'raw_plugin_file' => '',
        ];
    }

    $parts = explode('/', $raw_plugin_file);
    $folder_part = count($parts) > 1 ? (string) $parts[0] : '';
    $file_part = (string) $parts[count($parts) - 1];
    $file_part = preg_replace('/\.php$/i', '', $file_part);

    $folder_slug = $folder_part !== '' ? sanitize_title($folder_part) : '';
    $file_slug = sanitize_title($file_part);
    $clean_folder_slug = $folder_part !== '' ? sanitize_title(uptime_monitor_strip_plugin_slug_noise($folder_part)) : '';
    $clean_file_slug = sanitize_title(uptime_monitor_strip_plugin_slug_noise($file_part));

    $folder_compare = $clean_folder_slug !== '' ? $clean_folder_slug : $folder_slug;
    $file_compare = $clean_file_slug !== '' ? $clean_file_slug : $file_slug;
    $canonical_slug = '';

    if ($folder_compare !== '' && $file_compare !== '') {
        if (
            $folder_compare === $file_compare
            || uptime_monitor_plugin_slug_is_subset($folder_compare, $file_compare)
            || uptime_monitor_plugin_slug_is_subset($file_compare, $folder_compare)
        ) {
            $slug_options = array_values(array_unique(array_filter([$folder_compare, $file_compare], 'strlen')));
            usort($slug_options, function($a, $b) {
                $length_compare = strlen($a) <=> strlen($b);
                if ($length_compare !== 0) {
                    return $length_compare;
                }

                return strcasecmp($a, $b);
            });
            $canonical_slug = isset($slug_options[0]) ? (string) $slug_options[0] : '';
        }
    }

    if ($canonical_slug === '') {
        $canonical_slug = uptime_monitor_get_plugin_candidate_slug($raw_plugin_file);
    }

    $canonical_file_slug = $file_compare !== '' ? $file_compare : ($canonical_slug !== '' ? $canonical_slug : $file_slug);

    if ($folder_part === '') {
        $canonical_plugin_file = $canonical_file_slug !== '' ? ($canonical_file_slug . '.php') : $raw_plugin_file;
    } else {
        $canonical_plugin_file = ($canonical_slug !== '' && $canonical_file_slug !== '')
            ? ($canonical_slug . '/' . $canonical_file_slug . '.php')
            : $raw_plugin_file;
    }

    return [
        'plugin_file'     => $canonical_plugin_file,
        'canonical_slug'  => $canonical_slug,
        'raw_plugin_file' => $raw_plugin_file,
    ];
}

function uptime_monitor_get_plugin_candidate_slug($plugin_file) {
    $plugin_file = trim((string) $plugin_file);
    if ($plugin_file === '') {
        return '';
    }

    $plugin_file = ltrim($plugin_file, '/');
    $parts = explode('/', $plugin_file);
    $folder_part = count($parts) > 1 ? (string) $parts[0] : '';
    $file_part = (string) $parts[count($parts) - 1];
    $file_part = preg_replace('/\.php$/i', '', $file_part);

    $folder_slug = $folder_part !== '' ? sanitize_title($folder_part) : '';
    $file_slug = sanitize_title($file_part);
    $clean_folder_slug = $folder_part !== '' ? sanitize_title(uptime_monitor_strip_plugin_slug_noise($folder_part)) : '';
    $clean_file_slug = sanitize_title(uptime_monitor_strip_plugin_slug_noise($file_part));

    if ($folder_part !== '') {
        if ($clean_folder_slug !== '' && $clean_file_slug !== '' && $clean_folder_slug === $clean_file_slug) {
            return $clean_file_slug;
        }

        if ($clean_folder_slug !== '' && $file_slug !== '' && $clean_folder_slug === $file_slug) {
            return $file_slug;
        }

        if ($folder_slug !== '' && $clean_file_slug !== '' && $folder_slug === $clean_file_slug) {
            return $folder_slug;
        }

        if ($clean_folder_slug !== '') {
            return $clean_folder_slug;
        }

        if ($folder_slug !== '') {
            return $folder_slug;
        }
    }

    if ($clean_file_slug !== '') {
        return $clean_file_slug;
    }

    return $file_slug;
}

function uptime_monitor_get_wporg_plugin_page_url($plugin_slug) {
    $plugin_slug = sanitize_title($plugin_slug);
    if ($plugin_slug === '') {
        return '';
    }

    return esc_url_raw('https://wordpress.org/plugins/' . rawurlencode($plugin_slug) . '/');
}

function uptime_monitor_get_stronganchor_github_org_url() {
    return 'https://github.com/stronganchor/';
}

function uptime_monitor_get_stronganchor_github_repo_inventory() {
    $cache_key = 'uptime_monitor_stronganchor_github_repos_v1';
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['repos']) && is_array($cached['repos'])) {
        return $cached;
    }

    $request_args = [
        'timeout' => 10,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/Uptime-Monitor',
        ],
    ];
    $endpoints = [
        'org'  => 'https://api.github.com/orgs/stronganchor/repos?per_page=100&type=public&sort=updated',
        'user' => 'https://api.github.com/users/stronganchor/repos?per_page=100&type=public&sort=updated',
    ];
    $last_error = 'GitHub returned an unknown error while checking the Strong Anchor repository list.';

    foreach ($endpoints as $account_type => $endpoint_url) {
        $response = wp_remote_get($endpoint_url, $request_args);

        if (is_wp_error($response)) {
            $last_error = $response->get_error_message();
            continue;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code === 404 && $account_type === 'org') {
            continue;
        }

        if ($status_code === 404 && $account_type === 'user') {
            $last_error = 'GitHub did not find a public org or user named stronganchor.';
            continue;
        }

        if ($status_code !== 200) {
            $last_error = 'GitHub returned HTTP ' . $status_code . ' while checking the Strong Anchor repository list.';
            continue;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            $last_error = 'GitHub returned an invalid repository list response.';
            continue;
        }

        $repos = [];
        foreach ($decoded as $repo) {
            if (!is_array($repo) || empty($repo['name']) || !is_scalar($repo['name'])) {
                continue;
            }

            $repo_name = sanitize_title((string) $repo['name']);
            if ($repo_name === '') {
                continue;
            }

            $repos[$repo_name] = [
                'name' => uptime_monitor_clean_plugin_text($repo['name']),
                'url'  => isset($repo['html_url']) ? uptime_monitor_clean_plugin_url($repo['html_url']) : '',
            ];
        }

        $result = [
            'repos'  => $repos,
            'error'  => '',
            'status' => 'ok',
        ];

        set_transient($cache_key, $result, 7 * DAY_IN_SECONDS);
        return $result;
    }

    return [
        'repos'  => [],
        'error'  => $last_error,
        'status' => 'error',
    ];
}

function uptime_monitor_apply_stronganchor_github_matches(&$grouped) {
    if (!is_array($grouped) || empty($grouped)) {
        return '';
    }

    $needs_lookup = false;
    foreach ($grouped as &$group) {
        if (!is_array($group)) {
            continue;
        }

        $group['stronganchor_github_match'] = false;
        $group['stronganchor_github_repo'] = '';
        $group['stronganchor_github_url'] = '';

        if (empty($group['author_links']) && !empty($group['candidate'])) {
            $needs_lookup = true;
        }
    }
    unset($group);

    if (!$needs_lookup) {
        return '';
    }

    $inventory = uptime_monitor_get_stronganchor_github_repo_inventory();
    $repos = isset($inventory['repos']) && is_array($inventory['repos']) ? $inventory['repos'] : [];

    foreach ($grouped as &$group) {
        if (!is_array($group) || !empty($group['author_links']) || empty($group['candidate'])) {
            continue;
        }

        $candidate = sanitize_title($group['candidate']);
        if ($candidate === '' || !isset($repos[$candidate]) || !is_array($repos[$candidate])) {
            continue;
        }

        $group['stronganchor_github_match'] = true;
        $group['stronganchor_github_repo'] = isset($repos[$candidate]['name']) ? (string) $repos[$candidate]['name'] : $candidate;
        $group['stronganchor_github_url'] = isset($repos[$candidate]['url']) ? (string) $repos[$candidate]['url'] : '';
    }
    unset($group);

    return isset($inventory['error']) ? uptime_monitor_clean_plugin_text($inventory['error']) : '';
}

function uptime_monitor_format_wporg_closed_date($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value . ' 00:00:00 UTC');
    if ($timestamp === false) {
        return uptime_monitor_clean_plugin_text($value);
    }

    return gmdate('F j, Y', $timestamp);
}

function uptime_monitor_get_hidden_plugin_report_items() {
    $stored = get_option('uptime_monitor_hidden_plugin_report_items', []);
    if (!is_array($stored)) {
        return [];
    }

    $hidden = [];
    foreach ($stored as $plugin_file => $item) {
        $normalized_file = uptime_monitor_normalize_plugin_file_key($plugin_file);
        if ($normalized_file === '') {
            continue;
        }

        $item = is_array($item) ? $item : [];
        $identity = uptime_monitor_get_plugin_canonical_identity($normalized_file);
        $hidden[$normalized_file] = [
            'plugin_file' => $normalized_file,
            'name'        => isset($item['name']) ? uptime_monitor_clean_plugin_text($item['name']) : '',
            'candidate'   => !empty($identity['canonical_slug'])
                ? sanitize_title($identity['canonical_slug'])
                : (isset($item['candidate']) ? sanitize_title($item['candidate']) : ''),
            'hidden_at'   => isset($item['hidden_at']) ? absint($item['hidden_at']) : 0,
        ];
    }

    ksort($hidden, SORT_NATURAL);
    return $hidden;
}

function uptime_monitor_save_hidden_plugin_report_items($items) {
    if (!is_array($items)) {
        $items = [];
    }

    if (get_option('uptime_monitor_hidden_plugin_report_items', null) === null) {
        add_option('uptime_monitor_hidden_plugin_report_items', $items, '', false);
    } else {
        update_option('uptime_monitor_hidden_plugin_report_items', $items);
    }
}

function uptime_monitor_hide_plugin_report_item($plugin_file, $name = '', $candidate = '') {
    $plugin_file = uptime_monitor_normalize_plugin_file_key($plugin_file);
    if ($plugin_file === '') {
        return false;
    }

    $hidden = uptime_monitor_get_hidden_plugin_report_items();
    $hidden[$plugin_file] = [
        'plugin_file' => $plugin_file,
        'name'        => uptime_monitor_clean_plugin_text($name),
        'candidate'   => sanitize_title($candidate),
        'hidden_at'   => time(),
    ];

    uptime_monitor_save_hidden_plugin_report_items($hidden);
    return true;
}

function uptime_monitor_unhide_plugin_report_item($plugin_file) {
    $plugin_file = uptime_monitor_normalize_plugin_file_key($plugin_file);
    if ($plugin_file === '') {
        return false;
    }

    $hidden = uptime_monitor_get_hidden_plugin_report_items();
    if (!isset($hidden[$plugin_file])) {
        return false;
    }

    unset($hidden[$plugin_file]);
    uptime_monitor_save_hidden_plugin_report_items($hidden);
    return true;
}

function uptime_monitor_get_plugin_report_notes() {
    $stored = get_option('uptime_monitor_plugin_report_notes', []);
    if (!is_array($stored)) {
        return [];
    }

    $notes = [];
    foreach ($stored as $plugin_file => $note) {
        $normalized_file = uptime_monitor_normalize_plugin_file_key($plugin_file);
        if ($normalized_file === '') {
            continue;
        }

        $clean_note = sanitize_textarea_field((string) $note);
        if ($clean_note === '') {
            continue;
        }

        $notes[$normalized_file] = $clean_note;
    }

    ksort($notes, SORT_NATURAL);
    return $notes;
}

function uptime_monitor_save_plugin_report_notes($notes) {
    if (!is_array($notes)) {
        $notes = [];
    }

    if (get_option('uptime_monitor_plugin_report_notes', null) === null) {
        add_option('uptime_monitor_plugin_report_notes', $notes, '', false);
    } else {
        update_option('uptime_monitor_plugin_report_notes', $notes);
    }
}

function uptime_monitor_update_plugin_report_note($plugin_file, $note) {
    $plugin_file = uptime_monitor_normalize_plugin_file_key($plugin_file);
    if ($plugin_file === '') {
        return false;
    }

    $notes = uptime_monitor_get_plugin_report_notes();
    $note = sanitize_textarea_field((string) $note);

    if ($note === '') {
        unset($notes[$plugin_file]);
    } else {
        $notes[$plugin_file] = $note;
    }

    uptime_monitor_save_plugin_report_notes($notes);
    return true;
}

function uptime_monitor_get_plugin_report_note($plugin_file) {
    $plugin_file = uptime_monitor_normalize_plugin_file_key($plugin_file);
    if ($plugin_file === '') {
        return '';
    }

    $notes = uptime_monitor_get_plugin_report_notes();
    return isset($notes[$plugin_file]) ? (string) $notes[$plugin_file] : '';
}

function uptime_monitor_parse_optional_boolean($value) {
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return ((float) $value) !== 0.0;
    }

    if (!is_scalar($value)) {
        return null;
    }

    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return null;
    }

    if (in_array($value, ['1', 'true', 'yes', 'on', 'active', 'activated', 'enabled'], true)) {
        return true;
    }

    if (in_array($value, ['0', 'false', 'no', 'off', 'inactive', 'deactivated', 'disabled'], true)) {
        return false;
    }

    return null;
}

function uptime_monitor_get_plugin_activation_state($plugin_data = [], $live_headers = []) {
    if (!empty($live_headers['mu']) || !empty($plugin_data['mu'])) {
        return 'active';
    }

    if (is_array($live_headers)) {
        if (array_key_exists('network_active', $live_headers)) {
            $network_active = uptime_monitor_parse_optional_boolean($live_headers['network_active']);
            if ($network_active === true) {
                return 'active';
            }
        }

        if (array_key_exists('active', $live_headers)) {
            $active = uptime_monitor_parse_optional_boolean($live_headers['active']);
            if ($active !== null) {
                return $active ? 'active' : 'inactive';
            }
        }
    }

    if (is_array($plugin_data)) {
        foreach (['network_active', 'network'] as $flag_key) {
            if (!array_key_exists($flag_key, $plugin_data)) {
                continue;
            }

            $network_active = uptime_monitor_parse_optional_boolean($plugin_data[$flag_key]);
            if ($network_active === true) {
                return 'active';
            }
        }

        foreach (['active', 'activated', 'is_active', 'enabled'] as $flag_key) {
            if (!array_key_exists($flag_key, $plugin_data)) {
                continue;
            }

            $active = uptime_monitor_parse_optional_boolean($plugin_data[$flag_key]);
            if ($active !== null) {
                return $active ? 'active' : 'inactive';
            }
        }

        if (isset($plugin_data['status']) && is_scalar($plugin_data['status'])) {
            $status = strtolower(trim((string) $plugin_data['status']));
            if (in_array($status, ['inactive', 'deactivated', 'disabled'], true)) {
                return 'inactive';
            }
            if (in_array($status, ['active', 'activated', 'network-active', 'network_active'], true)) {
                return 'active';
            }
        }
    }

    return 'unknown';
}

function uptime_monitor_get_plugin_report_row_anchor($plugin_file) {
    $plugin_file = uptime_monitor_normalize_plugin_file_key($plugin_file);
    if ($plugin_file === '') {
        return 'uptime-monitor-plugin-report-top';
    }

    return 'uptime-monitor-plugin-report-item-' . substr(md5($plugin_file), 0, 12);
}

function uptime_monitor_get_plugin_report_redirect_url($notice_code = '', $notice_type = 'success', $anchor = '') {
    $url = admin_url('admin.php?page=uptime-monitor-plugin-report');
    $query_args = [];

    $notice_code = sanitize_key($notice_code);
    if ($notice_code !== '') {
        $query_args['uptime_monitor_notice'] = $notice_code;
    }

    $notice_type = ($notice_type === 'error') ? 'error' : 'success';
    if ($notice_type === 'error') {
        $query_args['uptime_monitor_notice_type'] = 'error';
    }

    if (!empty($query_args)) {
        $url = add_query_arg($query_args, $url);
    }

    $anchor = sanitize_html_class($anchor);
    if ($anchor !== '') {
        $url .= '#' . $anchor;
    }

    return $url;
}

function uptime_monitor_redirect_plugin_report($notice_code = '', $notice_type = 'success', $anchor = '') {
    $redirect_url = uptime_monitor_get_plugin_report_redirect_url($notice_code, $notice_type, $anchor);

    if (!headers_sent()) {
        wp_safe_redirect($redirect_url);
        exit;
    }

    echo '<script>window.location.href = ' . wp_json_encode($redirect_url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_attr($redirect_url) . '"></noscript>';
    exit;
}

function uptime_monitor_get_plugin_report_notice_message($notice_code) {
    switch (sanitize_key($notice_code)) {
        case 'notes-cleared':
            return 'Plugin notes cleared.';
        case 'notes-saved':
            return 'Plugin notes saved.';
        case 'notes-invalid':
            return 'Unable to save notes because the plugin record was invalid.';
        case 'plugin-hidden':
            return 'Plugin hidden from the main report.';
        case 'plugin-hide-invalid':
            return 'Unable to hide that plugin because the plugin record was invalid.';
        case 'plugin-restored':
            return 'Plugin restored to the main report.';
        case 'plugin-restore-invalid':
            return 'Unable to restore that plugin because it was not in the hidden list.';
        default:
            return '';
    }
}

function uptime_monitor_get_mainwp_sites_for_plugin_report($site_ids = null) {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'mainwp_wp';
    $sync_table  = $wpdb->prefix . 'mainwp_wp_sync';

    $sites_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sites_table));
    if ($sites_table_exists !== $sites_table) {
        return [];
    }

    $sync_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sync_table));
    $where_sql = '';
    if ($site_ids !== null) {
        $site_ids = array_values(array_unique(array_filter(array_map('absint', (array) $site_ids))));
        if (empty($site_ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($site_ids), '%d'));
        $where_sql = $wpdb->prepare(" WHERE wp.id IN ($placeholders)", $site_ids);
    }

    if ($sync_table_exists === $sync_table) {
        $query = "SELECT wp.id, wp.url, wp.name, wp.suspended, wp.plugins, wp.plugin_upgrades, wp.premium_upgrades, sync.dtsSync, sync.sync_errors
            FROM {$sites_table} AS wp
            LEFT JOIN {$sync_table} AS sync ON sync.wpid = wp.id{$where_sql}
            ORDER BY wp.url ASC";
    } else {
        $query = "SELECT wp.id, wp.url, wp.name, wp.suspended, wp.plugins, wp.plugin_upgrades, wp.premium_upgrades, 0 AS dtsSync, '' AS sync_errors
            FROM {$sites_table} AS wp{$where_sql}
            ORDER BY wp.url ASC";
    }

    $rows = $wpdb->get_results($query, ARRAY_A);
    if (!is_array($rows)) {
        return [];
    }

    $sites = [];
    foreach ($rows as $row) {
        $site_id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($site_id <= 0) {
            continue;
        }

        $sites[$site_id] = [
            'id'               => $site_id,
            'url'              => isset($row['url']) ? esc_url_raw($row['url']) : '',
            'name'             => isset($row['name']) ? sanitize_text_field($row['name']) : '',
            'suspended'        => !empty($row['suspended']),
            'plugins_raw'      => isset($row['plugins']) ? (string) $row['plugins'] : '',
            'plugin_upgrades'  => uptime_monitor_decode_json_array($row['plugin_upgrades'] ?? ''),
            'premium_upgrades' => uptime_monitor_decode_json_array($row['premium_upgrades'] ?? ''),
            'dts_sync'         => isset($row['dtsSync']) ? (int) $row['dtsSync'] : 0,
            'sync_errors'      => isset($row['sync_errors']) ? sanitize_text_field($row['sync_errors']) : '',
        ];
    }

    return $sites;
}

function uptime_monitor_get_wporg_plugin_lookup($plugin_slug) {
    $plugin_slug = sanitize_title($plugin_slug);
    if ($plugin_slug === '') {
        return [
            'slug'               => '',
            'status'             => 'error',
            'error'              => 'Missing plugin slug.',
            'name'               => '',
            'wporg_url'          => '',
            'closed_date'        => '',
            'closed_reason'      => '',
            'closed_reason_text' => '',
        ];
    }

    $cache_key = 'uptime_monitor_wporg_v2_' . md5($plugin_slug);
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['status'])) {
        return $cached;
    }

    $lookup = [
        'slug'               => $plugin_slug,
        'status'             => 'missing',
        'error'              => '',
        'name'               => '',
        'wporg_url'          => uptime_monitor_get_wporg_plugin_page_url($plugin_slug),
        'closed_date'        => '',
        'closed_reason'      => '',
        'closed_reason_text' => '',
    ];

    $api_url = add_query_arg(
        [
            'action'        => 'plugin_information',
            'request[slug]' => $plugin_slug,
        ],
        'https://api.wordpress.org/plugins/info/1.2/'
    );
    $response = wp_remote_get(
        $api_url,
        [
            'timeout' => 12,
        ]
    );

    if (is_wp_error($response)) {
        $lookup['status'] = 'error';
        $lookup['error'] = $response->get_error_message();
        set_transient($cache_key, $lookup, HOUR_IN_SECONDS);
        return $lookup;
    }

    $body = wp_remote_retrieve_body($response);
    if (!is_string($body) || trim($body) === '') {
        $lookup['status'] = 'error';
        $lookup['error'] = 'WordPress.org returned an empty plugin lookup response.';
        set_transient($cache_key, $lookup, HOUR_IN_SECONDS);
        return $lookup;
    }

    $result = json_decode($body, true);
    if (!is_array($result)) {
        $lookup['status'] = 'error';
        $lookup['error'] = 'WordPress.org returned an invalid plugin lookup response.';
        set_transient($cache_key, $lookup, HOUR_IN_SECONDS);
        return $lookup;
    }

    if (!empty($result['error'])) {
        $error_message = uptime_monitor_clean_plugin_text($result['error']);
        $error_code = sanitize_key($result['error']);
        if ($error_code === 'closed' || !empty($result['closed'])) {
            $lookup['status'] = 'closed';
            $lookup['name'] = isset($result['name']) ? uptime_monitor_clean_plugin_text($result['name']) : '';
            $lookup['closed_date'] = isset($result['closed_date']) ? uptime_monitor_clean_plugin_text($result['closed_date']) : '';
            $lookup['closed_reason'] = isset($result['reason']) ? sanitize_key($result['reason']) : '';
            $lookup['closed_reason_text'] = isset($result['reason_text']) ? uptime_monitor_clean_plugin_text($result['reason_text']) : '';
            set_transient($cache_key, $lookup, 7 * DAY_IN_SECONDS);
            return $lookup;
        }

        $not_found = stripos($error_message, 'not found') !== false || stripos($error_message, 'does not exist') !== false;
        if ($not_found) {
            set_transient($cache_key, $lookup, 7 * DAY_IN_SECONDS);
            return $lookup;
        }

        $lookup['status'] = 'error';
        $lookup['error'] = $error_message;
        set_transient($cache_key, $lookup, HOUR_IN_SECONDS);
        return $lookup;
    }

    if (!empty($result['slug']) || !empty($result['name'])) {
        $lookup['status'] = 'found';
        $lookup['name'] = isset($result['name']) ? uptime_monitor_clean_plugin_text($result['name']) : '';
        if (!empty($result['slug'])) {
            $lookup['slug'] = sanitize_title($result['slug']);
            $lookup['wporg_url'] = uptime_monitor_get_wporg_plugin_page_url($lookup['slug']);
        }
        set_transient($cache_key, $lookup, 7 * DAY_IN_SECONDS);
        return $lookup;
    }

    $lookup['status'] = 'error';
    $lookup['error'] = 'WordPress.org returned an unexpected plugin lookup response.';
    set_transient($cache_key, $lookup, HOUR_IN_SECONDS);
    return $lookup;
}

function uptime_monitor_get_plugin_directory_status($plugin_file, $plugin_update = [], $premium_update = []) {
    if (!empty($premium_update) && is_array($premium_update)) {
        return [
            'status'         => 'off-directory',
            'reason'         => 'premium_update',
            'candidate_slug' => uptime_monitor_get_plugin_candidate_slug($plugin_file),
        ];
    }

    $has_wporg_update_metadata = false;
    if (is_array($plugin_update) && !empty($plugin_update)) {
        $update_meta = isset($plugin_update['update']) && is_array($plugin_update['update']) ? $plugin_update['update'] : [];
        $update_id   = isset($update_meta['id']) && is_scalar($update_meta['id']) ? (string) $update_meta['id'] : '';
        $update_url  = isset($update_meta['url']) && is_scalar($update_meta['url']) ? (string) $update_meta['url'] : '';
        $is_premium  = !empty($update_meta['premium']);

        if ($is_premium) {
            return [
                'status'         => 'off-directory',
                'reason'         => 'premium_flag',
                'candidate_slug' => uptime_monitor_get_plugin_candidate_slug($plugin_file),
            ];
        }

        if (strpos($update_id, 'w.org/plugins/') === 0 || strpos($update_url, 'wordpress.org/plugins/') !== false) {
            $has_wporg_update_metadata = true;
        }
    }

    $candidate_slug = uptime_monitor_get_plugin_candidate_slug($plugin_file);
    if ($candidate_slug === '' && $has_wporg_update_metadata) {
        return [
            'status'         => 'wporg',
            'reason'         => 'update_metadata',
            'candidate_slug' => $candidate_slug,
        ];
    }

    $lookup = uptime_monitor_get_wporg_plugin_lookup($candidate_slug);

    if ($lookup['status'] === 'found') {
        return [
            'status'         => 'wporg',
            'reason'         => 'api_lookup',
            'candidate_slug' => $candidate_slug,
        ];
    }

    if ($lookup['status'] === 'missing') {
        return [
            'status'         => 'off-directory',
            'reason'         => 'lookup_missing',
            'candidate_slug' => $candidate_slug,
        ];
    }

    if ($lookup['status'] === 'closed') {
        return [
            'status'             => 'off-directory',
            'reason'             => 'lookup_closed',
            'candidate_slug'     => $candidate_slug,
            'wporg_name'         => isset($lookup['name']) ? (string) $lookup['name'] : '',
            'wporg_url'          => isset($lookup['wporg_url']) ? (string) $lookup['wporg_url'] : '',
            'closed_date'        => isset($lookup['closed_date']) ? (string) $lookup['closed_date'] : '',
            'closed_reason'      => isset($lookup['closed_reason']) ? (string) $lookup['closed_reason'] : '',
            'closed_reason_text' => isset($lookup['closed_reason_text']) ? (string) $lookup['closed_reason_text'] : '',
        ];
    }

    if ($has_wporg_update_metadata) {
        return [
            'status'         => 'wporg',
            'reason'         => 'update_metadata_fallback',
            'candidate_slug' => $candidate_slug,
        ];
    }

    return [
        'status'         => 'unknown',
        'reason'         => 'lookup_error',
        'candidate_slug' => $candidate_slug,
        'error'          => isset($lookup['error']) ? (string) $lookup['error'] : '',
    ];
}

function uptime_monitor_get_plugin_metadata_from_sources($plugin_data = [], $plugin_update = [], $premium_update = [], $live_headers = []) {
    $metadata = [
        'name'       => '',
        'version'    => '',
        'author'     => '',
        'author_uri' => '',
        'plugin_uri' => '',
        'mu'         => !empty($plugin_data['mu']) || !empty($live_headers['mu']),
    ];

    if (is_array($plugin_data)) {
        $metadata['name'] = isset($plugin_data['name']) ? uptime_monitor_clean_plugin_text($plugin_data['name']) : '';
        $metadata['version'] = isset($plugin_data['version']) ? uptime_monitor_clean_plugin_text($plugin_data['version']) : '';
    }

    $update_sources = [$premium_update, $plugin_update];
    foreach ($update_sources as $source) {
        if (!is_array($source) || empty($source)) {
            continue;
        }

        if ($metadata['name'] === '' && isset($source['Name'])) {
            $metadata['name'] = uptime_monitor_clean_plugin_text($source['Name']);
        }
        if ($metadata['version'] === '' && isset($source['Version'])) {
            $metadata['version'] = uptime_monitor_clean_plugin_text($source['Version']);
        }
        if ($metadata['author'] === '') {
            if (isset($source['Author'])) {
                $metadata['author'] = uptime_monitor_clean_plugin_text($source['Author']);
            } elseif (isset($source['AuthorName'])) {
                $metadata['author'] = uptime_monitor_clean_plugin_text($source['AuthorName']);
            }
        }
        if ($metadata['author_uri'] === '' && isset($source['AuthorURI'])) {
            $metadata['author_uri'] = uptime_monitor_clean_plugin_url($source['AuthorURI']);
        }
        if ($metadata['plugin_uri'] === '' && isset($source['PluginURI'])) {
            $metadata['plugin_uri'] = uptime_monitor_clean_plugin_url($source['PluginURI']);
        }
    }

    if (is_array($live_headers) && !empty($live_headers)) {
        if ($metadata['name'] === '' && isset($live_headers['name'])) {
            $metadata['name'] = uptime_monitor_clean_plugin_text($live_headers['name']);
        }
        if ($metadata['version'] === '' && isset($live_headers['version'])) {
            $metadata['version'] = uptime_monitor_clean_plugin_text($live_headers['version']);
        }
        if ($metadata['author'] === '' && isset($live_headers['author'])) {
            $metadata['author'] = uptime_monitor_clean_plugin_text($live_headers['author']);
        }
        if ($metadata['author_uri'] === '' && isset($live_headers['author_uri'])) {
            $metadata['author_uri'] = uptime_monitor_clean_plugin_url($live_headers['author_uri']);
        }
        if ($metadata['plugin_uri'] === '' && isset($live_headers['plugin_uri'])) {
            $metadata['plugin_uri'] = uptime_monitor_clean_plugin_url($live_headers['plugin_uri']);
        }
    }

    return $metadata;
}

function uptime_monitor_get_plugin_header_metadata_snippet() {
    return <<<'PHP'
if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$headers = array();
$plugins = get_plugins();
if (is_array($plugins)) {
    foreach ($plugins as $plugin_file => $plugin_data) {
        $headers[$plugin_file] = array(
            'name'       => isset($plugin_data['Name']) ? $plugin_data['Name'] : '',
            'version'    => isset($plugin_data['Version']) ? $plugin_data['Version'] : '',
            'author'     => isset($plugin_data['Author']) ? wp_strip_all_tags($plugin_data['Author']) : '',
            'author_uri' => isset($plugin_data['AuthorURI']) ? $plugin_data['AuthorURI'] : '',
            'plugin_uri' => isset($plugin_data['PluginURI']) ? $plugin_data['PluginURI'] : '',
            'active'     => function_exists('is_plugin_active') && is_plugin_active($plugin_file) ? 1 : 0,
            'network_active' => function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin_file) ? 1 : 0,
            'mu'         => 0,
        );
    }
}

if (function_exists('get_mu_plugins')) {
    $mu_plugins = get_mu_plugins();
    if (is_array($mu_plugins)) {
        foreach ($mu_plugins as $plugin_file => $plugin_data) {
            $headers[$plugin_file] = array(
                'name'       => isset($plugin_data['Name']) ? $plugin_data['Name'] : '',
                'version'    => isset($plugin_data['Version']) ? $plugin_data['Version'] : '',
                'author'     => isset($plugin_data['Author']) ? wp_strip_all_tags($plugin_data['Author']) : '',
                'author_uri' => isset($plugin_data['AuthorURI']) ? $plugin_data['AuthorURI'] : '',
                'plugin_uri' => isset($plugin_data['PluginURI']) ? $plugin_data['PluginURI'] : '',
                'active'     => 1,
                'network_active' => 0,
                'mu'         => 1,
            );
        }
    }
}

echo wp_json_encode($headers);
PHP;
}

function uptime_monitor_sanitize_live_plugin_headers($headers) {
    if (!is_array($headers)) {
        return [];
    }

    $sanitized = [];
    foreach ($headers as $plugin_file => $plugin_data) {
        if (!is_string($plugin_file) || !is_array($plugin_data)) {
            continue;
        }

        $plugin_file = ltrim($plugin_file, '/');
        if ($plugin_file === '') {
            continue;
        }

        $sanitized[$plugin_file] = [
            'name'       => isset($plugin_data['name']) ? uptime_monitor_clean_plugin_text($plugin_data['name']) : '',
            'version'    => isset($plugin_data['version']) ? uptime_monitor_clean_plugin_text($plugin_data['version']) : '',
            'author'     => isset($plugin_data['author']) ? uptime_monitor_clean_plugin_text($plugin_data['author']) : '',
            'author_uri' => isset($plugin_data['author_uri']) ? uptime_monitor_clean_plugin_url($plugin_data['author_uri']) : '',
            'plugin_uri' => isset($plugin_data['plugin_uri']) ? uptime_monitor_clean_plugin_url($plugin_data['plugin_uri']) : '',
            'active'     => array_key_exists('active', $plugin_data) ? uptime_monitor_parse_optional_boolean($plugin_data['active']) : null,
            'network_active' => array_key_exists('network_active', $plugin_data) ? uptime_monitor_parse_optional_boolean($plugin_data['network_active']) : null,
            'mu'         => !empty($plugin_data['mu']),
        ];
    }

    return $sanitized;
}

function uptime_monitor_fetch_live_plugin_headers($site_id) {
    if (!class_exists('\MainWP\Dashboard\MainWP_DB') || !class_exists('\MainWP\Dashboard\MainWP_Connect')) {
        return [
            'plugins' => [],
            'error'   => 'MainWP live plugin metadata is unavailable because MainWP dashboard classes were not loaded.',
        ];
    }

    $website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id((int) $site_id);
    if (!$website) {
        return [
            'plugins' => [],
            'error'   => 'Unable to load the MainWP child site record.',
        ];
    }

    try {
        $response = \MainWP\Dashboard\MainWP_Connect::fetch_url_authed(
            $website,
            'code_snippet',
            [
                'action' => 'run_snippet',
                'type'   => 'P',
                'slug'   => 'uptime-monitor-plugin-report',
                'code'   => uptime_monitor_get_plugin_header_metadata_snippet(),
            ],
            false,
            false,
            true
        );
    } catch (Throwable $throwable) {
        return [
            'plugins' => [],
            'error'   => uptime_monitor_clean_plugin_text($throwable->getMessage()),
        ];
    }

    if (!is_array($response)) {
        return [
            'plugins' => [],
            'error'   => 'MainWP returned an invalid response while fetching plugin metadata.',
        ];
    }

    if (!empty($response['error'])) {
        return [
            'plugins' => [],
            'error'   => uptime_monitor_clean_plugin_text($response['error']),
        ];
    }

    if (($response['status'] ?? '') !== 'SUCCESS') {
        return [
            'plugins' => [],
            'error'   => isset($response['result']) ? uptime_monitor_clean_plugin_text($response['result']) : 'The child site rejected the metadata request.',
        ];
    }

    $decoded = json_decode((string) ($response['result'] ?? ''), true);
    if (!is_array($decoded)) {
        return [
            'plugins' => [],
            'error'   => 'The child site returned plugin metadata in an unexpected format.',
        ];
    }

    return [
        'plugins' => uptime_monitor_sanitize_live_plugin_headers($decoded),
        'error'   => '',
    ];
}

function uptime_monitor_get_live_plugin_headers_for_sites($sites, $site_ids, $allow_remote_fetch = false) {
    $cache = get_option('uptime_monitor_plugin_header_cache', []);
    if (!is_array($cache)) {
        $cache = [];
    }

    $headers_by_site = [];
    $errors = [];
    $site_ids = array_values(array_unique(array_map('absint', (array) $site_ids)));

    foreach ($site_ids as $site_id) {
        if ($site_id <= 0 || !isset($sites[$site_id])) {
            continue;
        }

        $site_hash = md5((string) $sites[$site_id]['plugins_raw']);
        $cached_site = isset($cache[$site_id]) && is_array($cache[$site_id]) ? $cache[$site_id] : [];
        $cached_hash = isset($cached_site['hash']) ? (string) $cached_site['hash'] : '';
        $cached_plugins = isset($cached_site['plugins']) && is_array($cached_site['plugins']) ? $cached_site['plugins'] : [];

        if ($cached_hash === $site_hash && !empty($cached_plugins)) {
            $headers_by_site[$site_id] = $cached_plugins;
            continue;
        }

        if (!$allow_remote_fetch) {
            $headers_by_site[$site_id] = [];
            continue;
        }

        $live_result = uptime_monitor_fetch_live_plugin_headers($site_id);
        if ($live_result['error'] === '') {
            $headers_by_site[$site_id] = $live_result['plugins'];
            $cache[$site_id] = [
                'hash'       => $site_hash,
                'plugins'    => $live_result['plugins'],
                'fetched_at' => time(),
                'error'      => '',
            ];
            continue;
        }

        $errors[$site_id] = $live_result['error'];
        $headers_by_site[$site_id] = !empty($cached_plugins) ? $cached_plugins : [];
        $cache[$site_id] = [
            'hash'       => $site_hash,
            'plugins'    => $headers_by_site[$site_id],
            'fetched_at' => time(),
            'error'      => $live_result['error'],
        ];
    }

    foreach (array_keys($cache) as $cached_site_id) {
        if (!isset($sites[(int) $cached_site_id])) {
            unset($cache[$cached_site_id]);
        }
    }

    if (get_option('uptime_monitor_plugin_header_cache', null) === null) {
        add_option('uptime_monitor_plugin_header_cache', $cache, '', false);
    } else {
        update_option('uptime_monitor_plugin_header_cache', $cache);
    }

    return [
        'headers' => $headers_by_site,
        'errors'  => $errors,
        'cache'   => $cache,
    ];
}

function uptime_monitor_collect_unique_text($values, $candidate) {
    $candidate = trim((string) $candidate);
    if ($candidate === '') {
        return $values;
    }

    if (!is_array($values)) {
        $values = [];
    }

    if (!in_array($candidate, $values, true)) {
        $values[] = $candidate;
    }

    return $values;
}

function uptime_monitor_get_plugin_report_batch_size($allow_remote_fetch = false) {
    return $allow_remote_fetch ? 3 : 10;
}

function uptime_monitor_get_plugin_report_job_key($job_id) {
    return 'uptime_monitor_plugin_report_job_' . md5((string) $job_id);
}

function uptime_monitor_get_empty_off_directory_plugin_report($error = '') {
    return [
        'error'                    => (string) $error,
        'items'                    => [],
        'sites_total'              => 0,
        'matching_sites'           => 0,
        'plugins_total'            => 0,
        'lookup_failures'          => [],
        'metadata_errors'          => [],
        'metadata_cache'           => [],
        'used_remote_fetch'        => false,
        'stronganchor_lookup_error'=> '',
    ];
}

function uptime_monitor_add_off_directory_plugin_match(&$grouped, $match, $live_headers = []) {
    $plugin_identity = uptime_monitor_get_plugin_canonical_identity($match['plugin_file']);
    $plugin_file = isset($plugin_identity['plugin_file']) ? (string) $plugin_identity['plugin_file'] : (string) $match['plugin_file'];
    $raw_plugin_file = isset($plugin_identity['raw_plugin_file']) ? (string) $plugin_identity['raw_plugin_file'] : (string) $match['plugin_file'];
    $site_id = $match['site_id'];
    $metadata = uptime_monitor_get_plugin_metadata_from_sources(
        $match['plugin_data'],
        $match['plugin_update'],
        $match['premium_update'],
        $live_headers
    );
    $activation_state = uptime_monitor_get_plugin_activation_state($match['plugin_data'], $live_headers);

    if (!isset($grouped[$plugin_file])) {
        $grouped[$plugin_file] = [
            'plugin_file'         => $plugin_file,
            'plugin_files'        => [],
            'name'                => $metadata['name'] !== '' ? $metadata['name'] : $plugin_file,
            'versions'            => [],
            'sites'               => [],
            'author_links'        => [],
            'plugin_uris'         => [],
            'reasons'             => [],
            'candidate'           => isset($plugin_identity['canonical_slug']) ? (string) $plugin_identity['canonical_slug'] : '',
            'has_mu'              => !empty($metadata['mu']),
            'wporg_closed'        => false,
            'wporg_closed_date'   => '',
            'wporg_closed_reason' => '',
            'wporg_closed_link'   => '',
            'stronganchor_github_match' => false,
            'stronganchor_github_repo'  => '',
            'stronganchor_github_url'   => '',
        ];
    }

    if ($grouped[$plugin_file]['name'] === $plugin_file && $metadata['name'] !== '') {
        $grouped[$plugin_file]['name'] = $metadata['name'];
    }
    if ($grouped[$plugin_file]['name'] === $plugin_file && !empty($match['directory_status']['wporg_name'])) {
        $grouped[$plugin_file]['name'] = uptime_monitor_clean_plugin_text($match['directory_status']['wporg_name']);
    }
    if ($grouped[$plugin_file]['candidate'] === '' && !empty($plugin_identity['canonical_slug'])) {
        $grouped[$plugin_file]['candidate'] = (string) $plugin_identity['canonical_slug'];
    }
    $grouped[$plugin_file]['plugin_files'] = uptime_monitor_collect_unique_text($grouped[$plugin_file]['plugin_files'], $raw_plugin_file);

    $version = $metadata['version'] !== '' ? $metadata['version'] : 'Unknown';
    if (!isset($grouped[$plugin_file]['versions'][$version])) {
        $grouped[$plugin_file]['versions'][$version] = [];
    }
    $grouped[$plugin_file]['versions'][$version][$site_id] = true;

    if (!isset($grouped[$plugin_file]['sites'][$site_id])) {
        $grouped[$plugin_file]['sites'][$site_id] = [
            'name'             => $match['site_name'] !== '' ? $match['site_name'] : $match['site_url'],
            'url'              => $match['site_url'],
            'version'          => $version,
            'activation_state' => $activation_state,
            'suspended'        => !empty($match['site_suspended']),
            'sync_error'       => $match['site_sync_error'],
            'plugin_files'     => [],
        ];
    }

    if ($grouped[$plugin_file]['sites'][$site_id]['version'] === 'Unknown' && $version !== 'Unknown') {
        $grouped[$plugin_file]['sites'][$site_id]['version'] = $version;
    }

    if ($grouped[$plugin_file]['sites'][$site_id]['activation_state'] === 'unknown' && $activation_state !== 'unknown') {
        $grouped[$plugin_file]['sites'][$site_id]['activation_state'] = $activation_state;
    }

    $grouped[$plugin_file]['sites'][$site_id]['plugin_files'] = uptime_monitor_collect_unique_text(
        $grouped[$plugin_file]['sites'][$site_id]['plugin_files'],
        $raw_plugin_file
    );

    if ($metadata['author'] !== '') {
        if (!isset($grouped[$plugin_file]['author_links'][$metadata['author']])) {
            $grouped[$plugin_file]['author_links'][$metadata['author']] = $metadata['author_uri'];
        } elseif ($grouped[$plugin_file]['author_links'][$metadata['author']] === '' && $metadata['author_uri'] !== '') {
            $grouped[$plugin_file]['author_links'][$metadata['author']] = $metadata['author_uri'];
        }
    }

    $grouped[$plugin_file]['plugin_uris'] = uptime_monitor_collect_unique_text($grouped[$plugin_file]['plugin_uris'], $metadata['plugin_uri']);
    $grouped[$plugin_file]['reasons'] = uptime_monitor_collect_unique_text($grouped[$plugin_file]['reasons'], $match['directory_status']['reason'] ?? '');
    $grouped[$plugin_file]['has_mu'] = $grouped[$plugin_file]['has_mu'] || !empty($metadata['mu']);

    if (($match['directory_status']['reason'] ?? '') === 'lookup_closed') {
        $grouped[$plugin_file]['wporg_closed'] = true;
        if ($grouped[$plugin_file]['wporg_closed_date'] === '' && !empty($match['directory_status']['closed_date'])) {
            $grouped[$plugin_file]['wporg_closed_date'] = (string) $match['directory_status']['closed_date'];
        }
        if ($grouped[$plugin_file]['wporg_closed_reason'] === '' && !empty($match['directory_status']['closed_reason_text'])) {
            $grouped[$plugin_file]['wporg_closed_reason'] = (string) $match['directory_status']['closed_reason_text'];
        }
        if ($grouped[$plugin_file]['wporg_closed_link'] === '' && !empty($match['directory_status']['wporg_url'])) {
            $grouped[$plugin_file]['wporg_closed_link'] = (string) $match['directory_status']['wporg_url'];
        }
    }
}

function uptime_monitor_initialize_off_directory_plugin_report_state($site_ids, $allow_remote_fetch = false) {
    $site_ids = array_values(array_unique(array_filter(array_map('absint', (array) $site_ids))));

    return [
        'site_ids'          => $site_ids,
        'offset'            => 0,
        'allow_remote_fetch'=> !empty($allow_remote_fetch),
        'grouped'           => [],
        'lookup_failures'   => [],
        'metadata_errors'   => [],
        'matching_site_ids' => [],
        'sites_total'       => count($site_ids),
    ];
}

function uptime_monitor_process_off_directory_plugin_report_site_batch(&$state, $batch_site_ids) {
    $batch_site_ids = array_values(array_unique(array_filter(array_map('absint', (array) $batch_site_ids))));
    if (empty($batch_site_ids)) {
        return;
    }

    $sites = uptime_monitor_get_mainwp_sites_for_plugin_report($batch_site_ids);
    $raw_matches = [];
    $matching_site_ids = [];

    foreach ($batch_site_ids as $site_id) {
        if (!isset($sites[$site_id])) {
            continue;
        }

        $site = $sites[$site_id];
        $plugins = uptime_monitor_decode_json_array($site['plugins_raw']);
        if (empty($plugins)) {
            continue;
        }

        foreach ($plugins as $key => $plugin_data) {
            if (!is_array($plugin_data)) {
                continue;
            }

            $plugin_file = uptime_monitor_get_plugin_file_from_record($key, $plugin_data);
            if ($plugin_file === '') {
                continue;
            }

            $plugin_update = isset($site['plugin_upgrades'][$plugin_file]) && is_array($site['plugin_upgrades'][$plugin_file])
                ? $site['plugin_upgrades'][$plugin_file]
                : [];
            $premium_update = isset($site['premium_upgrades'][$plugin_file]) && is_array($site['premium_upgrades'][$plugin_file])
                ? $site['premium_upgrades'][$plugin_file]
                : [];

            $directory_status = uptime_monitor_get_plugin_directory_status($plugin_file, $plugin_update, $premium_update);
            if ($directory_status['status'] === 'wporg') {
                continue;
            }

            if ($directory_status['status'] === 'unknown') {
                $candidate_slug = $directory_status['candidate_slug'] ?? $plugin_file;
                $state['lookup_failures'][$candidate_slug] = $directory_status['error'] ?? 'Unable to verify the plugin against WordPress.org.';
                continue;
            }

            $matching_site_ids[$site_id] = true;
            $state['matching_site_ids'][$site_id] = true;
            $raw_matches[] = [
                'site_id'          => $site_id,
                'site_url'         => $site['url'],
                'site_name'        => $site['name'],
                'site_suspended'   => !empty($site['suspended']),
                'site_sync_error'  => isset($site['sync_errors']) ? (string) $site['sync_errors'] : '',
                'plugin_file'      => $plugin_file,
                'plugin_data'      => $plugin_data,
                'plugin_update'    => $plugin_update,
                'premium_update'   => $premium_update,
                'directory_status' => $directory_status,
            ];
        }
    }

    $headers_result = uptime_monitor_get_live_plugin_headers_for_sites(
        $sites,
        array_keys($matching_site_ids),
        !empty($state['allow_remote_fetch'])
    );
    $headers_by_site = isset($headers_result['headers']) && is_array($headers_result['headers']) ? $headers_result['headers'] : [];
    $metadata_errors = isset($headers_result['errors']) && is_array($headers_result['errors']) ? $headers_result['errors'] : [];

    foreach ($metadata_errors as $site_id => $error_message) {
        $state['metadata_errors'][(int) $site_id] = (string) $error_message;
    }

    foreach ($raw_matches as $match) {
        $live_headers = isset($headers_by_site[$match['site_id']][$match['plugin_file']]) ? $headers_by_site[$match['site_id']][$match['plugin_file']] : [];
        uptime_monitor_add_off_directory_plugin_match($state['grouped'], $match, $live_headers);
    }

    $state['offset'] += count($batch_site_ids);
}

function uptime_monitor_finalize_off_directory_plugin_report_state($state) {
    $grouped = isset($state['grouped']) && is_array($state['grouped']) ? $state['grouped'] : [];
    $stronganchor_lookup_error = uptime_monitor_apply_stronganchor_github_matches($grouped);

    foreach ($grouped as &$group) {
        ksort($group['versions'], SORT_NATURAL);
        if (!empty($group['plugin_files']) && is_array($group['plugin_files'])) {
            natcasesort($group['plugin_files']);
            $group['plugin_files'] = array_values($group['plugin_files']);
        }
        uasort($group['sites'], function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        foreach ($group['sites'] as &$site) {
            if (!empty($site['plugin_files']) && is_array($site['plugin_files'])) {
                natcasesort($site['plugin_files']);
                $site['plugin_files'] = array_values($site['plugin_files']);
            }
        }
        unset($site);
        $group['site_count'] = count($group['sites']);
        $group['version_count'] = count($group['versions']);
    }
    unset($group);

    uasort($grouped, function($a, $b) {
        $name_compare = strcasecmp($a['name'], $b['name']);
        if ($name_compare !== 0) {
            return $name_compare;
        }

        return strcasecmp($a['plugin_file'], $b['plugin_file']);
    });

    $items = array_values($grouped);
    uptime_monitor_cleanup_stale_hidden_plugin_report_items($items);

    return [
        'error'                     => '',
        'items'                     => $items,
        'sites_total'               => isset($state['sites_total']) ? (int) $state['sites_total'] : 0,
        'matching_sites'            => !empty($state['matching_site_ids']) && is_array($state['matching_site_ids']) ? count($state['matching_site_ids']) : 0,
        'plugins_total'             => count($items),
        'lookup_failures'           => isset($state['lookup_failures']) && is_array($state['lookup_failures']) ? $state['lookup_failures'] : [],
        'metadata_errors'           => isset($state['metadata_errors']) && is_array($state['metadata_errors']) ? $state['metadata_errors'] : [],
        'metadata_cache'            => get_option('uptime_monitor_plugin_header_cache', []),
        'used_remote_fetch'         => !empty($state['allow_remote_fetch']),
        'stronganchor_lookup_error' => $stronganchor_lookup_error,
    ];
}

function uptime_monitor_build_off_directory_plugin_report($allow_remote_fetch = false) {
    $sites = uptime_monitor_get_mainwp_sites_for_plugin_report();
    if (empty($sites)) {
        return uptime_monitor_get_empty_off_directory_plugin_report('No MainWP child-site records were found. This report only works on a MainWP dashboard site.');
    }

    $state = uptime_monitor_initialize_off_directory_plugin_report_state(array_keys($sites), $allow_remote_fetch);
    while ($state['offset'] < $state['sites_total']) {
        $batch_site_ids = array_slice($state['site_ids'], $state['offset'], uptime_monitor_get_plugin_report_batch_size($allow_remote_fetch));
        uptime_monitor_process_off_directory_plugin_report_site_batch($state, $batch_site_ids);
    }

    return uptime_monitor_finalize_off_directory_plugin_report_state($state);
}

function uptime_monitor_get_off_directory_plugin_report_partitions($report) {
    $hidden_plugins = uptime_monitor_get_hidden_plugin_report_items();
    $visible_items = [];
    $hidden_report_items = [];
    $active_hidden_keys = [];

    if (!empty($report['items']) && is_array($report['items'])) {
        foreach ($report['items'] as $item) {
            $plugin_file = uptime_monitor_normalize_plugin_file_key($item['plugin_file'] ?? '');
            if ($plugin_file !== '' && isset($hidden_plugins[$plugin_file])) {
                $item['hidden_at'] = $hidden_plugins[$plugin_file]['hidden_at'];
                $hidden_report_items[] = $item;
                $active_hidden_keys[$plugin_file] = true;
                continue;
            }

            $visible_items[] = $item;
        }
    }

    $stale_hidden_items = [];
    foreach ($hidden_plugins as $plugin_file => $hidden_item) {
        if (!isset($active_hidden_keys[$plugin_file])) {
            $stale_hidden_items[] = $hidden_item;
        }
    }

    $visible_site_ids = [];
    foreach ($visible_items as $item) {
        if (empty($item['sites']) || !is_array($item['sites'])) {
            continue;
        }

        foreach ($item['sites'] as $site_id => $site) {
            $site_id = (int) $site_id;
            if ($site_id > 0) {
                $visible_site_ids[$site_id] = true;
            }
        }
    }

    return [
        'hidden_plugins'     => $hidden_plugins,
        'visible_items'      => $visible_items,
        'hidden_report_items'=> $hidden_report_items,
        'stale_hidden_items' => $stale_hidden_items,
        'visible_site_ids'   => $visible_site_ids,
        'hidden_total'       => count($hidden_plugins),
        'date_time_format'   => trim(get_option('date_format') . ' ' . get_option('time_format')),
    ];
}

function uptime_monitor_cleanup_stale_hidden_plugin_report_items($report_items) {
    $hidden_plugins = uptime_monitor_get_hidden_plugin_report_items();
    if (empty($hidden_plugins)) {
        return 0;
    }

    $active_plugin_files = [];
    if (is_array($report_items)) {
        foreach ($report_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $plugin_file = uptime_monitor_normalize_plugin_file_key($item['plugin_file'] ?? '');
            if ($plugin_file !== '') {
                $active_plugin_files[$plugin_file] = true;
            }
        }
    }

    $pruned_hidden_plugins = [];
    $removed_count = 0;
    foreach ($hidden_plugins as $plugin_file => $hidden_item) {
        if (isset($active_plugin_files[$plugin_file])) {
            $pruned_hidden_plugins[$plugin_file] = $hidden_item;
            continue;
        }

        $removed_count++;
    }

    if ($removed_count > 0) {
        uptime_monitor_save_hidden_plugin_report_items($pruned_hidden_plugins);
    }

    return $removed_count;
}

function uptime_monitor_render_off_directory_plugin_report_site_list_items($sites) {
    if (!is_array($sites)) {
        return;
    }

    foreach ($sites as $site) {
        if (!is_array($site)) {
            continue;
        }

        $site_label = isset($site['name']) && $site['name'] !== '' ? (string) $site['name'] : ((string) ($site['url'] ?? 'Unknown site'));

        echo '<li>';
        if (!empty($site['url'])) {
            echo '<a href="' . esc_url($site['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($site_label) . '</a>';
        } else {
            echo esc_html($site_label);
        }
        if (!empty($site['version'])) {
            echo ' <span class="uptime-monitor-inline-note">(' . esc_html($site['version']) . ')</span>';
        }
        if (($site['activation_state'] ?? '') === 'inactive') {
            echo ' <span class="uptime-monitor-report-badge is-neutral">Inactive</span>';
        }
        if (!empty($site['suspended'])) {
            echo ' <span class="uptime-monitor-report-badge is-warning">Suspended</span>';
        }
        if (!empty($site['sync_error'])) {
            echo '<div class="uptime-monitor-inline-note">Sync warning: ' . esc_html($site['sync_error']) . '</div>';
        }
        echo '</li>';
    }
}

function uptime_monitor_get_off_directory_plugin_report_site_list_html($sites, $collapse_threshold = 3, $preview_count = 2) {
    if (!is_array($sites) || empty($sites)) {
        return '<span class="uptime-monitor-inline-note">Not available</span>';
    }

    $sites = array_values($sites);
    $collapse_threshold = max(1, (int) $collapse_threshold);
    $preview_count = max(1, min((int) $preview_count, $collapse_threshold));

    $preview_sites = $sites;
    $remaining_sites = [];

    if (count($sites) > $collapse_threshold) {
        $preview_sites = array_slice($sites, 0, $preview_count);
        $remaining_sites = array_slice($sites, $preview_count);
    }

    ob_start();

    echo '<ul class="uptime-monitor-report-list">';
    uptime_monitor_render_off_directory_plugin_report_site_list_items($preview_sites);
    echo '</ul>';

    if (!empty($remaining_sites)) {
        $remaining_count = count($remaining_sites);
        echo '<details class="uptime-monitor-report-list-toggle">';
        echo '<summary>+' . esc_html(number_format_i18n($remaining_count)) . ' more site' . ($remaining_count === 1 ? '' : 's') . '</summary>';
        echo '<ul class="uptime-monitor-report-list">';
        uptime_monitor_render_off_directory_plugin_report_site_list_items($remaining_sites);
        echo '</ul>';
        echo '</details>';
    }

    return (string) ob_get_clean();
}

function uptime_monitor_get_off_directory_plugin_report_variant_files($plugin_files, $canonical_plugin_file = '') {
    if (!is_array($plugin_files)) {
        return [];
    }

    $canonical_plugin_file = ltrim(trim((string) $canonical_plugin_file), '/');
    $variant_files = [];

    foreach ($plugin_files as $plugin_file) {
        if (!is_scalar($plugin_file)) {
            continue;
        }

        $plugin_file = ltrim(trim((string) $plugin_file), '/');
        if ($plugin_file === '' || $plugin_file === $canonical_plugin_file) {
            continue;
        }

        if (!in_array($plugin_file, $variant_files, true)) {
            $variant_files[] = $plugin_file;
        }
    }

    return $variant_files;
}

function uptime_monitor_get_off_directory_plugin_report_markdown($report) {
    $partitions = uptime_monitor_get_off_directory_plugin_report_partitions($report);
    $visible_items = $partitions['visible_items'];
    $hidden_report_items = $partitions['hidden_report_items'];
    $stale_hidden_items = $partitions['stale_hidden_items'];
    $visible_site_ids = $partitions['visible_site_ids'];
    $hidden_total = $partitions['hidden_total'];
    $date_time_format = $partitions['date_time_format'];
    $notes = uptime_monitor_get_plugin_report_notes();

    $lines = [];
    $lines[] = '# Off-Directory Plugin Report';
    $lines[] = '';

    if (!empty($report['error'])) {
        $lines[] = '- Error: ' . $report['error'];
        return implode("\n", $lines);
    }

    $lines[] = '- Visible plugins: ' . count($visible_items);
    $lines[] = '- Hidden by admin: ' . $hidden_total;
    $lines[] = '- Child sites with visible matches: ' . count($visible_site_ids);
    $lines[] = '- Total child sites checked: ' . (int) ($report['sites_total'] ?? 0);

    $warning_lines = [];
    if (!empty($report['lookup_failures'])) {
        $warning_lines[] = 'WordPress.org verification failures: ' . uptime_monitor_format_account_name_list(array_keys($report['lookup_failures']), 12);
    }
    if (!empty($report['metadata_errors'])) {
        $warning_lines[] = 'Child-site metadata refresh errors: ' . count($report['metadata_errors']);
    }
    if (!empty($report['stronganchor_lookup_error'])) {
        $warning_lines[] = 'Strong Anchor GitHub lookup warning: ' . uptime_monitor_clean_plugin_text($report['stronganchor_lookup_error']);
    }

    if (!empty($warning_lines)) {
        $lines[] = '';
        $lines[] = '## Warnings';
        foreach ($warning_lines as $warning_line) {
            $lines[] = '- ' . $warning_line;
        }
    }

    $lines[] = '';
    $lines[] = '## Visible Plugins';

    if (empty($visible_items)) {
        $lines[] = '- None';
    } else {
        foreach ($visible_items as $item) {
            $plugin_file = isset($item['plugin_file']) ? (string) $item['plugin_file'] : '';
            $note_text = isset($notes[$plugin_file]) ? preg_replace('/\s*\R\s*/', ' | ', $notes[$plugin_file]) : '';
            $variant_files = uptime_monitor_get_off_directory_plugin_report_variant_files($item['plugin_files'] ?? [], $plugin_file);

            $lines[] = '';
            $lines[] = '### ' . (isset($item['name']) ? (string) $item['name'] : $plugin_file);
            $lines[] = '- Plugin file: `' . $plugin_file . '`';

            if (!empty($item['candidate'])) {
                $lines[] = '- Canonical slug: `' . $item['candidate'] . '`';
            }
            if (!empty($variant_files)) {
                $lines[] = '- Detected as: ' . implode(', ', array_map(function($variant_file) {
                    return '`' . $variant_file . '`';
                }, $variant_files));
            }
            if ($note_text !== '') {
                $lines[] = '- Notes: ' . $note_text;
            }
            if (!empty($item['wporg_closed'])) {
                $closed_note = 'Closed on WordPress.org';
                if (!empty($item['wporg_closed_date'])) {
                    $closed_note .= ' on ' . uptime_monitor_format_wporg_closed_date($item['wporg_closed_date']);
                }
                if (!empty($item['wporg_closed_reason'])) {
                    $closed_note .= ' (' . $item['wporg_closed_reason'] . ')';
                }
                if (!empty($item['wporg_closed_link'])) {
                    $closed_note .= ' - ' . $item['wporg_closed_link'];
                }
                $lines[] = '- Directory status: ' . $closed_note;
            }
            if (!empty($item['has_mu'])) {
                $lines[] = '- Must-use on at least one site: Yes';
            }

            if (!empty($item['author_links']) && is_array($item['author_links'])) {
                $authors = [];
                foreach ($item['author_links'] as $author => $author_uri) {
                    $authors[] = $author_uri !== '' ? ($author . ' (' . $author_uri . ')') : $author;
                }
                $lines[] = '- Author: ' . implode('; ', $authors);
            } elseif (!empty($item['stronganchor_github_match'])) {
                $lines[] = '- Author fallback: Strong Anchor Tech';
                $github_label = !empty($item['stronganchor_github_repo']) ? (string) $item['stronganchor_github_repo'] : 'Strong Anchor repository match';
                if (!empty($item['stronganchor_github_url'])) {
                    $github_label .= ' (' . $item['stronganchor_github_url'] . ')';
                }
                $lines[] = '- GitHub match: ' . $github_label;
            } else {
                $lines[] = '- Author: Not available';
            }

            if (!empty($item['plugin_uris']) && is_array($item['plugin_uris'])) {
                $lines[] = '- Plugin URI: ' . implode('; ', $item['plugin_uris']);
            } else {
                $lines[] = '- Plugin URI: Not available';
            }

            if (!empty($item['sites']) && is_array($item['sites'])) {
                $lines[] = '- Installed on:';
                foreach ($item['sites'] as $site) {
                    $site_label = !empty($site['name']) ? (string) $site['name'] : ((string) ($site['url'] ?? 'Unknown site'));
                    $site_line = $site_label;
                    if (!empty($site['url'])) {
                        $site_line .= ' (' . $site['url'] . ')';
                    }
                    if (!empty($site['version'])) {
                        $site_line .= ' - version ' . $site['version'];
                    }
                    if (($site['activation_state'] ?? '') === 'inactive') {
                        $site_line .= ' - deactivated';
                    }
                    if (!empty($site['suspended'])) {
                        $site_line .= ' - suspended';
                    }
                    if (!empty($site['sync_error'])) {
                        $site_line .= ' - sync warning: ' . $site['sync_error'];
                    }
                    $lines[] = '  - ' . $site_line;
                }
            }
        }
    }

    $lines[] = '';
    $lines[] = '## Hidden Plugins';

    if (empty($hidden_report_items) && empty($stale_hidden_items)) {
        $lines[] = '- None';
    } else {
        foreach ($hidden_report_items as $item) {
            $plugin_file = isset($item['plugin_file']) ? (string) $item['plugin_file'] : '';
            $note_text = isset($notes[$plugin_file]) ? preg_replace('/\s*\R\s*/', ' | ', $notes[$plugin_file]) : '';
            $hidden_at = isset($item['hidden_at']) ? absint($item['hidden_at']) : 0;
            $hidden_at_text = $hidden_at > 0 ? date_i18n($date_time_format, $hidden_at) : 'Unknown';
            $variant_files = uptime_monitor_get_off_directory_plugin_report_variant_files($item['plugin_files'] ?? [], $plugin_file);

            $lines[] = '';
            $lines[] = '### ' . (isset($item['name']) ? (string) $item['name'] : $plugin_file);
            $lines[] = '- Plugin file: `' . $plugin_file . '`';
            if (!empty($item['candidate'])) {
                $lines[] = '- Canonical slug: `' . $item['candidate'] . '`';
            }
            if (!empty($variant_files)) {
                $lines[] = '- Detected as: ' . implode(', ', array_map(function($variant_file) {
                    return '`' . $variant_file . '`';
                }, $variant_files));
            }
            $lines[] = '- Status: Hidden on ' . $hidden_at_text . '; ' . count($item['sites']) . ' matching site' . (count($item['sites']) === 1 ? '' : 's') . ' currently hidden';
            if ($note_text !== '') {
                $lines[] = '- Notes: ' . $note_text;
            }
        }

        foreach ($stale_hidden_items as $hidden_item) {
            $plugin_file = isset($hidden_item['plugin_file']) ? (string) $hidden_item['plugin_file'] : '';
            $note_text = isset($notes[$plugin_file]) ? preg_replace('/\s*\R\s*/', ' | ', $notes[$plugin_file]) : '';
            $hidden_at = isset($hidden_item['hidden_at']) ? absint($hidden_item['hidden_at']) : 0;
            $hidden_at_text = $hidden_at > 0 ? date_i18n($date_time_format, $hidden_at) : 'Unknown';
            $plugin_label = !empty($hidden_item['name']) ? (string) $hidden_item['name'] : $plugin_file;
            $variant_files = uptime_monitor_get_off_directory_plugin_report_variant_files($hidden_item['plugin_files'] ?? [], $plugin_file);

            $lines[] = '';
            $lines[] = '### ' . $plugin_label;
            $lines[] = '- Plugin file: `' . $plugin_file . '`';
            if (!empty($hidden_item['candidate'])) {
                $lines[] = '- Canonical slug: `' . $hidden_item['candidate'] . '`';
            }
            if (!empty($variant_files)) {
                $lines[] = '- Detected as: ' . implode(', ', array_map(function($variant_file) {
                    return '`' . $variant_file . '`';
                }, $variant_files));
            }
            $lines[] = '- Status: Hidden on ' . $hidden_at_text . '; not currently detected in MainWP plugin inventories';
            if ($note_text !== '') {
                $lines[] = '- Notes: ' . $note_text;
            }
        }
    }

    return implode("\n", $lines);
}

function uptime_monitor_render_off_directory_plugin_report_results($report) {
    $partitions = uptime_monitor_get_off_directory_plugin_report_partitions($report);
    $hidden_plugins = $partitions['hidden_plugins'];
    $visible_items = $partitions['visible_items'];
    $hidden_report_items = $partitions['hidden_report_items'];
    $stale_hidden_items = $partitions['stale_hidden_items'];
    $visible_site_ids = $partitions['visible_site_ids'];
    $hidden_total = $partitions['hidden_total'];
    $date_time_format = $partitions['date_time_format'];
    $notes = uptime_monitor_get_plugin_report_notes();
    $report_action_url = admin_url('admin-post.php');

    if (!empty($report['error'])) {
        echo '<p>' . esc_html($report['error']) . '</p>';
        return;
    }

    echo '<div class="uptime-monitor-report-stats">';
    echo '<div class="uptime-monitor-report-stat"><span class="uptime-monitor-report-stat-value">' . esc_html(number_format_i18n(count($visible_items))) . '</span><span class="uptime-monitor-report-stat-label">Visible plugins</span></div>';
    echo '<div class="uptime-monitor-report-stat"><span class="uptime-monitor-report-stat-value">' . esc_html(number_format_i18n($hidden_total)) . '</span><span class="uptime-monitor-report-stat-label">Hidden by admin</span></div>';
    echo '<div class="uptime-monitor-report-stat"><span class="uptime-monitor-report-stat-value">' . esc_html(number_format_i18n(count($visible_site_ids))) . '</span><span class="uptime-monitor-report-stat-label">Child sites with visible matches</span></div>';
    echo '<div class="uptime-monitor-report-stat"><span class="uptime-monitor-report-stat-value">' . esc_html(number_format_i18n($report['sites_total'])) . '</span><span class="uptime-monitor-report-stat-label">Total child sites checked</span></div>';
    echo '</div>';

    if (!empty($report['lookup_failures'])) {
        $failed_slugs = array_keys($report['lookup_failures']);
        $failed_summary = uptime_monitor_format_account_name_list($failed_slugs, 6);
        echo '<p class="uptime-monitor-plugin-report-warning">Some plugins could not be verified against WordPress.org and were left out of the report: ' . esc_html($failed_summary) . '.</p>';
    }

    if (!empty($report['metadata_errors'])) {
        $failed_sites = [];
        foreach ($report['metadata_errors'] as $site_id => $message) {
            foreach ($report['items'] as $item) {
                if (isset($item['sites'][$site_id])) {
                    $failed_sites[] = $item['sites'][$site_id]['name'];
                    break;
                }
            }
        }
        $failed_summary = uptime_monitor_format_account_name_list($failed_sites, 5);
        echo '<p class="uptime-monitor-plugin-report-warning">Some child sites did not return extended metadata. Existing cached values were used when available: ' . esc_html($failed_summary) . '.</p>';
    }

    if (!empty($report['stronganchor_lookup_error'])) {
        echo '<p class="uptime-monitor-plugin-report-warning">Strong Anchor GitHub matching is currently unavailable: ' . esc_html($report['stronganchor_lookup_error']) . '.</p>';
    }

    if (!empty($visible_items)) {
        echo '<table class="widefat striped uptime-monitor-plugin-report-table">';
        echo '<thead><tr><th class="uptime-monitor-report-plugin-cell">Plugin</th><th class="uptime-monitor-report-details-cell">Details</th><th class="uptime-monitor-report-notes-actions-cell">Notes / Actions</th></tr></thead>';
        echo '<tbody>';

        foreach ($visible_items as $item) {
            $plugin_note = isset($notes[$item['plugin_file']]) ? (string) $notes[$item['plugin_file']] : '';
            $row_anchor = uptime_monitor_get_plugin_report_row_anchor($item['plugin_file']);
            $variant_files = uptime_monitor_get_off_directory_plugin_report_variant_files($item['plugin_files'] ?? [], $item['plugin_file']);
            echo '<tr id="' . esc_attr($row_anchor) . '">';

            echo '<td class="uptime-monitor-report-plugin-cell">';
            echo '<strong>' . esc_html($item['name']) . '</strong>';
            echo '<div class="uptime-monitor-inline-note"><code>' . esc_html($item['plugin_file']) . '</code></div>';
            if (!empty($item['candidate'])) {
                echo '<div class="uptime-monitor-inline-note">Canonical slug: <code>' . esc_html($item['candidate']) . '</code></div>';
            }
            if (!empty($variant_files)) {
                echo '<div class="uptime-monitor-inline-note">Detected as: ';
                foreach ($variant_files as $variant_index => $variant_file) {
                    if ($variant_index > 0) {
                        echo ', ';
                    }
                    echo '<code>' . esc_html($variant_file) . '</code>';
                }
                echo '</div>';
            }
            if (!empty($item['wporg_closed'])) {
                $closed_note = 'Closed on WordPress.org';
                if (!empty($item['wporg_closed_date'])) {
                    $closed_note .= ' on ' . uptime_monitor_format_wporg_closed_date($item['wporg_closed_date']);
                }
                if (!empty($item['wporg_closed_reason'])) {
                    $closed_note .= ' (' . $item['wporg_closed_reason'] . ')';
                }
                echo '<div class="uptime-monitor-inline-note"><span class="uptime-monitor-report-badge is-warning">Closed</span> ' . esc_html($closed_note);
                if (!empty($item['wporg_closed_link'])) {
                    echo ' <a href="' . esc_url($item['wporg_closed_link']) . '" target="_blank" rel="noopener noreferrer">View WordPress.org page</a>';
                }
                echo '</div>';
            }
            if (!empty($item['has_mu'])) {
                echo '<div><span class="uptime-monitor-report-badge">Must-use on at least one site</span></div>';
            }
            echo '</td>';

            echo '<td class="uptime-monitor-report-details-cell">';
            echo '<div class="uptime-monitor-report-detail-stack">';

            echo '<div class="uptime-monitor-report-detail-block">';
            echo '<div class="uptime-monitor-report-detail-label">Installed On</div>';
            echo uptime_monitor_get_off_directory_plugin_report_site_list_html($item['sites']);
            echo '</div>';

            echo '<div class="uptime-monitor-report-detail-block">';
            echo '<div class="uptime-monitor-report-detail-label">Author</div>';
            if (!empty($item['author_links'])) {
                $author_index = 0;
                foreach ($item['author_links'] as $author => $author_uri) {
                    if ($author_index > 0) {
                        echo '<br>';
                    }

                    if ($author_uri !== '') {
                        echo '<a href="' . esc_url($author_uri) . '" target="_blank" rel="noopener noreferrer">' . esc_html($author) . '</a>';
                    } else {
                        echo esc_html($author);
                    }

                    $author_index++;
                }
            } elseif (!empty($item['stronganchor_github_match'])) {
                echo 'Strong Anchor Tech';
                echo '<div class="uptime-monitor-inline-note">Matched from the Strong Anchor GitHub repository list.</div>';
            } else {
                echo '<span class="uptime-monitor-inline-note">Not available</span>';
            }
            echo '</div>';

            if (!empty($item['stronganchor_github_match'])) {
                $repo_url = !empty($item['stronganchor_github_url']) ? (string) $item['stronganchor_github_url'] : uptime_monitor_get_stronganchor_github_org_url();
                echo '<div class="uptime-monitor-report-detail-block">';
                echo '<div class="uptime-monitor-report-detail-label">GitHub</div>';
                echo '<a href="' . esc_url($repo_url) . '" target="_blank" rel="noopener noreferrer">View repository</a>';
                if (!empty($item['stronganchor_github_repo'])) {
                    echo '<div class="uptime-monitor-inline-note"><code>' . esc_html($item['stronganchor_github_repo']) . '</code></div>';
                }
                echo '</div>';
            }

            echo '<div class="uptime-monitor-report-detail-block">';
            echo '<div class="uptime-monitor-report-detail-label">Plugin URI</div>';
            if (!empty($item['plugin_uris'])) {
                foreach ($item['plugin_uris'] as $index => $plugin_uri) {
                    if ($index > 0) {
                        echo '<br>';
                    }
                    echo '<a href="' . esc_url($plugin_uri) . '" target="_blank" rel="noopener noreferrer">' . esc_html($plugin_uri) . '</a>';
                }
            } else {
                echo '<span class="uptime-monitor-inline-note">Not available</span>';
            }
            echo '</div>';

            echo '</div>';
            echo '</td>';

            echo '<td class="uptime-monitor-report-notes-actions-cell">';
            echo '<form method="post" action="' . esc_url($report_action_url) . '" class="uptime-monitor-report-inline-form">';
            echo '<input type="hidden" name="action" value="uptime_monitor_plugin_report_action">';
            wp_nonce_field('uptime_monitor_save_plugin_report_note', 'uptime_monitor_save_plugin_report_note_nonce');
            echo '<input type="hidden" name="plugin_file" value="' . esc_attr($item['plugin_file']) . '">';
            echo '<input type="hidden" name="return_anchor" value="' . esc_attr($row_anchor) . '">';
            echo '<textarea name="plugin_note" rows="4" class="large-text uptime-monitor-plugin-report-note-input" placeholder="Add internal notes for this plugin...">' . esc_textarea($plugin_note) . '</textarea>';
            echo '<button type="submit" name="save_plugin_report_note" class="button button-secondary">Save Notes</button>';
            echo '<div class="uptime-monitor-inline-note">Saved notes are included in the copyable report export.</div>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url($report_action_url) . '" class="uptime-monitor-report-inline-form">';
            echo '<input type="hidden" name="action" value="uptime_monitor_plugin_report_action">';
            wp_nonce_field('uptime_monitor_hide_plugin_report_item', 'uptime_monitor_hide_plugin_report_item_nonce');
            echo '<input type="hidden" name="plugin_file" value="' . esc_attr($item['plugin_file']) . '">';
            echo '<input type="hidden" name="plugin_name" value="' . esc_attr($item['name']) . '">';
            echo '<input type="hidden" name="plugin_candidate" value="' . esc_attr($item['candidate']) . '">';
            echo '<input type="hidden" name="return_anchor" value="' . esc_attr($row_anchor) . '">';
            echo '<button type="submit" name="hide_plugin_report_item" class="button button-secondary">Hide</button>';
            echo '<div class="uptime-monitor-inline-note">Keeps this entry out of the main table until restored.</div>';
            echo '</form>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    } elseif (empty($hidden_plugins)) {
        echo '<p>No off-directory plugins were detected from the current MainWP plugin inventories.</p>';
    } else {
        echo '<p>All currently detected off-directory plugins are hidden from the main report. Review the hidden section below to restore any of them.</p>';
    }

    if (!empty($hidden_report_items) || !empty($stale_hidden_items)) {
        echo '<h2>Hidden Plugins</h2>';
        echo '<p class="uptime-monitor-plugin-report-intro">Hidden plugins stay out of the main report until you restore them here.</p>';
        echo '<table class="widefat striped uptime-monitor-plugin-report-table">';
        echo '<thead><tr><th class="uptime-monitor-report-plugin-cell">Plugin</th><th class="uptime-monitor-report-status-cell">Status</th><th class="uptime-monitor-report-notes-actions-cell">Notes / Actions</th></tr></thead>';
        echo '<tbody>';

        foreach ($hidden_report_items as $item) {
            $hidden_at = isset($item['hidden_at']) ? absint($item['hidden_at']) : 0;
            $hidden_at_text = $hidden_at > 0 ? date_i18n($date_time_format, $hidden_at) : 'Unknown';
            $plugin_note = isset($notes[$item['plugin_file']]) ? (string) $notes[$item['plugin_file']] : '';
            $row_anchor = uptime_monitor_get_plugin_report_row_anchor($item['plugin_file']);
            $variant_files = uptime_monitor_get_off_directory_plugin_report_variant_files($item['plugin_files'] ?? [], $item['plugin_file']);

            echo '<tr id="' . esc_attr($row_anchor) . '">';
            echo '<td class="uptime-monitor-report-plugin-cell">';
            echo '<strong>' . esc_html($item['name']) . '</strong>';
            echo '<div class="uptime-monitor-inline-note"><code>' . esc_html($item['plugin_file']) . '</code></div>';
            if (!empty($item['candidate'])) {
                echo '<div class="uptime-monitor-inline-note">Canonical slug: <code>' . esc_html($item['candidate']) . '</code></div>';
            }
            if (!empty($variant_files)) {
                echo '<div class="uptime-monitor-inline-note">Detected as: ';
                foreach ($variant_files as $variant_index => $variant_file) {
                    if ($variant_index > 0) {
                        echo ', ';
                    }
                    echo '<code>' . esc_html($variant_file) . '</code>';
                }
                echo '</div>';
            }
            echo '</td>';

            echo '<td class="uptime-monitor-report-status-cell">';
            echo '<div>' . esc_html(number_format_i18n(count($item['sites']))) . ' matching site' . (count($item['sites']) === 1 ? '' : 's') . ' currently hidden.</div>';
            echo '<div class="uptime-monitor-inline-note">Hidden on ' . esc_html($hidden_at_text) . '.</div>';
            echo '</td>';

            echo '<td class="uptime-monitor-report-notes-actions-cell">';
            echo '<form method="post" action="' . esc_url($report_action_url) . '" class="uptime-monitor-report-inline-form">';
            echo '<input type="hidden" name="action" value="uptime_monitor_plugin_report_action">';
            wp_nonce_field('uptime_monitor_save_plugin_report_note', 'uptime_monitor_save_plugin_report_note_nonce');
            echo '<input type="hidden" name="plugin_file" value="' . esc_attr($item['plugin_file']) . '">';
            echo '<input type="hidden" name="return_anchor" value="' . esc_attr($row_anchor) . '">';
            echo '<textarea name="plugin_note" rows="3" class="large-text uptime-monitor-plugin-report-note-input" placeholder="Add internal notes for this plugin...">' . esc_textarea($plugin_note) . '</textarea>';
            echo '<button type="submit" name="save_plugin_report_note" class="button button-secondary">Save Notes</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url($report_action_url) . '" class="uptime-monitor-report-inline-form">';
            echo '<input type="hidden" name="action" value="uptime_monitor_plugin_report_action">';
            wp_nonce_field('uptime_monitor_unhide_plugin_report_item', 'uptime_monitor_unhide_plugin_report_item_nonce');
            echo '<input type="hidden" name="plugin_file" value="' . esc_attr($item['plugin_file']) . '">';
            echo '<input type="hidden" name="return_anchor" value="' . esc_attr($row_anchor) . '">';
            echo '<button type="submit" name="unhide_plugin_report_item" class="button button-secondary">Unhide</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        foreach ($stale_hidden_items as $hidden_item) {
            $hidden_at = isset($hidden_item['hidden_at']) ? absint($hidden_item['hidden_at']) : 0;
            $hidden_at_text = $hidden_at > 0 ? date_i18n($date_time_format, $hidden_at) : 'Unknown';
            $plugin_label = !empty($hidden_item['name']) ? $hidden_item['name'] : $hidden_item['plugin_file'];
            $plugin_note = isset($notes[$hidden_item['plugin_file']]) ? (string) $notes[$hidden_item['plugin_file']] : '';
            $row_anchor = uptime_monitor_get_plugin_report_row_anchor($hidden_item['plugin_file']);
            $variant_files = uptime_monitor_get_off_directory_plugin_report_variant_files($hidden_item['plugin_files'] ?? [], $hidden_item['plugin_file']);

            echo '<tr id="' . esc_attr($row_anchor) . '">';
            echo '<td class="uptime-monitor-report-plugin-cell">';
            echo '<strong>' . esc_html($plugin_label) . '</strong>';
            echo '<div class="uptime-monitor-inline-note"><code>' . esc_html($hidden_item['plugin_file']) . '</code></div>';
            if (!empty($hidden_item['candidate'])) {
                echo '<div class="uptime-monitor-inline-note">Canonical slug: <code>' . esc_html($hidden_item['candidate']) . '</code></div>';
            }
            if (!empty($variant_files)) {
                echo '<div class="uptime-monitor-inline-note">Detected as: ';
                foreach ($variant_files as $variant_index => $variant_file) {
                    if ($variant_index > 0) {
                        echo ', ';
                    }
                    echo '<code>' . esc_html($variant_file) . '</code>';
                }
                echo '</div>';
            }
            echo '</td>';

            echo '<td class="uptime-monitor-report-status-cell">';
            echo '<div>Not currently detected in MainWP plugin inventories.</div>';
            echo '<div class="uptime-monitor-inline-note">Hidden on ' . esc_html($hidden_at_text) . '.</div>';
            echo '</td>';

            echo '<td class="uptime-monitor-report-notes-actions-cell">';
            echo '<form method="post" action="' . esc_url($report_action_url) . '" class="uptime-monitor-report-inline-form">';
            echo '<input type="hidden" name="action" value="uptime_monitor_plugin_report_action">';
            wp_nonce_field('uptime_monitor_save_plugin_report_note', 'uptime_monitor_save_plugin_report_note_nonce');
            echo '<input type="hidden" name="plugin_file" value="' . esc_attr($hidden_item['plugin_file']) . '">';
            echo '<input type="hidden" name="return_anchor" value="' . esc_attr($row_anchor) . '">';
            echo '<textarea name="plugin_note" rows="3" class="large-text uptime-monitor-plugin-report-note-input" placeholder="Add internal notes for this plugin...">' . esc_textarea($plugin_note) . '</textarea>';
            echo '<button type="submit" name="save_plugin_report_note" class="button button-secondary">Save Notes</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url($report_action_url) . '" class="uptime-monitor-report-inline-form">';
            echo '<input type="hidden" name="action" value="uptime_monitor_plugin_report_action">';
            wp_nonce_field('uptime_monitor_unhide_plugin_report_item', 'uptime_monitor_unhide_plugin_report_item_nonce');
            echo '<input type="hidden" name="plugin_file" value="' . esc_attr($hidden_item['plugin_file']) . '">';
            echo '<input type="hidden" name="return_anchor" value="' . esc_attr($row_anchor) . '">';
            echo '<button type="submit" name="unhide_plugin_report_item" class="button button-secondary">Unhide</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }
}

function uptime_monitor_get_off_directory_plugin_report_results_html($report) {
    ob_start();
    uptime_monitor_render_off_directory_plugin_report_results($report);
    return (string) ob_get_clean();
}

function uptime_monitor_handle_plugin_report_form_actions() {
    if (!is_admin() || strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $is_plugin_report_post = uptime_monitor_get_current_admin_page() === 'uptime-monitor-plugin-report';
    $is_admin_post_request = isset($_POST['action']) && wp_unslash($_POST['action']) === 'uptime_monitor_plugin_report_action';

    if (!$is_plugin_report_post && !$is_admin_post_request) {
        return;
    }

    if (isset($_POST['save_plugin_report_note'])) {
        check_admin_referer('uptime_monitor_save_plugin_report_note', 'uptime_monitor_save_plugin_report_note_nonce');

        $plugin_file = isset($_POST['plugin_file']) ? uptime_monitor_normalize_plugin_file_key(wp_unslash($_POST['plugin_file'])) : '';
        $plugin_note = isset($_POST['plugin_note']) ? wp_unslash($_POST['plugin_note']) : '';
        $return_anchor = isset($_POST['return_anchor']) ? sanitize_html_class(wp_unslash($_POST['return_anchor'])) : uptime_monitor_get_plugin_report_row_anchor($plugin_file);

        if (uptime_monitor_update_plugin_report_note($plugin_file, $plugin_note)) {
            $notice_code = sanitize_textarea_field((string) $plugin_note) === '' ? 'notes-cleared' : 'notes-saved';
            uptime_monitor_redirect_plugin_report($notice_code, 'success', $return_anchor);
        }

        uptime_monitor_redirect_plugin_report('notes-invalid', 'error', $return_anchor);
    }

    if (isset($_POST['hide_plugin_report_item'])) {
        check_admin_referer('uptime_monitor_hide_plugin_report_item', 'uptime_monitor_hide_plugin_report_item_nonce');

        $plugin_file = isset($_POST['plugin_file']) ? uptime_monitor_normalize_plugin_file_key(wp_unslash($_POST['plugin_file'])) : '';
        $plugin_name = isset($_POST['plugin_name']) ? sanitize_text_field(wp_unslash($_POST['plugin_name'])) : '';
        $candidate = isset($_POST['plugin_candidate']) ? sanitize_title(wp_unslash($_POST['plugin_candidate'])) : '';
        $return_anchor = isset($_POST['return_anchor']) ? sanitize_html_class(wp_unslash($_POST['return_anchor'])) : uptime_monitor_get_plugin_report_row_anchor($plugin_file);

        if (uptime_monitor_hide_plugin_report_item($plugin_file, $plugin_name, $candidate)) {
            uptime_monitor_redirect_plugin_report('plugin-hidden', 'success', $return_anchor);
        }

        uptime_monitor_redirect_plugin_report('plugin-hide-invalid', 'error', $return_anchor);
    }

    if (isset($_POST['unhide_plugin_report_item'])) {
        check_admin_referer('uptime_monitor_unhide_plugin_report_item', 'uptime_monitor_unhide_plugin_report_item_nonce');

        $plugin_file = isset($_POST['plugin_file']) ? uptime_monitor_normalize_plugin_file_key(wp_unslash($_POST['plugin_file'])) : '';
        $return_anchor = isset($_POST['return_anchor']) ? sanitize_html_class(wp_unslash($_POST['return_anchor'])) : uptime_monitor_get_plugin_report_row_anchor($plugin_file);
        if (uptime_monitor_unhide_plugin_report_item($plugin_file)) {
            uptime_monitor_redirect_plugin_report('plugin-restored', 'success', $return_anchor);
        }

        uptime_monitor_redirect_plugin_report('plugin-restore-invalid', 'error', $return_anchor);
    }
}
add_action('admin_init', 'uptime_monitor_handle_plugin_report_form_actions');
add_action('admin_post_uptime_monitor_plugin_report_action', 'uptime_monitor_handle_plugin_report_form_actions');

function uptime_monitor_plugin_report_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $refresh_metadata = false;
    $action_notice = '';
    $action_notice_class = 'notice-success';

    if (isset($_POST['refresh_plugin_report'])) {
        check_admin_referer('uptime_monitor_refresh_plugin_report', 'uptime_monitor_refresh_plugin_report_nonce');
        $refresh_metadata = true;
    }

    if (isset($_GET['uptime_monitor_notice'])) {
        $action_notice = uptime_monitor_get_plugin_report_notice_message(wp_unslash($_GET['uptime_monitor_notice']));
        $notice_type = isset($_GET['uptime_monitor_notice_type']) ? sanitize_key(wp_unslash($_GET['uptime_monitor_notice_type'])) : 'success';
        $action_notice_class = ($notice_type === 'error') ? 'notice-error' : 'notice-success';
    }

    $progress_nonce = wp_create_nonce('uptime_monitor_plugin_report_batches');

    echo '<div class="wrap">';
    echo '<h1>Off-Directory Plugins</h1>';

    if ($action_notice !== '') {
        echo '<div class="notice ' . esc_attr($action_notice_class) . '"><p>' . esc_html($action_notice) . '</p></div>';
    }

    echo '<p class="uptime-monitor-plugin-report-intro">This report groups plugins found on MainWP child sites that are not currently available in the WordPress.org plugin directory. That includes custom plugins, premium plugins, and plugins that were later closed on WordPress.org. Versions and site mappings come from MainWP synced plugin inventories. Author and Plugin URI fields are filled from cached MainWP child-site metadata when available. Use Hide to suppress expected entries such as maintained premium plugins or hosting MU plugins.</p>';

    echo '<div class="uptime-monitor-report-actions">';
    echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=uptime-monitor-plugin-report')) . '" class="uptime-monitor-report-refresh-form">';
    wp_nonce_field('uptime_monitor_refresh_plugin_report', 'uptime_monitor_refresh_plugin_report_nonce');
    echo '<input type="submit" name="refresh_plugin_report" class="button button-secondary" value="Refresh Extended Metadata" data-plugin-report-refresh-button="1">';
    echo '<span class="uptime-monitor-inline-note">Runs batched MainWP requests on matching child sites so Author and Plugin URI values can be updated without locking the page.</span>';
    echo '</form>';
    echo '<button type="button" class="button button-secondary" data-plugin-report-copy-button="1" disabled>Copy Report for AI</button>';
    echo '<span class="uptime-monitor-inline-note" data-plugin-report-copy-status>Available after the report finishes loading.</span>';
    echo '</div>';

    echo '<div class="uptime-monitor-plugin-report-progress" data-plugin-report-loader="1" data-refresh="' . esc_attr($refresh_metadata ? '1' : '0') . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr($progress_nonce) . '">';
    echo '<div class="uptime-monitor-plugin-report-progress-bar"><span class="uptime-monitor-plugin-report-progress-fill" data-plugin-report-progress-fill></span></div>';
    echo '<div class="uptime-monitor-plugin-report-progress-meta">';
    echo '<strong data-plugin-report-progress-percent>0%</strong> ';
    echo '<span data-plugin-report-progress-status>' . esc_html($refresh_metadata ? 'Refreshing extended metadata...' : 'Loading plugin report...') . '</span>';
    echo '</div>';
    echo '<div class="uptime-monitor-inline-note" data-plugin-report-progress-counts>Preparing site batches...</div>';
    echo '<div class="uptime-monitor-plugin-report-error is-hidden" data-plugin-report-error></div>';
    echo '</div>';

    echo '<div data-plugin-report-results></div>';
    echo '<noscript><p>The plugin report requires JavaScript so it can load in batches.</p></noscript>';
    echo '</div>';
}

function uptime_monitor_ajax_process_plugin_report_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to view this report.'], 403);
    }

    check_ajax_referer('uptime_monitor_plugin_report_batches', 'nonce');

    $request_refresh = isset($_POST['refresh']) && wp_unslash($_POST['refresh']) === '1';
    $job_id = isset($_POST['job_id']) ? sanitize_key(wp_unslash($_POST['job_id'])) : '';

    try {
        if ($job_id === '') {
            $sites = uptime_monitor_get_mainwp_sites_for_plugin_report();
            if (empty($sites)) {
                $report = uptime_monitor_get_empty_off_directory_plugin_report('No MainWP child-site records were found. This report only works on a MainWP dashboard site.');
                wp_send_json_success([
                    'done'            => true,
                    'job_id'          => '',
                    'processed_sites' => 0,
                    'total_sites'     => 0,
                    'progress_pct'    => 100,
                    'status_text'     => 'Plugin report ready.',
                    'counts_text'     => 'No MainWP child sites were found.',
                    'html'            => uptime_monitor_get_off_directory_plugin_report_results_html($report),
                    'markdown'        => uptime_monitor_get_off_directory_plugin_report_markdown($report),
                    'is_refresh'      => $request_refresh,
                ]);
            }

            $job_id = md5(wp_generate_uuid4() . '|' . microtime(true) . '|' . wp_rand());
            $state = uptime_monitor_initialize_off_directory_plugin_report_state(array_keys($sites), $request_refresh);
        } else {
            $state = get_transient(uptime_monitor_get_plugin_report_job_key($job_id));
            if (!is_array($state)) {
                wp_send_json_error(['message' => 'The plugin report batch state expired. Reload the page and try again.'], 410);
            }
        }

        $total_sites = isset($state['sites_total']) ? (int) $state['sites_total'] : 0;
        if ($total_sites <= 0) {
            $report = uptime_monitor_finalize_off_directory_plugin_report_state($state);
            wp_send_json_success([
                'done'            => true,
                'job_id'          => $job_id,
                'processed_sites' => 0,
                'total_sites'     => 0,
                'progress_pct'    => 100,
                'status_text'     => 'Plugin report ready.',
                'counts_text'     => 'No child sites needed to be scanned.',
                'html'            => uptime_monitor_get_off_directory_plugin_report_results_html($report),
                'markdown'        => uptime_monitor_get_off_directory_plugin_report_markdown($report),
                'is_refresh'      => !empty($state['allow_remote_fetch']),
            ]);
        }

        $batch_size = uptime_monitor_get_plugin_report_batch_size(!empty($state['allow_remote_fetch']));
        $batch_site_ids = array_slice($state['site_ids'], (int) $state['offset'], $batch_size);
        uptime_monitor_process_off_directory_plugin_report_site_batch($state, $batch_site_ids);

        $processed_sites = min((int) $state['offset'], $total_sites);
        $progress_pct = (int) round(($processed_sites / $total_sites) * 100);
        $counts_text = 'Processed ' . number_format_i18n($processed_sites) . ' of ' . number_format_i18n($total_sites) . ' child sites.';
        $status_text = !empty($state['allow_remote_fetch'])
            ? 'Refreshing extended metadata in batches...'
            : 'Loading plugin report in batches...';

        if ($processed_sites >= $total_sites) {
            $report = uptime_monitor_finalize_off_directory_plugin_report_state($state);
            delete_transient(uptime_monitor_get_plugin_report_job_key($job_id));

            wp_send_json_success([
                'done'            => true,
                'job_id'          => $job_id,
                'processed_sites' => $processed_sites,
                'total_sites'     => $total_sites,
                'progress_pct'    => 100,
                'status_text'     => !empty($state['allow_remote_fetch']) ? 'Extended metadata refresh complete.' : 'Plugin report ready.',
                'counts_text'     => $counts_text,
                'html'            => uptime_monitor_get_off_directory_plugin_report_results_html($report),
                'markdown'        => uptime_monitor_get_off_directory_plugin_report_markdown($report),
                'is_refresh'      => !empty($state['allow_remote_fetch']),
            ]);
        }

        set_transient(uptime_monitor_get_plugin_report_job_key($job_id), $state, HOUR_IN_SECONDS);

        wp_send_json_success([
            'done'            => false,
            'job_id'          => $job_id,
            'processed_sites' => $processed_sites,
            'total_sites'     => $total_sites,
            'progress_pct'    => $progress_pct,
            'status_text'     => $status_text,
            'counts_text'     => $counts_text,
            'is_refresh'      => !empty($state['allow_remote_fetch']),
        ]);
    } catch (Throwable $throwable) {
        if ($job_id !== '') {
            delete_transient(uptime_monitor_get_plugin_report_job_key($job_id));
        }

        $message = uptime_monitor_clean_plugin_text($throwable->getMessage());
        if ($message === '') {
            $message = 'Unexpected server error while building the plugin report.';
        }

        wp_send_json_error(['message' => $message], 500);
    }
}
add_action('wp_ajax_uptime_monitor_process_plugin_report_batch', 'uptime_monitor_ajax_process_plugin_report_batch');

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
        $has_bandwidth_data = false;
        $has_inode_data = false;

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

            $has_bandwidth_data = ($bandwidth_used_mb !== null || $bandwidth_limit_mb !== null);
            $has_inode_data = ($inodes_used !== null || $inodes_limit !== null);

            if ($has_bandwidth_data) {
                $bandwidth_text = uptime_monitor_format_bandwidth_summary($bandwidth_used_mb, $bandwidth_limit_mb);
            }
            if ($has_inode_data) {
                $inode_text = uptime_monitor_format_inode_summary($inodes_used, $inodes_limit);
            }
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
            echo '<span class="uptime-monitor-disk-key-meta uptime-monitor-disk-key-meta-extra">' . esc_html($bandwidth_text) . '</span>';
        }
        if ($inode_text !== '') {
            echo '<span class="uptime-monitor-disk-key-meta uptime-monitor-disk-key-meta-extra">' . esc_html($inode_text) . '</span>';
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
        return 'No accounts found or insufficient permissions.';
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
        return 'Account bandwidth usage data was not included in the WHM response.';
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
        return 'Inode usage data was not included in the WHM response.';
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

    $sites = uptime_monitor_get_mainwp_sites();
    $results = get_option('uptime_monitor_results', []);
    if (!is_array($results)) {
        $results = [];
    }

    $sites_down = 0;
    foreach ($sites as $site_url) {
        if (uptime_monitor_result_is_down($results[$site_url] ?? [])) {
            $sites_down++;
        }
    }

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
        'sites' => [
            'total' => count($sites),
            'down'  => $sites_down,
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

    $site_down_count = 0;
    foreach ($sites as $site_info) {
        $site_url = isset($site_info['site_url']) ? (string) $site_info['site_url'] : '';
        if ($site_url !== '' && uptime_monitor_result_is_down($results[$site_url] ?? [])) {
            $site_down_count++;
        }
    }

    echo '<div id="uptime-monitor-tabicon-state" hidden data-sites-total="' . esc_attr(count($sites)) . '" data-sites-down="' . esc_attr($site_down_count) . '"></div>';

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
    $page = uptime_monitor_get_current_admin_page();
    if ($page !== 'uptime-monitor' && $page !== 'uptime-monitor-plugin-report') {
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
        .uptime-monitor-disk-key-meta-extra {
            display: none;
        }
        .uptime-monitor-disk-key-item.is-hovered .uptime-monitor-disk-key-meta-extra,
        .uptime-monitor-disk-key-item:hover .uptime-monitor-disk-key-meta-extra,
        .uptime-monitor-disk-key-item:focus-visible .uptime-monitor-disk-key-meta-extra {
            display: block;
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
        .uptime-monitor-plugin-report-intro {
            max-width: 88ch;
            margin: 10px 0 14px;
        }
        .uptime-monitor-report-actions {
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .uptime-monitor-report-refresh-form {
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .uptime-monitor-plugin-report-progress {
            margin: 0 0 18px;
            padding: 14px 16px;
            border: 1px solid #dcdcde;
            border-radius: 12px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            max-width: 760px;
        }
        .uptime-monitor-plugin-report-progress-bar {
            width: 100%;
            height: 12px;
            border-radius: 999px;
            overflow: hidden;
            background: #e5e7eb;
        }
        .uptime-monitor-plugin-report-progress-fill {
            display: block;
            width: 0;
            height: 100%;
            background: linear-gradient(90deg, #1d4ed8 0%, #0ea5e9 100%);
            transition: width 180ms ease;
        }
        .uptime-monitor-plugin-report-progress.is-complete .uptime-monitor-plugin-report-progress-fill {
            background: linear-gradient(90deg, #15803d 0%, #22c55e 100%);
        }
        .uptime-monitor-plugin-report-progress.is-error .uptime-monitor-plugin-report-progress-fill {
            background: #d63638;
        }
        .uptime-monitor-plugin-report-progress.is-error {
            border-color: #fca5a5;
            background: #fef2f2;
        }
        .uptime-monitor-plugin-report-progress-meta {
            margin-top: 10px;
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: wrap;
            color: #1d2327;
        }
        .uptime-monitor-plugin-report-progress-meta strong {
            font-size: 20px;
            line-height: 1;
        }
        .uptime-monitor-plugin-report-error {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #fff1f2;
            color: #991b1b;
        }
        .uptime-monitor-plugin-report-error.is-hidden {
            display: none;
        }
        .uptime-monitor-inline-note {
            font-size: 12px;
            color: #50575e;
            line-height: 1.45;
        }
        .uptime-monitor-report-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin: 0 0 16px;
            max-width: 760px;
        }
        .uptime-monitor-report-stat {
            padding: 12px 14px;
            border: 1px solid #dcdcde;
            border-radius: 10px;
            background: #ffffff;
        }
        .uptime-monitor-report-stat-value {
            display: block;
            font-size: 24px;
            font-weight: 700;
            color: #1d2327;
            line-height: 1.15;
        }
        .uptime-monitor-report-stat-label {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: #646970;
            line-height: 1.35;
        }
        .uptime-monitor-plugin-report-warning {
            margin: 10px 0;
            color: #9a3412;
        }
        .uptime-monitor-plugin-report-table {
            width: 100%;
            table-layout: fixed;
        }
        .uptime-monitor-plugin-report-table th,
        .uptime-monitor-plugin-report-table td {
            vertical-align: top;
        }
        .uptime-monitor-plugin-report-table th,
        .uptime-monitor-plugin-report-table td,
        .uptime-monitor-plugin-report-table a,
        .uptime-monitor-plugin-report-table code {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .uptime-monitor-report-plugin-cell {
            width: 24%;
        }
        .uptime-monitor-report-details-cell {
            width: 36%;
        }
        .uptime-monitor-report-status-cell {
            width: 28%;
        }
        .uptime-monitor-report-notes-actions-cell {
            width: 40%;
        }
        .uptime-monitor-report-inline-form {
            margin: 0;
            display: grid;
            gap: 6px;
            align-content: start;
        }
        .uptime-monitor-report-inline-form + .uptime-monitor-report-inline-form {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
        }
        .uptime-monitor-report-detail-stack {
            display: grid;
            gap: 10px;
        }
        .uptime-monitor-report-detail-block {
            display: grid;
            gap: 4px;
        }
        .uptime-monitor-report-detail-label {
            font-size: 11px;
            font-weight: 700;
            color: #50575e;
            line-height: 1.35;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .uptime-monitor-plugin-report-note-input {
            min-height: 72px;
            resize: vertical;
        }
        .uptime-monitor-report-list {
            margin: 0;
            padding-left: 18px;
        }
        .uptime-monitor-report-list li {
            margin: 0 0 6px;
        }
        .uptime-monitor-report-list-toggle {
            margin-top: 6px;
        }
        .uptime-monitor-report-list-toggle summary {
            cursor: pointer;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.4;
        }
        .uptime-monitor-report-list-toggle[open] summary {
            margin-bottom: 8px;
        }
        .uptime-monitor-report-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.4;
        }
        .uptime-monitor-report-badge.is-warning {
            background: #fff7ed;
            color: #9a3412;
        }
        .uptime-monitor-report-badge.is-neutral {
            background: #f3f4f6;
            color: #374151;
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
            .uptime-monitor-report-actions {
                align-items: flex-start;
            }
            .uptime-monitor-plugin-report-progress-meta {
                align-items: flex-start;
            }
            .uptime-monitor-plugin-report-table {
                table-layout: auto;
            }
            .uptime-monitor-report-plugin-cell,
            .uptime-monitor-report-details-cell,
            .uptime-monitor-report-status-cell,
            .uptime-monitor-report-notes-actions-cell {
                width: auto;
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
        var stateNode = document.getElementById("uptime-monitor-tabicon-state");
        var strip = document.querySelector(".uptime-monitor-load-strip[data-load-refresh=\"1\"]");
        var head = document.head || document.getElementsByTagName("head")[0];
        var iconState = {
            sitesDown: 0,
            sitesTotal: 0,
            loadLevel: "unknown",
            loadFive: null
        };

        var parseInteger = function(value) {
            var parsed = parseInt(value, 10);
            return Number.isFinite(parsed) ? parsed : 0;
        };

        var parseNumber = function(value) {
            if (value === null || typeof value === "undefined") {
                return null;
            }
            var text = String(value).trim();
            if (!text) {
                return null;
            }
            var parsed = parseFloat(text);
            return Number.isFinite(parsed) ? parsed : null;
        };

        if (stateNode) {
            iconState.sitesDown = Math.max(0, parseInteger(stateNode.getAttribute("data-sites-down")));
            iconState.sitesTotal = Math.max(0, parseInteger(stateNode.getAttribute("data-sites-total")));
        }

        var readLoadFromDom = function() {
            if (!strip) {
                return;
            }

            var statusNode = strip.querySelector("[data-load-status]");
            if (statusNode) {
                var className = statusNode.className || "";
                var match = className.match(/uptime-monitor-load-status-(healthy|elevated|high|unknown)/);
                iconState.loadLevel = (match && match[1]) ? match[1] : "unknown";
            }

            var fiveValueNode = strip.querySelector("[data-load-pill=\"five\"] .uptime-monitor-load-pill-value");
            iconState.loadFive = fiveValueNode ? parseNumber(fiveValueNode.textContent) : null;
        };

        var ensureIconLinks = function() {
            if (!head) {
                return [];
            }

            var links = Array.prototype.slice.call(document.querySelectorAll("link[rel~=\"icon\"]"));
            if (!links.length) {
                var link = document.createElement("link");
                link.setAttribute("rel", "icon");
                head.appendChild(link);
                links = [link];
            }

            return links;
        };

        var roundRect = function(ctx, x, y, width, height, radius) {
            var r = Math.max(0, Math.min(radius, width / 2, height / 2));
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.arcTo(x + width, y, x + width, y + height, r);
            ctx.arcTo(x + width, y + height, x, y + height, r);
            ctx.arcTo(x, y + height, x, y, r);
            ctx.arcTo(x, y, x + width, y, r);
            ctx.closePath();
        };

        var loadColorMap = {
            healthy: "#15803d",
            elevated: "#ea580c",
            high: "#dc2626",
            unknown: "#6b7280"
        };

        var getLoadText = function(value) {
            if (typeof value !== "number" || !Number.isFinite(value)) {
                return "UM";
            }

            if (value >= 100) {
                return "99";
            }
            if (value >= 10) {
                return String(Math.round(value));
            }

            var text = value.toFixed(1);
            return text.replace(/\\.0$/, "");
        };

        var getDownText = function(count) {
            if (count > 9) {
                return "9+";
            }
            return String(Math.max(0, count));
        };

        var setFaviconDataUrl = function(dataUrl) {
            var links = ensureIconLinks();
            links.forEach(function(link) {
                link.setAttribute("href", dataUrl);
                link.setAttribute("type", "image/png");
            });
        };

        var drawIcon = function() {
            var canvas = document.createElement("canvas");
            canvas.width = 32;
            canvas.height = 32;

            var ctx = canvas.getContext("2d");
            if (!ctx) {
                return;
            }

            ctx.clearRect(0, 0, 32, 32);

            if (iconState.sitesDown > 0) {
                var downText = getDownText(iconState.sitesDown);
                ctx.fillStyle = "#d63638";
                ctx.beginPath();
                ctx.arc(16, 16, 14, 0, Math.PI * 2);
                ctx.fill();

                ctx.lineWidth = 2;
                ctx.strokeStyle = "rgba(255,255,255,0.85)";
                ctx.stroke();

                ctx.fillStyle = "#ffffff";
                ctx.textAlign = "center";
                ctx.textBaseline = "middle";
                ctx.font = "700 " + (downText.length > 1 ? 13 : 16) + "px sans-serif";
                ctx.fillText(downText, 16, 16.5);
            } else {
                var level = loadColorMap[iconState.loadLevel] ? iconState.loadLevel : "unknown";
                var loadText = getLoadText(iconState.loadFive);

                ctx.fillStyle = loadColorMap[level];
                roundRect(ctx, 2, 2, 28, 28, 8);
                ctx.fill();

                ctx.lineWidth = 2;
                ctx.strokeStyle = "rgba(255,255,255,0.85)";
                ctx.stroke();

                ctx.fillStyle = "#ffffff";
                ctx.textAlign = "center";
                ctx.textBaseline = "middle";
                ctx.font = "700 " + (loadText.length >= 3 ? 12 : 14) + "px sans-serif";
                ctx.fillText(loadText, 16, 16.5);
            }

            try {
                setFaviconDataUrl(canvas.toDataURL("image/png"));
            } catch (error) {
                return;
            }
        };

        var applyPayload = function(data) {
            if (!data) {
                return;
            }

            if (data.status && data.status.level) {
                iconState.loadLevel = String(data.status.level);
            }

            if (data.metrics && data.metrics.five) {
                iconState.loadFive = parseNumber(data.metrics.five.raw);
                if (iconState.loadFive === null) {
                    iconState.loadFive = parseNumber(data.metrics.five.display);
                }
            }

            if (data.sites) {
                if (typeof data.sites.down !== "undefined") {
                    iconState.sitesDown = Math.max(0, parseInteger(data.sites.down));
                    if (stateNode) {
                        stateNode.setAttribute("data-sites-down", String(iconState.sitesDown));
                    }
                }
                if (typeof data.sites.total !== "undefined") {
                    iconState.sitesTotal = Math.max(0, parseInteger(data.sites.total));
                    if (stateNode) {
                        stateNode.setAttribute("data-sites-total", String(iconState.sitesTotal));
                    }
                }
            }

            drawIcon();
        };

        readLoadFromDom();
        drawIcon();

        window.uptimeMonitorTabIcon = {
            applyPayload: applyPayload,
            refreshFromDom: function() {
                readLoadFromDom();
                if (stateNode) {
                    iconState.sitesDown = Math.max(0, parseInteger(stateNode.getAttribute("data-sites-down")));
                    iconState.sitesTotal = Math.max(0, parseInteger(stateNode.getAttribute("data-sites-total")));
                }
                drawIcon();
            }
        };
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

            if (window.uptimeMonitorTabIcon && typeof window.uptimeMonitorTabIcon.applyPayload === "function") {
                window.uptimeMonitorTabIcon.applyPayload(data);
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

function uptime_monitor_enqueue_plugin_report_batch_script() {
    if (uptime_monitor_get_current_admin_page() !== 'uptime-monitor-plugin-report') {
        return;
    }

    echo '<script>
    (function() {
        var loader = document.querySelector("[data-plugin-report-loader=\"1\"]");
        var resultsNode = document.querySelector("[data-plugin-report-results]");
        if (!loader || !resultsNode) {
            return;
        }

        var ajaxUrl = loader.getAttribute("data-ajax-url") || "";
        var nonce = loader.getAttribute("data-nonce") || "";
        var refreshMode = loader.getAttribute("data-refresh") === "1";
        var fillNode = loader.querySelector("[data-plugin-report-progress-fill]");
        var percentNode = loader.querySelector("[data-plugin-report-progress-percent]");
        var statusNode = loader.querySelector("[data-plugin-report-progress-status]");
        var countsNode = loader.querySelector("[data-plugin-report-progress-counts]");
        var errorNode = loader.querySelector("[data-plugin-report-error]");
        var refreshButton = document.querySelector("[data-plugin-report-refresh-button=\"1\"]");
        var copyButton = document.querySelector("[data-plugin-report-copy-button=\"1\"]");
        var copyStatusNode = document.querySelector("[data-plugin-report-copy-status]");
        var jobId = "";
        var active = false;
        var reportMarkdown = "";

        if (!ajaxUrl || !nonce || !fillNode || !percentNode || !statusNode || !countsNode || !errorNode) {
            return;
        }

        var setButtonDisabled = function(disabled) {
            if (refreshButton) {
                refreshButton.disabled = !!disabled;
            }
        };

        var setCopyState = function(disabled, statusText) {
            if (copyButton) {
                copyButton.disabled = !!disabled;
            }
            if (copyStatusNode && typeof statusText === "string" && statusText !== "") {
                copyStatusNode.textContent = statusText;
            }
        };

        var setProgress = function(progressPct, statusText, countsText) {
            var safePct = parseInt(progressPct, 10);
            if (!Number.isFinite(safePct)) {
                safePct = 0;
            }

            safePct = Math.max(0, Math.min(100, safePct));
            fillNode.style.width = safePct + "%";
            percentNode.textContent = safePct + "%";

            if (statusText) {
                statusNode.textContent = statusText;
            }
            if (typeof countsText === "string" && countsText !== "") {
                countsNode.textContent = countsText;
            }
        };

        var scrollToHashTarget = function() {
            var hash = window.location.hash || "";
            if (!hash || hash.charAt(0) !== "#") {
                return;
            }

            var target = document.getElementById(hash.slice(1));
            if (!target || typeof target.scrollIntoView !== "function") {
                return;
            }

            window.setTimeout(function() {
                target.scrollIntoView({
                    block: "center",
                    behavior: "auto"
                });
            }, 30);
        };

        var clearError = function() {
            loader.classList.remove("is-error");
            errorNode.textContent = "";
            errorNode.classList.add("is-hidden");
        };

        var copyTextToClipboard = function(text) {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
                return navigator.clipboard.writeText(text);
            }

            return new Promise(function(resolve, reject) {
                var textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.setAttribute("readonly", "readonly");
                textArea.style.position = "fixed";
                textArea.style.top = "-9999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();

                try {
                    var copied = document.execCommand("copy");
                    document.body.removeChild(textArea);
                    if (copied) {
                        resolve();
                        return;
                    }
                } catch (error) {
                    document.body.removeChild(textArea);
                    reject(error);
                    return;
                }

                reject(new Error("Clipboard copy failed."));
            });
        };

        var showError = function(message) {
            active = false;
            reportMarkdown = "";
            loader.classList.remove("is-loading");
            loader.classList.remove("is-complete");
            loader.classList.add("is-error");
            errorNode.textContent = message || "Unable to load the plugin report.";
            errorNode.classList.remove("is-hidden");
            resultsNode.innerHTML = "";
            setButtonDisabled(false);
            setCopyState(true, "Copy unavailable until the report loads successfully.");
        };

        var parseResponse = function(response) {
            return response.text().then(function(text) {
                var payload = null;
                if (text) {
                    try {
                        payload = JSON.parse(text);
                    } catch (error) {
                        payload = null;
                    }
                }

                if (!response.ok) {
                    var errorMessage = payload && payload.data && payload.data.message ? payload.data.message : "The server returned an invalid plugin report response.";
                    throw new Error(errorMessage);
                }

                if (!payload) {
                    throw new Error("The server returned an empty plugin report response.");
                }

                return payload;
            });
        };

        var requestNextBatch = function() {
            if (active) {
                return;
            }

            active = true;
            reportMarkdown = "";
            loader.classList.add("is-loading");
            loader.classList.remove("is-complete");
            clearError();
            setButtonDisabled(true);
            setCopyState(true, refreshMode ? "Export will update when the refreshed report finishes loading." : "Preparing markdown export...");

            var formData = new URLSearchParams();
            formData.append("action", "uptime_monitor_process_plugin_report_batch");
            formData.append("nonce", nonce);
            formData.append("refresh", refreshMode ? "1" : "0");

            if (jobId) {
                formData.append("job_id", jobId);
            }

            fetch(ajaxUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
                },
                body: formData.toString()
            }).then(parseResponse).then(function(payload) {
                if (!payload || !payload.success || !payload.data) {
                    var message = payload && payload.data && payload.data.message ? payload.data.message : "The plugin report request failed.";
                    throw new Error(message);
                }

                var data = payload.data;
                if (data.job_id) {
                    jobId = String(data.job_id);
                }

                setProgress(data.progress_pct, data.status_text, data.counts_text);

                if (data.done) {
                    if (typeof data.html === "string") {
                        resultsNode.innerHTML = data.html;
                    }
                    reportMarkdown = typeof data.markdown === "string" ? data.markdown : "";
                    scrollToHashTarget();
                    loader.classList.remove("is-loading");
                    loader.classList.remove("is-error");
                    loader.classList.add("is-complete");
                    setButtonDisabled(false);
                    setCopyState(reportMarkdown === "", reportMarkdown === "" ? "Copy unavailable for this report." : "Ready to copy the full report.");
                    active = false;
                    return;
                }

                active = false;
                window.setTimeout(requestNextBatch, 25);
            }).catch(function(error) {
                showError(error && error.message ? error.message : "Unable to load the plugin report.");
            });
        };

        if (copyButton) {
            copyButton.addEventListener("click", function() {
                if (!reportMarkdown) {
                    setCopyState(true, "Copy unavailable until the report loads successfully.");
                    return;
                }

                copyTextToClipboard(reportMarkdown).then(function() {
                    setCopyState(false, "Report copied to clipboard.");
                }).catch(function() {
                    setCopyState(false, "Clipboard copy failed in this browser.");
                });
            });
        }

        requestNextBatch();
    })();
    </script>';
}
add_action('admin_footer', 'uptime_monitor_enqueue_plugin_report_batch_script');

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

function uptime_monitor_result_is_down($result) {
    if (!is_array($result)) {
        return false;
    }

    $status = isset($result['status']) ? (string) $result['status'] : '';
    $keyword_match = isset($result['keyword_match']) ? (string) $result['keyword_match'] : '';

    return (strpos($status, 'Error') !== false || $keyword_match === 'No match found');
}

function uptime_monitor_check_status($url, $retry_count = 1, $retry_delay = 0) {
    $retry_count = max(1, (int) $retry_count);

    // Kept for backward compatibility with existing calls/overrides; retries no longer sleep.

    $args = [
        'timeout'   => 12,
        'sslverify' => true,
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

            $status = ($status_code === 200) ? "OK ($status_code)" : "Error ($status_code): $status_message";

            $keyword_match = 'No match found';
            $site_title    = 'N/A';

            if ($status_code === 200) {
                $page_content = wp_remote_retrieve_body($response);

                if (preg_match('/<title>(.*?)<\/title>/is', $page_content, $matches)) {
                    $site_title = trim(wp_strip_all_tags(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8')));
                }

                if (!empty($custom_keyword)) {
                    $visible_text = extract_visible_text($page_content);
                    $quoted_keyword = preg_quote($custom_keyword, '/');

                    if ($quoted_keyword !== '' && preg_match("/{$quoted_keyword}/i", $visible_text, $matches)) {
                        $keyword_match = $matches[0];
                    }
                } else {
                    $domain      = parse_url($url, PHP_URL_HOST);
                    $base_domain = preg_replace('/^www\./', '', $domain);
                    $base_domain = preg_replace('/\.[^.]+$/', '', $base_domain);

                    $pattern = str_split($base_domain);
                    $pattern = implode('\s*', $pattern);

                    // Prefer <title> before full visible text to reduce false alerts on JS-heavy pages.
                    if (!empty($site_title) && preg_match("/{$pattern}/i", $site_title, $matches)) {
                        $keyword_match = $matches[0];
                    } else {
                        $visible_text = extract_visible_text($page_content);

                        if (preg_match("/{$pattern}/i", $visible_text, $matches)) {
                            $keyword_match = $matches[0];
                        }
                    }
                }
            }
        }

        if (strpos($status, 'Error') === false && $keyword_match !== 'No match found') {
            return [
                'status'        => $status,
                'keyword_match' => $keyword_match,
                'site_title'    => $site_title,
            ];
        }
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
        $result = uptime_monitor_perform_check($site);

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
    $result       = uptime_monitor_check_status($site);
    $results      = get_option('uptime_monitor_results', []);
    $results[$site] = $result;
    $last_checked = get_option('uptime_monitor_last_checked', []);
    $last_checked[$site] = current_time('timestamp');
    update_option('uptime_monitor_results', $results);
    update_option('uptime_monitor_last_checked', $last_checked);

    return $result;
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
