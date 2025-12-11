<?php
/**
 * Feature B: Google Ratings Badge (proxy-based).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Cache TTL & proxy URL.
define( 'VIIRL_RR_GR_TTL',       12 * HOUR_IN_SECONDS );
define( 'VIIRL_RR_GR_PROXY_URL', 'https://devapi.viirl.pro/gplaces-proxy.php' );

// Register settings.
add_action( 'admin_init', function () {
    // Default site ID: strip protocol from site_url().
    $default_site_id = preg_replace( '~^https?://~i', '', site_url() );

    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_place_id',
        [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]
    );

    // Site ID: always stored as bare domain (no http/https, no trailing slash).
    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_site_id',
        [
            'sanitize_callback' => 'viirl_rr_gr_sanitize_site_id',
            'default'           => $default_site_id,
        ]
    );

    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_site_secret',
        [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]
    );
    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_bg',
        [
            'sanitize_callback' => 'viirl_rr_gr_sanitize_color',
            'default'           => '#ffffff',
        ]
    );
    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_radius',
        [
            'sanitize_callback' => 'viirl_rr_gr_sanitize_int',
            'default'           => 16,
        ]
    );
    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_style',
        [
            'sanitize_callback' => 'viirl_rr_gr_sanitize_style',
            'default'           => 'left',
        ]
    );
    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_text',
        [
            'sanitize_callback' => 'viirl_rr_gr_sanitize_color',
            'default'           => '#2c3440',
        ]
    );
    register_setting(
        'viirl_rr_gr',
        'viirl_rr_gr_bg_opacity',
        [
            'sanitize_callback' => 'viirl_rr_gr_sanitize_opacity',
            'default'           => 100,
        ]
    );
} );

// Clear cache when settings change.
add_action( 'update_option_viirl_rr_gr_place_id', function ( $old, $new ) {
    if ( $old ) {
        delete_transient( 'viirl_rr_gr_' . md5( $old ) );
    }
    if ( $new ) {
        delete_transient( 'viirl_rr_gr_' . md5( $new ) );
    }
}, 10, 2 );

add_action( 'update_option_viirl_rr_gr_site_id', function () {
    $pid = get_option( 'viirl_rr_gr_place_id' );
    if ( $pid ) {
        delete_transient( 'viirl_rr_gr_' . md5( $pid ) );
    }
} );

add_action( 'update_option_viirl_rr_gr_site_secret', function () {
    $pid = get_option( 'viirl_rr_gr_place_id' );
    if ( $pid ) {
        delete_transient( 'viirl_rr_gr_' . md5( $pid ) );
    }
} );

// Sanitizers.
function viirl_rr_gr_sanitize_color( $v ) {
    $v = trim( $v );
    return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $v ) ? $v : '#ffffff';
}

function viirl_rr_gr_sanitize_int( $v ) {
    return max( 0, intval( $v ) );
}

function viirl_rr_gr_sanitize_style( $v ) {
    return in_array( $v, [ 'left', 'right' ], true ) ? $v : 'left';
}

function viirl_rr_gr_sanitize_opacity( $val ) {
    $n = is_numeric( $val ) ? (int) $val : 100;
    return max( 0, min( 100, $n ) );
}

/**
 * Sanitize Site ID:
 * - strip http/https
 * - trim slashes/whitespace
 * - lowercase
 */
function viirl_rr_gr_sanitize_site_id( $value ) {
    $value = sanitize_text_field( $value );
    // Remove protocol if present.
    $value = preg_replace( '~^https?://~i', '', $value );
    // Remove trailing slashes and backslashes.
    $value = rtrim( $value, "/\\" );
    // Normalize to lowercase.
    return strtolower( $value );
}

// Tiny probe endpoint for enrollment.
add_action( 'init', function () {
    if ( isset( $_GET['viirl_rr_probe'], $_GET['nonce'] ) ) {
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
        exit;
    }
} );

// Hex to RGB helper.
function viirl_rr_gr_hex_to_rgb( $hex ) {
    $hex = ltrim( trim( $hex ), '#' );

    if ( strlen( $hex ) === 3 ) {
        $r = hexdec( str_repeat( $hex[0], 2 ) );
        $g = hexdec( str_repeat( $hex[1], 2 ) );
        $b = hexdec( str_repeat( $hex[2], 2 ) );
        return [ $r, $g, $b ];
    }

    if ( strlen( $hex ) === 6 ) {
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        return [ $r, $g, $b ];
    }

    return [ 255, 255, 255 ];
}

// Secret rotation action.
add_action( 'admin_init', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_GET['viirl_rr_gr_rotate_secret'], $_GET['_wpnonce'] ) ) {
        return;
    }
    if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'viirl_rr_gr_rotate' ) ) {
        return;
    }

    // Site ID is stored as bare domain already, but be defensive and strip protocol again.
    $site_id    = preg_replace( '~^https?://~i', '', trim( get_option( 'viirl_rr_gr_site_id', site_url() ) ) );
    $site_id    = strtolower( $site_id );
    $old_secret = trim( get_option( 'viirl_rr_gr_site_secret', '' ) );

    if ( '' === $old_secret ) {
        viirl_rr_gr_enroll_if_needed();
        $old_secret = trim( get_option( 'viirl_rr_gr_site_secret', '' ) );
    }

    if ( '' !== $old_secret ) {
        $sig_old = hash_hmac( 'sha256', $site_id . '|rotate', $old_secret );
        $resp    = wp_remote_get(
            add_query_arg(
                [
                    'action'  => 'rotate',
                    'site_id' => rawurlencode( $site_id ),
                    'sig_old' => $sig_old,
                ],
                VIIRL_RR_GR_PROXY_URL
            ),
            [ 'timeout' => 12 ]
        );

        if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $body['site_secret'] ) ) {
                update_option( 'viirl_rr_gr_site_secret', sanitize_text_field( $body['site_secret'] ) );
                $pid = get_option( 'viirl_rr_gr_place_id' );
                if ( $pid ) {
                    delete_transient( 'viirl_rr_gr_' . md5( $pid ) );
                }
                update_option( 'viirl_rr_gr_secret_rotated_at', time() );
            }
        }
    }

    wp_safe_redirect( remove_query_arg( [ 'viirl_rr_gr_rotate_secret', '_wpnonce' ] ) );
    exit;
} );

