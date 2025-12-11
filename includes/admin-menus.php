<?php
/**
 * Admin menus + overview page for VIIRL Roadrunner.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register top-level and feature submenus.
 */
function viirl_roadrunner_register_menus() {
    // Top-level (overview)
    add_menu_page(
        'VIIRL Roadrunner',                  // Page title
        'VIIRL Roadrunner',                  // Top-level menu label
        'manage_options',
        'viirl-roadrunner',
        'viirl_roadrunner_overview_page',
        VIIRL_RR_URL . 'assets/VIIRL-icon.svg'
    );

    // Submenu: Dashboard (points to the same overview page)
    add_submenu_page(
        'viirl-roadrunner',                  // Parent slug
        'Dashboard',                         // Page title
        'Dashboard',                         // Submenu label
        'manage_options',
        'viirl-roadrunner',                  // Same slug as parent
        'viirl_roadrunner_overview_page'
    );

    // Submenu: Global Phone Number
    add_submenu_page(
        'viirl-roadrunner',
        'Global Phone Number',
        'Global Phone Number',
        'manage_options',
        'viirl-roadrunner-phone',
        'viirl_roadrunner_phone_page'
    );

    // Submenu: Google Ratings Badge
    add_submenu_page(
        'viirl-roadrunner',
        'Google Ratings Badge',
        'Google Ratings Badge',
        'manage_options',
        'viirl-roadrunner-google-ratings',
        'viirl_rr_gr_settings_page'
    );

    // Submenu: Footer Copyright
    add_submenu_page(
        'viirl-roadrunner',
        'Footer Copyright',
        'Footer Copyright',
        'manage_options',
        'viirl-roadrunner-footer',
        'viirl_roadrunner_footer_page'
    );
}
add_action( 'admin_menu', 'viirl_roadrunner_register_menus' );

/**
 * Tweak the icon size in the admin menu.
 */
add_action( 'admin_head', function () {
    echo '<style>
      #adminmenu .toplevel_page_viirl-roadrunner .wp-menu-image img {
        width:20px!important;
        height:20px!important;
        margin:6px 0!important;
        padding:0!important;
        object-fit:contain;
      }
    </style>';
} );

/**
 * Overview page content.
 */
function viirl_roadrunner_overview_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>VIIRL Roadrunner</h1>
        <p>Roadrunner bundles a few handy tools for the VIIRL dev team.</p>

        <h2>Shortcodes</h2>
        <ul style="list-style:disc;padding-left:20px;">
            <li><code>[viirl_phone]</code> – Outputs the saved phone number as text.</li>
            <li><code>[viirl_phone link="true"]</code> – Outputs the number as a clickable <code>tel:</code> link.</li>
            <li><code>[viirl_phone_tel]</code> – Outputs only the <code>tel:XXXXXXXXXX</code> value (useful for Elementor Link fields).</li>
            <li><code>[viirl_google_rating]</code> – Renders the Google Ratings Badge (configure Place ID &amp; Site Secret under Google Ratings Badge).</li>
            <li><code>[viirl_footer]</code> – Prints a simple footer: “Site © Year | Privacy | Terms | Website by VIIRL”.</li>
        </ul>

        <h2>Where to configure</h2>
        <ol>
            <li><strong>Global Phone Number</strong> – set the site’s phone number once, then use the shortcodes anywhere.</li>
            <li><strong>Google Ratings Badge</strong> – connect to VIIRL proxy, set Google Place ID, choose style/colors, then drop the shortcode where you want.</li>
            <li><strong>Footer Copyright</strong> – explains how the footer shortcode is generated (no settings required).</li>
        </ol>
    </div>
    <?php
}

