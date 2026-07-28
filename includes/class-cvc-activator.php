<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CVC_Activator
{
    public static function activate(): void
    {
        self::create_table();
        self::create_directories();
    }

    private static function create_table(): void
    {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'cvc_jobs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_filename VARCHAR(255) NOT NULL,
            output_filename VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'uploading',
            progress_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            filesize BIGINT UNSIGNED DEFAULT NULL,
            duration_seconds FLOAT DEFAULT NULL,
            pid INT UNSIGNED DEFAULT NULL,
            progress_file VARCHAR(255) DEFAULT NULL,
            log_file VARCHAR(255) DEFAULT NULL,
            done_file VARCHAR(255) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_directories(): void
    {
        $base = cvc_upload_base_dir();

        foreach (['tmp', 'compressed'] as $sub) {
            $dir = trailingslashit($base) . $sub;
            wp_mkdir_p($dir);
            self::lock_down_directory($dir);
        }
    }

    private static function lock_down_directory(string $dir): void
    {
        $index_file = trailingslashit($dir) . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden.\n");
        }

        $htaccess_file = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess = "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Deny from all\n"
                . "</IfModule>\n";
            file_put_contents($htaccess_file, $htaccess);
        }
    }
}
