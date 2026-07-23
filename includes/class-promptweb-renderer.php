<?php
/**
 * Structured JSON → frontend HTML renderer.
 *
 * Maximum AI Creativity:
 * - GitHub JSON is the single source of truth (not Gutenberg).
 * - Canonical tree: pages → sections → elements.
 * - Core types (section, heading, text, button, image, spacer, html) are first-class.
 * - Unknown / AI-invented types NEVER break the page — generic container + filters.
 * - settings{} → safe inline CSS; all public hooks are filter-rich for extension.
 *
 * Multisite: uses current-blog context; stored blueprints via PromptWeb_Settings
 * (network or site options depending on activation context).
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders PromptWeb blueprint JSON as clean, escaped HTML.
 *
 * @since 1.0.0
 */
class PromptWeb_Renderer {

	/**
	 * Max nesting depth for children/elements (prevents pathological AI trees).
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const MAX_DEPTH = 20;

	/**
	 * Current recursion depth while rendering nested elements.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	protected $depth = 0;

	/**
	 * Default settings key → CSS property map (extended via filter).
	 *
	 * @since 1.0.0
	 * @var   array<string, string>
	 */
	protected $style_map = array(
		// Backgrounds.
		'background'          => 'background',
		'background_color'    => 'background-color',
		'bg'                  => 'background',
		'bg_color'            => 'background-color',
		// Color / type.
		'color'               => 'color',
		'text_color'          => 'color',
		'font_size'           => 'font-size',
		'fontSize'            => 'font-size',
		'font_weight'         => 'font-weight',
		'fontWeight'          => 'font-weight',
		'font_family'         => 'font-family',
		'line_height'         => 'line-height',
		'letter_spacing'      => 'letter-spacing',
		'text_align'          => 'text-align',
		'text_decoration'     => 'text-decoration',
		'text_transform'      => 'text-transform',
		// Spacing.
		'padding'             => 'padding',
		'padding_top'         => 'padding-top',
		'padding_right'       => 'padding-right',
		'padding_bottom'      => 'padding-bottom',
		'padding_left'        => 'padding-left',
		'margin'              => 'margin',
		'margin_top'          => 'margin-top',
		'margin_right'        => 'margin-right',
		'margin_bottom'       => 'margin-bottom',
		'margin_left'         => 'margin-left',
		'gap'                 => 'gap',
		// Size.
		'width'               => 'width',
		'min_width'           => 'min-width',
		'max_width'           => 'max-width',
		'height'              => 'height',
		'min_height'          => 'min-height',
		'max_height'          => 'max-height',
		// Border / radius / shadow.
		'border'              => 'border',
		'border_width'        => 'border-width',
		'border_style'        => 'border-style',
		'border_color'        => 'border-color',
		'border_radius'       => 'border-radius',
		'borderRadius'        => 'border-radius',
		'box_shadow'          => 'box-shadow',
		// Layout.
		'display'             => 'display',
		'flex_direction'      => 'flex-direction',
		'justify_content'     => 'justify-content',
		'align_items'         => 'align-items',
		'flex_wrap'           => 'flex-wrap',
		'grid_template_columns' => 'grid-template-columns',
		'position'            => 'position',
		'top'                 => 'top',
		'right'               => 'right',
		'bottom'              => 'bottom',
		'left'                => 'left',
		'z_index'             => 'z-index',
		'overflow'            => 'overflow',
		'opacity'             => 'opacity',
		'object_fit'          => 'object-fit',
	);

