<?php
/**
 * Plugin Name: VIIRL Roadrunner
 * Description: VIIRL utilities: Global Phone Number + Google Ratings Badge + Footer Copyright.
 * Version: 2.1.0
 * Author: Shelby Gonzales
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin path/url helpers.
define( 'VIIRL_RR_PATH', plugin_dir_path( __FILE__ ) );
define( 'VIIRL_RR_URL',  plugin_dir_url( __FILE__ ) );

// Core + features.
require_once VIIRL_RR_PATH . 'includes/class-vr-core.php';
require_once VIIRL_RR_PATH . 'includes/admin-menus.php';
require_once VIIRL_RR_PATH . 'includes/phone.php';
require_once VIIRL_RR_PATH . 'includes/google-ratings.php';
require_once VIIRL_RR_PATH . 'includes/footer.php';

// Optional central bootstrap (can stay empty for now).
add_action( 'plugins_loaded', [ 'VR_Core', 'init' ] );

