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
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_place_id',    [ 'sanitize_callback' => 'sanitize_text_field',             'default' => '' ] );
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_site_id',     [ 'sanitize_callback' => 'sanitize_text_field',             'default' => site_url() ] );
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_site_secret', [ 'sanitize_callback' => 'sanitize_text_field',             'default' => '' ] );
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_bg',          [ 'sanitize_callback' => 'viirl_rr_gr_sanitize_color',      'default' => '#ffffff' ] );
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_radius',      [ 'sanitize_callback' => 'viirl_rr_gr_sanitize_int',        'default' => 16 ] );
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_style',       [ 'sanitize_callback' => 'viirl_rr_gr_sanitize_style',      'default' => 'left' ] );
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_text',        [ 'sanitize_callback' => 'viirl_rr_gr_sanitize_color',      'default' => '#2c3440' ] );
    register_setting( 'viirl_rr_gr', 'viirl_rr_gr_bg_opacity',  [ 'sanitize_callback' => 'viirl_rr_gr_sanitize_opacity',    'default' => 100 ] );
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
                        <input name="viirl_rr_gr_site_id" id="viirl_rr_gr_site_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'viirl_rr_gr_site_id', site_url() ) ); ?>">
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

/**
 * Enrollment + fetch/sign helpers.
 */
function viirl_rr_gr_enroll_if_needed(): bool {
    if ( get_option( 'viirl_rr_gr_site_secret', '' ) ) {
        return true;
    }

    $site_id = preg_replace( '~^https?://~i', '', trim( get_option( 'viirl_rr_gr_site_id', site_url() ) ) );
    $site_id = strtolower( $site_id );

    // Step 1: nonce.
    $resp = wp_remote_get(
        add_query_arg(
            [
                'action'    => 'enroll',
                'site_id'   => rawurlencode( $site_id ),
                'probe_url' => esc_url_raw( home_url( '/?viirl_rr_probe=1' ) ),
            ],
            VIIRL_RR_GR_PROXY_URL
        ),
        [ 'timeout' => 10 ]
    );
    if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
        return false;
    }
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( empty( $body['nonce'] ) ) {
        return false;
    }

    // Step 2: confirm.
    $resp2 = wp_remote_get(
        add_query_arg(
            [
                'action'    => 'enroll',
                'site_id'   => rawurlencode( $site_id ),
                'probe_url' => esc_url_raw( home_url( '/?viirl_rr_probe=1' ) ),
                'nonce'     => $body['nonce'],
            ],
            VIIRL_RR_GR_PROXY_URL
        ),
        [ 'timeout' => 10 ]
    );
    if ( is_wp_error( $resp2 ) || 200 !== wp_remote_retrieve_response_code( $resp2 ) ) {
        return false;
    }
    $body2 = json_decode( wp_remote_retrieve_body( $resp2 ), true );
    if ( empty( $body2['site_secret'] ) ) {
        return false;
    }

    update_option( 'viirl_rr_gr_site_secret', sanitize_text_field( $body2['site_secret'] ) );
    return true;
}

function viirl_rr_gr_hmac_sig( $site_id, $place_id, $secret ) {
    return hash_hmac( 'sha256', strtolower( $site_id ) . '|' . $place_id, $secret );
}

