<?php
/**
 * Structured JSON → frontend HTML renderer.
 *
 * Architecture (JSON-first):
 * - GitHub-stored structured JSON is the single source of truth for site content.
 * - Renders PromptWeb Schema v1.0: pages → sections → elements (+ settings styles).
 * - Does NOT write Gutenberg blocks or WordPress post_content as the content model.
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
 * Multisite: operates on the current site’s runtime blueprint; network-activated
 * installs still render in the current blog context.
 *
 * @since 1.0.0
 */
class PromptWeb_Renderer {

	/**
	 * CSS properties allowed in element/section settings → inline style.
	 *
	 * Keys are JSON settings keys; values are CSS property names.
	 *
	 * @since 1.0.0
	 * @var   array<string, string>
	 */
	protected $style_map = array(
		'background'       => 'background',
		'background_color' => 'background-color',
		'color'            => 'color',
		'padding'          => 'padding',
		'margin'           => 'margin',
		'margin_top'       => 'margin-top',
		'margin_right'     => 'margin-right',
		'margin_bottom'    => 'margin-bottom',
		'margin_left'      => 'margin-left',
		'font_size'        => 'font-size',
		'font_weight'      => 'font-weight',
		'line_height'      => 'line-height',
		'text_align'       => 'text-align',
		'border_radius'    => 'border-radius',
		'border'           => 'border',
		'width'            => 'width',
		'max_width'        => 'max-width',
		'height'           => 'height',
		'display'          => 'display',
		'gap'              => 'gap',
		'letter_spacing'   => 'letter-spacing',
		'text_decoration'  => 'text-decoration',
		'box_shadow'       => 'box-shadow',
		'opacity'          => 'opacity',
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
		 * @since 1.0.0
		 * @param PromptWeb_Renderer $renderer This instance.
		 */
		do_action( 'promptweb_renderer_init', $this );
	}