	/**
	 * Bootstrap renderer hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		/**
		 * Fires when the frontend renderer is initialized.
		 *
		 * Register custom element handlers here via filters.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Renderer $renderer This instance.
		 */
		do_action( 'promptweb_renderer_init', $this );
	}

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Render a blueprint page to HTML.
	 *
	 * Prefer pages → sections → elements. When $blueprint is null, loads the
	 * last synced blueprint (Multisite-aware).
	 *
	 * @since 1.0.0
	 * @param array|null  $blueprint Decoded blueprint JSON, or null to use stored.
	 * @param string|null $page_slug Optional page slug / id; null = front / first.
	 * @return string Safe HTML (never throws; may be empty).
	 */
	public function render( $blueprint = null, $page_slug = null ) {
		$this->depth = 0;

		if ( null === $blueprint ) {
			$blueprint = class_exists( 'PromptWeb_Settings' )
				? PromptWeb_Settings::get_blueprint()
				: array();
		}

		if ( ! is_array( $blueprint ) || empty( $blueprint ) ) {
			/**
			 * Filters HTML when no blueprint is available.
			 *
			 * @since 1.0.0
			 * @param string      $html      Default empty.
			 * @param string|null $page_slug Requested slug.
			 */
			return (string) apply_filters( 'promptweb_render_empty', '', $page_slug );
		}

		/**
		 * Filters blueprint data before any rendering work.
		 *
		 * @since 1.0.0
		 * @param array       $blueprint Blueprint payload.
		 * @param string|null $page_slug Requested page slug.
		 */
		$blueprint = apply_filters( 'promptweb_before_render', $blueprint, $page_slug );

		if ( ! is_array( $blueprint ) ) {
			return '';
		}

		// Prefer Schema normalize: legacy blocks → sections → elements.
		if ( class_exists( 'PromptWeb_Schema' ) ) {
			$blueprint = PromptWeb_Schema::normalize( $blueprint );
		}

		/**
		 * Filters blueprint after schema normalize.
		 *
		 * @since 1.0.0
		 * @param array       $blueprint Normalized blueprint.
		 * @param string|null $page_slug Requested page slug.
		 */
		$blueprint = apply_filters( 'promptweb_after_normalize', $blueprint, $page_slug );

		if ( ! is_array( $blueprint ) ) {
			return '';
		}

		$page = $this->resolve_page( $blueprint, $page_slug );

		/**
		 * Filters the resolved page node (or null).
		 *
		 * @since 1.0.0
		 * @param array|null  $page      Page definition.
		 * @param array       $blueprint Full blueprint.
		 * @param string|null $page_slug Requested slug.
		 */
		$page = apply_filters( 'promptweb_resolve_page', $page, $blueprint, $page_slug );

		if ( empty( $page ) || ! is_array( $page ) ) {
			return (string) apply_filters( 'promptweb_render_empty', '', $page_slug );
		}

		$html = $this->render_page( $page, $blueprint );

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
	 * @param string|null $page_slug Preferred slug or page id.
	 * @return array|null
	 */
	public function resolve_page( array $blueprint, $page_slug = null ) {
		$pages = isset( $blueprint['pages'] ) && is_array( $blueprint['pages'] ) ? $blueprint['pages'] : array();

		if ( empty( $pages ) ) {
			return null;
		}

		if ( null !== $page_slug && '' !== (string) $page_slug ) {
			$needle = sanitize_title( (string) $page_slug );
			foreach ( $pages as $page ) {
				if ( ! is_array( $page ) ) {
					continue;
				}
				$slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
				if ( $slug === $needle ) {
					return $page;
				}
				$id = isset( $page['id'] ) ? (string) $page['id'] : '';
				if ( $id === (string) $page_slug || sanitize_title( $id ) === $needle ) {
					return $page;
				}
			}
			return null;
		}

		foreach ( $pages as $page ) {
			if ( is_array( $page ) && ! empty( $page['is_front_page'] ) ) {
				return $page;
			}
		}

		foreach ( $pages as $page ) {
			if ( is_array( $page ) ) {
				return $page;
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Page / section / elements tree
	// -------------------------------------------------------------------------

	/**
	 * Render a page: always prefers sections[]; falls back to legacy blocks.
	 *
	 * @since 1.0.0
	 * @param array $page      Page definition.
	 * @param array $blueprint Optional full blueprint for filters.
	 * @return string
	 */
	public function render_page( array $page, array $blueprint = array() ) {
		/**
		 * Short-circuit full page render.
		 *
		 * @since 1.0.0
		 * @param string|null $pre       HTML or null to continue.
		 * @param array       $page      Page definition.
		 * @param array       $blueprint Full blueprint.
		 */
		$pre = apply_filters( 'promptweb_render_page_pre', null, $page, $blueprint );
		if ( is_string( $pre ) ) {
			return $pre;
		}

		$page_id = isset( $page['id'] ) ? (string) $page['id'] : '';
		$slug    = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';

		$classes = array( 'promptweb-page' );
		if ( $page_id ) {
			$classes[] = 'promptweb-page--' . sanitize_html_class( $page_id );
		}
		if ( $slug ) {
			$classes[] = 'promptweb-page-slug--' . sanitize_html_class( $slug );
		}

		/**
		 * Filters page wrapper CSS classes.
		 *
		 * @since 1.0.0
		 * @param string[] $classes Page classes.
		 * @param array    $page    Page definition.
		 */
		$classes = apply_filters( 'promptweb_page_classes', $classes, $page );
		$classes = array_filter( array_map( 'sanitize_html_class', (array) $classes ) );

		$attrs = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		if ( $page_id ) {
			$attrs .= ' data-promptweb-page-id="' . esc_attr( $page_id ) . '"';
		}
		if ( $slug ) {
			$attrs .= ' data-promptweb-page-slug="' . esc_attr( $slug ) . '"';
		}
		if ( ! empty( $page['title'] ) && is_scalar( $page['title'] ) ) {
			$attrs .= ' data-promptweb-page-title="' . esc_attr( (string) $page['title'] ) . '"';
		}

		// --- Prefer sections → elements ---
		$inner = '';
		if ( ! empty( $page['sections'] ) && is_array( $page['sections'] ) ) {
			$parts = array();
			foreach ( $page['sections'] as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$chunk = $this->render_section( $section );
				if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
					$parts[] = $chunk;
				}
			}
			$inner = implode( "\n", $parts );
		} elseif ( ! empty( $page['elements'] ) && is_array( $page['elements'] ) ) {
			// AI may put elements directly on a page.
			$inner = $this->render_elements( $page['elements'] );
		} elseif ( ! empty( $page['blocks'] ) && is_array( $page['blocks'] ) ) {
			// Legacy flat blocks (if normalize was skipped).
			$inner = $this->render_elements( $page['blocks'] );
		}

		/**
		 * Filters page inner HTML (sections/elements only).
		 *
		 * @since 1.0.0
		 * @param string $inner Page inner HTML.
		 * @param array  $page  Page definition.
		 */
		$inner = (string) apply_filters( 'promptweb_render_page_inner', $inner, $page );

		$html = '<div' . $attrs . ">\n" . $inner . "\n</div>";

		/**
		 * Filters full page HTML wrapper.
		 *
		 * @since 1.0.0
		 * @param string $html Page HTML.
		 * @param array  $page Page definition.
		 */
		return (string) apply_filters( 'promptweb_render_page', $html, $page );
	}

	/**
	 * Render a section (type: section) with settings + elements.
	 *
	 * @since 1.0.0
	 * @param array $section Section definition.
	 * @return string
	 */
	public function render_section( array $section ) {
		/**
		 * Short-circuit section rendering.
		 *
		 * @since 1.0.0
		 * @param string|null $pre     HTML or null.
		 * @param array       $section Section definition.
		 */
		$pre = apply_filters( 'promptweb_render_section_pre', null, $section );
		if ( is_string( $pre ) ) {
			return $pre;
		}

		$settings = $this->get_settings( $section );
		$elements = $this->extract_child_elements( $section );

		$section_id = isset( $section['id'] ) ? (string) $section['id'] : '';
		$type       = isset( $section['type'] ) ? strtolower( (string) $section['type'] ) : 'section';
		if ( '' === $type ) {
			$type = 'section';
		}

		$classes = array( 'promptweb-section', 'promptweb-element' );
		if ( $section_id ) {
			$classes[] = 'promptweb-section--' . sanitize_html_class( $section_id );
		}
		$classes[] = 'promptweb-type-' . sanitize_html_class( $type );

		/**
		 * Filters section CSS classes.
		 *
		 * @since 1.0.0
		 * @param string[] $classes  Class list.
		 * @param array    $section  Section definition.
		 * @param array    $settings Settings map.
		 */
		$classes = apply_filters( 'promptweb_section_classes', $classes, $section, $settings );
		$classes = array_filter( array_map( 'sanitize_html_class', (array) $classes ) );

		$style = $this->settings_to_style( $settings, $section );

		$attrs  = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		$attrs .= ' data-promptweb-type="' . esc_attr( $type ) . '"';
		if ( $section_id ) {
			$attrs .= ' data-promptweb-id="' . esc_attr( $section_id ) . '"';
			$attrs .= ' data-promptweb-editor-id="' . esc_attr( $section_id ) . '"';
		}
		if ( '' !== $style ) {
			$attrs .= ' style="' . esc_attr( $style ) . '"';
		}

		/**
		 * Filters extra HTML attributes for a section (already escaped string fragments).
		 * Editor injects data-promptweb-editable when the user can edit.
		 *
		 * @since 1.0.0
		 * @param string $attrs   Attribute string (leading space optional).
		 * @param array  $section Section definition.
		 */
		$extra = apply_filters( 'promptweb_section_attrs', '', $section );
		if ( is_string( $extra ) && '' !== $extra ) {
			$attrs .= ' ' . trim( $extra );
		}

		$inner = $this->render_elements( $elements );

		/**
		 * Filters section inner HTML.
		 *
		 * @since 1.0.0
		 * @param string $inner   Inner HTML.
		 * @param array  $section Section definition.
		 */
		$inner = (string) apply_filters( 'promptweb_render_section_inner', $inner, $section );

		$html = '<section' . $attrs . ">\n"
			. '<div class="promptweb-section__inner">' . "\n"
			. $inner . "\n"
			. '</div>' . "\n"
			. '</section>';

		/**
		 * Filters rendered section HTML.
		 *
		 * @since 1.0.0
		 * @param string $html    Section HTML.
		 * @param array  $section Section definition.
		 */
		return (string) apply_filters( 'promptweb_render_section', $html, $section );
	}

	/**
	 * Render a list of elements (skip invalid nodes; never break the page).
	 *
	 * @since 1.0.0
	 * @param array $elements Element definitions.
	 * @return string
	 */
	public function render_elements( array $elements ) {
		/**
		 * Filters the list of elements before rendering.
		 *
		 * @since 1.0.0
		 * @param array $elements Element list.
		 */
		$elements = apply_filters( 'promptweb_before_render_elements', $elements );
		if ( ! is_array( $elements ) ) {
			return '';
		}

		$parts = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			try {
				$chunk = $this->render_element( $element );
			} catch ( Exception $e ) {
				// Absolute safety net — AI data must never white-screen the site.
				$chunk = $this->render_error_placeholder( $element, $e );
			}

			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				$parts[] = $chunk;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Alias for legacy callers.
	 *
	 * @since 1.0.0
	 * @param array $blocks Element-like definitions.
	 * @return string
	 */
	public function render_blocks( array $blocks ) {
		return $this->render_elements( $blocks );
	}

	/**
	 * Render a single element by type (core + unknown).
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	public function render_element( array $element ) {
		if ( $this->depth >= self::MAX_DEPTH ) {
			return '';
		}

		++$this->depth;

		$type = $this->normalize_type( $element );

		/**
		 * Short-circuit any element before core handlers.
		 *
		 * Return a string to replace the entire element output.
		 *
		 * @since 1.0.0
		 * @param string|null $pre     HTML or null to continue.
		 * @param array       $element Element definition.
		 * @param string      $type    Normalized type.
		 * @param PromptWeb_Renderer $renderer This instance.
		 */
		$pre = apply_filters( 'promptweb_render_element_pre', null, $element, $type, $this );
		if ( null === $pre ) {
			$pre = apply_filters( 'promptweb_render_block_pre', null, $element, $type );
		}
		if ( is_string( $pre ) ) {
			--$this->depth;
			return $pre;
		}

		/**
		 * Dynamic per-type short-circuit: promptweb_render_element_{$type}
		 *
		 * Example: add_filter( 'promptweb_render_element_card', ... )
		 *
		 * @since 1.0.0
		 * @param string|null $pre     HTML or null.
		 * @param array       $element Element definition.
		 * @param PromptWeb_Renderer $renderer This instance.
		 */
		$type_filter = 'promptweb_render_element_' . preg_replace( '/[^a-z0-9_]+/', '_', $type );
		if ( '' !== $type && has_filter( $type_filter ) ) {
			$typed = apply_filters( $type_filter, null, $element, $this );
			if ( is_string( $typed ) ) {
				--$this->depth;
				return $typed;
			}
		}

		switch ( $type ) {
			case 'section':
				$html = $this->render_section( $element );
				break;

			case 'heading':
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$html = $this->render_heading( $element, $type );
				break;

			case 'text':
			case 'paragraph':
			case 'p':
				$html = $this->render_text( $element );
				break;

			case 'button':
				$html = $this->render_button( $element );
				break;

			case 'buttons':
			case 'button-group':
			case 'button_group':
				$html = $this->render_buttons_group( $element );
				break;

			case 'image':
			case 'img':
				$html = $this->render_image( $element );
				break;

			case 'spacer':
			case 'space':
			case 'divider_space':
				$html = $this->render_spacer( $element );
				break;

			case 'html':
			case 'custom-html':
			case 'custom_html':
			case 'raw':
				$html = $this->render_html_element( $element );
				break;

			default:
				// Maximum AI Creativity: never break on unknown types.
				$html = $this->render_unknown_element( $element, $type );

				/**
				 * Filters HTML for unknown / AI-invented element types.
				 *
				 * @since 1.0.0
				 * @param string             $html     Generic fallback HTML.
				 * @param array              $element  Element definition.
				 * @param string             $type     Normalized type.
				 * @param PromptWeb_Renderer $renderer This instance.
				 */
				$html = apply_filters( 'promptweb_render_element_unknown', $html, $element, $type, $this );
				$html = apply_filters( 'promptweb_render_block_unknown', $html, $element, $type );
				break;
		}

		/**
		 * Filters final HTML for any rendered element.
		 *
		 * @since 1.0.0
		 * @param string             $html     Element HTML.
		 * @param array              $element  Element definition.
		 * @param string             $type     Normalized type.
		 * @param PromptWeb_Renderer $renderer This instance.
		 */
		$html = apply_filters( 'promptweb_render_element', $html, $element, $type, $this );
		$html = apply_filters( 'promptweb_render_block', $html, $element, $type );

		--$this->depth;

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Alias for render_element().
	 *
	 * @since 1.0.0
	 * @param array $block Element definition.
	 * @return string
	 */
	public function render_block( array $block ) {
		return $this->render_element( $block );
	}

	// -------------------------------------------------------------------------
	// Core element renderers
	// -------------------------------------------------------------------------

	/**
	 * Heading (h1–h6).
	 *
	 * @since 1.0.0
	 * @param array  $element Element definition.
	 * @param string $type    Normalized type.
	 * @return string
	 */
	protected function render_heading( array $element, $type ) {
		$settings = $this->get_settings( $element );
		$level    = 2;

		if ( preg_match( '/^h([1-6])$/', $type, $matches ) ) {
			$level = (int) $matches[1];
		} elseif ( isset( $settings['level'] ) ) {
			$level = (int) $settings['level'];
		} elseif ( isset( $element['level'] ) ) {
			$level = (int) $element['level'];
		}

		if ( $level < 1 || $level > 6 ) {
			$level = 2;
		}

		$content = $this->get_text( $element );
		if ( '' === $content ) {
			return '';
		}

		$tag   = 'h' . $level;
		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-heading' ),
			$settings,
			'heading'
		);

		return '<' . $tag . $attrs . '>' . $content . '</' . $tag . '>';
	}

	/**
	 * Text / paragraph.
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	protected function render_text( array $element ) {
		$content = $this->get_text( $element );
		if ( '' === $content ) {
			return '';
		}

		$settings = $this->get_settings( $element );
		$tag      = 'p';

		// Optional: settings.tag = div|span|p for AI layouts.
		if ( ! empty( $settings['tag'] ) && is_string( $settings['tag'] ) ) {
			$maybe = strtolower( $settings['tag'] );
			if ( in_array( $maybe, array( 'p', 'div', 'span' ), true ) ) {
				$tag = $maybe;
			}
		}

		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-text' ),
			$settings,
			'text'
		);

		return '<' . $tag . $attrs . '>' . $content . '</' . $tag . '>';
	}

	/**
	 * Button (anchor).
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	protected function render_button( array $element ) {
		$settings = $this->get_settings( $element );
		$content  = $this->get_text( $element );

		if ( '' === $content ) {
			return '';
		}

		$url = $this->resolve_url( $element, $settings );

		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-button' ),
			$settings,
			'button'
		);

		if ( '' !== $url ) {
			$attrs .= ' href="' . esc_url( $url ) . '"';
		}

		$target = '';
		if ( ! empty( $settings['target'] ) && is_scalar( $settings['target'] ) ) {
			$target = sanitize_key( (string) $settings['target'] );
			if ( in_array( $target, array( '_blank', '_self', '_parent', '_top' ), true ) ) {
				$attrs .= ' target="' . esc_attr( $target ) . '"';
				if ( '_blank' === $target ) {
					$attrs .= ' rel="noopener noreferrer"';
				}
			}
		}

		return '<a' . $attrs . '>' . $content . '</a>';
	}

	/**
	 * Button group (items[] of buttons).
	 *
	 * @since 1.0.0
	 * @param array $element Group definition.
	 * @return string
	 */
	protected function render_buttons_group( array $element ) {
		$items = array();

		if ( ! empty( $element['items'] ) && is_array( $element['items'] ) ) {
			$items = $element['items'];
		} elseif ( ! empty( $element['buttons'] ) && is_array( $element['buttons'] ) ) {
			$items = $element['buttons'];
		} elseif ( isset( $element['content'] ) || isset( $element['text'] ) ) {
			$items = array( $element );
		}

		if ( empty( $items ) ) {
			return '';
		}

		$links = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$button = $item;
			if ( empty( $button['type'] ) ) {
				$button['type'] = 'button';
			}
			if ( empty( $button['id'] ) && ! empty( $element['id'] ) ) {
				$button['id'] = (string) $element['id'] . '-item-' . (int) $index;
			}
			if ( ! isset( $button['settings'] ) || ! is_array( $button['settings'] ) ) {
				$button['settings'] = array();
			}
			if ( isset( $item['url'] ) && empty( $button['settings']['url'] ) ) {
				$button['settings']['url'] = $item['url'];
			}
			$chunk = $this->render_button( $button );
			if ( '' !== trim( $chunk ) ) {
				$links[] = $chunk;
			}
		}

		if ( empty( $links ) ) {
			return '';
		}

		$settings = $this->get_settings( $element );
		// Default flex layout for button rows when AI omits display.
		if ( empty( $settings['display'] ) && empty( $settings['gap'] ) ) {
			$settings['display'] = 'flex';
			$settings['gap']     = isset( $settings['gap'] ) ? $settings['gap'] : '12px';
			$settings['flex_wrap'] = isset( $settings['flex_wrap'] ) ? $settings['flex_wrap'] : 'wrap';
		}

		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-buttons' ),
			$settings,
			'buttons'
		);

		return '<div' . $attrs . '>' . implode( '', $links ) . '</div>';
	}

	/**
	 * Image.
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	protected function render_image( array $element ) {
		$settings = $this->get_settings( $element );
		$url      = $this->resolve_image_url( $element, $settings );

		if ( '' === $url ) {
			return '';
		}

		$alt = '';
		if ( isset( $settings['alt'] ) ) {
			$alt = sanitize_text_field( (string) $settings['alt'] );
		} elseif ( isset( $element['alt'] ) ) {
			$alt = sanitize_text_field( (string) $element['alt'] );
		} elseif ( isset( $element['content'] ) && is_scalar( $element['content'] ) ) {
			$alt = sanitize_text_field( (string) $element['content'] );
		}

		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-image' ),
			$settings,
			'image'
		);

		$loading = 'lazy';
		if ( ! empty( $settings['loading'] ) && is_scalar( $settings['loading'] ) ) {
			$maybe = sanitize_key( (string) $settings['loading'] );
			if ( in_array( $maybe, array( 'lazy', 'eager' ), true ) ) {
				$loading = $maybe;
			}
		}

		$img = '<img' . $attrs
			. ' src="' . esc_url( $url ) . '"'
			. ' alt="' . esc_attr( $alt ) . '"'
			. ' loading="' . esc_attr( $loading ) . '"'
			. ' />';

		// Optional figure wrapper when caption present.
		$caption = '';
		if ( ! empty( $settings['caption'] ) && is_scalar( $settings['caption'] ) ) {
			$caption = $this->sanitize_inline( (string) $settings['caption'] );
		} elseif ( ! empty( $element['caption'] ) && is_scalar( $element['caption'] ) ) {
			$caption = $this->sanitize_inline( (string) $element['caption'] );
		}

		if ( '' !== $caption ) {
			return '<figure class="promptweb-element promptweb-image-figure">'
				. $img
				. '<figcaption class="promptweb-image-caption">' . $caption . '</figcaption>'
				. '</figure>';
		}

		return $img;
	}

	/**
	 * Spacer / vertical space.
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	protected function render_spacer( array $element ) {
		$settings = $this->get_settings( $element );

		// height from settings or content (e.g. "40" / "40px").
		$height = '';
		if ( isset( $settings['height'] ) && is_scalar( $settings['height'] ) ) {
			$height = trim( (string) $settings['height'] );
		} elseif ( isset( $settings['size'] ) && is_scalar( $settings['size'] ) ) {
			$height = trim( (string) $settings['size'] );
		} elseif ( isset( $element['content'] ) && is_scalar( $element['content'] ) ) {
			$height = trim( (string) $element['content'] );
		}

		if ( '' === $height ) {
			$height = '40px';
		} elseif ( is_numeric( $height ) ) {
			$height = $height . 'px';
		}

		// Force height into settings for style compiler.
		$settings['height']  = $height;
		$settings['display'] = isset( $settings['display'] ) ? $settings['display'] : 'block';

		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-spacer' ),
			$settings,
			'spacer'
		);

		return '<div' . $attrs . ' aria-hidden="true"></div>';
	}

	/**
	 * Custom HTML (sanitized with wp_kses_post + filterable allowed HTML).
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	protected function render_html_element( array $element ) {
		$raw = '';
		if ( isset( $element['content'] ) && is_scalar( $element['content'] ) ) {
			$raw = (string) $element['content'];
		} elseif ( isset( $element['html'] ) && is_scalar( $element['html'] ) ) {
			$raw = (string) $element['html'];
		}

		/**
		 * Filters raw HTML before kses for type=html elements.
		 *
		 * @since 1.0.0
		 * @param string $raw     Raw HTML.
		 * @param array  $element Element definition.
		 */
		$raw = (string) apply_filters( 'promptweb_html_element_raw', $raw, $element );

		/**
		 * Filters allowed HTML for type=html (defaults to wp_kses_post context).
		 *
		 * Return null to use wp_kses_post(); return an array for wp_kses().
		 *
		 * @since 1.0.0
		 * @param array|null $allowed null = wp_kses_post.
		 * @param array      $element Element definition.
		 */
		$allowed = apply_filters( 'promptweb_html_element_allowed', null, $element );

		if ( is_array( $allowed ) ) {
			$html = wp_kses( $raw, $allowed );
		} else {
			$html = wp_kses_post( $raw );
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		$settings = $this->get_settings( $element );
		$attrs    = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-html' ),
			$settings,
			'html'
		);

		return '<div' . $attrs . '>' . $html . '</div>';
	}

	/**
	 * Generic container for unknown / AI-invented element types.
	 *
	 * Never throws; always returns valid HTML so the page stays intact.
	 * Nested children/elements/items are rendered recursively.
	 *
	 * @since 1.0.0
	 * @param array  $element Element definition.
	 * @param string $type    Normalized type (may be empty → "unknown").
	 * @return string
	 */
	protected function render_unknown_element( array $element, $type ) {
		$settings = $this->get_settings( $element );
		$type_key = '' !== $type ? $type : 'unknown';

		$classes = array(
			'promptweb-element',
			'promptweb-element--custom',
			'promptweb-element--unknown',
			'promptweb-type-' . sanitize_html_class( $type_key ),
		);

		$attrs  = $this->build_element_attrs( $element, $classes, $settings, $type_key );
		// Ensure type is always present for Editor targeting.
		if ( false === strpos( $attrs, 'data-promptweb-type=' ) ) {
			$attrs .= ' data-promptweb-type="' . esc_attr( $type_key ) . '"';
		}

		$inner_parts = array();

		// Primary text/HTML content.
		$content = $this->get_text( $element );
		if ( '' !== $content ) {
			$inner_parts[] = '<div class="promptweb-element__content">' . $content . '</div>';
		}

		// Nested trees (AI creativity).
		$nested = $this->extract_child_elements( $element );
		if ( ! empty( $nested ) ) {
			$inner_parts[] = '<div class="promptweb-element__children">' . "\n"
				. $this->render_elements( $nested ) . "\n"
				. '</div>';
		}

		$inner = implode( "\n", $inner_parts );

		/**
		 * Filters inner HTML of an unknown element before wrapping.
		 *
		 * @since 1.0.0
		 * @param string $inner   Inner HTML.
		 * @param array  $element Element definition.
		 * @param string $type    Normalized type.
		 */
		$inner = (string) apply_filters( 'promptweb_unknown_element_inner', $inner, $element, $type_key );

		/**
		 * Filters the HTML tag used for unknown elements (default div).
		 *
		 * @since 1.0.0
		 * @param string $tag     Tag name.
		 * @param array  $element Element definition.
		 * @param string $type    Normalized type.
		 */
		$tag = apply_filters( 'promptweb_unknown_element_tag', 'div', $element, $type_key );
		$tag = is_string( $tag ) ? strtolower( preg_replace( '/[^a-z0-9]/', '', $tag ) ) : 'div';
		if ( '' === $tag || in_array( $tag, array( 'script', 'style', 'iframe', 'object', 'embed' ), true ) ) {
			$tag = 'div';
		}

		return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
	}

	/**
	 * Visible only when WP_DEBUG — failed element safety net.
	 *
	 * @since 1.0.0
	 * @param array     $element Element definition.
	 * @param Exception $e       Exception thrown.
	 * @return string
	 */
	protected function render_error_placeholder( array $element, Exception $e ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return '';
		}

		$type = $this->normalize_type( $element );
		$msg  = sanitize_text_field( $e->getMessage() );

		return '<!-- promptweb render error type=' . esc_attr( $type ) . ' msg=' . esc_html( $msg ) . ' -->';
	}

	// -------------------------------------------------------------------------
	// Attributes, settings → CSS
	// -------------------------------------------------------------------------

	/**
	 * Build class / data / style attributes for an element.
	 *
	 * @since 1.0.0
	 * @param array    $element  Element definition.
	 * @param string[] $classes  Base classes.
	 * @param array    $settings Settings map.
	 * @param string   $type     Normalized type for data-promptweb-type.
	 * @return string Attribute string (leading space when non-empty).
	 */
	protected function build_element_attrs( array $element, array $classes, array $settings, $type = '' ) {
		$id = isset( $element['id'] ) ? (string) $element['id'] : '';
		if ( $id ) {
			$classes[] = 'promptweb-element--' . sanitize_html_class( $id );
		}
		if ( '' !== $type ) {
			$classes[] = 'promptweb-type-' . sanitize_html_class( $type );
		}

		// Optional class from settings.
		if ( ! empty( $settings['class'] ) && is_scalar( $settings['class'] ) ) {
			foreach ( preg_split( '/\s+/', (string) $settings['class'] ) as $extra_class ) {
				if ( '' !== $extra_class ) {
					$classes[] = $extra_class;
				}
			}
		}
		if ( ! empty( $settings['className'] ) && is_scalar( $settings['className'] ) ) {
			foreach ( preg_split( '/\s+/', (string) $settings['className'] ) as $extra_class ) {
				if ( '' !== $extra_class ) {
					$classes[] = $extra_class;
				}
			}
		}

		/**
		 * Filters element CSS classes.
		 *
		 * @since 1.0.0
		 * @param string[] $classes  Class list.
		 * @param array    $element  Element definition.
		 * @param array    $settings Settings map.
		 * @param string   $type     Normalized type.
		 */
		$classes = apply_filters( 'promptweb_element_classes', $classes, $element, $settings, $type );
		$classes = array_filter( array_map( 'sanitize_html_class', (array) $classes ) );
		$classes = array_unique( $classes );

		$parts = array();
		if ( ! empty( $classes ) ) {
			$parts[] = 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		}

		// Stable hooks for Frontend Editor + AI targeting (always present for public HTML).
		// data-promptweb-editable is added only when the editor boots (capability-gated).
		if ( $id ) {
			$parts[] = 'data-promptweb-id="' . esc_attr( $id ) . '"';
			$parts[] = 'data-promptweb-editor-id="' . esc_attr( $id ) . '"';
		}
		if ( '' !== $type ) {
			$parts[] = 'data-promptweb-type="' . esc_attr( $type ) . '"';
		}

		$style = $this->settings_to_style( $settings, $element );
		if ( '' !== $style ) {
			$parts[] = 'style="' . esc_attr( $style ) . '"';
		}

		/**
		 * Filters additional HTML attributes string for an element.
		 *
		 * Must return already-escaped attribute fragments (e.g. 'data-x="1"').
		 * Used by PromptWeb_Editor to inject data-promptweb-editable="1" for capable users only.
		 *
		 * @since 1.0.0
		 * @param string $extra    Extra attributes.
		 * @param array  $element  Element definition.
		 * @param array  $settings Settings map.
		 * @param string $type     Normalized type.
		 */
		$extra = apply_filters( 'promptweb_element_attrs', '', $element, $settings, $type );
		if ( is_string( $extra ) && '' !== trim( $extra ) ) {
			$parts[] = trim( $extra );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return ' ' . implode( ' ', $parts );
	}

	/**
	 * Convert settings map into a safe inline CSS string.
	 *
	 * @since 1.0.0
	 * @param array $settings Settings from JSON.
	 * @param array $context  Optional element/section for filters.
	 * @return string e.g. "color:#fff;font-size:18px"
	 */
	public function settings_to_style( array $settings, array $context = array() ) {
		/**
		 * Filters settings before style compilation.
		 *
		 * @since 1.0.0
		 * @param array $settings Settings map.
		 * @param array $context  Element/section node.
		 */
		$settings = apply_filters( 'promptweb_before_settings_to_style', $settings, $context );
		if ( ! is_array( $settings ) ) {
			return '';
		}

		/**
		 * Filters the settings key → CSS property map.
		 *
		 * @since 1.0.0
		 * @param array $style_map Map of setting key => CSS property.
		 * @param array $settings  Settings map.
		 * @param array $context   Element/section node.
		 */
		$map = apply_filters( 'promptweb_style_map', $this->style_map, $settings, $context );

		// Keys that are not CSS (skip in style output).
		$non_style_keys = array(
			'level', 'url', 'href', 'src', 'alt', 'target', 'class', 'className',
			'tag', 'caption', 'loading', 'size', 'type', 'id', 'align',
		);

		/**
		 * Filters setting keys that must not become CSS properties.
		 *
		 * @since 1.0.0
		 * @param string[] $non_style_keys Keys to skip.
		 */
		$non_style_keys = apply_filters( 'promptweb_non_style_setting_keys', $non_style_keys );

		$rules = array();

		foreach ( (array) $map as $key => $css_prop ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			if ( in_array( $key, (array) $non_style_keys, true ) ) {
				continue;
			}

			$value = $settings[ $key ];
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			// Auto-suffix bare numbers for common size properties.
			$value = $this->maybe_add_px( $value, $css_prop );
			$value = $this->sanitize_css_value( $value );
			if ( '' === $value ) {
				continue;
			}

			$rules[ $css_prop ] = $css_prop . ':' . $value;
		}

		// align → text-align alias.
		if ( isset( $settings['align'] ) && is_scalar( $settings['align'] ) && ! isset( $settings['text_align'] ) ) {
			$align = $this->sanitize_css_value( (string) $settings['align'] );
			if ( in_array( $align, array( 'left', 'right', 'center', 'justify', 'start', 'end' ), true ) ) {
				$rules['text-align'] = 'text-align:' . $align;
			}
		}

		// Safer extra rules: array of prop => value.
		/**
		 * Filters additional style declarations as [ css_prop => value ].
		 *
		 * @since 1.0.0
		 * @param array $extra    Map of CSS property => value.
		 * @param array $settings Settings map.
		 * @param array $context  Element/section node.
		 */
		$extra_map = apply_filters( 'promptweb_extra_style_map', array(), $settings, $context );
		if ( is_array( $extra_map ) ) {
			foreach ( $extra_map as $prop => $val ) {
				if ( ! is_string( $prop ) || ! is_scalar( $val ) ) {
					continue;
				}
				$prop = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $prop ) );
				$val  = $this->sanitize_css_value( (string) $val );
				if ( '' === $prop || '' === $val ) {
					continue;
				}
				$rules[ $prop ] = $prop . ':' . $val;
			}
		}

		/**
		 * Filters compiled style rule list (unique "prop:value" strings).
		 *
		 * @since 1.0.0
		 * @param string[] $rules    CSS declarations.
		 * @param array    $settings Original settings.
		 * @param array    $context  Element/section node.
		 */
		$rules = apply_filters( 'promptweb_style_rules', array_values( $rules ), $settings, $context );

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return '';
		}

		$out = implode( ';', array_filter( array_map( 'strval', $rules ) ) );

		/**
		 * Filters final inline style string.
		 *
		 * @since 1.0.0
		 * @param string $out      Style attribute value.
		 * @param array  $settings Settings map.
		 * @param array  $context  Element/section node.
		 */
		return (string) apply_filters( 'promptweb_style_string', $out, $settings, $context );
	}

	/**
	 * Sanitize a CSS value for use in style attributes.
	 *
	 * @since 1.0.0
	 * @param string $value Raw value.
	 * @return string
	 */
	protected function sanitize_css_value( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = str_replace( array( '{', '}', '<', '>', '"', "'" ), '', $value );

		if ( preg_match( '/expression\s*\(/i', $value ) ) {
			return '';
		}
		if ( preg_match( '/javascript\s*:/i', $value ) ) {
			return '';
		}
		if ( preg_match( '/@import/i', $value ) ) {
			return '';
		}
		// Block data: and vbscript in url().
		if ( preg_match( '/url\s*\(\s*[\'"]?\s*(javascript|data|vbscript)\s*:/i', $value ) ) {
			return '';
		}

		/**
		 * Filters a sanitized CSS value (return empty to drop the rule).
		 *
		 * @since 1.0.0
		 * @param string $value CSS value.
		 */
		return trim( (string) apply_filters( 'promptweb_sanitize_css_value', $value ) );
	}

	/**
	 * Append px to bare numeric values for length properties.
	 *
	 * @since 1.0.0
	 * @param string $value    Raw value.
	 * @param string $css_prop CSS property name.
	 * @return string
	 */
	protected function maybe_add_px( $value, $css_prop ) {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		$length_props = array(
			'font-size', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
			'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
			'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
			'gap', 'border-radius', 'border-width', 'top', 'right', 'bottom', 'left',
			'letter-spacing',
		);

		/**
		 * Filters CSS properties that accept bare numbers as px.
		 *
		 * @since 1.0.0
		 * @param string[] $length_props Property names.
		 */
		$length_props = apply_filters( 'promptweb_px_css_properties', $length_props );

		if ( in_array( $css_prop, (array) $length_props, true ) ) {
			return $value . 'px';
		}

		return $value;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize element type string.
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string Lowercase type without core/ prefix.
	 */
	protected function normalize_type( array $element ) {
		$type = '';
		if ( isset( $element['type'] ) && is_scalar( $element['type'] ) ) {
			$type = strtolower( trim( (string) $element['type'] ) );
		}

		$type = str_replace( 'core/', '', $type );
		// Normalize separators so "button-group" / "button_group" / "button group" match.
		$type = preg_replace( '/[\s\-]+/', '_', $type );
		$type = is_string( $type ) ? $type : '';

		/**
		 * Filters normalized element type.
		 *
		 * @since 1.0.0
		 * @param string $type    Normalized type.
		 * @param array  $element Element definition.
		 */
		return (string) apply_filters( 'promptweb_normalize_element_type', $type, $element );
	}

	/**
	 * Extract nested element lists from a node (section or unknown).
	 *
	 * @since 1.0.0
	 * @param array $node Parent node.
	 * @return array List of element arrays.
	 */
	protected function extract_child_elements( array $node ) {
		$candidates = array( 'elements', 'children', 'items', 'blocks' );
		$nested     = array();

		foreach ( $candidates as $key ) {
			if ( empty( $node[ $key ] ) || ! is_array( $node[ $key ] ) ) {
				continue;
			}
			foreach ( $node[ $key ] as $child ) {
				if ( is_array( $child ) ) {
					// Skip pure scalar item lists without type/content for items key —
					// still allow any array object.
					$nested[] = $child;
				}
			}
			// First matching list wins to avoid double-rendering.
			if ( ! empty( $nested ) ) {
				break;
			}
		}

		/**
		 * Filters extracted child elements for a parent node.
		 *
		 * @since 1.0.0
		 * @param array $nested Child elements.
		 * @param array $node   Parent node.
		 */
		$nested = apply_filters( 'promptweb_child_elements', $nested, $node );

		return is_array( $nested ) ? $nested : array();
	}

	/**
	 * Read settings object from a node (array or object).
	 *
	 * @since 1.0.0
	 * @param array $node Element or section.
	 * @return array
	 */
	protected function get_settings( array $node ) {
		$settings = array();

		if ( isset( $node['settings'] ) ) {
			if ( is_array( $node['settings'] ) ) {
				$settings = $node['settings'];
			} elseif ( is_object( $node['settings'] ) ) {
				$settings = (array) $node['settings'];
			}
		}

		// Merge style sub-object if AI nests styles.
		if ( isset( $settings['style'] ) && is_array( $settings['style'] ) ) {
			$settings = array_merge( $settings['style'], $settings );
			unset( $settings['style'] );
		}

		/**
		 * Filters resolved settings for a node.
		 *
		 * @since 1.0.0
		 * @param array $settings Settings map.
		 * @param array $node     Element/section.
		 */
		$settings = apply_filters( 'promptweb_element_settings', $settings, $node );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Extract and sanitize textual content.
	 *
	 * @since 1.0.0
	 * @param array $node Element definition.
	 * @return string
	 */
	protected function get_text( array $node ) {
		$text = '';

		if ( isset( $node['content'] ) && is_scalar( $node['content'] ) ) {
			$text = (string) $node['content'];
		} elseif ( isset( $node['text'] ) && is_scalar( $node['text'] ) ) {
			$text = (string) $node['text'];
		} elseif ( isset( $node['label'] ) && is_scalar( $node['label'] ) ) {
			$text = (string) $node['label'];
		} elseif ( isset( $node['title'] ) && is_scalar( $node['title'] ) && empty( $node['type'] ) ) {
			$text = (string) $node['title'];
		}

		/**
		 * Filters raw text before inline sanitization.
		 *
		 * @since 1.0.0
		 * @param string $text Raw text.
		 * @param array  $node Element node.
		 */
		$text = (string) apply_filters( 'promptweb_element_text_raw', $text, $node );

		return $this->sanitize_inline( $text );
	}

	/**
	 * Resolve a link URL from element/settings.
	 *
	 * @since 1.0.0
	 * @param array $element  Element definition.
	 * @param array $settings Settings map.
	 * @return string
	 */
	protected function resolve_url( array $element, array $settings ) {
		$url = '';
		foreach ( array( 'url', 'href', 'link' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
				$url = (string) $settings[ $key ];
				break;
			}
			if ( ! empty( $element[ $key ] ) && is_scalar( $element[ $key ] ) ) {
				$url = (string) $element[ $key ];
				break;
			}
		}

		/**
		 * Filters resolved URL for buttons/links.
		 *
		 * @since 1.0.0
		 * @param string $url      URL.
		 * @param array  $element  Element definition.
		 * @param array  $settings Settings map.
		 */
		$url = (string) apply_filters( 'promptweb_element_url', $url, $element, $settings );

		return $this->sanitize_url( $url );
	}

	/**
	 * Resolve image source URL.
	 *
	 * @since 1.0.0
	 * @param array $element  Element definition.
	 * @param array $settings Settings map.
	 * @return string
	 */
	protected function resolve_image_url( array $element, array $settings ) {
		$url = '';
		foreach ( array( 'src', 'url', 'image', 'image_url', 'href' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
				$url = (string) $settings[ $key ];
				break;
			}
			if ( ! empty( $element[ $key ] ) && is_scalar( $element[ $key ] ) ) {
				$url = (string) $element[ $key ];
				break;
			}
		}

		/**
		 * Filters resolved image URL.
		 *
		 * @since 1.0.0
		 * @param string $url      Image URL.
		 * @param array  $element  Element definition.
		 * @param array  $settings Settings map.
		 */
		$url = (string) apply_filters( 'promptweb_image_url', $url, $element, $settings );

		return $this->sanitize_url( $url );
	}

	/**
	 * Allow light inline HTML in text nodes.
	 *
	 * @since 1.0.0
	 * @param string $text Raw text.
	 * @return string
	 */
	protected function sanitize_inline( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		/**
		 * Filters sanitized inline HTML for text/heading content.
		 *
		 * @since 1.0.0
		 * @param string $html Sanitized HTML.
		 * @param string $text Original text.
		 */
		return (string) apply_filters( 'promptweb_sanitize_inline', wp_kses_post( $text ), $text );
	}

	/**
	 * Sanitize URLs (including root-relative paths).
	 *
	 * @since 1.0.0
	 * @param string $url Raw URL.
	 * @return string
	 */
	protected function sanitize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		// Allow bare anchors.
		if ( isset( $url[0] ) && '#' === $url[0] ) {
			return esc_url_raw( $url );
		}

		return esc_url_raw( $url );
	}
}