function viirl_rr_gr_fetch( $place_id ) {
    if ( ! get_option( 'viirl_rr_gr_site_secret', '' ) ) {
        viirl_rr_gr_enroll_if_needed();
    }
    $site_id     = preg_replace( '~^https?://~i', '', trim( get_option( 'viirl_rr_gr_site_id', site_url() ) ) );
    $site_id     = strtolower( $site_id );
    $site_secret = trim( get_option( 'viirl_rr_gr_site_secret', '' ) );

    $t_key = 'viirl_rr_gr_' . md5( $place_id );
    if ( ( $cached = get_transient( $t_key ) ) && is_array( $cached ) ) {
        return $cached;
    }
    if ( ! $site_secret ) {
        return [ '_error' => 'no-site-secret' ];
    }

    $sig = viirl_rr_gr_hmac_sig( $site_id, $place_id, $site_secret );
    $url = add_query_arg(
        [
            'place_id' => $place_id,
            'site_id'  => rawurlencode( $site_id ),
            'sig'      => $sig,
        ],
        VIIRL_RR_GR_PROXY_URL
    );

    $res  = wp_remote_get( $url, [ 'timeout' => 12 ] );
    $code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
    if ( is_wp_error( $res ) || 200 !== $code ) {
        if ( current_user_can( 'manage_options' ) ) {
            $msg = is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res );
            return [ '_error' => "Proxy error (HTTP $code): $msg" ];
        }
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $res ), true );
    if ( ! is_array( $body ) ) {
        return null;
    }

    $data = [
        'rating'     => isset( $body['rating'] ) ? round( (float) $body['rating'], 1 ) : null,
        'count'      => isset( $body['userRatingCount'] ) ? (int) $body['userRatingCount'] : null,
        'url'        => $body['googleMapsUri'] ?? '#',
        'name'       => isset( $body['displayName'] ) ? sanitize_text_field( $body['displayName'] ) : 'Google',
        'fetched_at' => time(),
    ];
    if ( empty( $data['rating'] ) && empty( $data['count'] ) ) {
        return null;
    }

    set_transient( $t_key, $data, VIIRL_RR_GR_TTL );
    return $data;
}

// SVG helpers.
function viirl_rr_gr_google_g_svg() {
    return '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" role="img" style="display:block;flex:0 0 auto;vertical-align:middle">
    <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.6-.2-2.36H12v4.49h6.4a5.49 5.49 0 0 1-2.37 3.6v2.98h3.83c2.24-2.06 3.53-5.1 3.53-8.71z"/>
    <path fill="#34A853" d="M12 24c3.2 0 5.88-1.06 7.84-2.88l-3.83-2.98c-1.06.71-2.42 1.13-4.01 1.13-3.08 0-5.69-2.08-6.62-4.87H1.4v3.06A12 12 0 0 0 12 24z"/>
    <path fill="#FBBC05" d="M5.38 14.4a7.2 7.2 0 0 1 0-4.8V6.54H1.4a12 12 0 0 0 0 10.92l3.98-3.06z"/>
    <path fill="#EA4335" d="M12 4.74c1.74 0 3.3.6 4.53 1.78l3.4-3.4C17.87 1.11 15.2 0 12 0 7.32 0 3.26 2.69 1.4 6.54l3.98 3.06C6.31 6.81 8.92 4.74 12 4.74z"/>
  </svg>';
}

function viirl_rr_gr_star_svg( $fillPct ) {
    $fillPct = max( 0, min( 100, (int) $fillPct ) );
    $filled  = 24 * ( $fillPct / 100.0 );
    $filled  = max( 0, min( 24, $filled ) );

    return '<svg class="viirl-gr-star" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" role="img">
    <defs>
      <linearGradient id="viirl-gr-gold" x1="0%" y1="0%" x2="100%" y2="0%">
        <stop offset="0%" stop-color="#f9b400"/>
        <stop offset="100%" stop-color="#f5a623"/>
      </linearGradient>
      <clipPath id="viirl-gr-clip-' . $fillPct . '"><rect x="0" y="0" width="' . $filled . '" height="24"></rect></clipPath>
    </defs>
    <path fill="#e6e6e6" d="M12 .9l3.5 7 7.7 1.1-5.6 5.4 1.3 7.7L12 19.3 5.1 22.1l1.3-7.7L.8 9l7.7-1.1z"/>
    <path fill="url(#viirl-gr-gold)" clip-path="url(#viirl-gr-clip-' . $fillPct . ')" d="M12 .9l3.5 7 7.7 1.1-5.6 5.4 1.3 7.7L12 19.3 5.1 22.1l1.3-7.7L.8 9l7.7-1.1z"/>
  </svg>';
}

/**
 * Render the ratings badge as HTML.
 */
