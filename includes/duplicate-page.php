<?php
/**
 * VIIRL Duplicate Page (Draft)
 *
 * Adds a "VIIRL Duplicate" row action on Pages/Posts list screens.
 * Duplicates the selected post/page and saves the duplicate as a draft.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add row action link: "VIIRL Duplicate"
 */
add_filter( 'post_row_actions', 'viirl_rr_add_duplicate_row_action', 10, 2 );
add_filter( 'page_row_actions', 'viirl_rr_add_duplicate_row_action', 10, 2 );

function viirl_rr_add_duplicate_row_action( $actions, $post ) {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return $actions;
    }

    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return $actions;
    }

    $url = wp_nonce_url(
        admin_url( 'admin-post.php?action=viirl_rr_duplicate_post&post_id=' . absint( $post->ID ) ),
        'viirl_rr_duplicate_post_' . absint( $post->ID )
    );

    $actions['viirl_rr_duplicate'] = '<a href="' . esc_url( $url ) . '">VIIRL Duplicate</a>';

    return $actions;
}

/**
 * Handle duplication request.
 */
add_action( 'admin_post_viirl_rr_duplicate_post', 'viirl_rr_handle_duplicate_post' );

function viirl_rr_handle_duplicate_post() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
    if ( ! $post_id ) {
        wp_die( 'Missing post_id.' );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    check_admin_referer( 'viirl_rr_duplicate_post_' . $post_id );

    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_die( 'Post not found.' );
    }

    /**
     * Create the new draft.
     * Keep post_content because it's harmless, but Elementor should read _elementor_data.
     */
    $new_post_args = array(
        'post_type'      => $post->post_type,
        'post_status'    => 'draft',
        'post_title'     => $post->post_title . ' (Copy)',
        'post_content'   => $post->post_content,
        'post_excerpt'   => $post->post_excerpt,
        'post_parent'    => $post->post_parent,
        'menu_order'     => $post->menu_order,
        'post_password'  => $post->post_password,
        'comment_status' => $post->comment_status,
        'ping_status'    => $post->ping_status,
        'post_author'    => get_current_user_id(),
    );

    $new_post_id = wp_insert_post( $new_post_args, true );

    if ( is_wp_error( $new_post_id ) ) {
        wp_die( $new_post_id->get_error_message() );
    }

    /**
     * Copy taxonomies.
     */
    $taxonomies = get_object_taxonomies( $post->post_type );
    if ( ! empty( $taxonomies ) ) {
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) ) {
                wp_set_object_terms( $new_post_id, $terms, $taxonomy );
            }
        }
    }

    /**
     * Copy featured image.
     */
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    if ( $thumbnail_id ) {
        set_post_thumbnail( $new_post_id, $thumbnail_id );
    }

    /**
     * Copy normal post meta first.
     * Skip Elementor-critical keys here because we handle them separately.
     */
    $all_meta = get_post_meta( $post_id );

    $skip_keys = array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',

        // Elementor critical keys handled separately below
        '_elementor_data',
        '_elementor_edit_mode',
        '_elementor_template_type',
        '_elementor_version',
        '_elementor_page_settings',
        '_elementor_css',
        '_elementor_page_assets',
        '_elementor_controls_usage',
    );

    if ( ! empty( $all_meta ) ) {
        foreach ( $all_meta as $meta_key => $values ) {
            if ( in_array( $meta_key, $skip_keys, true ) ) {
                continue;
            }

            delete_post_meta( $new_post_id, $meta_key );

            foreach ( $values as $value ) {
                add_post_meta( $new_post_id, $meta_key, maybe_unserialize( $value ) );
            }
        }
    }

    /**
     * Force-copy critical Elementor/meta keys exactly how Elementor expects them.
     */

    // 1) _elementor_data: JSON string, MUST be wp_slash()'d before saving.
    $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
    if ( ! empty( $elementor_data ) ) {
        delete_post_meta( $new_post_id, '_elementor_data' );
        update_post_meta( $new_post_id, '_elementor_data', wp_slash( $elementor_data ) );
    }

    // 2) Simple single-value Elementor/meta keys.
    $single_meta_keys = array(
        '_elementor_edit_mode',
        '_elementor_template_type',
        '_elementor_version',
        '_wp_page_template',
    );

    foreach ( $single_meta_keys as $meta_key ) {
        $meta_value = get_post_meta( $post_id, $meta_key, true );
        if ( '' !== $meta_value && false !== $meta_value ) {
            delete_post_meta( $new_post_id, $meta_key );
            update_post_meta( $new_post_id, $meta_key, $meta_value );
        }
    }

    // 3) JSON/array-ish Elementor keys that may need slashing if string-based.
    $json_like_keys = array(
        '_elementor_page_settings',
        '_elementor_page_assets',
        '_elementor_controls_usage',
    );

    foreach ( $json_like_keys as $meta_key ) {
        $meta_value = get_post_meta( $post_id, $meta_key, true );

        if ( '' === $meta_value || false === $meta_value ) {
            continue;
        }

        delete_post_meta( $new_post_id, $meta_key );

        if ( is_string( $meta_value ) ) {
            update_post_meta( $new_post_id, $meta_key, wp_slash( $meta_value ) );
        } else {
            update_post_meta( $new_post_id, $meta_key, $meta_value );
        }
    }

    /**
     * If Elementor data exists, explicitly mark as builder mode.
     */
    if ( ! empty( $elementor_data ) ) {
        update_post_meta( $new_post_id, '_elementor_edit_mode', 'builder' );
    }

    /**
     * Remove generated CSS so Elementor rebuilds it for the duplicate.
     */
    delete_post_meta( $new_post_id, '_elementor_css' );

    if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
        try {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        } catch ( \Throwable $e ) {
            // Ignore cache-clearing issues.
        }
    }

    /**
     * Redirect back to list screen with a success notice.
     */
    $sendback = wp_get_referer();
    if ( ! $sendback ) {
        $sendback = admin_url( 'edit.php?post_type=' . $post->post_type );
    }

    $sendback = add_query_arg(
        array(
            'viirl_rr_duplicated' => 1,
            'duplicated_id'       => $new_post_id,
        ),
        $sendback
    );

    wp_safe_redirect( $sendback );
    exit;
}

/**
 * Admin notice after duplication.
 */
add_action( 'admin_notices', 'viirl_rr_duplicate_notice' );

function viirl_rr_duplicate_notice() {
    if ( empty( $_GET['viirl_rr_duplicated'] ) ) {
        return;
    }

    $new_id = isset( $_GET['duplicated_id'] ) ? absint( $_GET['duplicated_id'] ) : 0;
    if ( ! $new_id ) {
        return;
    }

    $edit_link = get_edit_post_link( $new_id, 'raw' );

    echo '<div class="notice notice-success is-dismissible"><p>';
    echo 'Draft duplicate created. ';
    if ( $edit_link ) {
        echo '<a href="' . esc_url( $edit_link ) . '">Edit the duplicate</a>.';
    }
    echo '</p></div>';
}