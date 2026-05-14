<?php
/**
 * Bricks Builder exporter.
 *
 * Builds a zip archive containing Bricks options, pages, templates, and all
 * associated meta. The archive can be streamed to the browser (admin) or
 * written to disk (WP-CLI).
 *
 * @package BricksIE
 */

class Bricks_IE_Exporter {

	/**
	 * Get the list of option names to export.
	 *
	 * @return array
	 */
	private function get_option_names() {
		return bricks_ie_get_option_names();
	}

	/**
	 * Get the list of meta keys to export.
	 *
	 * @return array
	 */
	private function get_meta_keys() {
		return apply_filters( 'bricks_ie_meta_keys', array(
			'_bricks_page_content_2',
			'_bricks_page_header_2',
			'_bricks_page_footer_2',
			'_bricks_editor_mode',
			'_bricks_template_type',
			'_bricks_template_settings',
			'_bricks_page_settings',
		) );
	}

	/**
	 * Get the list of post types to export.
	 *
	 * @return array
	 */
	private function get_post_types() {
		return apply_filters( 'bricks_ie_post_types', array( 'page', 'bricks_template' ) );
	}

	/**
	 * Build the zip archive at the given file path.
	 *
	 * @param string $output_path Absolute path where the zip file will be written.
	 * @return array|WP_Error On success returns array with keys 'file', 'options_count', 'posts_count', 'size'. On failure a WP_Error.
	 */
	public function build_zip( $output_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'ZipArchive is not available on this server.', 'bricks-ie' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not create the zip archive.', 'bricks-ie' ) );
		}

		$options     = $this->collect_options();
		$posts       = $this->collect_posts();
		$posts_index = array();

		foreach ( $options as $name => $value ) {
			$zip->addFromString( 'options/' . $name . '.json', wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		}

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $item ) {
				$filename = sanitize_file_name( $item['type'] . '__' . $item['slug'] );
				if ( empty( $filename ) ) {
					$filename = sanitize_file_name( $item['type'] . '__id-' . $item['id'] );
				}
				$filename .= '.json';

				$posts_index[] = array(
					'slug' => $item['slug'],
					'type' => $item['type'],
					'file' => $filename,
				);

				$zip->addFromString( 'posts/' . $filename, wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
			}

			$zip->addFromString( 'posts/index.json', wp_json_encode( $posts_index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		}

		$manifest = $this->build_manifest( count( $options ), count( $posts ) );
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );

		$zip->close();

		return array(
			'file'          => $output_path,
			'options_count' => count( $options ),
			'posts_count'   => count( $posts ),
			'size'          => filesize( $output_path ),
		);
	}

	/**
	 * Handle the admin export request — build the zip and stream it to the browser.
	 */
	public function download() {
		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$temp_file = wp_tempnam( 'bricks-ie-export-' );
		if ( ! $temp_file ) {
			wp_die( esc_html__( 'Could not create temporary file for the export.', 'bricks-ie' ) );
		}

		$result = $this->build_zip( $temp_file );

		if ( is_wp_error( $result ) ) {
			unlink( $temp_file );
			wp_die( esc_html( $result->get_error_message() ) );
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="bricks-export-' . gmdate( 'Y-m-d' ) . '.zip"' );
		header( 'Content-Length: ' . $result['size'] );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		readfile( $temp_file );
		unlink( $temp_file );
		exit;
	}

	/**
	 * Collect Bricks options. Skips options that do not exist.
	 *
	 * @return array Associative array of option_name => value.
	 */
	private function collect_options() {
		$options = array();

		foreach ( $this->get_option_names() as $name ) {
			$value = get_option( $name, null );
			if ( null !== $value ) {
				$options[ $name ] = $value;
			}
		}

		return $options;
	}

	/**
	 * Collect posts that carry Bricks meta.
	 *
	 * @return array Array of post data items with meta encoded as base64 of serialize.
	 */
	private function collect_posts() {
		$posts = get_posts( array(
			'post_type'      => $this->get_post_types(),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$out     = array();
		$meta_keys = $this->get_meta_keys();

		foreach ( $posts as $p ) {
			$meta = array();

			foreach ( $meta_keys as $k ) {
				$v = get_post_meta( $p->ID, $k, true );
				if ( '' !== $v ) {
					$meta[ $k ] = base64_encode( serialize( $v ) );
				}
			}

			if ( ! empty( $meta ) ) {
				$out[] = array(
					'id'     => $p->ID,
					'slug'   => $p->post_name,
					'type'   => $p->post_type,
					'status' => $p->post_status,
					'title'  => $p->post_title,
					'meta'   => $meta,
				);
			}
		}

		return $out;
	}

	/**
	 * Build the manifest describing the export.
	 *
	 * @param int $options_count Number of options included.
	 * @param int $posts_count   Number of posts included.
	 * @return array
	 */
	private function build_manifest( $options_count, $posts_count ) {
		$bricks_theme = wp_get_theme( 'bricks' );

		return array(
			'version'        => 1,
			'plugin_version' => defined( 'BRICKS_IE_VERSION' ) ? BRICKS_IE_VERSION : null,
			'generated_at'   => gmdate( 'c' ),
			'site_url'       => home_url(),
			'bricks_version' => $bricks_theme->exists() ? $bricks_theme->get( 'Version' ) : null,
			'counts'         => array(
				'options' => $options_count,
				'posts'   => $posts_count,
			),
		);
	}
}
