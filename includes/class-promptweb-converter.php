<?php
/**
 * LEGACY / DEPRECATED — Blueprint → Gutenberg page converter.
 *
 * -------------------------------------------------------------------------
 * ⚠ DO NOT USE FOR NEW FEATURES.
 *
 * PromptWeb’s product direction is **Maximum AI Creativity**:
 *   - Structured JSON (GitHub) is the single source of truth.
 *   - PromptWeb_Renderer outputs HTML from pages → sections → elements.
 *   - PromptWeb_Editor edits AI-generated elements on the frontend.
 *   - Gutenberg block conversion is NOT the main path and will be removed
 *     in a future major version.
 *
 * This file is kept only for backward compatibility (e.g. sites that still
 * opt in via the `promptweb_sync_use_legacy_converter` filter). Prefer
 * Schema + Renderer + Editor instead.
 * -------------------------------------------------------------------------
 *
 * @package PromptWeb
 * @since   1.0.0
 * @deprecated 1.0.0 Use PromptWeb_Renderer and PromptWeb_Schema instead.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LEGACY: Converts blueprints into Gutenberg-powered WP pages.
 *
 * @since 1.0.0
 * @deprecated 1.0.0 Maximum AI Creativity uses JSON + Renderer, not Gutenberg.
 */
class PromptWeb_Converter {

	/**
	 * Post meta key: page is managed by PromptWeb (legacy converter only).
	 *
	 * @since 1.0.0
	 * @deprecated 1.0.0
	 * @var   string
	 */
	const META_MANAGED = '_promptweb_managed';

	/**
	 * Post meta key: blueprint slug used for create/update matching (legacy).
	 *
	 * @since 1.0.0
	 * @deprecated 1.0.0
	 * @var   string
	 */
	const META_SLUG = '_promptweb_blueprint_slug';

	/**
	 * LEGACY: Convert a decoded blueprint into Gutenberg pages.
	 *
	 * Not part of the Maximum AI Creativity path. Will emit a deprecation
	 * notice when WP_DEBUG is enabled.
	 *
	 * @since 1.0.0
	 * @deprecated 1.0.0 Use promptweb()->renderer->render() with Schema JSON.
	 * @param mixed $blueprint_data Decoded JSON (array).
	 * @return array{
	 *     success: bool,
	 *     message: string,
	 *     code?: string,
	 *     pages?: array<int, array>,
	 *     site?: array,
	 *     errors?: array<int, string>
	 * }
	 */
	public function convert_blueprint( $blueprint_data ) {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function(
				__METHOD__,
				'PromptWeb 1.0.0',
				'PromptWeb_Renderer::render() / PromptWeb_Schema (Maximum AI Creativity JSON path)'
			);
		}

		if ( ! is_array( $blueprint_data ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_invalid_blueprint',
				'message' => __( 'Blueprint data must be a JSON object/array.', 'promptweb' ),
			);
		}

		/**
		 * Filters blueprint data before conversion.
		 *
		 * @since 1.0.0
		 * @param array $blueprint_data Blueprint payload.
		 */
		$blueprint_data = apply_filters( 'promptweb_before_convert_blueprint', $blueprint_data );

		if ( ! is_array( $blueprint_data ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_invalid_blueprint',
				'message' => __( 'Blueprint data became invalid after filtering.', 'promptweb' ),
			);
		}

		$pages_def = isset( $blueprint_data['pages'] ) ? $blueprint_data['pages'] : null;

		if ( ! is_array( $pages_def ) || empty( $pages_def ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_no_pages',
				'message' => __( 'Blueprint has no pages to convert.', 'promptweb' ),
			);
		}

		$site_result   = array(
			'updated' => false,
			'fields'  => array(),
		);
		$page_results  = array();
		$errors        = array();
		$front_page_id = 0;

		// Optional site-level options (blogname / tagline).
		if ( ! empty( $blueprint_data['site'] ) && is_array( $blueprint_data['site'] ) ) {
			$site_result = $this->apply_site_settings( $blueprint_data['site'] );
		}

