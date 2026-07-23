<?php
/**
 * Official PromptWeb blueprint JSON schema (Maximum AI Creativity).
 *
 * Direction:
 * - Structured JSON in GitHub is the single source of truth (not Gutenberg).
 * - AI has high freedom to invent element types, settings, and nested content.
 * - We only enforce a light skeleton so Renderer + Editor always have a tree:
 *     pages[] → sections[] → elements[]
 * - Unknown element types are VALID; the Renderer renders them generically
 *   (or via filters) so AI creativity is not blocked by a rigid allow-list.
 *
 * Hierarchy:
 *   blueprint
 *     ├── version   (schema version string, e.g. "1.0") — recommended
 *     ├── site      (global site metadata) — optional
 *     ├── pages[]   (at least one page object recommended)
 *     │     ├── id, title, slug, status, is_front_page
 *     │     └── sections[]
 *     │           ├── id, type, settings{}  (free-form settings)
 *     │           └── elements[]           (any type string allowed)
 *     │                 ├── id, type, content, settings{}, children? …
 *     └── prompts[] (optional; AI instructions for external processing)
 *     └── *         (other top-level keys allowed for AI/meta extensions)
 *
 * Known element types (hints for docs / examples — NOT a closed set):
 *   heading, text, button, image, html, … plus any AI-invented type.
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
	 * Suggested core element types (documentation / examples only).
	 *
	 * Maximum AI Creativity: this is NOT an allow-list. Unknown types are valid.
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
		// Common aliases — still not exclusive.
		'paragraph',
		'buttons',
	);

	/**
	 * Common page statuses (hints only — unknown status strings are allowed).
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
PromptWeb Blueprint Schema v1.0 — Maximum AI Creativity
=======================================================

Philosophy
----------
JSON is the source of truth. AI may invent new element types, settings keys,
and nested structures. Validation only protects the minimum tree shape so
Renderer and Editor can walk the document.

Root object
-----------
version   string   Recommended. Schema version (e.g. "1.0").
site      object   Optional global metadata (title, tagline, …).
pages     array    Soft-required: non-empty array of page objects for a usable site.
prompts   array    Optional AI prompt records for external processing.
*         any      Extra top-level keys are allowed (theme, meta, ai, …).

Page object
-----------
id             string   Stable unique id (e.g. "page-home").
title          string   Recommended (or slug).
slug           string   Recommended (or title).
status         string   Free string (publish/draft/… recommended, not enforced).
is_front_page  bool     When true, treated as the site home page.
sections       array    Preferred content tree.
blocks         array    Legacy flat list (normalized into sections when possible).
*              any      Extra page keys allowed for AI/editor extensions.

Section object
--------------
id         string   Stable unique id (e.g. "section-hero").
type       string   Usually "section" — other values allowed.
settings   object   Free-form visual/layout map.
elements   array    Ordered list of element objects (any types).
*          any      Extra section keys allowed.

Element object
--------------
id         string   Stable unique id.
type       string   ANY non-empty string (known or AI-invented).
content    mixed    Text/HTML/structured payload (type-dependent).
settings   object   Free-form options (CSS-oriented keys rendered when safe).
children   array    Optional nested elements (AI layouts).
elements   array    Optional alias for nested elements.
items      array    Optional list payload (e.g. buttons, cards).
*          any      Extra keys allowed — preserve through sync/edit cycles.

Validation (loose minimum)
--------------------------
- Root must be an object.
- If "pages" is present it must be an array (empty pages = soft warning only via filter).
- Page entries that exist should be objects; title/slug recommended not hard-failed if id exists.
- Sections/elements when present should be arrays of objects.
- Unknown element types never fail validation.
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
	 * Loose validation for Maximum AI Creativity.
	 *
	 * Hard failures only when the document cannot be walked at all.
	 * Unknown element types, free-form settings, and extra keys are allowed.
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

		// Soft type checks only when fields are present.
		if ( array_key_exists( 'version', $blueprint ) && ! is_scalar( $blueprint['version'] ) && null !== $blueprint['version'] ) {
			$errors[] = __( 'Field "version" should be a string when provided.', 'promptweb' );
		}

		if ( isset( $blueprint['site'] ) && ! is_array( $blueprint['site'] ) ) {
			$errors[] = __( 'Field "site" should be an object when provided.', 'promptweb' );
		}

		// pages: preferred, but empty/missing is only an error if we cannot find any content tree.
		if ( isset( $blueprint['pages'] ) && ! is_array( $blueprint['pages'] ) ) {
			$errors[] = __( 'Field "pages" must be an array when provided.', 'promptweb' );
		} elseif ( ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] ) ) {
			foreach ( $blueprint['pages'] as $index => $page ) {
				$page_errors = self::validate_page( $page, (int) $index );
				if ( ! empty( $page_errors ) ) {
					$errors = array_merge( $errors, $page_errors );
				}
			}
		} elseif ( empty( $blueprint['pages'] ) ) {
			// Allow blueprints that only carry prompts/meta (AI pipeline stages).
			// Callers that need a renderable site can check for pages separately.
		}

		if ( isset( $blueprint['prompts'] ) && ! is_array( $blueprint['prompts'] ) ) {
			$errors[] = __( 'Field "prompts" should be an array when provided.', 'promptweb' );
		}

		/**
		 * Filters schema validation errors (empty array = valid).
		 *
		 * Use this to tighten or further loosen rules for custom pipelines.
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
	 * Soft-validate a single page node (AI-friendly).
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

		// Identity: title, slug, OR id is enough (AI may use only id early on).
		$title = isset( $page['title'] ) ? trim( (string) $page['title'] ) : '';
		$slug  = isset( $page['slug'] ) ? trim( (string) $page['slug'] ) : '';
		$id    = isset( $page['id'] ) ? trim( (string) $page['id'] ) : '';

		if ( '' === $title && '' === $slug && '' === $id ) {
			$errors[] = sprintf(
				/* translators: %s: page label */
				__( '%s should have at least one of: id, title, or slug.', 'promptweb' ),
				$label
			);
		}

		// Status is free-form — do not reject unknown values (AI creativity).

		if ( isset( $page['sections'] ) && ! is_array( $page['sections'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: page label */
				__( '%s "sections" must be an array when provided.', 'promptweb' ),
				$label
			);
		} elseif ( ! empty( $page['sections'] ) && is_array( $page['sections'] ) ) {
			foreach ( $page['sections'] as $s_index => $section ) {
				$errors = array_merge(
					$errors,
					self::validate_section( $section, $index, (int) $s_index )
				);
			}
		}

		if ( isset( $page['blocks'] ) && ! is_array( $page['blocks'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: page label */
				__( '%s legacy "blocks" must be an array when provided.', 'promptweb' ),
				$label
			);
		}

		return $errors;
	}

	/**
	 * Soft-validate a section node.
	 *
	 * Unknown section types and free-form settings are allowed.
	 *
	 * @since 1.0.0
	 * @param mixed $section   Section value.
	 * @param int   $page_i    Page index.
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

		// settings: if present and not array, soft-coerce expectation only.
		if ( isset( $section['settings'] ) && ! is_array( $section['settings'] ) && ! is_object( $section['settings'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: section label */
				__( '%s "settings" should be an object when provided.', 'promptweb' ),
				$label
			);
		}

		// elements optional; unknown element types are never errors.
		if ( isset( $section['elements'] ) && ! is_array( $section['elements'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: section label */
				__( '%s "elements" must be an array when provided.', 'promptweb' ),
				$label
			);
		} elseif ( ! empty( $section['elements'] ) && is_array( $section['elements'] ) ) {
			foreach ( $section['elements'] as $e_index => $element ) {
				if ( ! is_array( $element ) ) {
					$errors[] = sprintf(
						/* translators: 1: section label, 2: element index */
						__( '%1$s.elements[%2$d] should be an object.', 'promptweb' ),
						$label,
						(int) $e_index
					);
					continue;
				}
				// type optional for ultra-loose AI drafts; if set, should be string/scalar.
				if ( isset( $element['type'] ) && ! is_scalar( $element['type'] ) ) {
					$errors[] = sprintf(
						/* translators: 1: section label, 2: element index */
						__( '%1$s.elements[%2$d] "type" should be a string when provided.', 'promptweb' ),
						$label,
						(int) $e_index
					);
				}
				// Unknown types: intentionally not validated against ELEMENT_TYPES.
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
