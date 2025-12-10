<?php
/**
 * Plugin Name: VIIRL Roadrunner
 * Description: VIIRL utilities: Global Phone Number + Google Ratings Badge + Footer Copyright.
 * Version: 2.1.3
 * Author: Shelby Gonzales
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin path/url helpers.
define( 'VIIRL_RR_PATH', plugin_dir_path( __FILE__ ) );
define( 'VIIRL_RR_URL',  plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------
// GitHub auto-updates (using plugin-update-checker).
// ---------------------------------------------------------
require_once VIIRL_RR_PATH . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';

add_action( 'init', function () {
    // If the library didn't load for some reason, don't fatal.
    if ( ! class_exists( 'Puc_v4_Factory' ) ) {
        return;
    }

    $viirl_rr_update_checker = Puc_v4_Factory::buildUpdateChecker(
        'https://github.com/Viirl/viirl-roadrunner', // GitHub repo URL (no .git)
        __FILE__,                                    // Full path to main plugin file
        'viirl-roadrunner'                           // Plugin slug
    );

    // If your default branch is "main", set it explicitly:
    $viirl_rr_update_checker->setBranch( 'main' );

    // SIMPLE VERSION
    $viirl_rr_update_checker->setAuthentication(
        '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'
    );
    
    // Disable silent auto updates
    $viirl_rr_update_checker->setAutomaticUpdates(false);

    // BETTER VERSION (optional): use a constant from wp-config.php instead
    // if ( defined( 'VIIRL_RR_GITHUB_TOKEN' ) && VIIRL_RR_GITHUB_TOKEN ) {
    //     $viirl_rr_update_checker->setAuthentication( VIIRL_RR_GITHUB_TOKEN );
    // }
} );

// Core + features.
require_once VIIRL_RR_PATH . 'includes/class-vr-core.php';
require_once VIIRL_RR_PATH . 'includes/admin-menus.php';
require_once VIIRL_RR_PATH . 'includes/phone.php';
require_once VIIRL_RR_PATH . 'includes/google-ratings.php';
require_once VIIRL_RR_PATH . 'includes/footer.php';

// Optional central bootstrap (can stay empty for now).
add_action( 'plugins_loaded', [ 'VR_Core', 'init' ] );

