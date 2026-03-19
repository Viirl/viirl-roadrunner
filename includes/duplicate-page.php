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

    // Only show for posts the user can actually edit.
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return $actions;
    }

    // Optional: limit to pages only (uncomment if you want ONLY Pages)
    // if ( $post->post_type !== 'page' ) return $actions;

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

    // Build the duplicated post (as draft).
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

    // Copy taxonomies.
    $taxonomies = get_object_taxonomies( $post->post_type );
    if ( ! empty( $taxonomies ) ) {
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $new_post_id, $terms, $taxonomy );
            }
        }
    }

    // Copy post meta (including Elementor data).
    $meta = get_post_meta( $post_id );
    if ( ! empty( $meta ) ) {
        foreach ( $meta as $meta_key => $values ) {
            // Skip internal keys that should not be cloned
            $skip_keys = array(
                '_edit_lock',
                '_edit_last',
                '_wp_old_slug',
            );

            if ( in_array( $meta_key, $skip_keys, true ) ) {
                continue;
            }

            foreach ( $values as $value ) {
                add_post_meta( $new_post_id, $meta_key, maybe_unserialize( $value ) );
            }
        }
    }

    // Redirect back to list screen with a success notice.
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