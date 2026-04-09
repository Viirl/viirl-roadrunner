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
        'VIIRL Roadrunner',
        'VIIRL Roadrunner',
        'manage_options',
        'viirl-roadrunner',
        'viirl_roadrunner_overview_page',
        VIIRL_RR_URL . 'assets/VIIRL-icon.svg'
    );

    // Submenu: Dashboard
    add_submenu_page(
        'viirl-roadrunner',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'viirl-roadrunner',
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
    
    // Submenu: Tel Link Cleaner
    add_submenu_page(
	'viirl-roadrunner',
	'Tel Link Cleaner',
	'Tel Link Cleaner',
	'manage_options',
	'viirl-rr-tel-link-cleaner',
	'viirl_rr_render_tel_link_cleaner_page'
    );

    // Submenu: Global Content Variables
    add_submenu_page(
        'viirl-roadrunner',
        'Global Content Variables',
        'Global Content Variables',
        'manage_options',
        'viirl-roadrunner-global-content-variables',
        'viirl_roadrunner_global_content_variables_page'
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

    // Submenu: Button & Link Checker
    add_submenu_page(
        'viirl-roadrunner',
        'Button & Link Checker',
        'Button & Link Checker',
        'manage_options',
        'viirl-roadrunner-link-checker',
        'viirl_rr_link_checker_page'
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

// Prefix all Roadrunner admin page titles with "VIIRL Roadrunner ›".
add_filter( 'admin_title', function ( $admin_title, $title ) {
    // Only modify Roadrunner plugin pages
    if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'viirl' ) !== false ) {
        return 'VIIRL Roadrunner › ' . $title;
    }

    return $admin_title;
}, 10, 2 );

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
        <p>Roadrunner bundles reusable tools and shortcodes for common VIIRL site tasks.</p>

        <h2>Features</h2>
            <ol>
                <li>
                    <strong>Global Phone Number</strong> – set the site’s phone number once, then use the shortcodes anywhere.
                </li>
                <li>
                    <strong>Tel Link Cleaner</strong> – scans saved site content for malformed <code>tel:</code> links, cleans them to a digits-only format, and shows a list of affected numbers and content after the scan runs.
                </li>
                <li>
                    <strong>Global Content Variables</strong> – reuse WordPress site information like the site name and website URL across legal pages, templates, and other boilerplate content via shortcodes.
                </li>
                <li>
                    <strong>Google Ratings Badge</strong> – connect to the VIIRL proxy, set a Google Place ID, choose style/colors, then drop the shortcode where you want.
                </li>
                <li>
                    <strong>Footer Copyright</strong> – outputs a consistent footer line via shortcode (no extra settings required).
                </li>
                <li>
                    <strong>Button &amp; Link Checker</strong> – scans this site for placeholder buttons and links
                    (like <code>#</code>, <code>/</code>, or <code>javascript:void(0)</code>), malformed links that do not function, or all links that exist on the site. Scans pages, Elementor templates,
                    menus, and common widget areas with an option to export as a CSV. Does not scan for 404s.
                </li>
                <li>
                    <strong>Page &amp; Post Duplicator</strong> – adds a "VIIRL duplicate" option on pages and posts to duplicate an exact copy into a draft.
                </li>
            </ol>

        <p style="max-width: 720px; background: #fffbe5; border-left: 4px solid #dba617; padding: 12px 16px; margin-top: 24px;">
            <strong>Heads up:</strong> If the VIIRL Roadrunner plugin is deactivated or removed, any place that uses
            these shortcodes will stop working as expected. <strong>Phone number shortcodes</strong> will show the raw shortcode text
            instead of a number or link, <strong>Global Content Variables shortcodes</strong> will show the raw shortcode text instead
            of the site information, <strong>footer shortcodes</strong> will show the raw shortcode text instead of the formatted footer line,
            and the <strong>Google rating shortcode</strong> will show the raw shortcode text instead of the Google Ratings Badge widget.
            If planning to deactivate or uninstall this plugin for any reason, keep in mind that you’ll need to manually replace those
            shortcodes and find a replacement for the Google Ratings Badge widget.
        </p>

        <h2 style="margin-top:32px;">Short Codes Quick Reference</h2>
        <ul style="list-style:disc;padding-left:20px;">
            <li><code>[viirl_phone]</code> – Outputs the saved phone number as text.</li>
            <li><code>[viirl_phone link="true"]</code> – Outputs the number as a clickable <code>tel:</code> link.</li>
            <li><code>[viirl_phone_tel]</code> – Outputs only the <code>tel:XXXXXXXXXX</code> value (useful for Elementor Link fields).</li>
            <li><code>[viirl_google_rating]</code> – Renders the Google Ratings Badge (configure Place ID &amp; Site Secret under Google Ratings Badge).</li>
            <li><code>[viirl_footer]</code> – Prints a simple footer: “Site © Year | Privacy | Terms and Conditions | Website by VIIRL”.</li>
            <li><code>[viirl_site_name]</code> – Outputs the site name as set in WordPress General settings.</li>
            <li><code>[viirl_home_url]</code> – Outputs the website URL.</li>

        </ul>
    </div>
    <?php
}