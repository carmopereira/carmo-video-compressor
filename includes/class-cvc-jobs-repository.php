<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CVC_Jobs_Repository
{
    private const CACHE_GROUP = 'cvc_jobs';

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cvc_jobs';
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function create(array $fields): int
    {
        global $wpdb;
        $wpdb->insert($this->table, $fields);

        $this->flush_list_cache();

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        global $wpdb;
        $wpdb->update($this->table, $fields, ['id' => $id]);

        wp_cache_delete('job_' . $id, self::CACHE_GROUP);
        $this->flush_list_cache();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $cache_key = 'job_' . $id;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached ?: null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table, $id),
            ARRAY_A
        );

        wp_cache_set($cache_key, $row ?: '', self::CACHE_GROUP);

        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $cached = wp_cache_get('all', self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i ORDER BY created_at DESC', $this->table),
            ARRAY_A
        );

        wp_cache_set('all', $rows, self::CACHE_GROUP);

        return $rows;
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete($this->table, ['id' => $id]);

        wp_cache_delete('job_' . $id, self::CACHE_GROUP);
        $this->flush_list_cache();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_active(): ?array
    {
        $cached = wp_cache_get('active', self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached ?: null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE status IN ('uploading', 'processing') ORDER BY created_at DESC LIMIT 1",
                $this->table
            ),
            ARRAY_A
        );

        wp_cache_set('active', $row ?: '', self::CACHE_GROUP);

        return $row ?: null;
    }

    private function flush_list_cache(): void
    {
        wp_cache_delete('all', self::CACHE_GROUP);
        wp_cache_delete('active', self::CACHE_GROUP);
    }
}
