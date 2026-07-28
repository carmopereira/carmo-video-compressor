<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CVC_Jobs_Repository
{
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

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        global $wpdb;
        $wpdb->update($this->table, $fields, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        global $wpdb;

        return $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY created_at DESC", ARRAY_A);
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete($this->table, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_active(): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT * FROM {$this->table} WHERE status IN ('uploading', 'processing') ORDER BY created_at DESC LIMIT 1",
            ARRAY_A
        );

        return $row ?: null;
    }
}
