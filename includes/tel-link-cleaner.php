<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roadrunner Tel Link Cleaner
 *
 * Adds a submenu page under the VIIRL Roadrunner plugin where admins can:
 * - review what the cleaner does
 * - run a dry scan to preview affected tel: links
 * - apply cleanup to normalize tel: links across content
 * - view a report of affected posts and numbers changed
 *
 * This tool scans:
 * - standard WordPress post_content
 * - Elementor _elementor_data (including raw URL fields)
 *
 * Tel links are normalized to a strict 10-digit format:
 * - strips all non-digit characters
 * - removes leading "1" if present (US country code)
 * - leaves non-10-digit numbers unchanged
 */
 
function viirl_rr_render_tel_link_cleaner_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$mode    = '';
	$results = null;

	if (
		isset( $_POST['viirl_rr_tel_cleaner_action'] ) &&
		isset( $_POST['viirl_rr_tel_cleaner_nonce'] ) &&
		wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['viirl_rr_tel_cleaner_nonce'] ) ),
			'viirl_rr_tel_cleaner_action'
		)
	) {
		$mode = sanitize_text_field( wp_unslash( $_POST['viirl_rr_tel_cleaner_action'] ) );

		if ( 'dry_run' === $mode ) {
			$results = viirl_rr_run_tel_link_cleanup( false );
		} elseif ( 'apply' === $mode ) {
			$results = viirl_rr_run_tel_link_cleanup( true );
		}
	}
	?>
	<div class="wrap">
		<h1>Tel Link Cleaner</h1>

		<p>
			This utility scans saved site content for malformed <code>tel:</code> links and reformats them to a 10-digit standard.
		</p>

		<p>
			Example: <code>tel:+1(222) 455-6677</code> becomes <code>tel:2224556677</code>.
		</p>

		<p>
			It checks standard WordPress content and Elementor data. Use <strong>Dry Run</strong> first to preview what would change, then run <strong>Apply Cleanup</strong> to save the updates.
		</p>

		<form method="post" style="margin-top:20px;">
			<?php wp_nonce_field( 'viirl_rr_tel_cleaner_action', 'viirl_rr_tel_cleaner_nonce' ); ?>

			<p style="display:flex; gap:12px; align-items:center;">
				<button type="submit" name="viirl_rr_tel_cleaner_action" value="dry_run" class="button button-secondary">
					Run Dry Scan
				</button>

				<button
					type="submit"
					name="viirl_rr_tel_cleaner_action"
					value="apply"
					class="button button-primary"
					onclick="return confirm('This will update saved content where malformed tel: links are found. Continue?');"
				>
					Apply Cleanup
				</button>
			</p>
		</form>

		<?php if ( is_array( $results ) ) : ?>
			<hr>
			<h2>
				<?php echo ( 'dry_run' === $mode ) ? 'Dry Run Results' : 'Cleanup Results'; ?>
			</h2>

			<p><strong>Posts scanned:</strong> <?php echo esc_html( $results['posts_scanned'] ); ?></p>
			<p><strong>Posts with detected changes:</strong> <?php echo esc_html( $results['posts_with_changes'] ); ?></p>
			<p><strong>Total tel links detected:</strong> <?php echo esc_html( $results['total_links_changed'] ); ?></p>

			<?php if ( 'dry_run' === $mode ) : ?>
				<p><em>No changes were saved.</em></p>
			<?php else : ?>
				<p><em>Detected changes were applied and saved.</em></p>
			<?php endif; ?>

			<?php if ( ! empty( $results['items'] ) ) : ?>
				<table class="widefat striped" style="margin-top:20px;">
					<thead>
						<tr>
							<th style="width:28%;">Post</th>
							<th style="width:12%;">Type</th>
							<th style="width:14%;">Source</th>
							<th>Affected Links</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $results['items'] as $item ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>">
										<?php echo esc_html( $item['post_title'] ); ?>
									</a>
									<br>
									<small>ID: <?php echo esc_html( $item['post_id'] ); ?></small>
								</td>
								<td><?php echo esc_html( $item['post_type'] ); ?></td>
								<td><?php echo esc_html( $item['source'] ); ?></td>
								<td>
									<?php if ( ! empty( $item['changes'] ) ) : ?>
										<ul style="margin:0; padding-left:18px;">
											<?php foreach ( $item['changes'] as $change ) : ?>
												<li>
													<code><?php echo esc_html( urldecode( $change['old'] ) ); ?></code>
													&rarr;
													<code><?php echo esc_html( urldecode( $change['new'] ) ); ?></code>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>No malformed <code>tel:</code> links were found.</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Run tel link cleanup or dry run.
 *
 * @param bool $apply_changes Whether to save detected changes.
 * @return array
 */
