<?php
/**
 * Feature: Google Ratings Badge (proxy-based) for VIIRL Roadrunner
 *
 * - Uses VIIRL proxy to avoid exposing Google API key on client sites.
 * - Auto-enrolls a site with the proxy to obtain a per-site secret.
 * - Fetches rating data via proxy with HMAC signature.
 * - Caches results for 12 hours (WP transient) and stamps fetched_at for admin display.
 *
 * Admin page renderer function provided:
 *   viirl_rr_gr_settings_page()
 *
 * Frontend renderer function provided:
 *   viirl_rr_gr_render()
 *
 * Shortcode registered:
 *   [viirl_google_rating]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Cache TTL + proxy endpoint */
define( 'VIIRL_RR_GR_TTL',       12 * HOUR_IN_SECONDS );
define( 'VIIRL_RR_GR_PROXY_URL', 'https://devapi.viirl.pro/gplaces-proxy.php' );

/* ------------------------------------------------------------
 * Settings registration
 * ------------------------------------------------------------ */
add_action( 'admin_init', function () {
    $default_site_id = preg_replace( '~^https?://~i', '', site_url() );

    register_setting('viirl_rr_gr','viirl_rr_gr_place_id',   ['sanitize_callback'=>'sanitize_text_field','default'=>'']);
    register_setting('viirl_rr_gr_site_id', 'viirl_rr_gr_site_id', [
        'sanitize_callback' => 'viirl_rr_gr_sanitize_site_id',
        'default'           => $default_site_id,
    ]);
    register_setting('viirl_rr_gr','viirl_rr_gr_site_secret',['sanitize_callback'=>'sanitize_text_field','default'=>'']);
    register_setting('viirl_rr_gr','viirl_rr_gr_bg',         ['sanitize_callback'=>'viirl_rr_gr_sanitize_color','default'=>'#ffffff']);
    register_setting('viirl_rr_gr','viirl_rr_gr_bg_opacity', ['sanitize_callback'=>'viirl_rr_gr_sanitize_opacity','default'=>100]);
    register_setting('viirl_rr_gr','viirl_rr_gr_text',       ['sanitize_callback'=>'viirl_rr_gr_sanitize_color','default'=>'#2c3440']);
    register_setting('viirl_rr_gr','viirl_rr_gr_radius',     ['sanitize_callback'=>'viirl_rr_gr_sanitize_int','default'=>16]);
    register_setting('viirl_rr_gr','viirl_rr_gr_style',      ['sanitize_callback'=>'viirl_rr_gr_sanitize_style','default'=>'left']);
});

/** Clear cache when key inputs change */
add_action( 'update_option_viirl_rr_gr_place_id', function( $old, $new ){
    if ( $old ) delete_transient( 'viirl_rr_gr_' . md5( $old ) );
    if ( $new ) delete_transient( 'viirl_rr_gr_' . md5( $new ) );
}, 10, 2 );

add_action( 'update_option_viirl_rr_gr_site_id', function(){
    $pid = get_option('viirl_rr_gr_place_id', '');
    if ( $pid ) delete_transient( 'viirl_rr_gr_' . md5( $pid ) );
});

add_action( 'update_option_viirl_rr_gr_site_secret', function(){
    $pid = get_option('viirl_rr_gr_place_id', '');
    if ( $pid ) delete_transient( 'viirl_rr_gr_' . md5( $pid ) );
});

/* ------------------------------------------------------------
 * Sanitizers + helpers
 * ------------------------------------------------------------ */
