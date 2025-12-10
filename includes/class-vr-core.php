<?php
/**
 * Core loader for VIIRL Roadrunner.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VR_Core {

    /**
     * Boot the core functionality.
     * Right now this is a placeholder; you can centralize hooks here later.
     */
    public static function init() {
        // Example future use:
        // add_action( 'init', [ __CLASS__, 'register_assets' ] );
        // add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    // public static function register_assets() {
    //     // Enqueue shared scripts/styles for Roadrunner features.
    // }

    // public static function register_settings() {
    //     // Centralized settings if you refactor later.
    // }
}