		foreach ( $pages_def as $index => $page_def ) {
			if ( ! is_array( $page_def ) ) {
				$errors[] = sprintf(
					/* translators: %d: page index in blueprint */
					__( 'Page at index %d is invalid and was skipped.', 'promptweb' ),
					(int) $index
				);
				continue;
			}

			$result = $this->upsert_page( $page_def );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			$page_results[] = $result;

			if ( ! empty( $page_def['is_front_page'] ) && ! empty( $result['id'] ) ) {
				$front_page_id = (int) $result['id'];
			}
		}

		if ( $front_page_id > 0 ) {
			$this->set_front_page( $front_page_id );
		}

		if ( empty( $page_results ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_convert_failed',
				'message' => ! empty( $errors )
					? implode( ' ', $errors )
					: __( 'No pages were created or updated from the blueprint.', 'promptweb' ),
				'errors'  => $errors,
				'site'    => $site_result,
			);
		}

		$created = 0;
		$updated = 0;
		foreach ( $page_results as $row ) {
			if ( isset( $row['action'] ) && 'created' === $row['action'] ) {
				++$created;
			} else {
				++$updated;
			}
		}

		$message = sprintf(
			/* translators: 1: created count, 2: updated count */
			__( 'Blueprint converted: %1$d page(s) created, %2$d page(s) updated.', 'promptweb' ),
			$created,
			$updated
		);

		if ( ! empty( $errors ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of page errors */
				_n( '%d page had errors.', '%d pages had errors.', count( $errors ), 'promptweb' ),
				count( $errors )
			);
		}

		$payload = array(
			'success' => true,
			'code'    => empty( $errors ) ? 'promptweb_convert_success' : 'promptweb_convert_partial',
			'message' => $message,
			'pages'   => $page_results,
			'site'    => $site_result,
			'errors'  => $errors,
		);

		/**
		 * Fires after a blueprint conversion that created/updated at least one page.
		 *
		 * @since 1.0.0
		 * @param array $payload        Conversion result.
		 * @param array $blueprint_data Source blueprint.
		 */
		do_action( 'promptweb_blueprint_converted', $payload, $blueprint_data );