function viirl_rr_gr_sanitize_color( $v ) {
    $v = trim( (string)$v );
    return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $v) ? $v : '#ffffff';
}
function viirl_rr_gr_sanitize_int( $v ) { return max(0, intval($v)); }
function viirl_rr_gr_sanitize_style( $v ) { return in_array($v, ['left','right'], true) ? $v : 'left'; }
function viirl_rr_gr_sanitize_opacity( $val ) {
    $n = is_numeric($val) ? (int)$val : 100;
    return max(0, min(100, $n));
}
/** Site ID stored as bare domain (no scheme / no trailing slash) */
function viirl_rr_gr_sanitize_site_id( $value ) {
    $value = sanitize_text_field( $value );
    $value = preg_replace('~^https?://~i','', $value);
    $value = rtrim($value, "/\\");
    return strtolower($value);
}
/** Hex -> RGB helper */
function viirl_rr_gr_hex_to_rgb( $hex ) {
    $hex = ltrim(trim((string)$hex), '#');
    if (strlen($hex) === 3) {
        return [hexdec($hex[0].$hex[0]), hexdec($hex[1].$hex[1]), hexdec($hex[2].$hex[2])];
    }
    if (strlen($hex) === 6) {
        return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    }
    return [255,255,255];
}

/* ------------------------------------------------------------
 * Probe endpoint for proxy enrollment verification
 * URL: https://example.com/?viirl_rr_probe=1&nonce=XXXX
 * Must echo nonce exactly.
 * ------------------------------------------------------------ */
add_action( 'init', function(){
    if ( isset($_GET['viirl_rr_probe'], $_GET['nonce']) ) {
        header('Content-Type: text/plain; charset=utf-8');
        echo sanitize_text_field( wp_unslash($_GET['nonce']) );
        exit;
    }
});

/* ------------------------------------------------------------
 * Proxy request wrapper (GET only to match your proxy)
 * ------------------------------------------------------------ */
function viirl_rr_gr_proxy_get( array $params ) : array {
    $url  = add_query_arg( $params, VIIRL_RR_GR_PROXY_URL );
    $resp = wp_remote_get( $url, ['timeout'=>15] );

    if ( is_wp_error($resp) ) {
        return ['ok'=>false,'code'=>0,'raw'=>'','data'=>null,'error'=>$resp->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);

    return [
        'ok'    => ($code >= 200 && $code < 300),
        'code'  => $code,
        'raw'   => $raw,
        'data'  => is_array($json) ? $json : null,
        'error' => '',
    ];
}

/* ------------------------------------------------------------
 * Enrollment (matches proxy exactly: 2-step GET)
 * Step 1: GET action=enroll&site_id&probe_url  -> {nonce}
 * Step 2: GET action=enroll&site_id&probe_url&nonce -> {site_secret}
 * ------------------------------------------------------------ */
function viirl_rr_gr_enroll_if_needed() {
    $site_id = get_option('viirl_rr_gr_site_id', '');
    $site_id = viirl_rr_gr_sanitize_site_id( $site_id ?: preg_replace('~^https?://~i','', site_url()) );

    $secret = trim((string)get_option('viirl_rr_gr_site_secret',''));
    if ($secret !== '') return true;

    // Proxy expects probe_url WITHOUT nonce param; it appends nonce itself.
    $probe_url = add_query_arg(['viirl_rr_probe'=>1], home_url('/'));

    $step1 = viirl_rr_gr_proxy_get([
        'action'    => 'enroll',
        'site_id'   => $site_id,
        'probe_url' => $probe_url,
    ]);

    if ( empty($step1['data']['nonce']) ) {
        $msg = 'Could not auto-enroll with the VIIRL proxy.';
        $msg .= ' HTTP ' . (int)$step1['code'] . ' — ' . wp_strip_all_tags((string)$step1['raw']);
        return new WP_Error('viirl_rr_gr_enroll_failed', $msg);
    }

    $nonce = sanitize_text_field($step1['data']['nonce']);

    $step2 = viirl_rr_gr_proxy_get([
        'action'    => 'enroll',
        'site_id'   => $site_id,
        'probe_url' => $probe_url,
        'nonce'     => $nonce,
    ]);

    if ( ! empty($step2['data']['site_secret']) ) {
        update_option('viirl_rr_gr_site_secret', sanitize_text_field($step2['data']['site_secret']));
        return true;
    }

    $msg = 'Could not auto-enroll with the VIIRL proxy.';
    $msg .= ' HTTP ' . (int)$step2['code'] . ' — ' . wp_strip_all_tags((string)$step2['raw']);
    return new WP_Error('viirl_rr_gr_enroll_failed', $msg);
}

/* ------------------------------------------------------------
 * Fetch ratings via proxy (cached)
 * Proxy expects: GET ?site_id=...&place_id=...&sig=...
 * sig = HMAC(site_id|place_id, site_secret)
 * ------------------------------------------------------------ */
function viirl_rr_gr_fetch( $place_id ) : array {
    $place_id = trim((string)$place_id);
    if ($place_id === '') return ['_error'=>'Missing Place ID.'];

    $cache_key = 'viirl_rr_gr_' . md5($place_id);
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached)) return $cached;

    $site_id = get_option('viirl_rr_gr_site_id', '');
    $site_id = viirl_rr_gr_sanitize_site_id( $site_id ?: preg_replace('~^https?://~i','', site_url()) );

    $secret = trim((string)get_option('viirl_rr_gr_site_secret',''));
    if ($secret === '') {
        $enroll = viirl_rr_gr_enroll_if_needed();
        $secret = trim((string)get_option('viirl_rr_gr_site_secret',''));
        if ($secret === '') {
            $msg = is_wp_error($enroll) ? $enroll->get_error_message() : 'Site Secret is required.';
            return ['_error'=>$msg];
        }
    }

    $sig = hash_hmac('sha256', $site_id . '|' . $place_id, $secret);

    $res = viirl_rr_gr_proxy_get([
        'site_id'  => $site_id,
        'place_id' => $place_id,
        'sig'      => $sig,
    ]);

    if (!$res['ok'] || !is_array($res['data'])) {
        $msg = 'Proxy request failed.';
        $msg .= ' HTTP ' . (int)$res['code'] . ' — ' . wp_strip_all_tags((string)$res['raw']);
        return ['_error'=>$msg];
    }

    $body = $res['data'];
    $body['fetched_at'] = time();

    set_transient($cache_key, $body, VIIRL_RR_GR_TTL);
    return $body;
}

