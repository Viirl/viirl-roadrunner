<?php
/**
 * Feature A: Global Phone Number.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin page for the Global Phone Number settings.
 */
function viirl_roadrunner_phone_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ( isset( $_POST['viirl_phone_number'] ) || isset( $_POST['viirl_clear_phone'] ) )
         && check_admin_referer( 'viirl_roadrunner_phone_save', 'viirl_roadrunner_phone_nonce' ) ) {

        if ( isset( $_POST['viirl_clear_phone'] ) ) {
            delete_option( 'viirl_phone_number' );
            echo '<div class="updated"><p>Phone number cleared.</p></div>';
        } elseif ( isset( $_POST['viirl_phone_number'] ) ) {
            $input       = sanitize_text_field( wp_unslash( $_POST['viirl_phone_number'] ) );
            $digits_only = preg_replace( '/[^0-9]/', '', $input );

            if ( preg_match( '/^\d{10}$/', $digits_only ) ) {
                update_option( 'viirl_phone_number', $input );
                echo '<div class="updated"><p>Phone number saved.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Please enter a valid 10-digit phone number (letters are not allowed).</p></div>';
            }
        }
    }

    $phone_number    = get_option( 'viirl_phone_number', '' );
    $formatted_phone = format_viirl_phone_number( $phone_number );
    ?>
    <div class="wrap">
        <h1>Global Phone Number</h1>
        <p>Save a single phone number and reuse it everywhere via shortcodes.</p>

        <h3>Shortcodes</h3>
        <ul style="list-style:disc;padding-left:20px;">
            <li><code>[viirl_phone]</code> – plain text</li>
            <li><code>[viirl_phone link="true"]</code> – clickable <code>tel:</code> link</li>
            <li><code>[viirl_phone_tel]</code> – outputs just the <code>tel:</code> link value</li>
        </ul>

        <?php if ( ! empty( $phone_number ) ) : ?>
            <p><strong>Current:</strong> <?php echo esc_html( $formatted_phone ); ?></p>
        <?php else : ?>
            <p><em>No phone number saved yet.</em></p>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'viirl_roadrunner_phone_save', 'viirl_roadrunner_phone_nonce' ); ?>
            <label for="viirl_phone_number">Phone Number:</label><br />
            <input type="text" name="viirl_phone_number" id="viirl_phone_number" value="" style="width:300px;" />
            <p class="submit">
                <input type="submit" class="button button-primary" value="Save Phone Number" />
                <button name="viirl_clear_phone" value="1" class="button">Clear</button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Format a 10-digit US number as (XXX) XXX-XXXX.
 */
function format_viirl_phone_number( $number ) {
    $digits = preg_replace( '/[^0-9]/', '', $number );
    if ( strlen( $digits ) === 10 ) {
        return '(' . substr( $digits, 0, 3 ) . ') ' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
    }
    return $number;
}

/**
 * [viirl_phone] shortcode.
 */
function viirl_phone_shortcode( $atts ) {
    $atts      = shortcode_atts( [ 'link' => 'false' ], $atts, 'viirl_phone' );
    $phone     = get_option( 'viirl_phone_number', '' );
    $digits    = preg_replace( '/[^0-9]/', '', $phone );
    $formatted = format_viirl_phone_number( $phone );

    if ( ! $digits ) {
        return '<span class="viirl-phone-number">[Phone number not set]</span>';
    }

    if ( strtolower( $atts['link'] ) === 'true' ) {
        return '<a href="tel:' . esc_attr( $digits ) . '" class="viirl-phone-link" data-number="' . esc_attr( $digits ) . '">' . esc_html( $formatted ) . '</a>';
    }

    return '<span class="viirl-phone-number" data-number="' . esc_attr( $digits ) . '">' . esc_html( $formatted ) . '</span>';
}
add_shortcode( 'viirl_phone', 'viirl_phone_shortcode' );

/**
 * [viirl_phone_tel] shortcode – outputs tel:XXXXXXXXXX.
 */
function viirl_phone_tel_shortcode() {
    $digits = preg_replace( '/[^0-9]/', '', get_option( 'viirl_phone_number', '' ) );
    return $digits ? 'tel:' . esc_attr( $digits ) : '';
}
add_shortcode( 'viirl_phone_tel', 'viirl_phone_tel_shortcode' );

