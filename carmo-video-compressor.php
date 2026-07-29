<?php
/**
 * Plugin Name: Carmo Video Compressor
 * Description: Compress videos on the server with a fixed ffmpeg (libx264/CRF 28) pipeline, via a drag-and-drop admin tool under Tools.
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: carmopereira
 * Author URI: https://github.com/carmopereira
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Version:           1.0.9
 * Text Domain: carmo-video-compressor
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CVC_PLUGIN_FILE', __FILE__);
define('CVC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CVC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CVC_PLUGIN_DIR . 'includes/class-cvc-activator.php';
require_once CVC_PLUGIN_DIR . 'includes/class-cvc-jobs-repository.php';
require_once CVC_PLUGIN_DIR . 'includes/class-cvc-compressor.php';
require_once CVC_PLUGIN_DIR . 'includes/class-cvc-rest-controller.php';
require_once CVC_PLUGIN_DIR . 'includes/class-cvc-admin.php';

/**
 * Base directory for all files this plugin creates, inside the uploads folder.
 * Not a constant: depends on wp_upload_dir(), which can vary per site (multisite).
 */
function cvc_upload_base_dir(): string
{
    $upload_dir = wp_upload_dir();

    return trailingslashit($upload_dir['basedir']) . 'carmo-video-compressor';
}

register_activation_hook(CVC_PLUGIN_FILE, ['CVC_Activator', 'activate']);

add_action('plugins_loaded', static function (): void {
    new CVC_Admin();
    new CVC_Rest_Controller();
});
