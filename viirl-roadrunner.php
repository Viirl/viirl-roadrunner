<?php
/**
 * Plugin Name: VIIRL Roadrunner
 * Description: VIIRL Utilities: Global Phone Number + Global Content Variables (reusable site info shortcodes) + Google Ratings Badge + Link Scanner + Footer Copyright + Page/Post Duplicator.
 * Version: 2.2.2
 * Author: Shelby Gonzales
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin path/url helpers.
define( 'VIIRL_RR_PATH', plugin_dir_path( __FILE__ ) );
define( 'VIIRL_RR_URL',  plugin_dir_url( __FILE__ ) );

// Force Roadrunner to use manual updates only.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
    if ( empty( $item->plugin ) ) {
        return $update;
    }
    if ( $item->plugin === plugin_basename( __FILE__ ) ) {
        return false;
    }
    return $update;
}, 999, 2 );

// ---------------------------------------------------------
// GitHub auto-updates (using plugin-update-checker v5).
// Public repo version: no authentication token needed.
// ---------------------------------------------------------
$puchecker = VIIRL_RR_PATH . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $puchecker ) ) {
    require_once $puchecker;
} else {
    // Don’t fatal the whole site if the vendor folder didn’t ship in the update.
    add_action( 'admin_notices', function () use ( $puchecker ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>VIIRL Roadrunner:</strong> Update checker library missing. Expected: <code>' .
            esc_html( str_replace( ABSPATH, '/', $puchecker ) ) .
            '</code>. Please reinstall the plugin or redeploy the release package.</p></div>';
    } );
}

// Initialize update checker immediately, but only if the library loaded.
// This keeps the "don’t fatal" behavior while making update checks more reliable.
if ( class_exists( PucFactory::class ) ) {
    $updateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/Viirl/viirl-roadrunner/',
        __FILE__,
        'viirl-roadrunner'
    );

    // If your default branch is "main", set it explicitly.
    $updateChecker->setBranch( 'main' );
}

// Core + features.
require_once VIIRL_RR_PATH . 'includes/class-vr-core.php';
require_once VIIRL_RR_PATH . 'includes/admin-menus.php';
require_once VIIRL_RR_PATH . 'includes/phone.php';
require_once VIIRL_RR_PATH . 'includes/google-ratings.php';
require_once VIIRL_RR_PATH . 'includes/footer.php';
require_once VIIRL_RR_PATH . 'includes/link-checker.php';
require_once VIIRL_RR_PATH . 'includes/duplicate-page.php';
require_once VIIRL_RR_PATH . 'includes/global-content-variables.php';

// Optional central bootstrap.
add_action( 'plugins_loaded', [ 'VR_Core', 'init' ] );