/* ------------------------------------------------------------
 * Rotate secret (matches proxy exactly)
 * GET action=rotate&site_id&sig_old
 * sig_old = HMAC(site_id|rotate, current_secret)
 * ------------------------------------------------------------ */
add_action('admin_init', function(){
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['viirl_rr_gr_rotate_secret'], $_GET['_wpnonce'])) return;
    if (!wp_verify_nonce($_GET['_wpnonce'], 'viirl_rr_gr_rotate')) {
        wp_safe_redirect(remove_query_arg(['viirl_rr_gr_rotate_secret','_wpnonce']));
        exit;
    }

    $site_id = get_option('viirl_rr_gr_site_id', '');
    $site_id = viirl_rr_gr_sanitize_site_id( $site_id ?: preg_replace('~^https?://~i','', site_url()) );

    $secret = trim((string)get_option('viirl_rr_gr_site_secret',''));
    if ($secret === '') {
        $enroll = viirl_rr_gr_enroll_if_needed();
        $secret = trim((string)get_option('viirl_rr_gr_site_secret',''));
        if ($secret === '') {
            wp_safe_redirect(remove_query_arg(['viirl_rr_gr_rotate_secret','_wpnonce']));
            exit;
        }
    }

    $sig_old = hash_hmac('sha256', $site_id . '|rotate', $secret);

    $res = viirl_rr_gr_proxy_get([
        'action'  => 'rotate',
        'site_id' => $site_id,
        'sig_old' => $sig_old,
    ]);

    if (!empty($res['data']['site_secret'])) {
        update_option('viirl_rr_gr_site_secret', sanitize_text_field($res['data']['site_secret']));
        update_option('viirl_rr_gr_secret_rotated_at', time());

        $pid = get_option('viirl_rr_gr_place_id', '');
        if ($pid) delete_transient('viirl_rr_gr_' . md5($pid));
    }

    wp_safe_redirect(remove_query_arg(['viirl_rr_gr_rotate_secret','_wpnonce']));
    exit;
});

