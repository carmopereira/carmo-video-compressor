<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CVC_Admin
{
    private string $page_hook = '';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_page(): void
    {
        $this->page_hook = add_submenu_page(
            'tools.php',
            __('Video Compressor', 'carmo-video-compressor'),
            __('Video Compressor', 'carmo-video-compressor'),
            'manage_options',
            'carmo-video-compressor',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Não tens permissões para aceder a esta página.', 'carmo-video-compressor'));
        }

        echo '<div class="wrap"><h1>' . esc_html__('Video Compressor', 'carmo-video-compressor') . '</h1><div id="cvc-app"></div></div>';
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== $this->page_hook) {
            return;
        }

        $asset_file = CVC_PLUGIN_DIR . 'build/index.asset.php';
        if (!file_exists($asset_file)) {
            return;
        }

        $asset = include $asset_file;

        wp_enqueue_script(
            'cvc-admin',
            CVC_PLUGIN_URL . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        if (file_exists(CVC_PLUGIN_DIR . 'build/index.css')) {
            wp_enqueue_style('cvc-admin', CVC_PLUGIN_URL . 'build/index.css', [], $asset['version']);
        }

        wp_localize_script('cvc-admin', 'cvcSettings', [
            'restUrl' => esc_url_raw(rest_url('carmo-video-compressor/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}
