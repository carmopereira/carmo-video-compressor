<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cvc_jobs");

$upload_dir = wp_upload_dir();
$base_dir   = trailingslashit($upload_dir['basedir']) . 'carmo-video-compressor';

if (is_dir($base_dir)) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    global $wp_filesystem;

    if (WP_Filesystem() && $wp_filesystem) {
        $wp_filesystem->delete($base_dir, true, 'd');
    }
}