/* ------------------------------------------------------------
 * SVG helpers
 * ------------------------------------------------------------ */
function viirl_rr_gr_google_g_svg() {
  // Use Google's official "G" asset to avoid SVG path/color issues.
  // (64dp PNG, downscaled via CSS/width/height)
  $src = 'https://www.google.com/images/branding/googleg/1x/googleg_standard_color_64dp.png';

  return '<img
    src="'.esc_url($src).'"
    alt="Google"
    width="28"
    height="28"
    style="display:block;width:28px;height:28px;"
    loading="lazy"
    decoding="async"
  >';
}

function viirl_rr_gr_star_svg($fillPct){
    $fillPct = max(0, min(100, (int)$fillPct));
    $filledWidth = 24 * ($fillPct / 100.0);
    $filledWidth = max(0, min(24, $filledWidth));
    return '<svg class="viirl-gr-star" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" role="img">
      <defs>
        <linearGradient id="viirl-gr-gold" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#f9b400"/>
          <stop offset="100%" stop-color="#f5a623"/>
        </linearGradient>
        <clipPath id="viirl-gr-clip-'.$fillPct.'">
          <rect x="0" y="0" width="'.$filledWidth.'" height="24"></rect>
        </clipPath>
      </defs>
      <path fill="#e6e6e6" d="M12 .9l3.5 7 7.7 1.1-5.6 5.4 1.3 7.7L12 19.3 5.1 22.1l1.3-7.7L.8 9l7.7-1.1z"/>
      <path fill="url(#viirl-gr-gold)" clip-path="url(#viirl-gr-clip-'.$fillPct.')" d="M12 .9l3.5 7 7.7 1.1-5.6 5.4 1.3 7.7L12 19.3 5.1 22.1l1.3-7.7L.8 9l7.7-1.1z"/>
    </svg>';
}

/* ------------------------------------------------------------
 * Frontend renderer + shortcode
 * ------------------------------------------------------------ */
function viirl_rr_gr_render() {
    $place_id = get_option('viirl_rr_gr_place_id', '');
    $bg       = get_option('viirl_rr_gr_bg', '#ffffff');
    $bg_op    = (int)get_option('viirl_rr_gr_bg_opacity', 100);
    $text     = get_option('viirl_rr_gr_text', '#2c3440');
    $radius   = (int)get_option('viirl_rr_gr_radius', 16);
    $style    = get_option('viirl_rr_gr_style', 'left');

    if (!$place_id) {
        return current_user_can('manage_options')
            ? '<em>VIIRL Google Rating: please set Place ID in Roadrunner → Google Ratings Badge.</em>'
            : '';
    }

    $data = viirl_rr_gr_fetch($place_id);
    if (!is_array($data) || !empty($data['_error'])) {
        if (current_user_can('manage_options')) {
            $msg = !empty($data['_error']) ? $data['_error'] : 'Fetch failed.';
            return '<em>VIIRL Google Rating: ' . esc_html($msg) . '</em>';
        }
        return '';
    }

    $rating = isset($data['rating']) ? round((float)$data['rating'], 1) : 0.0;
    $count  = isset($data['userRatingCount']) ? (int)$data['userRatingCount'] : 0;
    $url    = $data['googleMapsUri'] ?? '#';
    $name   = $data['displayName'] ?? 'Google';

    // Build stars 0-5 with partial fill
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $diff = $rating - ($i - 1);
        $fill = ($diff >= 1) ? 100 : (($diff <= 0) ? 0 : (int)round($diff * 100));
        $stars .= viirl_rr_gr_star_svg($fill);
    }

    // RGBA background
    list($r,$g,$b) = viirl_rr_gr_hex_to_rgb($bg);
    $alpha = max(0, min(1, $bg_op / 100));
    $bg_css = sprintf('rgba(%d,%d,%d,%.3f)', $r, $g, $b, $alpha);

    $wrapStyle = sprintf(
        'background:%s;border-radius:%dpx;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:14px 16px;display:inline-flex;gap:12px;align-items:center;text-decoration:none;color:%s;',
        esc_attr($bg_css),
        $radius,
        esc_attr($text)
    );

    $stack     = 'display:flex;flex-direction:column;gap:4px;';
    $row       = 'display:flex;align-items:center;gap:8px;flex-wrap:nowrap;';
    $label     = 'font-weight:700;font-size:16px;';
    $numStyle  = 'font-weight:800;font-size:22px;line-height:1;';
    $countStyle= 'font-weight:700;';

    $logo = viirl_rr_gr_google_g_svg();

    if ($style === 'right') {
        $html  = '<a class="viirl-gr-card" href="'.esc_url($url).'" target="_blank" rel="noopener" style="'.$wrapStyle.'" aria-label="View '.esc_attr($name).' reviews on Google">';
        $html .= $logo;
        $html .= '<div style="'.$stack.'">';
        $html .= '<div style="'.$label.'">Google rating</div>';
        $html .= '<div style="'.$row.'"><span style="'.$numStyle.'">'.esc_html(number_format($rating,1)).'</span><div class="viirl-gr-stars" style="display:flex;gap:2px;">'.$stars.'</div></div>';
        $html .= '</div>';
        $html .= '</a>';
    } else {
        $html  = '<a class="viirl-gr-card" href="'.esc_url($url).'" target="_blank" rel="noopener" style="'.$wrapStyle.'" aria-label="View '.esc_attr($name).' reviews on Google">';
        $html .= '<div style="'.$stack.';align-items:flex-start;">';
        $html .= '<div style="display:flex;align-items:center;gap:8px;">'.$logo.'<span style="font-weight:700">Google</span></div>';
        $html .= '<div class="viirl-gr-stars" style="display:flex;gap:2px;margin-top:4px;margin-bottom:4px">'.$stars.'</div>';
        $html .= '<div style="'.$countStyle.'">'.esc_html(number_format($rating,1)).' | '.esc_html(number_format_i18n($count)).' reviews</div>';
        $html .= '</div>';
        $html .= '</a>';
    }

    return $html;
}