function viirl_rr_run_tel_link_cleanup( $apply_changes = false ) {
	$results = array(
		'posts_scanned'       => 0,
		'posts_with_changes'  => 0,
		'total_links_changed' => 0,
		'items'               => array(),
	);

	$post_types = get_post_types(
		array(
			'public' => true,
		),
		'names'
	);

	$query = new WP_Query(
		array(
			'post_type'              => array_values( $post_types ),
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( empty( $query->posts ) ) {
		return $results;
	}

	foreach ( $query->posts as $post_id ) {
		$results['posts_scanned']++;

		$post = get_post( $post_id );
		if ( ! $post ) {
			continue;
		}

		$post_title = get_the_title( $post_id );
		$post_title = $post_title ? $post_title : '(no title)';
		$post_type  = get_post_type( $post_id );

		// Standard post content.
		$content_changes = array();
		$new_content     = viirl_rr_clean_tel_links_in_string( $post->post_content, $content_changes );

		if ( ! empty( $content_changes ) ) {
			$results['posts_with_changes']++;
			$results['total_links_changed'] += count( $content_changes );

			$results['items'][] = array(
				'post_id'    => $post_id,
				'post_title' => $post_title,
				'post_type'  => $post_type,
				'source'     => 'post_content',
				'changes'    => $content_changes,
			);

			if ( $apply_changes && $new_content !== $post->post_content ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $new_content,
					)
				);
			}
		}

		// Elementor data.
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! empty( $elementor_data ) && is_string( $elementor_data ) ) {
			$elementor_changes = array();
			$new_elementor     = viirl_rr_clean_elementor_tel_links( $elementor_data, $elementor_changes );

			if ( ! empty( $elementor_changes ) ) {
				$results['posts_with_changes']++;
				$results['total_links_changed'] += count( $elementor_changes );

				$results['items'][] = array(
					'post_id'    => $post_id,
					'post_title' => $post_title,
					'post_type'  => $post_type,
					'source'     => 'elementor_data',
					'changes'    => $elementor_changes,
				);

				if ( $apply_changes && $new_elementor !== $elementor_data ) {
					update_post_meta( $post_id, '_elementor_data', wp_slash( $new_elementor ) );

					if ( class_exists( '\Elementor\Plugin' ) ) {
						$elementor_instance = \Elementor\Plugin::$instance;

						if ( isset( $elementor_instance->files_manager ) ) {
							$elementor_instance->files_manager->clear_cache();
						}
					}
				}
			}
		}
	}

	return $results;
}

/**
 * Clean tel: links inside general HTML/text content.
 *
 * @param string $content Content to scan.
 * @param array  $changes Collected changes.
 * @return string
 */
function viirl_rr_clean_tel_links_in_string( $content, &$changes = array() ) {
	if ( empty( $content ) || ! is_string( $content ) ) {
		return $content;
	}

	$changes = array();
	$updated = $content;

	$updated = preg_replace_callback(
		'/href=(["\'])(tel:[^"\']+)\1/i',
		function( $matches ) use ( &$changes ) {
			$quote    = $matches[1];
			$original = $matches[2];
			$cleaned  = viirl_rr_normalize_tel_value( $original );

			if ( $cleaned !== $original ) {
				$changes[] = array(
					'old' => $original,
					'new' => $cleaned,
				);
			}

			return 'href=' . $quote . $cleaned . $quote;
		},
		$updated
	);

	if ( preg_match( '/^tel:/i', trim( $updated ) ) ) {
		$original = trim( $updated );
		$cleaned  = viirl_rr_normalize_tel_value( $original );

		if ( $cleaned !== $original ) {
			$already_recorded = false;

			foreach ( $changes as $change ) {
				if ( $change['old'] === $original && $change['new'] === $cleaned ) {
					$already_recorded = true;
					break;
				}
			}

			if ( ! $already_recorded ) {
				$changes[] = array(
					'old' => $original,
					'new' => $cleaned,
				);
			}

			$updated = $cleaned;
		}
	}

	return $updated;
}

/**
 * Clean tel values inside Elementor JSON data.
 *
 * @param string $json Elementor raw JSON string.
 * @param array  $changes Collected changes.
 * @return string
 */
function viirl_rr_clean_elementor_tel_links( $json, &$changes = array() ) {
	$changes = array();

	$data = json_decode( $json, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		return viirl_rr_clean_tel_links_in_string( $json, $changes );
	}

	$data = viirl_rr_walk_elementor_data( $data, $changes );

	$encoded = wp_json_encode( $data );

	return is_string( $encoded ) ? $encoded : $json;
}

/**
 * Recursively walk Elementor data and clean tel links.
 *
 * @param mixed $value Current value.
 * @param array $changes Collected changes.
 * @return mixed
 */
function viirl_rr_walk_elementor_data( $value, &$changes ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			if ( 'url' === $key && is_string( $item ) && preg_match( '/^tel:/i', trim( $item ) ) ) {
				$original = $item;
				$cleaned  = viirl_rr_normalize_tel_value( $item );

				if ( $cleaned !== $original ) {
					$changes[]   = array(
						'old' => $original,
						'new' => $cleaned,
					);
					$value[ $key ] = $cleaned;
				}
			} elseif ( is_string( $item ) && stripos( $item, 'tel:' ) !== false ) {
				$string_changes = array();
				$cleaned_string = viirl_rr_clean_tel_links_in_string( $item, $string_changes );

				if ( ! empty( $string_changes ) ) {
					$value[ $key ] = $cleaned_string;
					$changes       = array_merge( $changes, $string_changes );
				}
			} else {
				$value[ $key ] = viirl_rr_walk_elementor_data( $item, $changes );
			}
		}
	}

	return $value;
}

/**
 * Normalize a tel: value to strict 10-digit format where possible.
 *
 * Rules:
 * - strip all non-digits
 * - if 11 digits and starts with 1, remove leading 1
 * - return tel:XXXXXXXXXX
 * - if final length is not 10, leave original unchanged
 *
 * @param string $tel_value Raw tel value.
 * @return string
 */
function viirl_rr_normalize_tel_value( $tel_value ) {
	$original = $tel_value;

	$raw_number = preg_replace( '/^tel:/i', '', trim( $tel_value ) );
	$raw_number = urldecode( $raw_number );

	$digits = preg_replace( '/\D+/', '', $raw_number );

	if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
		$digits = substr( $digits, 1 );
	}

	if ( 10 !== strlen( $digits ) ) {
		return $original;
	}

	return 'tel:' . $digits;
}