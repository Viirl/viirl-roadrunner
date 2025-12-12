<?php
/**
 * Button & Link Checker
 *
 * Scans the current site for:
 * - Placeholder links (#, /, javascript:void(0), etc.).
 * - Phone shortcodes used incorrectly in URL fields (e.g. [viirl_phone ...] as a href).
 *
 * Location types scanned:
 * - Pages/posts/public CPTs (front-end URL + slug).
 * - Elementor Templates (elementor_library).
 * - Menus.
 * - Text widgets.
 * - Elementor widgets (link controls).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin page callback.
 * admin-menus.php submenu callback should point to: viirl_rr_link_checker_page
 */
function viirl_rr_link_checker_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $results = [];
    if ( isset( $_POST['viirl_rr_link_checker_scan'] ) ) {
        check_admin_referer( 'viirl_rr_link_checker_scan', 'viirl_rr_link_checker_nonce' );
        $results = viirl_rr_link_checker_scan_site();
    }

    ?>
    <div class="wrap">
        <h1>Button &amp; Link Checker</h1>

        <p>
            This tool scans the website for <strong>buttons</strong> and <strong>text links</strong> that still use
            placeholder URLs (like <code>#</code> or <code>/</code>) or that have a
            <strong>VIIRL phone shortcode</strong> entered incorrectly.
        </p>

        <form method="post" style="margin: 1em 0 2em;">
            <?php wp_nonce_field( 'viirl_rr_link_checker_scan', 'viirl_rr_link_checker_nonce' ); ?>
            <p>
                <button type="submit" name="viirl_rr_link_checker_scan" class="button button-primary">
                    Scan Website
                </button>
            </p>
        </form>

        <?php if ( isset( $_POST['viirl_rr_link_checker_scan'] ) ) : ?>
            <hr />
            <h2>Scan Results</h2>

            <?php if ( empty( $results ) ) : ?>
                <p><strong>No issues were found.</strong></p>
            <?php else : ?>
                <p>Found <strong><?php echo (int) count( $results ); ?></strong> issue(s).</p>

                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:35%;">Page</th>
                            <th style="width:15%;">Type</th>
                            <th style="width:35%;">Link Text</th>
                            <th style="width:15%;">Issue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results as $row ) : ?>
                            <?php
                                // Normalized keys guaranteed by viirl_rr_link_checker_normalize_row().
                                $path      = $row['path'];
                                $title     = $row['page_title'];
                                $page_url  = $row['page_url'];
                                $type_html = $row['type_html'];
                                $link_text = $row['link_text'];
                                $issue     = $row['issue'];
                            ?>
                            <tr>
                                <td>
                                    <div style="font-family: monospace; opacity:.8;">
                                        <?php echo esc_html( $path ?: '/' ); ?>
                                    </div>

                                    <?php if ( ! empty( $page_url ) ) : ?>
                                        <a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html( $title ?: '(no title)' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <strong><?php echo esc_html( $title ?: '(no title)' ); ?></strong>
                                    <?php endif; ?>
                                </td>

                                <td><?php echo wp_kses_post( $type_html ); ?></td>
                                <td><?php echo esc_html( $link_text ); ?></td>
                                <td><code><?php echo esc_html( $issue ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Scan the whole site for link issues.
 *
 * @return array[] Normalized rows for UI.
 */
function viirl_rr_link_checker_scan_site() {
    $raw = [];

    $raw = array_merge( $raw, viirl_rr_link_checker_scan_posts() );
    $raw = array_merge( $raw, viirl_rr_link_checker_scan_menus() );
    $raw = array_merge( $raw, viirl_rr_link_checker_scan_widgets() );

    // Normalize all rows for the UI.
    $normalized = [];
    foreach ( $raw as $r ) {
        $normalized[] = viirl_rr_link_checker_normalize_row( $r );
    }

    return $normalized;
}

/**
 * Normalize a raw result row into consistent UI keys.
 *
 * Raw rows may contain:
 * - path, location, title, title_url, link_text, link_type, raw, context_type, elementor_template
 *
 * Normalized UI keys:
 * - path, page_title, page_url, type_html, link_text, issue
 *
 * @param array $row
 * @return array
 */
function viirl_rr_link_checker_normalize_row( array $row ) {
    $path      = $row['path'] ?? '/';
    $location  = $row['location'] ?? '';
    $title     = $row['title'] ?? '';
    $title_url = $row['title_url'] ?? '';
    $link_text = $row['link_text'] ?? '';
    $link_type = $row['link_type'] ?? '';
    $raw_href  = $row['raw'] ?? '';

    // Roadrunner dashboard URL for quick reference.
    // Adjust if your dashboard slug differs.
    $roadrunner_dashboard = admin_url( 'admin.php?page=viirl-roadrunner' );

    // Elementor templates dashboard.
    $elementor_templates_dashboard = admin_url( 'edit.php?post_type=elementor_library&tabs_group=library' );

    // Determine display title (Elementor vs regular pages).
    if ( ! empty( $row['elementor_template_name'] ) ) {
        $page_title = 'Elementor Template: ' . $row['elementor_template_name'];
        // For templates, link the TITLE to the templates dashboard (not a front-end page).
        $page_url   = $elementor_templates_dashboard;
    } else {
        $page_title = $title;
        // For regular pages, link to front-end.
        $page_url   = $title_url;
    }

    // Determine type label HTML.
    // - placeholder issues: show Button/Text link/Menu/Widget where possible
    // - phone shortcode issues: "Phone shortcode" (linked to Roadrunner dashboard)
    if ( $link_type === 'phone_shortcode' ) {
        $type_html = '<a href="' . esc_url( $roadrunner_dashboard ) . '">Phone shortcode</a>';
    } else {
        // Button vs text link, etc.
        $pretty = $link_type;

        // If a context type was set, prefer that.
        if ( ! empty( $row['ui_type'] ) ) {
            $pretty = $row['ui_type'];
        } elseif ( is_string( $link_type ) && $link_type !== '' ) {
            // Standardize common values.
            if ( strtolower( $link_type ) === 'placeholder' ) {
                $pretty = 'Text link';
            }
        } else {
            $pretty = 'Text link';
        }

        $type_html = esc_html( $pretty );
    }

    // Determine issue string.
    // If it’s a phone shortcode in the URL field, show that specifically.
    if ( $link_type === 'phone_shortcode' ) {
        $issue = 'Phone shortcode in URL field';
    } else {
        $issue = $raw_href ?: 'placeholder';
    }

    // If location is useful, you can append to title or issue later; keeping UI clean for now.

    return [
        'path'      => $path,
        'page_title'=> $page_title,
        'page_url'  => $page_url,
        'type_html' => $type_html,
        'link_text' => $link_text,
        'issue'     => $issue,
    ];
}

/**
 * Scan posts, pages, public CPTs and Elementor templates.
 *
 * @return array[] Raw rows.
 */
function viirl_rr_link_checker_scan_posts() {
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

        // Elementor templates often don’t have a meaningful front-end URL; still extract if present.
        $path = viirl_rr_link_checker_extract_path_from_url( $permalink );

        $context = [
            'path'     => $path ?: '/',
            'location' => $is_elementor_template ? 'Elementor Template' : ucfirst( $post->post_type ),
            'title'    => $post->post_title,
            'title_url'=> $permalink, // For pages, we want front-end links.
        ];

        if ( $is_elementor_template ) {
            $context['elementor_template_name'] = $post->post_title;
            // For templates, path is usually not meaningful; keep it but it may show "/?elementor_library=..."
            // If you want to suppress it, set to "/".
            if ( empty( $context['path'] ) ) {
                $context['path'] = '/';
            }
        }

        // Scan standard post content for <a href="..."> occurrences.
        $results = array_merge(
            $results,
            viirl_rr_link_checker_scan_html_for_links( $post->post_content, $context )
        );

        // Scan Elementor JSON for widget link fields.
        $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor_data ) && is_string( $elementor_data ) ) {
            $decoded = json_decode( $elementor_data, true );
            if ( is_array( $decoded ) ) {
                $results = array_merge(
                    $results,
                    viirl_rr_link_checker_scan_elementor_tree( $decoded, $context )
                );
            }
        }
    }

    wp_reset_postdata();
    return $results;
}

