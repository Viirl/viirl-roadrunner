<?php
/**
 * Feature: Global Content Variables
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * [viirl_site_name] shortcode
 */
function viirl_site_name_shortcode() {
    $name = get_bloginfo( 'name' );
    return $name ? esc_html( $name ) : '[Site name not set]';
}
add_shortcode( 'viirl_site_name', 'viirl_site_name_shortcode' );

/**
 * [viirl_home_url] shortcode
 */
function viirl_home_url_shortcode() {
    $url = home_url();
    return $url ? esc_url( $url ) : '[Site URL not set]';
}
add_shortcode( 'viirl_home_url', 'viirl_home_url_shortcode' );

/**
 * Admin page for Global Content Variables
 */
function viirl_roadrunner_global_content_variables_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $site_name = get_bloginfo( 'name' );
    $home_url  = home_url();
    ?>
    <div class="wrap">
        <h1>Global Content Variables</h1>

        <p>
            Reuse important site information across your content using shortcodes. These values come from your WordPress settings
            and help keep legal pages, templates, and boilerplate content consistent.
        </p>

        <h3>Shortcodes</h3>
        <ul style="list-style:disc;padding-left:20px;">
            <li><code>[viirl_site_name]</code> – Outputs the site name</li>
            <li><code>[viirl_home_url]</code> – Outputs the website URL</li>
        </ul>

        <h3>Current Values</h3>
        <ul style="list-style:disc;padding-left:20px;">
            <li><strong>Site Name:</strong> <?php echo esc_html( $site_name ); ?></li>
            <li><strong>Website URL:</strong> <?php echo esc_html( $home_url ); ?></li>
        </ul>

        <p style="margin-top:20px;">
            <em>These values are managed in WordPress settings. Roadrunner provides a way to reuse them dynamically across your site.</em>
        </p>
    </div>
    <?php
}