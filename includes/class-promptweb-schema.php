<?php
/**
 * Official PromptWeb blueprint JSON schema.
 *
 * The structured JSON stored in GitHub is the single source of truth for the
 * entire website (not Gutenberg, not post_content as the content model).
 *
 * Hierarchy:
 *   blueprint
 *     ├── version   (schema version string, e.g. "1.0")
 *     ├── site      (global site metadata)
 *     ├── pages[]   (one entry per page)
 *     │     ├── id, title, slug, status, is_front_page
 *     │     └── sections[]
 *     │           ├── id, type ("section"), settings{}
 *     │           └── elements[]
 *     │                 ├── id, type, content, settings{}
 *     └── prompts[] (optional; AI instructions stored for external processing)
 *
 * Element types (v1.0 core set):
 *   - heading  — settings.level (1–6), color, font_size, margin_bottom, …
 *   - text     — paragraph body; color, font_size, …
 *   - button   — settings.url + visual styles
 *   - image    — settings.src / url, alt (extensible)
 *   - html     — raw allowed HTML in content (use sparingly)
 *
 * Settings keys are free-form CSS-oriented maps. The Renderer only emits a
 * safe subset of CSS properties (see PromptWeb_Renderer::settings_to_style()).
 *
 * Extensibility:
 *   - New element types via `promptweb_render_element_unknown` / schema filters.
 *   - Optional top-level keys (e.g. prompts, theme, meta) are allowed; validate()
 *     only enforces the minimum required shape for renderable sites.
 *
 * Multisite: schema is site-agnostic; each blog may store/sync its own blueprint.
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documents, examples, and validates PromptWeb blueprints.
 *
 * @since 1.0.0
 */
class PromptWeb_Schema {

