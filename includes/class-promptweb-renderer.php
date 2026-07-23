<?php
/**
 * Structured JSON → frontend HTML renderer.
 *
 * Architecture (JSON-first):
 * - GitHub-stored structured JSON is the single source of truth for site content.
 * - This class turns that JSON into HTML for public (and editor) front-end views.
 * - It does NOT write Gutenberg blocks or WordPress post_content as the content model.
 * - Manual edits and AI prompts update JSON and push back to GitHub (see Editor).
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders PromptWeb blueprint JSON as HTML.
 *
 * Multisite: operates on the current site’s runtime settings / blueprint cache.
 * Network-activated installs still render per-blog context.
 *
 * @since 1.0.0
 */
class PromptWeb_Renderer {

	/**
	 * Bootstrap renderer hooks (placeholder for theme integration, shortcodes, etc.).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		/**
		 * Fires when the frontend renderer is initialized.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Renderer $renderer This instance.
		 */
		do_action( 'promptweb_renderer_init', $this );

		// Future: register shortcodes, template redirects, or theme hooks here.
	}

	/**
	 * Render a full blueprint (or a page subset) to HTML.
	 *
	 * Expected shape (aligned with GitHub blueprints):
	 * {
	 *   "site":  { "title", "tagline" },
	 *   "pages": [ { "title", "slug", "blocks": [ ... ] } ]
	 * }
	 *
	 * @since 1.0.0
	 * @param array       $blueprint Decoded blueprint JSON.
	 * @param string|null $page_slug Optional page slug; null = first page or full site shell later.
	 * @return string Safe HTML string (may be empty).
	 */
	public function render( $blueprint, $page_slug = null ) {
		if ( ! is_array( $blueprint ) ) {
			return '';
		}

		/**
		 * Filters blueprint data before HTML rendering.
		 *
		 * @since 1.0.0
		 * @param array       $blueprint Blueprint payload.
		 * @param string|null $page_slug Requested page slug.
		 */
		$blueprint = apply_filters( 'promptweb_before_render', $blueprint, $page_slug );

		if ( ! is_array( $blueprint ) ) {
			return '';
		}

		$page = $this->resolve_page( $blueprint, $page_slug );
		if ( empty( $page ) || ! is_array( $page ) ) {
			return '';
		}

		$blocks = isset( $page['blocks'] ) && is_array( $page['blocks'] ) ? $page['blocks'] : array();
		$html   = $this->render_blocks( $blocks );

		/**
		 * Filters the final HTML for a rendered page.
		 *
		 * @since 1.0.0
		 * @param string      $html      Rendered HTML.
		 * @param array       $page      Page definition.
		 * @param array       $blueprint Full blueprint.
		 * @param string|null $page_slug Requested slug.
		 */
		return (string) apply_filters( 'promptweb_render_html', $html, $page, $blueprint, $page_slug );
	}

	/**
	 * Pick a page definition from the blueprint.
	 *
	 * @since 1.0.0
	 * @param array       $blueprint Blueprint payload.
	 * @param string|null $page_slug Preferred slug.
	 * @return array|null
	 */
	public function resolve_page( array $blueprint, $page_slug = null ) {
		$pages = isset( $blueprint['pages'] ) && is_array( $blueprint['pages'] ) ? $blueprint['pages'] : array();

		if ( empty( $pages ) ) {
			return null;
		}

		if ( null !== $page_slug && '' !== $page_slug ) {
			$needle = sanitize_title( (string) $page_slug );
			foreach ( $pages as $page ) {
				if ( ! is_array( $page ) ) {
					continue;
				}
				$slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
				if ( $slug === $needle ) {
					return $page;
				}
			}
			return null;
		}

		// Prefer front page when present.
		foreach ( $pages as $page ) {
			if ( is_array( $page ) && ! empty( $page['is_front_page'] ) ) {
				return $page;
			}
		}

		// Fallback: first valid page entry.
		foreach ( $pages as $page ) {
			if ( is_array( $page ) ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Render a list of block definitions to HTML.
	 *
	 * @since 1.0.0
	 * @param array $blocks Block definitions from JSON.
	 * @return string
	 */
	public function render_blocks( array $blocks ) {
		$parts = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$chunk = $this->render_block( $block );
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				$parts[] = $chunk;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Render a single structured block to HTML.
	 *
	 * Foundation only: supports the same basic types as the blueprint format
	 * (heading, paragraph, buttons). Extended types can be added later or via filters.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	public function render_block( array $block ) {
		$type = isset( $block['type'] ) ? strtolower( (string) $block['type'] ) : '';
		$type = str_replace( 'core/', '', $type );

		/**
		 * Short-circuit a single block render.
		 *
		 * @since 1.0.0
		 * @param string|null $pre   HTML or null to continue.
		 * @param array       $block Block definition.
		 * @param string      $type  Normalized type.
		 */
		$pre = apply_filters( 'promptweb_render_block_pre', null, $block, $type );
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
				$html = $this->render_heading( $block, $type );
				break;

			case 'paragraph':
			case 'p':
			case 'text':
				$html = $this->render_paragraph( $block );
				break;

			case 'buttons':
			case 'button-group':
				$html = $this->render_buttons( $block );
				break;

			case 'button':
				$html = $this->render_buttons(
					array(
						'type'  => 'buttons',
						'items' => array( $block ),
					)
				);
				break;

			default:
				$html = '';
				/**
				 * Filters HTML for unknown block types.
				 *
				 * @since 1.0.0
				 * @param string $html  Empty by default.
				 * @param array  $block Block definition.
				 * @param string $type  Normalized type.
				 */
				$html = apply_filters( 'promptweb_render_block_unknown', $html, $block, $type );
				break;
		}

		/**
		 * Filters HTML for a rendered block.
		 *
		 * @since 1.0.0
		 * @param string $html  Block HTML.
		 * @param array  $block Block definition.
		 * @param string $type  Normalized type.
		 */
		return (string) apply_filters( 'promptweb_render_block', $html, $block, $type );
	}

	/**
	 * Heading element.
	 *
	 * @since 1.0.0
	 * @param array  $block Block definition.
	 * @param string $type  Normalized type.
	 * @return string
	 */
	protected function render_heading( array $block, $type ) {
		$level = 2;

		if ( preg_match( '/^h([1-6])$/', $type, $matches ) ) {
			$level = (int) $matches[1];
		} elseif ( isset( $block['level'] ) ) {
			$level = (int) $block['level'];
		}

		if ( $level < 1 || $level > 6 ) {
			$level = 2;
		}

		$content = $this->get_text( $block );
		if ( '' === $content ) {
			return '';
		}

		$tag = 'h' . $level;

		return '<' . $tag . ' class="promptweb-block promptweb-heading">' . $content . '</' . $tag . '>';
	}

	/**
	 * Paragraph element.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function render_paragraph( array $block ) {
		$content = $this->get_text( $block );
		if ( '' === $content ) {
			return '';
		}

		return '<p class="promptweb-block promptweb-paragraph">' . $content . '</p>';
	}

	/**
	 * Button group.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function render_buttons( array $block ) {
		$items = array();

		if ( ! empty( $block['items'] ) && is_array( $block['items'] ) ) {
			$items = $block['items'];
		} elseif ( isset( $block['text'] ) || isset( $block['url'] ) ) {
			$items = array( $block );
		}

		if ( empty( $items ) ) {
			return '';
		}

		$links = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$text = '';
			if ( isset( $item['text'] ) ) {
				$text = $this->sanitize_inline( (string) $item['text'] );
			} elseif ( isset( $item['content'] ) ) {
				$text = $this->sanitize_inline( (string) $item['content'] );
			} elseif ( isset( $item['label'] ) ) {
				$text = $this->sanitize_inline( (string) $item['label'] );
			}

			if ( '' === $text ) {
				continue;
			}

			$url = '';
			if ( isset( $item['url'] ) ) {
				$url = $this->sanitize_url( (string) $item['url'] );
			} elseif ( isset( $item['href'] ) ) {
				$url = $this->sanitize_url( (string) $item['href'] );
			}

			$href = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';

			$links[] = '<a class="promptweb-button"' . $href . '>' . $text . '</a>';
		}

		if ( empty( $links ) ) {
			return '';
		}

		return '<div class="promptweb-block promptweb-buttons">' . implode( '', $links ) . '</div>';
	}

	/**
	 * Extract and sanitize textual content from a block.
	 *
	 * @since 1.0.0
	 * @param array $block Block definition.
	 * @return string
	 */
	protected function get_text( array $block ) {
		if ( isset( $block['content'] ) ) {
			return $this->sanitize_inline( (string) $block['content'] );
		}
		if ( isset( $block['text'] ) ) {
			return $this->sanitize_inline( (string) $block['text'] );
		}

		return '';
	}

	/**
	 * Allow light inline HTML in text nodes.
	 *
	 * @since 1.0.0
	 * @param string $text Raw text.
	 * @return string
	 */
	protected function sanitize_inline( $text ) {
		return trim( wp_kses_post( $text ) );
	}

	/**
	 * Sanitize URLs, including root-relative paths.
	 *
	 * @since 1.0.0
	 * @param string $url Raw URL.
	 * @return string
	 */
	protected function sanitize_url( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		return esc_url_raw( $url );
	}
}