/**
 * Extract path from a URL (e.g. https://example.com/thank-you/ -> /thank-you/).
 */
function viirl_rr_link_checker_extract_path_from_url( $url ) {
    $parts = wp_parse_url( $url );
    return isset( $parts['path'] ) ? $parts['path'] : '/';
}

/**
 * Scan raw HTML for <a> tags with placeholder URLs or phone shortcodes in href.
 *
 * @param string $html
 * @param array  $context
 * @return array[] Raw rows.
 */
function viirl_rr_link_checker_scan_html_for_links( $html, $context ) {
    $results = [];

    if ( empty( $html ) || ! is_string( $html ) ) {
        return $results;
    }

    if ( ! preg_match_all( '#<a\s[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
        return $results;
    }

    foreach ( $matches as $m ) {
        $href      = trim( html_entity_decode( $m[1] ) );
        $link_text = wp_strip_all_tags( $m[2] );

        $classification = viirl_rr_link_checker_classify_href( $href );
        if ( ! $classification['is_issue'] ) {
            continue;
        }

        $results[] = [
            'path'      => $context['path'] ?? '/',
            'location'  => $context['location'] ?? '',
            'title'     => $context['title'] ?? '',
            'title_url' => $context['title_url'] ?? '',
            'link_text' => $link_text,
            'link_type' => $classification['link_type'],
            'raw'       => $href,
            // UI hint:
            'ui_type'   => 'Text link',
            // Elementor template label handling:
            'elementor_template_name' => $context['elementor_template_name'] ?? '',
        ];
    }

    return $results;
}

/**
 * Recursively scan Elementor element tree for link controls.
 *
 * @param array $elements
 * @param array $context
 * @return array[] Raw rows.
 */
function viirl_rr_link_checker_scan_elementor_tree( array $elements, array $context ) {
    $results = [];

    foreach ( $elements as $element ) {
        if ( ! is_array( $element ) ) {
            continue;
        }

        $el_type     = $element['elType'] ?? '';
        $widget      = $element['widgetType'] ?? '';
        $settings    = ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : [];
        $child_elems = ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) ? $element['elements'] : [];

        // Elementor stores links commonly as settings['link']['url'].
        if ( $el_type === 'widget' && isset( $settings['link'] ) && is_array( $settings['link'] ) && ! empty( $settings['link']['url'] ) ) {
            $href = trim( (string) $settings['link']['url'] );

            $classification = viirl_rr_link_checker_classify_href( $href );
            if ( $classification['is_issue'] ) {

                // Determine if this should be "Button" vs "Text link".
                $ui_type = 'Text link';
                if ( is_string( $widget ) && stripos( $widget, 'button' ) !== false ) {
                    $ui_type = 'Button';
                }

                // Try to infer visible text.
                $link_text = '';
                if ( isset( $settings['text'] ) && is_string( $settings['text'] ) ) {
                    $link_text = wp_strip_all_tags( $settings['text'] );
                } elseif ( isset( $settings['title'] ) && is_string( $settings['title'] ) ) {
                    $link_text = wp_strip_all_tags( $settings['title'] );
                } elseif ( isset( $settings['button_text'] ) && is_string( $settings['button_text'] ) ) {
                    $link_text = wp_strip_all_tags( $settings['button_text'] );
                }

                $results[] = [
                    'path'      => $context['path'] ?? '/',
                    'location'  => ( $context['location'] ?? '' ) . ' (Elementor)',
                    'title'     => $context['title'] ?? '',
                    'title_url' => $context['title_url'] ?? '',
                    'link_text' => $link_text,
                    'link_type' => $classification['link_type'],
                    'raw'       => $href,
                    'ui_type'   => ( $classification['link_type'] === 'phone_shortcode' ) ? 'Phone shortcode' : $ui_type,
                    'elementor_template_name' => $context['elementor_template_name'] ?? '',
                ];
            }
        }

        // Recurse.
        if ( ! empty( $child_elems ) ) {
            $results = array_merge( $results, viirl_rr_link_checker_scan_elementor_tree( $child_elems, $context ) );
        }
    }

    return $results;
}