add_shortcode('viirl_google_rating', function(){
    return viirl_rr_gr_render();
});

/* ------------------------------------------------------------
 * Admin settings page (submenu)
 * Call this from your Roadrunner admin menu callback.
 * ------------------------------------------------------------ */
function viirl_rr_gr_settings_page(){ ?>
  <div class="wrap">
    <h1>Google Ratings Badge</h1>

    <form method="post" action="options.php">
      <?php settings_fields('viirl_rr_gr'); ?>
      <table class="form-table" role="presentation">

        <tr>
          <th scope="row"><label for="viirl_rr_gr_place_id">Place ID</label></th>
          <td>
            <input name="viirl_rr_gr_place_id" id="viirl_rr_gr_place_id" type="text" class="regular-text"
                   value="<?php echo esc_attr(get_option('viirl_rr_gr_place_id','')); ?>">
            <p class="description">
              Use Google’s Place ID Finder →
              <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener">Open tool ↗</a>
            </p>
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="viirl_rr_gr_site_id">Site ID</label></th>
          <td>
            <?php $default_site_id = preg_replace('~^https?://~i','', site_url()); ?>
            <input name="viirl_rr_gr_site_id" id="viirl_rr_gr_site_id" type="text" class="regular-text"
                   value="<?php echo esc_attr(get_option('viirl_rr_gr_site_id', $default_site_id)); ?>">
            <p class="description">Bare domain only (e.g., <code>example.com</code>) — no <code>https://</code>.</p>
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="viirl_rr_gr_site_secret">Site Secret</label></th>
          <td>
            <input name="viirl_rr_gr_site_secret" id="viirl_rr_gr_site_secret" type="text" class="regular-text"
                   value="<?php echo esc_attr(get_option('viirl_rr_gr_site_secret','')); ?>">
            <p class="description">Can be blank on first save — plugin will attempt to auto-enroll with the VIIRL proxy.</p>

            <p>
              <a class="button button-secondary"
                 href="<?php echo esc_url(wp_nonce_url(add_query_arg('viirl_rr_gr_rotate_secret','1'), 'viirl_rr_gr_rotate')); ?>"
                 onclick="return confirm('Rotate the Site Secret? New requests will use the new secret immediately.');">
                Rotate Site Secret
              </a>

              <?php
              $rot = (int)get_option('viirl_rr_gr_secret_rotated_at', 0);
              if ($rot) {
                $when = date_i18n(get_option('date_format').' '.get_option('time_format'), $rot);
                echo '<span style="margin-left:8px;opacity:.7;">Last rotated: '.esc_html($when).'</span>';
              }
              ?>
            </p>
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="viirl_rr_gr_bg">Background color</label></th>
          <td><input name="viirl_rr_gr_bg" id="viirl_rr_gr_bg" type="text" class="regular-text"
                     value="<?php echo esc_attr(get_option('viirl_rr_gr_bg','#ffffff')); ?>"></td>
        </tr>

        <tr>
          <th scope="row"><label for="viirl_rr_gr_bg_opacity">Background opacity (%)</label></th>
          <td>
            <input name="viirl_rr_gr_bg_opacity" id="viirl_rr_gr_bg_opacity" type="number" class="small-text"
                   min="0" max="100"
                   value="<?php echo esc_attr(get_option('viirl_rr_gr_bg_opacity', 100)); ?>">
            <p class="description">0 = transparent, 100 = fully opaque.</p>
          </td>
        </tr>

        <tr>
          <th scope="row"><label for="viirl_rr_gr_text">Text color</label></th>
          <td><input name="viirl_rr_gr_text" id="viirl_rr_gr_text" type="text" class="regular-text"
                     value="<?php echo esc_attr(get_option('viirl_rr_gr_text','#2c3440')); ?>"></td>
        </tr>

        <tr>
          <th scope="row"><label for="viirl_rr_gr_radius">Border radius (px)</label></th>
          <td><input name="viirl_rr_gr_radius" id="viirl_rr_gr_radius" type="number" class="small-text"
                     min="0" value="<?php echo esc_attr(get_option('viirl_rr_gr_radius',16)); ?>"></td>
        </tr>

        <tr>
          <th scope="row"><label for="viirl_rr_gr_style">Style</label></th>
          <td>
            <?php $cur = get_option('viirl_rr_gr_style','left'); ?>
            <select name="viirl_rr_gr_style" id="viirl_rr_gr_style">
              <option value="left"  <?php selected($cur,'left'); ?>>Left card (logo above stars)</option>
              <option value="right" <?php selected($cur,'right'); ?>>Right card (logo left, text right)</option>
            </select>
          </td>
        </tr>

      </table>

      <?php submit_button('Save Ratings Settings'); ?>
    </form>

    <?php
    $pid = get_option('viirl_rr_gr_place_id','');
    if ($pid) {
        $preview = viirl_rr_gr_fetch($pid);
        echo '<h2 style="margin-top:24px;">Preview &amp; status</h2>';

        if (is_array($preview) && empty($preview['_error'])) {
            echo '<div style="margin:10px 0;">' . viirl_rr_gr_render() . '</div>';

            if (!empty($preview['fetched_at'])) {
                $t    = (int)$preview['fetched_at'];
                $ago  = human_time_diff($t, current_time('timestamp'));
                $when = date_i18n(get_option('date_format').' '.get_option('time_format'), $t);
                echo '<div style="font-size:12px;opacity:.7;margin-top:4px;">Last updated '.esc_html($ago).' ago ('.esc_html($when).'). Cached up to 12 hours.</div>';
            }
            echo '<div style="font-size:12px;opacity:.7;margin-top:4px;">Source: Google Maps</div>';
        } else {
            $msg = is_array($preview) && !empty($preview['_error']) ? $preview['_error'] : 'No data yet.';
            echo '<p style="color:#a00"><em>'.esc_html($msg).'</em></p>';
        }
    }
    ?>

    <h2>Shortcode</h2>
    <p>Use <code>[viirl_google_rating]</code> anywhere.</p>
  </div>
<?php }