	/**
	 * Render a blueprint page to HTML (Schema v1.0).
	 *
	 * @since 1.0.0
	 * @param array       $blueprint Decoded blueprint JSON.
	 * @param string|null $page_slug Optional page slug; null = front page / first page.
	 * @return string Safe HTML (may be empty).
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

		// Normalize legacy shapes (blocks → sections) when Schema class is available.
		if ( class_exists( 'PromptWeb_Schema' ) ) {
			$blueprint = PromptWeb_Schema::normalize( $blueprint );
		}

		$page = $this->resolve_page( $blueprint, $page_slug );
		if ( empty( $page ) || ! is_array( $page ) ) {
			return '';
		}

		$html = $this->render_page( $page );

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
	 * Render a single page object (sections or legacy blocks).
	 *
	 * @since 1.0.0
	 * @param array $page Page definition.
	 * @return string
	 */
	public function render_page( array $page ) {
		$page_id = isset( $page['id'] ) ? sanitize_html_class( (string) $page['id'] ) : '';
		$slug    = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';

		$classes = array( 'promptweb-page' );
		if ( $page_id ) {
			$classes[] = 'promptweb-page--' . $page_id;
		}
		if ( $slug ) {
			$classes[] = 'promptweb-page-slug--' . sanitize_html_class( $slug );
		}

		$attrs = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		if ( $page_id ) {
			$attrs .= ' data-promptweb-page-id="' . esc_attr( (string) $page['id'] ) . '"';
		}
		if ( $slug ) {
			$attrs .= ' data-promptweb-page-slug="' . esc_attr( $slug ) . '"';
		}

		$inner = '';

		if ( ! empty( $page['sections'] ) && is_array( $page['sections'] ) ) {
			$parts = array();
			foreach ( $page['sections'] as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$chunk = $this->render_section( $section );
				if ( '' !== trim( $chunk ) ) {
					$parts[] = $chunk;
				}
			}
			$inner = implode( "\n", $parts );
		} elseif ( ! empty( $page['blocks'] ) && is_array( $page['blocks'] ) ) {
			// Direct legacy path if normalize() was skipped.
			$inner = $this->render_elements( $page['blocks'] );
		}

		return '<div' . $attrs . ">\n" . $inner . "\n</div>";
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
				// Also allow match by stable page id.
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

	/**
	 * Render a section wrapper + its elements.
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

		$settings = isset( $section['settings'] ) && is_array( $section['settings'] ) ? $section['settings'] : array();
		$elements = isset( $section['elements'] ) && is_array( $section['elements'] ) ? $section['elements'] : array();

		$section_id = isset( $section['id'] ) ? (string) $section['id'] : '';
		$classes    = array( 'promptweb-section' );
		if ( $section_id ) {
			$classes[] = 'promptweb-section--' . sanitize_html_class( $section_id );
		}

		$style = $this->settings_to_style( $settings );
		$attrs = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		if ( $section_id ) {
			$attrs .= ' data-promptweb-id="' . esc_attr( $section_id ) . '"';
		}
		if ( '' !== $style ) {
			$attrs .= ' style="' . esc_attr( $style ) . '"';
		}

		$inner = $this->render_elements( $elements );

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
	 * Render a list of elements.
	 *
	 * @since 1.0.0
	 * @param array $elements Element definitions.
	 * @return string
	 */
	public function render_elements( array $elements ) {
		$parts = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$chunk = $this->render_element( $element );
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				$parts[] = $chunk;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Alias for legacy callers that used render_blocks().
	 *
	 * @since 1.0.0
	 * @param array $blocks Element-like definitions.
	 * @return string
	 */
	public function render_blocks( array $blocks ) {
		return $this->render_elements( $blocks );
	}

	/**
	 * Render a single element (Schema v1.0) or legacy block.
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	public function render_element( array $element ) {
		$type = isset( $element['type'] ) ? strtolower( (string) $element['type'] ) : '';
		$type = str_replace( 'core/', '', $type );

		/**
		 * Short-circuit a single element render.
		 *
		 * @since 1.0.0
		 * @param string|null $pre     HTML or null to continue.
		 * @param array       $element Element definition.
		 * @param string      $type    Normalized type.
		 */
		$pre = apply_filters( 'promptweb_render_element_pre', null, $element, $type );
		if ( null === $pre ) {
			// Backward-compatible filter name from earlier renderer.
			$pre = apply_filters( 'promptweb_render_block_pre', null, $element, $type );
		}
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
				$html = $this->render_buttons_group( $element );
				break;

			case 'image':
				$html = $this->render_image( $element );
				break;

			case 'html':
				$html = $this->render_html_element( $element );
				break;

			case 'section':
				// Nested section support.
				$html = $this->render_section( $element );
				break;

			default:
				$html = '';
				/**
				 * Filters HTML for unknown element types.
				 *
				 * @since 1.0.0
				 * @param string $html    Empty by default.
				 * @param array  $element Element definition.
				 * @param string $type    Normalized type.
				 */
				$html = apply_filters( 'promptweb_render_element_unknown', $html, $element, $type );
				$html = apply_filters( 'promptweb_render_block_unknown', $html, $element, $type );
				break;
		}

		/**
		 * Filters final HTML for a rendered element.
		 *
		 * @since 1.0.0
		 * @param string $html    Element HTML.
		 * @param array  $element Element definition.
		 * @param string $type    Normalized type.
		 */
		$html = apply_filters( 'promptweb_render_element', $html, $element, $type );
		return (string) apply_filters( 'promptweb_render_block', $html, $element, $type );
	}

	/**
	 * Alias for render_element() (legacy name).
	 *
	 * @since 1.0.0
	 * @param array $block Element definition.
	 * @return string
	 */
	public function render_block( array $block ) {
		return $this->render_element( $block );
	}