function viirl_rr_gr_render() {
    $place_id = get_option( 'viirl_rr_gr_place_id', '' );
    $bg       = get_option( 'viirl_rr_gr_bg', '#ffffff' );
    $bg_op    = (int) get_option( 'viirl_rr_gr_bg_opacity', 100 );
    $radius   = (int) get_option( 'viirl_rr_gr_radius', 16 );
    $style    = get_option( 'viirl_rr_gr_style', 'left' );
    $text     = get_option( 'viirl_rr_gr_text', '#2c3440' );

    if ( ! $place_id ) {
        if ( current_user_can( 'manage_options' ) ) {
            return '<em>Google Rating: please set a Place ID in Roadrunner → Google Ratings Badge.</em>';
        }
        return '';
    }

    $data = viirl_rr_gr_fetch( $place_id );
    if ( ! $data || isset( $data['_error'] ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            $msg = $data['_error'] ?? 'fetch failed (check proxy, Place ID, and enrollment)';
            return '<em>Google Rating: ' . esc_html( $msg ) . '.</em>';
        }
        return '';
    }

    $rating = isset( $data['rating'] ) ? (float) $data['rating'] : 0.0;
    $count  = isset( $data['count'] ) ? (int) $data['count'] : 0;
    $url    = $data['url'] ?? '#';
    $name   = $data['name'] ?? 'Google';

    // Stars.
    $stars = '';
    for ( $i = 1; $i <= 5; $i++ ) {
        $diff = $rating - ( $i - 1 );
        $fill = ( $diff >= 1 ) ? 100 : ( ( $diff <= 0 ) ? 0 : (int) round( $diff * 100 ) );
        $stars .= viirl_rr_gr_star_svg( $fill );
    }

    list( $r, $g, $b ) = viirl_rr_gr_hex_to_rgb( $bg );
    $alpha             = max( 0, min( 1, $bg_op / 100 ) );
    $bg_css            = sprintf( 'rgba(%d,%d,%d,%.3f)', $r, $g, $b, $alpha );

    $wrapStyle = sprintf(
        'background:%s;border-radius:%dpx;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:14px 16px;display:inline-flex;gap:12px;align-items:center;text-decoration:none;color:%s;',
        esc_attr( $bg_css ),
        $radius,
        esc_attr( $text )
    );

    $stack      = 'display:flex;flex-direction:column;gap:4px;';
    $row        = 'display:flex;align-items:center;gap:8px;flex-wrap:nowrap;';
    $label      = 'font-weight:700;font-size:16px;';
    $numStyle   = 'font-weight:800;font-size:22px;line-height:1;';
    $countStyle = 'font-weight:700;';
    $logo       = viirl_rr_gr_google_g_svg();

    if ( 'right' === $style ) {
        $html  = '<a class="viirl-gr-card" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="' . $wrapStyle . '" aria-label="View ' . esc_attr( $name ) . ' reviews on Google">';
        $html .= $logo . '<div style="' . $stack . '"><div style="' . $label . '">Google Rating</div><div style="' . $row . '"><span style="' . $numStyle . '">' . esc_html( number_format( $rating, 1 ) ) . '</span><div class="viirl-gr-stars" style="display:flex;gap:2px;">' . $stars . '</div></div></div></a>';
    } else {
        $html  = '<a class="viirl-gr-card" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="' . $wrapStyle . '" aria-label="View ' . esc_attr( $name ) . ' reviews on Google">';
        $html .= '<div style="' . $stack . ';align-items:flex-start;"><div style="display:flex;align-items:center;gap:8px;line-height:1">' . $logo . '<span style="font-weight:700">Google Rating</span></div><div class="viirl-gr-stars" style="display:flex;gap:2px;margin:4px 0">' . $stars . '</div><div style="' . $countStyle . '">' . esc_html( number_format( $rating, 1 ) ) . ' | ' . esc_html( number_format_i18n( $count ) ) . ' reviews</div></div></a>';
    }

    return $html;
}

add_shortcode( 'viirl_google_rating', function () {
    return viirl_rr_gr_render();
} );

