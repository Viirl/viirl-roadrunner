<?php
/**
 * Button & Link Checker
 *
 * Scans the current site for:
 * - Placeholder links (#, /, javascript:void(0), etc.).
 * - Phone shortcodes used incorrectly in URL fields (e.g. [viirl_phone ...] as a href).
 * - Malformed URLs that commonly result in browser blocking (e.g. about:blank, http://[...]).
 *
 * Location types scanned:
 * - Pages/posts/public CPTs (front-end URL + slug).
 * - Elementor Templates (elementor_library).
 * - Menus.
 * - Text widgets.
 * - Elementor widgets (link controls).
 *
 * Options:
 * - Ignore "/" links where link text contains "home" (does not ignore image/logo links).
 * - Filter results (All / Issues / OK / Flagged / Broken).
 * - Export results to CSV (available after a scan).
 *
 * NOTE: This tool does NOT scan for 404s. It checks for placeholder/misconfigured links and patterns
 * that prevent a link from functioning normally.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-user setting: suppress intentional "/" home links when text contains "home".
 * Default: enabled.
 */
define( 'VIIRL_RR_LC_USERMETA_SUPPRESS_HOME', 'viirl_rr_lc_suppress_home_root' );

/**
 * ------------------------------------------------------------
 * User meta helpers
 * ------------------------------------------------------------
 */
function viirl_rr_lc_get_suppress_home_setting() : bool {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return true;
    }

    $val = get_user_meta( $user_id, VIIRL_RR_LC_USERMETA_SUPPRESS_HOME, true );
    if ( $val === '' ) {
        return true;
    }
    return (bool) intval( $val );
}

function viirl_rr_lc_set_suppress_home_setting( bool $enabled ) : void {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }
    update_user_meta( $user_id, VIIRL_RR_LC_USERMETA_SUPPRESS_HOME, $enabled ? 1 : 0 );
}

/**
 * Suppress root/home links only when:
 * - href is exactly "/"
 * - link text contains "home" anywhere (case-insensitive)
 */
function viirl_rr_link_checker_should_suppress_root_link(
    $href,
    $link_text,
    $raw_inner_html = '',
    $enabled = true
) : bool {
    if ( ! $enabled ) {
        return false;
    }

    $href = trim( (string) $href );
    if ( $href !== '/' ) {
        return false;
    }

    // If anchor contains an image, do NOT suppress (logo/image links)
    if ( is_string( $raw_inner_html ) && stripos( $raw_inner_html, '<img' ) !== false ) {
        return false;
    }

    // Suppress if link text contains "home" anywhere (case-insensitive)
    $t = strtolower( (string) $link_text );
    return strpos( $t, 'home' ) !== false;
}

/**
 * Convert a href to a displayable "Path" value.
 * - For normal URLs, show path (+ query).
 * - For tel/mailto/javascript/about, show the original href.
 */
function viirl_rr_link_checker_href_to_path( string $href ) : string {
    $href = trim( $href );
    if ( $href === '' ) {
        return '';
    }

    $parts = wp_parse_url( $href );
    if ( ! is_array( $parts ) ) {
        return $href;
    }

    // Schemes like tel:, mailto:, javascript:, about:
    if ( isset( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), [ 'tel', 'mailto', 'javascript', 'about' ], true ) ) {
        return $href;
    }

    $path = $parts['path'] ?? '';
    if ( $path === '' ) {
        // Relative things like "#", etc. keep as-is
        return $href;
    }

    if ( isset( $parts['query'] ) && $parts['query'] !== '' ) {
        $path .= '?' . $parts['query'];
    }

    return $path;
}

/**
 * Status rendering (dashicons).
 * ok      => check
 * flagged => flag
 * broken  => x
 */
function viirl_rr_link_checker_status_html( string $status ) : string {
    $status = strtolower( $status );

    if ( $status === 'ok' ) {
        return '<span class="dashicons dashicons-yes" style="color:#1e8e3e;" title="OK"></span>';
    }
    if ( $status === 'broken' ) {
        return '<span class="dashicons dashicons-dismiss" style="color:#d63638;" title="Broken"></span>';
    }
    // flagged
    return '<span class="dashicons dashicons-flag" style="color:#dba617;" title="Flagged"></span>';
}

