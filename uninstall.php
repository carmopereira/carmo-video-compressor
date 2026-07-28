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

    /**
     * Recursively delete a directory using the WP_Filesystem-independent
     * approach (uninstall.php runs in a minimal bootstrap where direct
     * filesystem access is the simplest, safest option here).
     */
    $delete_recursive = static function (string $dir) use (&$delete_recursive): void {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = trailingslashit($dir) . $item;
            if (is_dir($path)) {
                $delete_recursive($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    };

    $delete_recursive($base_dir);
}