/**
 * Settings page (submenu).
 */
function viirl_rr_gr_settings_page() { ?>
    <div class="wrap">
        <h1>Google Ratings Badge</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'viirl_rr_gr' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_place_id">Place ID</label></th>
                    <td>
                        <input name="viirl_rr_gr_place_id" id="viirl_rr_gr_place_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_place_id', '' ) ); ?>">
                        <p class="description">
                            Use Google’s Place ID Finder → <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener">Open tool ↗</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_site_id">Site ID</label></th>
                    <td>
                        <?php $default_site_id = preg_replace( '~^https?://~i', '', site_url() ); ?>
                        <input name="viirl_rr_gr_site_id" id="viirl_rr_gr_site_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_site_id', $default_site_id ) ); ?>">
                        <p class="description">Bare domain only (e.g., <code>example.com</code>) — no <code>https://</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_site_secret">Site Secret</label></th>
                    <td>
                        <input name="viirl_rr_gr_site_secret" id="viirl_rr_gr_site_secret" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_site_secret', '' ) ); ?>">
                        <p class="description">First save can be blank — the plugin will auto-enroll with the VIIRL proxy.</p>
                        <p>
                            <a class="button button-secondary"
                               href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'viirl_rr_gr_rotate_secret', '1' ), 'viirl_rr_gr_rotate' ) ); ?>"
                               onclick="return confirm('Rotate the Site Secret? New requests will use the new secret immediately.');">
                                Rotate Site Secret
                            </a>
                            <?php
                            $rot = (int) get_option( 'viirl_rr_gr_secret_rotated_at', 0 );
                            if ( $rot ) {
                                $when = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rot );
                                echo '<span style="margin-left:8px;opacity:.7;">Last rotated: ' . esc_html( $when ) . '</span>';
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_bg">Background color</label></th>
                    <td><input name="viirl_rr_gr_bg" id="viirl_rr_gr_bg" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_bg', '#ffffff' ) ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_bg_opacity">Background opacity (%)</label></th>
                    <td>
                        <input name="viirl_rr_gr_bg_opacity" id="viirl_rr_gr_bg_opacity" type="number" class="small-text" min="0" max="100" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_bg_opacity', 100 ) ); ?>">
                        <p class="description">0 = transparent, 100 = fully opaque.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_text">Text color</label></th>
                    <td><input name="viirl_rr_gr_text" id="viirl_rr_gr_text" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_text', '#2c3440' ) ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_radius">Border radius (px)</label></th>
                    <td><input name="viirl_rr_gr_radius" id="viirl_rr_gr_radius" type="number" min="0" class="small-text" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_radius', 16 ) ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="viirl_rr_gr_style">Style</label></th>
                    <td>
                        <?php $cur = get_option( 'viirl_rr_gr_style', 'left' ); ?>
                        <select name="viirl_rr_gr_style" id="viirl_rr_gr_style">
                            <option value="left"  <?php selected( $cur, 'left' ); ?>>Left card (logo above stars)</option>
                            <option value="right" <?php selected( $cur, 'right' ); ?>>Right card (logo left, text right)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Ratings Settings' ); ?>
        </form>

        <?php
        $pid = get_option( 'viirl_rr_gr_place_id', '' );
        if ( $pid ) {
            $preview = viirl_rr_gr_fetch( $pid );
            echo '<h2 style="margin-top:24px;">Preview &amp; status</h2>';
            if ( is_array( $preview ) && empty( $preview['_error'] ) ) {
                echo '<div style="margin:10px 0;">' . viirl_rr_gr_render() . '</div>';
                echo '<div style="font-size:12px;opacity:.7;margin-top:6px;">Source: Google Maps</div>';
                if ( ! empty( $preview['fetched_at'] ) ) {
                    $t    = (int) $preview['fetched_at'];
                    $ago  = human_time_diff( $t, current_time( 'timestamp' ) );
                    $when = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $t );
                    echo '<div style="font-size:12px;opacity:.7;margin-top:4px;">Last updated ' . esc_html( $ago ) . ' ago (' . esc_html( $when ) . '). Cached up to 12 hours.</div>';
                }
            } else {
                $msg = is_array( $preview ) && ! empty( $preview['_error'] ) ? $preview['_error'] : 'No data yet.';
                echo '<p style="color:#a00"><em>' . esc_html( $msg ) . '</em></p>';
            }
        }
        ?>

        <h2>Shortcode</h2>
        <p>Use <code>[viirl_google_rating]</code> anywhere.</p>
    </div>
<?php }