/**
 * Decide whether this scan should include ALL links or only issues, based on filter.
 * - all / ok => include all
 * - issues / flagged / broken => include only issues
 */
function viirl_rr_link_checker_include_all_from_filter( string $filter ) : bool {
    $filter = sanitize_key( $filter );
    return in_array( $filter, [ 'all', 'ok' ], true );
}

/**
 * Admin page callback.
 */
function viirl_rr_link_checker_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $results       = [];
    $suppress_home = viirl_rr_lc_get_suppress_home_setting();

    // Default filter: issues
    $filter = 'issues';

    $did_scan  = isset( $_POST['viirl_rr_link_checker_scan'] );
    $did_export = isset( $_POST['viirl_rr_link_checker_export_csv'] );

    // Process scan/export (no output yet)
    if ( $did_scan || $did_export ) {
        check_admin_referer( 'viirl_rr_link_checker_scan', 'viirl_rr_link_checker_nonce' );

        $suppress_home = ! empty( $_POST['viirl_rr_suppress_home_root'] ) && (int) $_POST['viirl_rr_suppress_home_root'] === 1;

        $filter = sanitize_key( $_POST['viirl_rr_link_filter'] ?? 'issues' );
        $allowed_filters = [ 'all', 'issues', 'ok', 'flagged', 'broken' ];
        if ( ! in_array( $filter, $allowed_filters, true ) ) {
            $filter = 'issues';
        }

        viirl_rr_lc_set_suppress_home_setting( $suppress_home );

        $results = viirl_rr_link_checker_scan_site(
            [
                'suppress_home_root' => $suppress_home,
                'filter'             => $filter,
            ]
        );

        if ( $did_export ) {
            viirl_rr_link_checker_output_csv_and_exit( $results, $filter );
        }
    }

    ?>
    <div class="wrap">
        <h1>Button &amp; Link Checker</h1>

        <p>
            Scan for placeholder links, misconfigured phone shortcodes, and malformed URLs that prevent links from functioning.
            <strong>This does not check for 404s.</strong>
        </p>

        <form method="post" style="margin: 1em 0 2em;">
            <?php wp_nonce_field( 'viirl_rr_link_checker_scan', 'viirl_rr_link_checker_nonce' ); ?>

            <p style="margin: 0 0 12px;">
                <input type="hidden" name="viirl_rr_suppress_home_root" value="0">
                <label style="display:inline-flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="viirl_rr_suppress_home_root" value="1" <?php checked( $suppress_home, true ); ?>>
                    Ignore <code>/</code> links where the text contains <strong>home</strong> (does not ignore image/logo links)
                </label>
            </p>

            <p style="margin: 0 0 12px;">
                <label style="display:inline-flex;align-items:center;gap:8px;">
                    <strong>Filter:</strong>
                    <select name="viirl_rr_link_filter">
                        <option value="all"     <?php selected( $filter, 'all' ); ?>>All</option>
                        <option value="issues"  <?php selected( $filter, 'issues' ); ?>>Issues (Flagged + Broken)</option>
                        <option value="ok"      <?php selected( $filter, 'ok' ); ?>>OK only</option>
                        <option value="flagged" <?php selected( $filter, 'flagged' ); ?>>Flagged only</option>
                        <option value="broken"  <?php selected( $filter, 'broken' ); ?>>Broken only</option>
                    </select>
                </label>
            </p>

            <p>
                <button type="submit" name="viirl_rr_link_checker_scan" class="button button-primary">
                    Scan Website
                </button>
            </p>
        </form>

        <?php if ( $did_scan ) : ?>
            <hr />
            <h2>Scan Results</h2>

            <?php if ( empty( $results ) ) : ?>
                <p><strong>No results.</strong></p>
            <?php else : ?>
                <p>Found <strong><?php echo (int) count( $results ); ?></strong> result(s).</p>

                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:30%;">Page</th>
                            <th style="width:12%;">Type</th>
                            <th style="width:28%;">Link Text</th>
                            <th style="width:8%;">Status</th>
                            <th style="width:22%;">Path</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results as $row ) : ?>
                            <tr>
                                <td>
                                    <div style="font-family: monospace; opacity:.8;">
                                        <?php echo esc_html( $row['page_path'] ?: '/' ); ?>
                                    </div>

                                    <?php if ( ! empty( $row['page_url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $row['page_url'] ); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html( $row['page_title'] ?: '(no title)' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <strong><?php echo esc_html( $row['page_title'] ?: '(no title)' ); ?></strong>
                                    <?php endif; ?>
                                </td>

                                <td><?php echo wp_kses_post( $row['type_html'] ); ?></td>
                                <td><?php echo esc_html( $row['link_text'] ); ?></td>
                                <td><?php echo wp_kses_post( $row['status_html'] ); ?></td>
                                <td><code><?php echo esc_html( $row['link_path'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Export CSV appears only after scan -->
                <form method="post" style="margin-top:14px;">
                    <?php wp_nonce_field( 'viirl_rr_link_checker_scan', 'viirl_rr_link_checker_nonce' ); ?>
                    <input type="hidden" name="viirl_rr_suppress_home_root" value="<?php echo $suppress_home ? '1' : '0'; ?>">
                    <input type="hidden" name="viirl_rr_link_filter" value="<?php echo esc_attr( $filter ); ?>">
                    <button type="submit" name="viirl_rr_link_checker_export_csv" class="button">
                        Export CSV
                    </button>
                </form>

            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Output CSV and exit.
 *
 * (Separate columns):
 * - Page Path
 * - Page Title
 *
 * Column order follows the UI:
 * Page (Path+Title) -> split into Page Path + Page Title,
 * then Type, Link Text, Status, Path.
 */
function viirl_rr_link_checker_output_csv_and_exit( array $rows, string $filter ) : void {
    $filename = 'viirl-link-scan-' . gmdate( 'Y-m-d_H-i-s' ) . '-' . sanitize_key( $filter ) . '.csv';

    // Kill any buffered output so the CSV is clean.
    while ( ob_get_level() ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );
    header( 'X-Content-Type-Options: nosniff' );

    $out = fopen( 'php://output', 'w' );
    if ( ! $out ) {
        exit;
    }

    fputcsv( $out, [ 'Page Path', 'Page Title', 'Type', 'Link Text', 'Status', 'Path' ] );

    foreach ( $rows as $r ) {
        fputcsv( $out, [
            (string) ( $r['page_path'] ?? '' ),
            (string) ( $r['page_title'] ?? '' ),
            wp_strip_all_tags( (string) ( $r['type_html'] ?? '' ) ),
            (string) ( $r['link_text'] ?? '' ),
            (string) ( $r['status'] ?? '' ),    // ok|flagged|broken
            (string) ( $r['link_path'] ?? '' ),
        ] );
    }

    fclose( $out );
    exit;
}

/**
 * Scan the whole site.
 *
 * @param array $opts
 * @return array[] Normalized rows for UI.
 */
function viirl_rr_link_checker_scan_site( array $opts = [] ) {
    $defaults = [
        'suppress_home_root' => true,
        'filter'             => 'issues',
    ];
    $opts = array_merge( $defaults, $opts );

    $filter = sanitize_key( $opts['filter'] ?? 'issues' );
    $include_all = viirl_rr_link_checker_include_all_from_filter( $filter );

    $raw = [];
    $raw = array_merge( $raw, viirl_rr_link_checker_scan_posts( $opts, $include_all ) );
    $raw = array_merge( $raw, viirl_rr_link_checker_scan_menus( $opts, $include_all ) );
    $raw = array_merge( $raw, viirl_rr_link_checker_scan_widgets( $opts, $include_all ) );

    // Filter + normalize + de-dupe.
    $normalized = [];
    $seen = [];

    foreach ( $raw as $r ) {
        $nr = viirl_rr_link_checker_normalize_row( $r, $opts );
        if ( ! $nr ) {
            continue;
        }

        // Deduplicate same thing appearing multiple times.
        $dupe_key = md5(
            strtolower( (string) $nr['page_path'] ) . '|' .
            strtolower( (string) $nr['page_title'] ) . '|' .
            strtolower( wp_strip_all_tags( (string) $nr['type_html'] ) ) . '|' .
            strtolower( (string) $nr['link_text'] ) . '|' .
            strtolower( (string) $nr['status'] ) . '|' .
            strtolower( (string) $nr['link_path'] )
        );

        if ( isset( $seen[ $dupe_key ] ) ) {
            continue;
        }
        $seen[ $dupe_key ] = true;

        $normalized[] = $nr;
    }

    return $normalized;
}

/**
 * Normalize and apply filter.
 *
 * Raw row keys expected:
 * - page_path, title, title_url, link_text, link_type, raw, ui_type, elementor_template_name, status
 */
function viirl_rr_link_checker_normalize_row( array $row, array $opts = [] ) {
    $page_path = $row['page_path'] ?? ( $row['path'] ?? '/' );
    $title     = $row['title'] ?? '';
    $title_url = $row['title_url'] ?? '';
    $link_text = $row['link_text'] ?? '';
    $link_type = $row['link_type'] ?? '';
    $raw_href  = $row['raw'] ?? '';
    $status    = $row['status'] ?? 'ok';

    $filter = sanitize_key( $opts['filter'] ?? 'issues' );

    // Apply filter.
    if ( $filter === 'issues' ) {
        if ( ! in_array( $status, [ 'flagged', 'broken' ], true ) ) {
            return null;
        }
    } elseif ( $filter === 'ok' ) {
        if ( $status !== 'ok' ) {
            return null;
        }
    } elseif ( $filter === 'flagged' ) {
        if ( $status !== 'flagged' ) {
            return null;
        }
    } elseif ( $filter === 'broken' ) {
        if ( $status !== 'broken' ) {
            return null;
        }
    } // 'all' keeps everything

    $roadrunner_dashboard = admin_url( 'admin.php?page=viirl-roadrunner' );
    $elementor_templates_dashboard = admin_url( 'edit.php?post_type=elementor_library&tabs_group=library' );

    if ( ! empty( $row['elementor_template_name'] ) ) {
        $page_title = 'Elementor Template: ' . $row['elementor_template_name'];
        $page_url   = $elementor_templates_dashboard;
    } else {
        $page_title = $title;
        $page_url   = $title_url;
    }

    // Type label HTML.
    if ( $link_type === 'phone_shortcode' ) {
        $type_html = '<a href="' . esc_url( $roadrunner_dashboard ) . '">Phone shortcode</a>';
    } else {
        $pretty = ! empty( $row['ui_type'] ) ? (string) $row['ui_type'] : 'Text link';
        $type_html = esc_html( $pretty );
    }

    $link_path = viirl_rr_link_checker_href_to_path( (string) $raw_href );

    return [
        'page_path'   => $page_path ?: '/',
        'page_title'  => $page_title,
        'page_url'    => $page_url,
        'type_html'   => $type_html,
        'link_text'   => $link_text,
        'status'      => $status,
        'status_html' => viirl_rr_link_checker_status_html( $status ),
        'link_path'   => $link_path,
    ];
}

/**
 * Scan posts/pages/CPTs + elementor_library.
 */
function viirl_rr_link_checker_scan_posts( array $opts = [], bool $include_all = false ) {
    $results = [];

    $post_types = get_post_types( [ 'public' => true ], 'names' );
    if ( post_type_exists( 'elementor_library' ) ) {
        $post_types[] = 'elementor_library';
    }
    $post_types = array_unique( $post_types );

    $query = new WP_Query(
        [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]
    );

    if ( ! $query->have_posts() ) {
        return $results;
    }

    while ( $query->have_posts() ) {
        $query->the_post();
        $post_id = get_the_ID();
        $post    = get_post( $post_id );
        if ( ! $post ) {
            continue;
        }

        $is_elementor_template = ( 'elementor_library' === $post->post_type );
        $permalink             = get_permalink( $post_id );
        $page_path             = viirl_rr_link_checker_extract_path_from_url( $permalink );

        $context = [
            'page_path' => $page_path ?: '/',
            'location'  => $is_elementor_template ? 'Elementor Template' : ucfirst( $post->post_type ),
            'title'     => $post->post_title,
            'title_url' => $permalink,
        ];

        if ( $is_elementor_template ) {
            $context['elementor_template_name'] = $post->post_title;
            if ( empty( $context['page_path'] ) ) {
                $context['page_path'] = '/';
            }
        }

        // HTML content
        $results = array_merge(
            $results,
            viirl_rr_link_checker_scan_html_for_links( $post->post_content, $context, $opts, $include_all )
        );

        // Elementor JSON
        $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor_data ) && is_string( $elementor_data ) ) {
            $decoded = json_decode( $elementor_data, true );
            if ( is_array( $decoded ) ) {
                $results = array_merge(
                    $results,
                    viirl_rr_link_checker_scan_elementor_tree( $decoded, $context, $opts, $include_all )
                );
            }
        }
    }

    wp_reset_postdata();
    return $results;
}

/**
 * Extract path from URL.
 */
function viirl_rr_link_checker_extract_path_from_url( $url ) {
    $parts = wp_parse_url( $url );
    return isset( $parts['path'] ) ? $parts['path'] : '/';
}

/**
 * Classify href into status.
 *
 * returns:
 * - status: ok|flagged|broken
 * - link_type: placeholder|phone_shortcode|ok|malformed
 */
function viirl_rr_link_checker_classify_href( $href ) {
    $href = trim( (string) $href );

    if ( $href === '' ) {
        return [
            'is_issue'  => true,
            'status'    => 'flagged',
            'link_type' => 'placeholder',
        ];
    }

    $lower = strtolower( $href );

    // Browser-blocked destinations often show as about:blank#blocked.
    if ( strpos( $lower, 'about:blank' ) === 0 ) {
        return [
            'is_issue'  => true,
            'status'    => 'broken',
            'link_type' => 'malformed',
        ];
    }

    // Phone shortcode accidentally used in a URL/link field.
    if ( stripos( $href, '[viirl_phone' ) !== false ) {
        return [
            'is_issue'  => true,
            'status'    => 'broken',
            'link_type' => 'phone_shortcode',
        ];
    }

    // Malformed URL patterns that commonly trigger blocking:
    // - http(s)://[ ... ]
    // - encoded brackets %5B %5D
    if (
        ( preg_match( '#^https?://\[#i', $href ) )
        || ( strpos( $lower, '%5b' ) !== false )
        || ( strpos( $lower, '%5d' ) !== false )
        || ( ( strpos( $lower, 'http://' ) === 0 || strpos( $lower, 'https://' ) === 0 ) && ( strpos( $href, '[' ) !== false || strpos( $href, ']' ) !== false ) )
    ) {
        return [
            'is_issue'  => true,
            'status'    => 'broken',
            'link_type' => 'malformed',
        ];
    }

    $placeholder_values = [
        '#',
        '/#',
        '#/',
        '/',
        './',
        'javascript:void(0)',
        'javascript:void(0);',
        'javascript:;',
    ];

    if ( in_array( $lower, $placeholder_values, true ) ) {
        return [
            'is_issue'  => true,
            'status'    => 'flagged',
            'link_type' => 'placeholder',
        ];
    }

    return [
        'is_issue'  => false,
        'status'    => 'ok',
        'link_type' => 'ok',
    ];
}

/**
 * Scan raw HTML <a href="...">...</a>
 * Adds Type="Image" when anchor contains an <img>.
 */
function viirl_rr_link_checker_scan_html_for_links( $html, $context, array $opts = [], bool $include_all = false ) {
    $results = [];

    if ( empty( $html ) || ! is_string( $html ) ) {
        return $results;
    }

    if ( ! preg_match_all( '#<a\s[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
        return $results;
    }

    foreach ( $matches as $m ) {
        $href       = trim( html_entity_decode( $m[1] ) );
        $inner_html = (string) $m[2];
        $link_text  = wp_strip_all_tags( $inner_html );

        $classification = viirl_rr_link_checker_classify_href( $href );

        // Suppress only the intentional "/" home links (not images).
        if ( $classification['link_type'] === 'placeholder' ) {
            $enabled = ! empty( $opts['suppress_home_root'] );
            if ( viirl_rr_link_checker_should_suppress_root_link( $href, $link_text, $inner_html, $enabled ) ) {
                continue;
            }
        }

        if ( ! $include_all && ! $classification['is_issue'] ) {
            continue;
        }

        $is_image = ( stripos( $inner_html, '<img' ) !== false );

        $results[] = [
            'page_path' => $context['page_path'] ?? '/',
            'location'  => $context['location'] ?? '',
            'title'     => $context['title'] ?? '',
            'title_url' => $context['title_url'] ?? '',
            'link_text' => $is_image ? '[Image]' : $link_text,
            'link_type' => $classification['link_type'],
            'status'    => $classification['status'],
            'raw'       => $href,
            'ui_type'   => $is_image ? 'Image' : 'Text link',
            'elementor_template_name' => $context['elementor_template_name'] ?? '',
        ];
    }

    return $results;
}

/**
 * Scan Elementor element tree for link controls (settings['link']['url']).
 */
function viirl_rr_link_checker_scan_elementor_tree( array $elements, array $context, array $opts = [], bool $include_all = false ) {
    $results = [];

    foreach ( $elements as $element ) {
        if ( ! is_array( $element ) ) {
            continue;
        }

        $el_type     = $element['elType'] ?? '';
        $widget      = $element['widgetType'] ?? '';
        $settings    = ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : [];
        $child_elems = ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) ? $element['elements'] : [];

        if ( $el_type === 'widget'
            && isset( $settings['link'] )
            && is_array( $settings['link'] )
            && array_key_exists( 'url', $settings['link'] )
        ) {
            $href = trim( (string) ( $settings['link']['url'] ?? '' ) );

            if ( $href !== '' ) {
                $classification = viirl_rr_link_checker_classify_href( $href );

                // Determine UI type.
                $ui_type = 'Text link';

                $widget_lower = strtolower( (string) $widget );
                $looks_like_image_or_logo = (
                    strpos( $widget_lower, 'image' ) !== false ||
                    strpos( $widget_lower, 'logo' ) !== false ||
                    strpos( $widget_lower, 'site-logo' ) !== false ||
                    strpos( $widget_lower, 'icon' ) !== false
                );

                if ( $looks_like_image_or_logo ) {
                    $ui_type = 'Image';
                } elseif ( is_string( $widget ) && stripos( $widget, 'button' ) !== false ) {
                    $ui_type = 'Button';
                }

                // Visible text.
                $link_text = '';
                if ( isset( $settings['text'] ) && is_string( $settings['text'] ) ) {
                    $link_text = wp_strip_all_tags( $settings['text'] );
                } elseif ( isset( $settings['title'] ) && is_string( $settings['title'] ) ) {
                    $link_text = wp_strip_all_tags( $settings['title'] );
                } elseif ( isset( $settings['button_text'] ) && is_string( $settings['button_text'] ) ) {
                    $link_text = wp_strip_all_tags( $settings['button_text'] );
                }

                if ( $ui_type === 'Image' && $link_text === '' ) {
                    $link_text = '[Image]';
                }

                // Suppress only intentional "/" home links (never suppress image/logo widgets).
                if ( $classification['link_type'] === 'placeholder' ) {
                    $enabled = ! empty( $opts['suppress_home_root'] );

                    if ( ! $looks_like_image_or_logo && viirl_rr_link_checker_should_suppress_root_link( $href, $link_text, '', $enabled ) ) {
                        goto recurse_children;
                    }
                }

                if ( ! $include_all && ! $classification['is_issue'] ) {
                    goto recurse_children;
                }

                $results[] = [
                    'page_path' => $context['page_path'] ?? '/',
                    'location'  => ( $context['location'] ?? '' ) . ' (Elementor)',
                    'title'     => $context['title'] ?? '',
                    'title_url' => $context['title_url'] ?? '',
                    'link_text' => $link_text,
                    'link_type' => $classification['link_type'],
                    'status'    => $classification['status'],
                    'raw'       => $href,
                    'ui_type'   => $classification['link_type'] === 'phone_shortcode' ? 'Phone shortcode' : $ui_type,
                    'elementor_template_name' => $context['elementor_template_name'] ?? '',
                ];
            }
        }

        recurse_children:
        if ( ! empty( $child_elems ) ) {
            $results = array_merge( $results, viirl_rr_link_checker_scan_elementor_tree( $child_elems, $context, $opts, $include_all ) );
        }
    }

    return $results;
}

/**
 * Scan nav menus.
 */
function viirl_rr_link_checker_scan_menus( array $opts = [], bool $include_all = false ) {
    $results = [];

    $menus = wp_get_nav_menus();
    if ( empty( $menus ) ) {
        return $results;
    }

    foreach ( $menus as $menu ) {
        $items = wp_get_nav_menu_items( $menu->term_id );
        if ( empty( $items ) ) {
            continue;
        }

        foreach ( $items as $item ) {
            if ( empty( $item->url ) ) {
                continue;
            }

            $href = trim( (string) $item->url );
            $classification = viirl_rr_link_checker_classify_href( $href );

            // Suppress root/home menu items if enabled.
            if ( $classification['link_type'] === 'placeholder' ) {
                $enabled   = ! empty( $opts['suppress_home_root'] );
                $link_text = (string) ( $item->title ?? '' );

                if ( viirl_rr_link_checker_should_suppress_root_link( $href, $link_text, '', $enabled ) ) {
                    continue;
                }
            }

            if ( ! $include_all && ! $classification['is_issue'] ) {
                continue;
            }

            $results[] = [
                'page_path' => viirl_rr_link_checker_extract_path_from_url( $href ),
                'location'  => 'Menu: ' . $menu->name,
                'title'     => $item->title,
                'title_url' => '',
                'link_text' => $item->title,
                'link_type' => $classification['link_type'],
                'status'    => $classification['status'],
                'raw'       => $href,
                'ui_type'   => 'Menu item',
            ];
        }
    }

    return $results;
}

/**
 * Scan text widgets for links.
 */
function viirl_rr_link_checker_scan_widgets( array $opts = [], bool $include_all = false ) {
    $results = [];

    $sidebars_widgets = get_option( 'sidebars_widgets' );
    if ( ! is_array( $sidebars_widgets ) ) {
        return $results;
    }

    foreach ( $sidebars_widgets as $sidebar_id => $widget_ids ) {
        if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
            continue;
        }

        foreach ( $widget_ids as $widget_id ) {
            if ( ! is_string( $widget_id ) || false === strpos( $widget_id, '-' ) ) {
                continue;
            }

            [ $base_id, $instance_id ] = explode( '-', $widget_id, 2 );
            $instance_id = (int) $instance_id;

            if ( 'text' !== $base_id ) {
                continue;
            }

            $all_text_widgets = get_option( 'widget_text' );
            if ( ! is_array( $all_text_widgets ) || empty( $all_text_widgets[ $instance_id ] ) ) {
                continue;
            }

            $widget = $all_text_widgets[ $instance_id ];
            $text   = isset( $widget['text'] ) ? $widget['text'] : '';

            if ( ! is_string( $text ) || '' === trim( $text ) ) {
                continue;
            }

            $context = [
                'page_path' => '/',
                'location'  => 'Widget: ' . $sidebar_id,
                'title'     => ! empty( $widget['title'] ) ? $widget['title'] : 'Text widget',
                'title_url' => '',
            ];

            $results = array_merge( $results, viirl_rr_link_checker_scan_html_for_links( $text, $context, $opts, $include_all ) );
        }
    }

    return $results;
}