	/**
	 * Heading element.
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
		$attrs = $this->build_element_attrs( $element, array( 'promptweb-element', 'promptweb-heading' ), $settings );

		return '<' . $tag . $attrs . '>' . $content . '</' . $tag . '>';
	}

	/**
	 * Text / paragraph element.
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
		$attrs    = $this->build_element_attrs( $element, array( 'promptweb-element', 'promptweb-text' ), $settings );

		return '<p' . $attrs . '>' . $content . '</p>';
	}

	/**
	 * Single button element (Schema v1.0).
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

		$url = '';
		if ( isset( $settings['url'] ) ) {
			$url = $this->sanitize_url( (string) $settings['url'] );
		} elseif ( isset( $element['url'] ) ) {
			$url = $this->sanitize_url( (string) $element['url'] );
		} elseif ( isset( $settings['href'] ) ) {
			$url = $this->sanitize_url( (string) $settings['href'] );
		}

		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-button' ),
			$settings
		);

		if ( '' !== $url ) {
			$attrs .= ' href="' . esc_url( $url ) . '"';
		}

		return '<a' . $attrs . '>' . $content . '</a>';
	}

	/**
	 * Legacy buttons group (`type: buttons` + `items`).
	 *
	 * @since 1.0.0
	 * @param array $element Group definition.
	 * @return string
	 */
	protected function render_buttons_group( array $element ) {
		$items = array();

		if ( ! empty( $element['items'] ) && is_array( $element['items'] ) ) {
			$items = $element['items'];
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
			// Normalize item to button element shape.
			$button = $item;
			if ( empty( $button['type'] ) ) {
				$button['type'] = 'button';
			}
			if ( empty( $button['id'] ) && ! empty( $element['id'] ) ) {
				$button['id'] = $element['id'] . '-item-' . $index;
			}
			if ( ! isset( $button['settings'] ) || ! is_array( $button['settings'] ) ) {
				$button['settings'] = array();
			}
			if ( isset( $item['url'] ) && ! isset( $button['settings']['url'] ) ) {
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
		$attrs    = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-buttons' ),
			$settings
		);

		return '<div' . $attrs . '>' . implode( '', $links ) . '</div>';
	}

	/**
	 * Image element.
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	protected function render_image( array $element ) {
		$settings = $this->get_settings( $element );

		$url = '';
		if ( isset( $settings['src'] ) ) {
			$url = $this->sanitize_url( (string) $settings['src'] );
		} elseif ( isset( $settings['url'] ) ) {
			$url = $this->sanitize_url( (string) $settings['url'] );
		} elseif ( isset( $element['url'] ) ) {
			$url = $this->sanitize_url( (string) $element['url'] );
		} elseif ( isset( $element['src'] ) ) {
			$url = $this->sanitize_url( (string) $element['src'] );
		}

		if ( '' === $url ) {
			return '';
		}

		$alt = '';
		if ( isset( $settings['alt'] ) ) {
			$alt = sanitize_text_field( (string) $settings['alt'] );
		} elseif ( isset( $element['alt'] ) ) {
			$alt = sanitize_text_field( (string) $element['alt'] );
		} elseif ( isset( $element['content'] ) ) {
			$alt = sanitize_text_field( (string) $element['content'] );
		}

		// Image-specific style keys already covered by style_map (width/height).
		$attrs = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-image' ),
			$settings
		);

		return '<img' . $attrs . ' src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" />';
	}

	/**
	 * Custom HTML element (sanitized).
	 *
	 * @since 1.0.0
	 * @param array $element Element definition.
	 * @return string
	 */
	protected function render_html_element( array $element ) {
		$html = '';
		if ( isset( $element['content'] ) ) {
			$html = (string) $element['content'];
		} elseif ( isset( $element['html'] ) ) {
			$html = (string) $element['html'];
		}

		$html = wp_kses_post( $html );
		if ( '' === trim( $html ) ) {
			return '';
		}

		$settings = $this->get_settings( $element );
		$attrs    = $this->build_element_attrs(
			$element,
			array( 'promptweb-element', 'promptweb-html' ),
			$settings
		);

		return '<div' . $attrs . '>' . $html . '</div>';
	}

	/**
	 * Build class/id/style/data attributes for an element wrapper.
	 *
	 * @since 1.0.0
	 * @param array    $element  Element definition.
	 * @param string[] $classes  Base classes.
	 * @param array    $settings Settings map.
	 * @return string Attribute string starting with a space (or empty).
	 */
	protected function build_element_attrs( array $element, array $classes, array $settings ) {
		$id = isset( $element['id'] ) ? (string) $element['id'] : '';
		if ( $id ) {
			$classes[] = 'promptweb-element--' . sanitize_html_class( $id );
		}

		/**
		 * Filters element CSS classes.
		 *
		 * @since 1.0.0
		 * @param string[] $classes  Class list.
		 * @param array    $element  Element definition.
		 * @param array    $settings Settings map.
		 */
		$classes = apply_filters( 'promptweb_element_classes', $classes, $element, $settings );
		$classes = array_filter( array_map( 'sanitize_html_class', (array) $classes ) );

		$parts = array();
		if ( ! empty( $classes ) ) {
			$parts[] = 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		}
		if ( $id ) {
			$parts[] = 'data-promptweb-id="' . esc_attr( $id ) . '"';
		}

		$style = $this->settings_to_style( $settings );
		if ( '' !== $style ) {
			$parts[] = 'style="' . esc_attr( $style ) . '"';
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return ' ' . implode( ' ', $parts );
	}

	/**
	 * Convert a settings object into a safe inline CSS string.
	 *
	 * @since 1.0.0
	 * @param array $settings Settings map from JSON.
	 * @return string e.g. "color:#fff;font-size:18px"
	 */
	public function settings_to_style( array $settings ) {
		/**
		 * Filters the settings→CSS property map.
		 *
		 * @since 1.0.0
		 * @param array $style_map Map of setting key => CSS property.
		 */
		$map = apply_filters( 'promptweb_style_map', $this->style_map );

		$rules = array();

		foreach ( $map as $key => $css_prop ) {
			if ( ! array_key_exists( $key, $settings ) ) {
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
			// Strip characters that could break out of a style attribute.
			$value = $this->sanitize_css_value( $value );
			if ( '' === $value ) {
				continue;
			}
			$rules[] = $css_prop . ':' . $value;
		}

		// Allow text_align via common alias "align".
		if ( isset( $settings['align'] ) && is_scalar( $settings['align'] ) && ! isset( $settings['text_align'] ) ) {
			$align = $this->sanitize_css_value( (string) $settings['align'] );
			if ( in_array( $align, array( 'left', 'right', 'center', 'justify', 'start', 'end' ), true ) ) {
				$rules[] = 'text-align:' . $align;
			}
		}

		/**
		 * Filters compiled inline style declarations (without trailing semicolon requirement).
		 *
		 * @since 1.0.0
		 * @param string[] $rules    CSS "prop:value" pieces.
		 * @param array    $settings Original settings.
		 */
		$rules = apply_filters( 'promptweb_style_rules', $rules, $settings );

		if ( empty( $rules ) ) {
			return '';
		}

		return implode( ';', $rules );
	}

	/**
	 * Sanitize a CSS value for inline styles (no braces, no angle brackets).
	 *
	 * @since 1.0.0
	 * @param string $value Raw value.
	 * @return string
	 */
	protected function sanitize_css_value( $value ) {
		$value = wp_strip_all_tags( $value );
		$value = str_replace( array( '{', '}', '<', '>', '"', "'" ), '', $value );
		// Block expression() / url(javascript:...) style vectors loosely.
		if ( preg_match( '/expression\s*\(/i', $value ) ) {
			return '';
		}
		if ( preg_match( '/javascript\s*:/i', $value ) ) {
			return '';
		}

		return trim( $value );
	}

	/**
	 * Read settings object from an element/section.
	 *
	 * @since 1.0.0
	 * @param array $node Element or section.
	 * @return array
	 */
	protected function get_settings( array $node ) {
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			return $node['settings'];
		}

		return array();
	}

	/**
	 * Extract and sanitize textual content.
	 *
	 * @since 1.0.0
	 * @param array $node Element definition.
	 * @return string
	 */
	protected function get_text( array $node ) {
		if ( isset( $node['content'] ) ) {
			return $this->sanitize_inline( (string) $node['content'] );
		}
		if ( isset( $node['text'] ) ) {
			return $this->sanitize_inline( (string) $node['text'] );
		}
		if ( isset( $node['label'] ) ) {
			return $this->sanitize_inline( (string) $node['label'] );
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
	 * Sanitize URLs (including root-relative paths).
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
