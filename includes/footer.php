<?php
/**
 * Feature C: Footer Copyright.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin page for the footer feature (info only).
 */
function viirl_roadrunner_footer_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Footer Copyright</h1>
        <p>This feature outputs a simple, consistent footer line via shortcode.</p>

        <h3>Shortcode</h3>
        <p><code>[viirl_footer]</code></p>

        <h3>Output</h3>
        <p><?php echo esc_html( viirl_footer_shortcode() ); ?></p>

        <p>It uses:</p>
        <ul style="list-style:disc;padding-left:20px;">
            <li>Your site name (<em>Settings → General → Site Title</em>)</li>
            <li>Current year</li>
            <li>Your Privacy Policy page (if set in <em>Settings → Privacy</em>)</li>
            <li><code>/terms-and-conditions</code> for Terms</li>
            <li>A “Website by VIIRL” credit</li>
        </ul>
    </div>
    <?php
}

/**
 * [viirl_footer] shortcode.
 */
function viirl_footer_shortcode() {
    $site_name = get_bloginfo( 'name' );
    $home_url  = home_url( '/' );
    $year      = date( 'Y' );
    $privacy   = get_permalink( get_option( 'wp_page_for_privacy_policy' ) );
    $terms     = home_url( '/terms-and-conditions' );

    return sprintf(
        '<a href="%s">%s</a> © %s | <a href="%s">Privacy Policy</a> | <a href="%s">Terms and Conditions</a> | Website by <a href="https://viirl.com" target="_blank" rel="noopener">VIIRL</a>',
        esc_url( $home_url ),
        esc_html( $site_name ),
        esc_html( $year ),
        esc_url( $privacy ),
        esc_url( $terms )
    );
}
add_shortcode( 'viirl_footer', 'viirl_footer_shortcode' );