/**
 * Classify a href / URL string.
 *
 * @param string $href
 * @return array{is_issue:bool, link_type:string}
 */
function viirl_rr_link_checker_classify_href( $href ) {
    $href = trim( (string) $href );

    // Phone shortcode accidentally used in a URL/link field.
    if ( stripos( $href, '[viirl_phone' ) !== false ) {
        return [
            'is_issue'  => true,
            'link_type' => 'phone_shortcode',
        ];
    }

    $lower = strtolower( $href );

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
            'link_type' => 'placeholder',
        ];
    }

    return [
        'is_issue'  => false,
        'link_type' => '',
    ];
}

/**
 * Scan navigation menus.
 *
 * @return array[] Raw rows.
 */
function viirl_rr_link_checker_scan_menus() {
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
            if ( ! $classification['is_issue'] ) {
                continue;
            }

            $results[] = [
                'path'      => viirl_rr_link_checker_extract_path_from_url( $href ),
                'location'  => 'Menu: ' . $menu->name,
                'title'     => $item->title,
                'title_url' => '', // No front-end "page" here in a reliable way.
                'link_text' => $item->title,
                'link_type' => $classification['link_type'],
                'raw'       => $href,
                'ui_type'   => 'Menu item',
            ];
        }
    }

    return $results;
}

/**
 * Scan text widgets for <a> tags with placeholder links / phone shortcodes.
 *
 * @return array[] Raw rows.
 */
function viirl_rr_link_checker_scan_widgets() {
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

            // Text widget only.
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
                'path'      => '/',
                'location'  => 'Widget: ' . $sidebar_id,
                'title'     => ! empty( $widget['title'] ) ? $widget['title'] : 'Text widget',
                'title_url' => '', // No front-end URL for widget itself.
            ];

            $results = array_merge( $results, viirl_rr_link_checker_scan_html_for_links( $text, $context ) );
        }
    }

    return $results;
}
