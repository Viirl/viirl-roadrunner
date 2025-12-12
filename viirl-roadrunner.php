<?php
/**
 * Plugin Name: VIIRL Roadrunner
 * Description: VIIRL utilities: Global Phone Number + Google Ratings Badge + Footer Copyright.
 * Version: 2.1.6
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
// ---------------------------------------------------------
require_once VIIRL_RR_PATH . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';

add_action( 'init', function () {
    // If the library didn't load for some reason, don't fatal.
    if ( ! class_exists( PucFactory::class ) ) {
        return;
    }

    $updateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/Viirl/viirl-roadrunner/', // GitHub repo URL (no .git)
        __FILE__,                                     // Full path to main plugin file
        'viirl-roadrunner'                            // Plugin slug
    );

    // If your default branch is "main", set it explicitly:
    $updateChecker->setBranch( 'main' );

    // Private repo authentication.
    $updateChecker->setAuthentication(
        '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'
    );

    // BETTER VERSION (optional): use a constant from wp-config.php instead
    // if ( defined( 'VIIRL_RR_GITHUB_TOKEN' ) && VIIRL_RR_GITHUB_TOKEN ) {
    //     $updateChecker->setAuthentication( VIIRL_RR_GITHUB_TOKEN );
    // }
} );

// Core + features.
require_once VIIRL_RR_PATH . 'includes/class-vr-core.php';
require_once VIIRL_RR_PATH . 'includes/admin-menus.php';
require_once VIIRL_RR_PATH . 'includes/phone.php';
require_once VIIRL_RR_PATH . 'includes/google-ratings.php';
require_once VIIRL_RR_PATH . 'includes/footer.php';
require_once VIIRL_RR_PATH . 'includes/link-checker.php';

// Optional central bootstrap (can stay empty for now).
add_action( 'plugins_loaded', [ 'VR_Core', 'init' ] );