		return $payload;
	}

	/**
	 * Apply site title / tagline from the blueprint.
	 *
	 * @since 1.0.0
	 * @param array $site Site section of the blueprint.
	 * @return array{updated: bool, fields: array<int, string>}
	 */
	protected function apply_site_settings( array $site ) {
		$fields  = array();
		$updated = false;

		if ( isset( $site['title'] ) && is_string( $site['title'] ) && '' !== $site['title'] ) {
			$title = sanitize_text_field( $site['title'] );
			if ( get_option( 'blogname' ) !== $title ) {
				update_option( 'blogname', $title );
				$updated = true;
			}
			$fields[] = 'title';
		}

		if ( isset( $site['tagline'] ) && is_string( $site['tagline'] ) ) {
			$tagline = sanitize_text_field( $site['tagline'] );
			if ( get_option( 'blogdescription' ) !== $tagline ) {
				update_option( 'blogdescription', $tagline );
				$updated = true;
			}
			$fields[] = 'tagline';
		}

		return array(
			'updated' => $updated,
			'fields'  => $fields,
		);
	}

	/**
	 * Create or update a single page from a blueprint page definition.
	 *
	 * @since 1.0.0
	 * @param array $page_def Page definition.
	 * @return array|WP_Error {
	 *     @type int    $id     Post ID.
	 *     @type string $slug   Page slug.
	 *     @type string $title  Page title.
	 *     @type string $action created|updated.
	 *     @type string $status Post status.
	 * }
	 */
	protected function upsert_page( array $page_def ) {
		$title = isset( $page_def['title'] ) ? sanitize_text_field( (string) $page_def['title'] ) : '';
		$slug  = isset( $page_def['slug'] ) ? sanitize_title( (string) $page_def['slug'] ) : '';

		if ( '' === $title && '' === $slug ) {
			return new WP_Error(
				'promptweb_page_missing_identity',
				__( 'A page in the blueprint is missing both title and slug.', 'promptweb' )
			);
		}

		if ( '' === $slug ) {
			$slug = sanitize_title( $title );
		}

		if ( '' === $title ) {
			$title = $slug;
		}

		$status           = isset( $page_def['status'] ) ? sanitize_key( (string) $page_def['status'] ) : 'publish';
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'publish';
		}

		$blocks  = isset( $page_def['blocks'] ) && is_array( $page_def['blocks'] ) ? $page_def['blocks'] : array();
		$content = $this->blocks_to_content( $blocks );

		/**
		 * Filters generated post_content for a blueprint page.
		 *
		 * @since 1.0.0
		 * @param string $content  Block markup.
		 * @param array  $page_def Page definition.
		 * @param array  $blocks   Block definitions.
		 */
		$content = apply_filters( 'promptweb_page_content', $content, $page_def, $blocks );

		$existing_id = $this->find_page_id( $slug );
		$postarr     = array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => $status,
			'post_content' => $content,
		);

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$page_id       = wp_update_post( wp_slash( $postarr ), true );
			$action        = 'updated';
		} else {
			$page_id = wp_insert_post( wp_slash( $postarr ), true );
			$action  = 'created';
		}

		if ( is_wp_error( $page_id ) ) {
			return new WP_Error(
				'promptweb_page_save_failed',
				sprintf(
					/* translators: 1: page title, 2: error message */
					__( 'Could not save page “%1$s”: %2$s', 'promptweb' ),
					$title,
					$page_id->get_error_message()
				)
			);
		}

		$page_id = (int) $page_id;

		update_post_meta( $page_id, self::META_MANAGED, 1 );
		update_post_meta( $page_id, self::META_SLUG, $slug );

		return array(
			'id'     => $page_id,
			'slug'   => $slug,
			'title'  => $title,
			'action' => $action,
			'status' => $status,
		);
	}

	/**
	 * Locate an existing page by blueprint slug or WordPress path.
	 *
	 * @since 1.0.0
	 * @param string $slug Sanitized slug.
	 * @return int Post ID or 0.
	 */
	protected function find_page_id( $slug ) {
		// Prefer pages previously managed by PromptWeb with this slug meta.
		$query = new WP_Query(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_SLUG,
						'value' => $slug,
					),
				),
			)
		);

		if ( ! empty( $query->posts[0] ) ) {
			return (int) $query->posts[0];
		}

		// Fall back to native page path match.
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page instanceof WP_Post ) {
			return (int) $page->ID;
		}

		return 0;
	}

	/**
	 * Assign a page as the site front page.
	 *
	 * @since 1.0.0
	 * @param int $page_id Page post ID.
	 * @return void
	 */
	protected function set_front_page( $page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return;
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
	}

	/**
	 * Convert a list of blueprint blocks into Gutenberg post_content markup.
	 *
	 * @since 1.0.0
	 * @param array $blocks List of block definition arrays.
	 * @return string
	 */
	public function blocks_to_content( array $blocks ) {
		$parts = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$markup = $this->convert_block( $block );
			if ( is_string( $markup ) && '' !== trim( $markup ) ) {
				$parts[] = $markup;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Convert a single blueprint block definition to block HTML comments + markup.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string Empty string if unsupported/empty.
	 */
	public function convert_block( array $block ) {
		$type = isset( $block['type'] ) ? strtolower( (string) $block['type'] ) : '';
		$type = str_replace( 'core/', '', $type );

		/**
		 * Filters a single converted block markup string.
		 *
		 * Return a non-null string to short-circuit built-in converters.
		 *
		 * @since 1.0.0
		 * @param string|null $pre   Pre-rendered markup or null to continue.
		 * @param array       $block Block definition.
		 * @param string      $type  Normalized type (without core/ prefix).
		 */
		$pre = apply_filters( 'promptweb_convert_block_pre', null, $block, $type );
		if ( is_string( $pre ) ) {
			return $pre;
		}

		switch ( $type ) {
			case 'heading':
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$markup = $this->render_heading_block( $block, $type );
				break;

			case 'paragraph':
			case 'p':
			case 'text':
				$markup = $this->render_paragraph_block( $block );
				break;

			case 'buttons':
			case 'button-group':
				$markup = $this->render_buttons_block( $block );
				break;

			case 'button':
				// Single button → wrap as buttons group for valid structure.
				$markup = $this->render_buttons_block(
					array(
						'type'  => 'buttons',
						'items' => array( $block ),
					)
				);
				break;

			case 'image':
				$markup = $this->render_image_block( $block );
				break;

			case 'list':
				$markup = $this->render_list_block( $block );
				break;

			case 'separator':
			case 'divider':
				$markup = $this->render_separator_block();
				break;

			case 'spacer':
				$markup = $this->render_spacer_block( $block );
				break;

			case 'html':
			case 'custom-html':
				$markup = $this->render_html_block( $block );
				break;

			default:
				$markup = '';
				/**
				 * Filters markup for unsupported block types.
				 *
				 * @since 1.0.0
				 * @param string $markup Empty by default.
				 * @param array  $block  Block definition.
				 * @param string $type   Normalized type.
				 */
				$markup = apply_filters( 'promptweb_convert_block_unknown', $markup, $block, $type );
				break;
		}

		/**
		 * Filters final markup for a converted block.
		 *
		 * @since 1.0.0
		 * @param string $markup Block markup.
		 * @param array  $block  Block definition.
		 * @param string $type   Normalized type.
		 */
		return (string) apply_filters( 'promptweb_convert_block', $markup, $block, $type );
	}

	/**
	 * Render a core/heading block.
	 *
	 * @since 1.0.0
	 * @param array  $block Block definition.
	 * @param string $type  Normalized type (may be h1–h6 alias).
	 * @return string
	 */
	protected function render_heading_block( array $block, $type = 'heading' ) {
		$level = 2;

		if ( preg_match( '/^h([1-6])$/', $type, $matches ) ) {
			$level = (int) $matches[1];
		} elseif ( isset( $block['level'] ) ) {
			$level = (int) $block['level'];
		}

		if ( $level < 1 || $level > 6 ) {
			$level = 2;
		}

		$content = $this->get_block_text_content( $block );
		if ( '' === $content ) {
			return '';
		}

		$tag   = 'h' . $level;
		$attrs = $this->encode_block_attrs( array( 'level' => $level ) );

		// Concatenate user content (avoid sprintf % collisions).
		return '<!-- wp:heading ' . $attrs . " -->\n"
			. '<' . $tag . ' class="wp-block-heading">' . $content . '</' . $tag . ">\n"
			. '<!-- /wp:heading -->';
	}

	/**
	 * Render a core/paragraph block.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function render_paragraph_block( array $block ) {
		$content = $this->get_block_text_content( $block );
		if ( '' === $content ) {
			return '';
		}

		return "<!-- wp:paragraph -->\n<p>" . $content . "</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Render a core/buttons block with nested core/button items.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition (expects `items` array).
	 * @return string
	 */
	protected function render_buttons_block( array $block ) {
		$items = array();

		if ( ! empty( $block['items'] ) && is_array( $block['items'] ) ) {
			$items = $block['items'];
		} elseif ( isset( $block['text'] ) || isset( $block['url'] ) ) {
			$items = array( $block );
		}

		if ( empty( $items ) ) {
			return '';
		}

		$inner = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$text = '';
			if ( isset( $item['text'] ) ) {
				$text = $this->sanitize_inline_html( (string) $item['text'] );
			} elseif ( isset( $item['content'] ) ) {
				$text = $this->sanitize_inline_html( (string) $item['content'] );
			} elseif ( isset( $item['label'] ) ) {
				$text = $this->sanitize_inline_html( (string) $item['label'] );
			}

			if ( '' === $text ) {
				continue;
			}

			$url = '';
			if ( isset( $item['url'] ) ) {
				$url = $this->sanitize_button_url( (string) $item['url'] );
			} elseif ( isset( $item['href'] ) ) {
				$url = $this->sanitize_button_url( (string) $item['href'] );
			}

			$href_attr = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';

			$inner[] = "<!-- wp:button -->\n"
				. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href_attr . '>'
				. $text
				. "</a></div>\n<!-- /wp:button -->";
		}

		if ( empty( $inner ) ) {
			return '';
		}

		return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">\n"
			. implode( "\n\n", $inner )
			. "\n</div>\n<!-- /wp:buttons -->";
	}

	/**
	 * Render a core/image block (URL-based; does not sideload media).
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function render_image_block( array $block ) {
		$url = '';
		if ( isset( $block['url'] ) ) {
			$url = esc_url_raw( (string) $block['url'] );
		} elseif ( isset( $block['src'] ) ) {
			$url = esc_url_raw( (string) $block['src'] );
		}

		if ( '' === $url ) {
			return '';
		}

		$alt = isset( $block['alt'] ) ? sanitize_text_field( (string) $block['alt'] ) : '';
		$attrs = $this->encode_block_attrs(
			array(
				'url' => $url,
				'alt' => $alt,
			)
		);

		return '<!-- wp:image ' . $attrs . " -->\n"
			. '<figure class="wp-block-image"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"/></figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Render a core/list block.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition (expects `items` array of strings).
	 * @return string
	 */
	protected function render_list_block( array $block ) {
		$items = array();

		if ( ! empty( $block['items'] ) && is_array( $block['items'] ) ) {
			$items = $block['items'];
		} elseif ( ! empty( $block['content'] ) && is_array( $block['content'] ) ) {
			$items = $block['content'];
		}

		if ( empty( $items ) ) {
			return '';
		}

		$ordered = ! empty( $block['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';
		$lis     = array();

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$text = isset( $item['content'] ) ? (string) $item['content'] : ( isset( $item['text'] ) ? (string) $item['text'] : '' );
			} else {
				$text = (string) $item;
			}

			$text = $this->sanitize_inline_html( $text );
			if ( '' === $text ) {
				continue;
			}

			$lis[] = '<li>' . $text . '</li>';
		}

		if ( empty( $lis ) ) {
			return '';
		}

		$opener = $ordered
			? '<!-- wp:list ' . $this->encode_block_attrs( array( 'ordered' => true ) ) . ' -->'
			: '<!-- wp:list -->';

		return $opener . "\n<" . $tag . ' class="wp-block-list">' . implode( '', $lis ) . '</' . $tag . ">\n<!-- /wp:list -->";
	}

	/**
	 * Render a core/separator block.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function render_separator_block() {
		return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
	}

	/**
	 * Render a core/spacer block.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function render_spacer_block( array $block ) {
		$height = isset( $block['height'] ) ? (int) $block['height'] : 50;
		if ( $height < 1 ) {
			$height = 50;
		}

		$height_css = $height . 'px';
		$attrs      = $this->encode_block_attrs( array( 'height' => $height_css ) );

		return '<!-- wp:spacer ' . $attrs . " -->\n"
			. '<div style="height:' . esc_attr( $height_css ) . '" aria-hidden="true" class="wp-block-spacer"></div>' . "\n"
			. '<!-- /wp:spacer -->';
	}

	/**
	 * Render a core/html (Custom HTML) block.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function render_html_block( array $block ) {
		$html = '';
		if ( isset( $block['content'] ) ) {
			$html = (string) $block['content'];
		} elseif ( isset( $block['html'] ) ) {
			$html = (string) $block['html'];
		}

		$html = wp_kses_post( $html );
		if ( '' === trim( $html ) ) {
			return '';
		}

		return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
	}

	/**
	 * Read and sanitize textual content from a block definition.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function get_block_text_content( array $block ) {
		if ( isset( $block['content'] ) ) {
			return $this->sanitize_inline_html( (string) $block['content'] );
		}
		if ( isset( $block['text'] ) ) {
			return $this->sanitize_inline_html( (string) $block['text'] );
		}

		return '';
	}

	/**
	 * Allow light inline HTML in text content (links, emphasis, etc.).
	 *
	 * @since 1.0.0
	 * @param string $text Raw text.
	 * @return string
	 */
	protected function sanitize_inline_html( $text ) {
		$text = wp_kses_post( $text );
		return trim( $text );
	}

	/**
	 * Sanitize a button URL (supports root-relative paths like /contact).
	 *
	 * @since 1.0.0
	 * @param string $url Raw URL.
	 * @return string
	 */
	protected function sanitize_button_url( $url ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		// Root-relative or hash links.
		if ( isset( $url[0] ) && ( '/' === $url[0] || '#' === $url[0] ) ) {
			return esc_url_raw( $url );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Encode block attributes for a block comment opener.
	 *
	 * Returns JSON (with a leading space when non-empty) suitable for:
	 * `<!-- wp:heading {"level":1} -->`
	 *
	 * @since 1.0.0
	 * @param array $attrs Attribute map.
	 * @return string Empty string or JSON object string (no leading space).
	 */
	protected function encode_block_attrs( array $attrs ) {
		if ( empty( $attrs ) ) {
			return '';
		}

		$json = wp_json_encode( $attrs );
		if ( ! is_string( $json ) ) {
			return '';
		}

		return $json;
	}
}