	/**
	 * Current official schema version string.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const VERSION = '1.0';

	/**
	 * Core element types recognized by the v1.0 schema.
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	const ELEMENT_TYPES = array(
		'heading',
		'text',
		'button',
		'image',
		'html',
		// Legacy aliases still accepted by the renderer.
		'paragraph',
		'buttons',
	);

	/**
	 * Allowed page statuses (mirror common WP post statuses).
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	const PAGE_STATUSES = array(
		'publish',
		'draft',
		'pending',
		'private',
	);

	/**
	 * Human-readable schema documentation (for admin/docs/tools).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_documentation() {
		return <<<'DOC'
PromptWeb Blueprint Schema v1.0
===============================

Root object
-----------
version   string   Required for new blueprints. Schema version (e.g. "1.0").
site      object   Optional global metadata.
  title   string   Site title.
  tagline string   Site tagline / description.
pages     array    Required. At least one page object.
prompts   array    Optional. AI prompt records for external processing:
  id      string
  text    string   The prompt body.
  status  string   e.g. "pending", "processing", "done".
  created string   ISO-8601 or MySQL datetime (optional).

Page object
-----------
id             string   Stable unique id (e.g. "page-home"). Preferred over slug for edits.
title          string   Required (or slug). Human title.
slug           string   Required (or title). URL slug.
status         string   publish|draft|pending|private (default publish).
is_front_page  bool     When true, treated as the site home page.
sections       array    Preferred content tree (schema v1.0).
blocks         array    Legacy flat list (still accepted by Renderer for older files).

Section object
--------------
id         string   Stable unique id (e.g. "section-hero").
type       string   Usually "section".
settings   object   Visual/layout map (background, padding, text_align, …).
elements   array    Ordered list of element objects.

Element object
--------------
id         string   Stable unique id (e.g. "heading-1").
type       string   heading|text|button|image|html (plus legacy aliases).
content    string   Primary text/HTML payload (type-dependent).
settings   object   Type-specific + CSS-oriented options:
  heading: level, color, font_size, margin_bottom, text_align, …
  text:    color, font_size, line_height, …
  button:  url, background, color, padding, border_radius, …
  image:   src|url, alt, width, height, …

Validation (minimum)
--------------------
- Root must be an object/array.
- pages must be a non-empty array of objects.
- Each page needs title and/or slug.
- If sections exist, each section should be an object; elements optional arrays.
DOC;
	}

	/**
	 * Full example blueprint matching the official v1.0 structure.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public static function get_example_blueprint() {
		$example = array(
			'version' => self::VERSION,
			'site'    => array(
				'title'   => 'Website Title',
				'tagline' => 'Website tagline',
			),
			'pages'   => array(
				array(
					'id'            => 'page-home',
					'title'         => 'Home',
					'slug'          => 'home',
					'status'        => 'publish',
					'is_front_page' => true,
					'sections'      => array(
						array(
							'id'       => 'section-hero',
							'type'     => 'section',
							'settings' => array(
								'background' => '#0f172a',
								'padding'    => '80px 20px',
								'text_align' => 'center',
							),
							'elements' => array(
								array(
									'id'       => 'heading-1',
									'type'     => 'heading',
									'content'  => 'Welcome to our website',
									'settings' => array(
										'level'         => 1,
										'color'         => '#ffffff',
										'font_size'     => '48px',
										'margin_bottom' => '16px',
									),
								),
								array(
									'id'       => 'text-1',
									'type'     => 'text',
									'content'  => 'This is a powerful AI-generated website.',
									'settings' => array(
										'color'     => '#cbd5e1',
										'font_size' => '18px',
									),
								),
								array(
									'id'       => 'button-1',
									'type'     => 'button',
									'content'  => 'Get Started',
									'settings' => array(
										'url'           => '/contact',
										'background'    => '#3b82f6',
										'color'         => '#ffffff',
										'padding'       => '14px 32px',
										'border_radius' => '8px',
									),
								),
							),
						),
					),
				),
			),
			// Optional: AI prompts stored in JSON for external processing after GitHub push.
			'prompts' => array(),
		);

		/**
		 * Filters the official example blueprint.
		 *
		 * @since 1.0.0
		 * @param array $example Example payload.
		 */
		return (array) apply_filters( 'promptweb_example_blueprint', $example );
	}

	/**
	 * Validate the minimum required structure of a blueprint.
	 *
	 * Does not enforce every optional field; focuses on “can this be rendered?”.
	 *
	 * @since 1.0.0
	 * @param mixed $blueprint Decoded JSON (array expected).
	 * @return true|WP_Error True when valid; WP_Error with messages when not.
	 */
	public static function validate( $blueprint ) {
		$errors = array();

		if ( ! is_array( $blueprint ) ) {
			return new WP_Error(
				'promptweb_schema_not_object',
				__( 'Blueprint must be a JSON object.', 'promptweb' )
			);
		}

		// version is recommended; warn-style error only if present but empty/invalid type.
		if ( array_key_exists( 'version', $blueprint ) && ! is_scalar( $blueprint['version'] ) ) {
			$errors[] = __( 'Field "version" must be a string when provided.', 'promptweb' );
		}

		if ( isset( $blueprint['site'] ) && ! is_array( $blueprint['site'] ) ) {
			$errors[] = __( 'Field "site" must be an object when provided.', 'promptweb' );
		}

		if ( ! isset( $blueprint['pages'] ) || ! is_array( $blueprint['pages'] ) ) {
			$errors[] = __( 'Blueprint must include a "pages" array.', 'promptweb' );
		} elseif ( empty( $blueprint['pages'] ) ) {
			$errors[] = __( 'Blueprint "pages" array must not be empty.', 'promptweb' );
		} else {
			foreach ( $blueprint['pages'] as $index => $page ) {
				$page_errors = self::validate_page( $page, (int) $index );
				if ( ! empty( $page_errors ) ) {
					$errors = array_merge( $errors, $page_errors );
				}
			}
		}

		if ( isset( $blueprint['prompts'] ) && ! is_array( $blueprint['prompts'] ) ) {
			$errors[] = __( 'Field "prompts" must be an array when provided.', 'promptweb' );
		}

		/**
		 * Filters schema validation errors (empty array = valid).
		 *
		 * @since 1.0.0
		 * @param string[] $errors    Error messages.
		 * @param array    $blueprint Blueprint under test.
		 */
		$errors = apply_filters( 'promptweb_schema_validate_errors', $errors, $blueprint );

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'promptweb_schema_invalid',
				implode( ' ', $errors ),
				array( 'errors' => $errors )
			);
		}

		return true;
	}

	/**
	 * Validate a single page node.
	 *
	 * @since 1.0.0
	 * @param mixed $page  Page value.
	 * @param int   $index Index in pages array.
	 * @return string[] Error messages (empty if OK).
	 */
	protected static function validate_page( $page, $index ) {
		$errors = array();
		$label  = sprintf(
			/* translators: %d: page index */
			__( 'Page[%d]', 'promptweb' ),
			$index
		);

		if ( ! is_array( $page ) ) {
			$errors[] = sprintf(
				/* translators: %s: page label */
				__( '%s must be an object.', 'promptweb' ),
				$label
			);
			return $errors;
		}

		$title = isset( $page['title'] ) ? trim( (string) $page['title'] ) : '';
		$slug  = isset( $page['slug'] ) ? trim( (string) $page['slug'] ) : '';

		if ( '' === $title && '' === $slug ) {
			$errors[] = sprintf(
				/* translators: %s: page label */
				__( '%s requires a "title" and/or "slug".', 'promptweb' ),
				$label
			);
		}

		if ( isset( $page['status'] ) && is_string( $page['status'] ) ) {
			$status = sanitize_key( $page['status'] );
			if ( ! in_array( $status, self::PAGE_STATUSES, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: page label, 2: status value */
					__( '%1$s has unsupported status "%2$s".', 'promptweb' ),
					$label,
					$status
				);
			}
		}

		// Prefer sections (v1.0); allow legacy blocks without error.
		if ( isset( $page['sections'] ) ) {
			if ( ! is_array( $page['sections'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: page label */
					__( '%s "sections" must be an array.', 'promptweb' ),
					$label
				);
			} else {
				foreach ( $page['sections'] as $s_index => $section ) {
					$errors = array_merge(
						$errors,
						self::validate_section( $section, $index, (int) $s_index )
					);
				}
			}
		} elseif ( isset( $page['blocks'] ) && ! is_array( $page['blocks'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: page label */
				__( '%s legacy "blocks" must be an array when provided.', 'promptweb' ),
				$label
			);
		}

		return $errors;
	}

	/**
	 * Validate a section node.
	 *
	 * @since 1.0.0
	 * @param mixed $section  Section value.
	 * @param int   $page_i   Page index.
	 * @param int   $section_i Section index.
	 * @return string[]
	 */
	protected static function validate_section( $section, $page_i, $section_i ) {
		$errors = array();
		$label  = sprintf(
			/* translators: 1: page index, 2: section index */
			__( 'Page[%1$d].sections[%2$d]', 'promptweb' ),
			$page_i,
			$section_i
		);

		if ( ! is_array( $section ) ) {
			$errors[] = sprintf(
				/* translators: %s: section label */
				__( '%s must be an object.', 'promptweb' ),
				$label
			);
			return $errors;
		}

		if ( isset( $section['settings'] ) && ! is_array( $section['settings'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: section label */
				__( '%s "settings" must be an object.', 'promptweb' ),
				$label
			);
		}

		if ( isset( $section['elements'] ) ) {
			if ( ! is_array( $section['elements'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: section label */
					__( '%s "elements" must be an array.', 'promptweb' ),
					$label
				);
			} else {
				foreach ( $section['elements'] as $e_index => $element ) {
					if ( ! is_array( $element ) ) {
						$errors[] = sprintf(
							/* translators: 1: section label, 2: element index */
							__( '%1$s.elements[%2$d] must be an object.', 'promptweb' ),
							$label,
							(int) $e_index
						);
						continue;
					}
					if ( empty( $element['type'] ) || ! is_string( $element['type'] ) ) {
						$errors[] = sprintf(
							/* translators: 1: section label, 2: element index */
							__( '%1$s.elements[%2$d] requires a string "type".', 'promptweb' ),
							$label,
							(int) $e_index
						);
					}
					if ( isset( $element['settings'] ) && ! is_array( $element['settings'] ) ) {
						$errors[] = sprintf(
							/* translators: 1: section label, 2: element index */
							__( '%1$s.elements[%2$d] "settings" must be an object.', 'promptweb' ),
							$label,
							(int) $e_index
						);
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Whether a blueprint validates (boolean convenience wrapper).
	 *
	 * @since 1.0.0
	 * @param mixed $blueprint Blueprint data.
	 * @return bool
	 */
	public static function is_valid( $blueprint ) {
		return true === self::validate( $blueprint );
	}

	/**
	 * JSON-encoded example blueprint (pretty-printed).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_example_json() {
		$json = wp_json_encode(
			self::get_example_blueprint(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Normalize known aliases so consumers can rely on v1.0 field names.
	 *
	 * - Maps page.blocks → synthetic sections when sections missing (non-destructive copy).
	 * - Maps element type "paragraph" → "text".
	 *
	 * Does not mutate the original; returns a new array.
	 *
	 * @since 1.0.0
	 * @param array $blueprint Blueprint payload.
	 * @return array
	 */
	public static function normalize( array $blueprint ) {
		$out = $blueprint;

		if ( empty( $out['version'] ) ) {
			$out['version'] = self::VERSION;
		}

		if ( empty( $out['pages'] ) || ! is_array( $out['pages'] ) ) {
			return $out;
		}

		foreach ( $out['pages'] as $i => $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			// Legacy: flat blocks → one section with those elements.
			if ( empty( $page['sections'] ) && ! empty( $page['blocks'] ) && is_array( $page['blocks'] ) ) {
				$elements = array();
				foreach ( $page['blocks'] as $b_index => $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}
					$el = $block;
					if ( empty( $el['id'] ) ) {
						$el['id'] = 'legacy-el-' . $i . '-' . $b_index;
					}
					if ( isset( $el['type'] ) && 'paragraph' === $el['type'] ) {
						$el['type'] = 'text';
					}
					// Lift top-level level/url into settings when needed.
					if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
						$el['settings'] = array();
					}
					if ( isset( $el['level'] ) && ! isset( $el['settings']['level'] ) ) {
						$el['settings']['level'] = $el['level'];
					}
					if ( isset( $el['url'] ) && ! isset( $el['settings']['url'] ) ) {
						$el['settings']['url'] = $el['url'];
					}
					// buttons group → multiple button elements later; keep as-is for renderer legacy.
					$elements[] = $el;
				}

				$out['pages'][ $i ]['sections'] = array(
					array(
						'id'       => 'section-legacy-' . $i,
						'type'     => 'section',
						'settings' => array(),
						'elements' => $elements,
					),
				);
			}

			if ( ! empty( $out['pages'][ $i ]['sections'] ) && is_array( $out['pages'][ $i ]['sections'] ) ) {
				foreach ( $out['pages'][ $i ]['sections'] as $s => $section ) {
					if ( empty( $section['elements'] ) || ! is_array( $section['elements'] ) ) {
						continue;
					}
					foreach ( $section['elements'] as $e => $element ) {
						if ( ! is_array( $element ) ) {
							continue;
						}
						if ( isset( $element['type'] ) && 'paragraph' === $element['type'] ) {
							$out['pages'][ $i ]['sections'][ $s ]['elements'][ $e ]['type'] = 'text';
						}
					}
				}
			}
		}

		/**
		 * Filters a normalized blueprint.
		 *
		 * @since 1.0.0
		 * @param array $out        Normalized blueprint.
		 * @param array $blueprint  Original blueprint.
		 */
		return (array) apply_filters( 'promptweb_schema_normalize', $out, $blueprint );
	}
}
