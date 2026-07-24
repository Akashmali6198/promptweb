<?php
/**
 * Public reference URL inspection for strict design matching.
 *
 * Used by MCP/REST tool analyze_reference_url. Fetches public HTML only
 * (http/https, no private IPs) and returns a structured rebuild checklist.
 *
 * @package PromptWeb
 * @since   2.0.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reference URL analyzer.
 *
 * @since 2.0.2
 */
class PromptWeb_Reference {

	/**
	 * HTTP timeout for remote fetches (seconds).
	 *
	 * @since 2.0.2
	 * @var   int
	 */
	const FETCH_TIMEOUT = 20;

	/**
	 * Max response body size to parse (bytes).
	 *
	 * @since 2.0.2
	 * @var   int
	 */
	const MAX_BODY_BYTES = 2500000;

	/**
	 * Analyze a public reference URL for design reconstruction.
	 *
	 * @since 2.0.2
	 * @param string $url        Public http(s) URL.
	 * @param int    $max_images Max image URLs to return (default 30).
	 * @return array|WP_Error
	 */
	public function analyze_url( $url, $max_images = 30 ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		$max_images = max( 1, min( 100, (int) $max_images ) );

		$validated = $this->validate_public_url( $url );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$url = $validated;

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::FETCH_TIMEOUT,
				'redirection'         => 5,
				'limit_response_size' => self::MAX_BODY_BYTES,
				'user-agent'          => 'PromptWeb/' . ( defined( 'PROMPTWEB_VERSION' ) ? PROMPTWEB_VERSION : '2.0' ) . ' (Reference Inspector; WordPress)',
				'headers'             => array(
					'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'promptweb_reference_fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not fetch reference URL: %s', 'promptweb' ),
					$response->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'promptweb_reference_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'Reference URL returned HTTP %d.', 'promptweb' ),
					$code
				),
				array( 'status' => 400 )
			);
		}

		$final_url = $url;
		// Prefer effective URL if available from redirects.
		if ( ! empty( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$obj = $response['http_response']->get_response_object();
			if ( is_object( $obj ) && ! empty( $obj->url ) ) {
				$final_url = (string) $obj->url;
			}
		}

		// Validate final URL after redirects (block private redirect targets).
		$final_check = $this->validate_public_url( $final_url );
		if ( is_wp_error( $final_check ) ) {
			return new WP_Error(
				'promptweb_reference_redirect_blocked',
				__( 'Reference URL redirected to a blocked/private destination.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}
		$final_url = $final_check;

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return new WP_Error(
				'promptweb_reference_empty',
				__( 'Reference URL returned empty HTML.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		// Use full body for asset extraction (lazy attrs, JSON-LD, CSS).
		// Use noise-stripped HTML for readable text/nav/headings.
		$clean = $this->strip_noise( $body );

		$extraction = $this->extract_images_detailed( $body, $final_url, $max_images );
		$images     = isset( $extraction['image_urls'] ) ? $extraction['image_urls'] : array();
		$notes      = isset( $extraction['extraction_notes'] ) ? $extraction['extraction_notes'] : array();

		$title     = $this->extract_title( $clean );
		$meta_desc = $this->extract_meta_description( $body ); // meta may be in head with less noise.
		$nav_items = $this->extract_nav_items( $clean );
		$headings  = $this->extract_headings( $clean );
		$ctas      = $this->extract_ctas( $clean );
		$colors    = $this->extract_color_hints( $body );
		$snippets  = $this->extract_text_snippets( $clean );
		$sections  = $this->build_section_hints( $headings, $snippets, $nav_items );
		$js_heavy  = $this->detect_js_heavy( $body, $images, $headings, $snippets );
		$checklist = $this->build_rebuild_checklist( $title, $nav_items, $headings, $sections, $images, $ctas, $js_heavy );
		$fallback  = $this->build_fallback_guidance( $js_heavy, $images, $headings, $sections );

		if ( $js_heavy ) {
			$notes[] = 'js_heavy_likely: raw HTML may hide product/hero media loaded by JavaScript; use screenshot/PDF for visual fidelity and keep extracted structure.';
		}
		if ( empty( $images ) ) {
			$notes[] = 'No image URLs found in raw HTML (lazy/JS-rendered or CSS-background heavy). Prefer attached screenshot/PDF for media composition.';
		}

		$result = array(
			'success'           => true,
			'requested_url'     => $url,
			'final_url'         => $final_url,
			'title'             => $title,
			'meta_description'  => $meta_desc,
			'nav_items'         => $nav_items,
			'headings'          => $headings,
			'section_hints'     => $sections,
			'image_urls'        => $images,
			'image_count'       => count( $images ),
			'extraction_notes'  => array_values( array_unique( $notes ) ),
			'js_heavy_likely'   => (bool) $js_heavy,
			'fallback_guidance' => $fallback,
			'cta_texts'         => $ctas,
			'color_hints'       => $colors,
			'text_snippets'     => $snippets,
			'rebuild_checklist' => $checklist,
			'match_mode'        => 'strict_100_percent',
			'instructions'      => array(
				'Always call analyze_reference_url first when a reference URL is given.',
				'Strict goal: exact same design 100% — same section order, layout, hierarchy, density, CTAs, media.',
				'Reuse image_urls from this response whenever available.',
				'If image_urls empty or js_heavy_likely true: use attached screenshot/PDF visually; keep exact section structure; only ask for missing critical image URLs if absolutely required.',
				'If screenshot/PDF is provided: rebuild the full page first — do not stop at placeholders.',
				'Create as Draft, revise until exact match quality, then publish.',
				'End reply with the page public_url only.',
			),
		);

		/**
		 * Filters reference URL analysis result.
		 *
		 * @since 2.0.2
		 * @param array  $result Analysis payload.
		 * @param string $url    Final validated URL.
		 */
		return (array) apply_filters( 'promptweb_reference_analysis', $result, $final_url );
	}

	/**
	 * Validate public http(s) URL; block private/internal hosts.
	 *
	 * @since 2.0.2
	 * @param string $url URL.
	 * @return string|WP_Error Valid URL or error.
	 */
	public function validate_public_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return new WP_Error(
				'promptweb_reference_url_required',
				__( 'A reference URL is required.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error(
				'promptweb_reference_url_invalid',
				__( 'Reference URL is invalid.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'promptweb_reference_scheme',
				__( 'Only http and https reference URLs are allowed.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		$host = strtolower( (string) $parts['host'] );
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return new WP_Error(
				'promptweb_reference_private',
				__( 'Private/internal hosts are not allowed.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		// Block obvious local TLDs / link-local.
		if ( preg_match( '/\.(local|internal|lan|home|localhost)$/i', $host ) ) {
			return new WP_Error(
				'promptweb_reference_private',
				__( 'Private/internal hosts are not allowed.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		// Resolve IP when possible and block private ranges.
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			// gethostbynamel may return false.
			$resolved = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $resolved ) ) {
				$ips = $resolved;
			}
		}

		foreach ( $ips as $ip ) {
			if ( $this->is_private_ip( $ip ) ) {
				return new WP_Error(
					'promptweb_reference_private',
					__( 'Reference URL resolves to a private/internal IP address.', 'promptweb' ),
					array( 'status' => 400 )
				);
			}
		}

		return $url;
	}

	/**
	 * Whether an IP is private/reserved.
	 *
	 * @since 2.0.2
	 * @param string $ip IP address.
	 * @return bool
	 */
	protected function is_private_ip( $ip ) {
		$ip = (string) $ip;
		// IPv4.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return ! filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}
		// IPv6.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return ! filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}
		return true;
	}

	/**
	 * Strip script/style/noscript for text extraction.
	 *
	 * @since 2.0.2
	 * @param string $html HTML.
	 * @return string
	 */
	protected function strip_noise( $html ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', ' ', $html );
		$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', ' ', $html );
		$html = preg_replace( '#<noscript\b[^>]*>.*?</noscript>#is', ' ', $html );
		return is_string( $html ) ? $html : '';
	}

	/**
	 * @since 2.0.2
	 * @param string $html HTML.
	 * @return string
	 */
	protected function extract_title( $html ) {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return $this->clean_text( $m[1] );
		}
		if ( preg_match( '#<h1\b[^>]*>(.*?)</h1>#is', $html, $m ) ) {
			return $this->clean_text( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * @since 2.0.2
	 * @param string $html HTML.
	 * @return string
	 */
	protected function extract_meta_description( $html ) {
		if ( preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m ) ) {
			return $this->clean_text( $m[1] );
		}
		if ( preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']#i', $html, $m ) ) {
			return $this->clean_text( $m[1] );
		}
		return '';
	}

	/**
	 * @since 2.0.2
	 * @param string $html HTML.
	 * @return string[]
	 */
	protected function extract_nav_items( $html ) {
		$items = array();
		if ( preg_match_all( '#<nav\b[^>]*>(.*?)</nav>#is', $html, $navs ) ) {
			foreach ( $navs[1] as $nav_html ) {
				if ( preg_match_all( '#<a\b[^>]*>(.*?)</a>#is', $nav_html, $as ) ) {
					foreach ( $as[1] as $label ) {
						$text = $this->clean_text( wp_strip_all_tags( $label ) );
						if ( '' !== $text && strlen( $text ) < 80 ) {
							$items[] = $text;
						}
					}
				}
			}
		}
		// Fallback: header links.
		if ( empty( $items ) && preg_match( '#<header\b[^>]*>(.*?)</header>#is', $html, $h ) ) {
			if ( preg_match_all( '#<a\b[^>]*>(.*?)</a>#is', $h[1], $as ) ) {
				foreach ( $as[1] as $label ) {
					$text = $this->clean_text( wp_strip_all_tags( $label ) );
					if ( '' !== $text && strlen( $text ) < 60 ) {
						$items[] = $text;
					}
				}
			}
		}
		return array_values( array_unique( array_slice( $items, 0, 30 ) ) );
	}

	/**
	 * @since 2.0.2
	 * @param string $html HTML.
	 * @return array{h1:string[],h2:string[],h3:string[]}
	 */
	protected function extract_headings( $html ) {
		$out = array(
			'h1' => array(),
			'h2' => array(),
			'h3' => array(),
		);
		foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
			if ( preg_match_all( '#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#is', $html, $m ) ) {
				foreach ( $m[1] as $inner ) {
					$text = $this->clean_text( wp_strip_all_tags( $inner ) );
					if ( '' !== $text ) {
						$out[ $tag ][] = $text;
					}
				}
				$out[ $tag ] = array_values( array_unique( array_slice( $out[ $tag ], 0, 40 ) ) );
			}
		}
		return $out;
	}

	/**
	 * Maximum asset extraction from raw HTML (free, no headless browser).
	 *
	 * Sources: img src/srcset, lazy data-* attrs, og/twitter meta, JSON-LD images,
	 * inline style background-image, and CSS url() in style blocks.
	 *
	 * @since 2.0.2
	 * @param string $html     Raw HTML body.
	 * @param string $base_url Base for relative → absolute.
	 * @param int    $max      Max image URLs.
	 * @return array{image_urls:string[],extraction_notes:string[]}
	 */
	protected function extract_images_detailed( $html, $base_url, $max ) {
		$urls  = array();
		$notes = array();
		$counts = array(
			'img_src'       => 0,
			'srcset'        => 0,
			'data_lazy'     => 0,
			'og_twitter'    => 0,
			'json_ld'       => 0,
			'inline_bg'     => 0,
			'style_url'     => 0,
		);

		// --- img[src] and common lazy attributes on <img> and any tag ---
		$lazy_attrs = array(
			'src',
			'data-src',
			'data-lazy-src',
			'data-original',
			'data-bg',
			'data-background',
			'data-background-image',
			'data-lazy',
			'data-url',
			'data-image',
			'data-src-retina',
			'data-hi-res-src',
		);
		foreach ( $lazy_attrs as $attr ) {
			// Negative lookbehind so "src" does not match inside "data-src".
			$pattern = '#(?<![\w-])' . preg_quote( $attr, '#' ) . '\s*=\s*["\']([^"\']+)["\']#i';
			if ( preg_match_all( $pattern, $html, $m ) ) {
				foreach ( $m[1] as $src ) {
					$abs = $this->absolutize_url( $src, $base_url );
					if ( ! $abs || ! $this->looks_like_image_url( $abs, $attr ) ) {
						continue;
					}
					$urls[] = $abs;
					if ( 'src' === $attr ) {
						$counts['img_src']++;
					} else {
						$counts['data_lazy']++;
					}
				}
			}
		}

		// --- srcset / data-srcset (all candidates, prefer larger later via de-dupe) ---
		if ( preg_match_all( '#(?:srcset|data-srcset)\s*=\s*["\']([^"\']+)["\']#i', $html, $m ) ) {
			foreach ( $m[1] as $srcset ) {
				$parts = preg_split( '/\s*,\s*/', $srcset );
				if ( ! is_array( $parts ) ) {
					continue;
				}
				foreach ( $parts as $part ) {
					$first = preg_split( '/\s+/', trim( $part ) );
					if ( empty( $first[0] ) ) {
						continue;
					}
					$abs = $this->absolutize_url( $first[0], $base_url );
					if ( $abs && $this->looks_like_image_url( $abs, 'srcset' ) ) {
						$urls[] = $abs;
						$counts['srcset']++;
					}
				}
			}
		}

		// --- <link rel="image_src|preload" as=image / apple-touch-icon> ---
		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $link_tags ) ) {
			foreach ( $link_tags[0] as $tag ) {
				if ( ! preg_match( '#rel\s*=\s*["\']([^"\']+)["\']#i', $tag, $rm ) ) {
					continue;
				}
				$rel = strtolower( $rm[1] );
				$is_image_link = ( false !== strpos( $rel, 'image_src' )
					|| false !== strpos( $rel, 'apple-touch-icon' )
					|| false !== strpos( $rel, 'icon' )
					|| ( false !== strpos( $rel, 'preload' ) && preg_match( '#as\s*=\s*["\']image["\']#i', $tag ) ) );
				if ( ! $is_image_link ) {
					continue;
				}
				if ( preg_match( '#href\s*=\s*["\']([^"\']+)["\']#i', $tag, $hm ) ) {
					$abs = $this->absolutize_url( $hm[1], $base_url );
					if ( $abs && $this->looks_like_image_url( $abs, 'src' ) ) {
						// Skip tiny favicons later via filter; still collect.
						$urls[] = $abs;
						$counts['og_twitter']++;
					}
				}
			}
		}

		// --- meta og:image / twitter:image ---
		if ( preg_match_all( '#<meta\b[^>]*(?:property|name)\s*=\s*["\'](?:og:image|og:image:url|og:image:secure_url|twitter:image|twitter:image:src)["\'][^>]*>#i', $html, $meta_tags ) ) {
			foreach ( $meta_tags[0] as $tag ) {
				if ( preg_match( '#content\s*=\s*["\']([^"\']+)["\']#i', $tag, $cm ) ) {
					$abs = $this->absolutize_url( $cm[1], $base_url );
					if ( $abs ) {
						$urls[] = $abs;
						$counts['og_twitter']++;
					}
				}
			}
		}
		// content before property order.
		if ( preg_match_all( '#<meta\b[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*(?:property|name)\s*=\s*["\'](?:og:image|twitter:image)[^"\']*["\'][^>]*>#i', $html, $m ) ) {
			foreach ( $m[1] as $src ) {
				$abs = $this->absolutize_url( $src, $base_url );
				if ( $abs ) {
					$urls[] = $abs;
					$counts['og_twitter']++;
				}
			}
		}

		// --- JSON-LD image fields ---
		if ( preg_match_all( '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json_raw ) {
				$json_raw = trim( $json_raw );
				$data     = json_decode( $json_raw, true );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					// Some sites wrap multiple objects; try loose "image" string harvest.
					if ( preg_match_all( '#"image"\s*:\s*"([^"]+)"#i', $json_raw, $im ) ) {
						foreach ( $im[1] as $src ) {
							$abs = $this->absolutize_url( $src, $base_url );
							if ( $abs ) {
								$urls[] = $abs;
								$counts['json_ld']++;
							}
						}
					}
					continue;
				}
				$found = $this->harvest_json_ld_images( $data );
				foreach ( $found as $src ) {
					$abs = $this->absolutize_url( $src, $base_url );
					if ( $abs ) {
						$urls[] = $abs;
						$counts['json_ld']++;
					}
				}
			}
		}

		// --- inline style="... background-image: url(...)" ---
		if ( preg_match_all( '#style\s*=\s*["\']([^"\']+)["\']#i', $html, $m ) ) {
			foreach ( $m[1] as $style ) {
				if ( preg_match_all( '#url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)#i', $style, $um ) ) {
					foreach ( $um[1] as $src ) {
						$abs = $this->absolutize_url( $src, $base_url );
						if ( $abs && $this->looks_like_image_url( $abs, 'bg' ) ) {
							$urls[] = $abs;
							$counts['inline_bg']++;
						}
					}
				}
			}
		}

		// --- <style> blocks url(...) ---
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $m ) ) {
			foreach ( $m[1] as $css ) {
				if ( preg_match_all( '#url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)#i', $css, $um ) ) {
					foreach ( $um[1] as $src ) {
						$abs = $this->absolutize_url( $src, $base_url );
						if ( $abs && $this->looks_like_image_url( $abs, 'css' ) ) {
							$urls[] = $abs;
							$counts['style_url']++;
						}
					}
				}
			}
		}

		// Normalize, de-dupe, filter junk.
		$seen     = array();
		$filtered = array();
		foreach ( $urls as $u ) {
			$u = $this->normalize_image_url( $u );
			if ( '' === $u || isset( $seen[ $u ] ) ) {
				continue;
			}
			if ( 0 === strpos( $u, 'data:' ) ) {
				continue;
			}
			if ( preg_match( '/(1x1|pixel|spacer|tracking|analytics|favicon\.ico|sprite\.svg|gravatar\.com\/avatar)/i', $u ) ) {
				continue;
			}
			$seen[ $u ] = true;
			$filtered[] = $u;
			if ( count( $filtered ) >= $max ) {
				break;
			}
		}

		$notes[] = sprintf(
			'Extracted image candidates: img/src~%d, srcset~%d, data-lazy~%d, og/twitter~%d, json-ld~%d, inline-bg~%d, style-url~%d → kept %d unique after filter.',
			(int) $counts['img_src'],
			(int) $counts['srcset'],
			(int) $counts['data_lazy'],
			(int) $counts['og_twitter'],
			(int) $counts['json_ld'],
			(int) $counts['inline_bg'],
			(int) $counts['style_url'],
			count( $filtered )
		);

		return array(
			'image_urls'       => $filtered,
			'extraction_notes' => $notes,
		);
	}

	/**
	 * Backward-compatible thin wrapper.
	 *
	 * @since 2.0.2
	 * @param string $html     HTML.
	 * @param string $base_url Base URL.
	 * @param int    $max      Max.
	 * @return string[]
	 */
	protected function extract_images( $html, $base_url, $max ) {
		$detailed = $this->extract_images_detailed( $html, $base_url, $max );
		return isset( $detailed['image_urls'] ) ? $detailed['image_urls'] : array();
	}

	/**
	 * Recursively collect image URLs from JSON-LD structures.
	 *
	 * @since 2.0.2
	 * @param mixed $data Decoded JSON.
	 * @return string[]
	 */
	protected function harvest_json_ld_images( $data ) {
		$out = array();
		if ( is_string( $data ) ) {
			if ( preg_match( '#^https?://#i', $data ) || preg_match( '/\.(jpe?g|png|gif|webp|svg)(\?|$)/i', $data ) ) {
				$out[] = $data;
			}
			return $out;
		}
		if ( ! is_array( $data ) ) {
			return $out;
		}
		// List of nodes.
		if ( isset( $data[0] ) ) {
			foreach ( $data as $node ) {
				$out = array_merge( $out, $this->harvest_json_ld_images( $node ) );
			}
			return $out;
		}
		foreach ( array( 'image', 'thumbnailUrl', 'thumbnail', 'logo', 'photo', 'contentUrl', 'url' ) as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}
			$val = $data[ $key ];
			if ( is_string( $val ) ) {
				if ( 'url' === $key && ! preg_match( '/\.(jpe?g|png|gif|webp|svg)(\?|$)/i', $val ) && false === strpos( $val, 'image' ) ) {
					continue;
				}
				$out[] = $val;
			} elseif ( is_array( $val ) ) {
				if ( isset( $val['url'] ) && is_string( $val['url'] ) ) {
					$out[] = $val['url'];
				} elseif ( isset( $val['@id'] ) && is_string( $val['@id'] ) ) {
					$out[] = $val['@id'];
				} else {
					$out = array_merge( $out, $this->harvest_json_ld_images( $val ) );
				}
			}
		}
		if ( isset( $data['@graph'] ) ) {
			$out = array_merge( $out, $this->harvest_json_ld_images( $data['@graph'] ) );
		}
		return $out;
	}

	/**
	 * Heuristic: is this URL likely an image asset?
	 *
	 * @since 2.0.2
	 * @param string $url  Absolute URL.
	 * @param string $hint Source hint.
	 * @return bool
	 */
	protected function looks_like_image_url( $url, $hint = '' ) {
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return false;
		}
		// Clear image extensions.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|avif|bmp|ico)(\?|#|$)/i', $url ) ) {
			return true;
		}
		// CDN paths without extension often still serve images.
		if ( preg_match( '#/(images?|img|media|uploads?|static|assets|cdn)/#i', $url ) ) {
			return true;
		}
		// Lazy attrs / og often lack extension; accept http(s) unless clearly not an image.
		$loose_hints = array(
			'og',
			'src',
			'srcset',
			'data-src',
			'data-lazy-src',
			'data-original',
			'data-bg',
			'data-background',
			'data-background-image',
			'data-lazy',
			'data-url',
			'data-image',
			'data-src-retina',
			'data-hi-res-src',
			'bg',
			'css',
		);
		if ( in_array( $hint, $loose_hints, true ) ) {
			if ( preg_match( '#^https?://#i', $url ) && ! preg_match( '/\.(js|css|json|xml|html?|php|map)(\?|#|$)/i', $url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Light normalize for de-duplication (strip fragment, trim).
	 *
	 * @since 2.0.2
	 * @param string $url URL.
	 * @return string
	 */
	protected function normalize_image_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		// Drop hash fragment only.
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}
		return esc_url_raw( $url );
	}

	/**
	 * Heuristic: JS-rendered site where raw HTML lacks media/content.
	 *
	 * @since 2.0.2
	 * @param string $body     Raw HTML.
	 * @param array  $images   Extracted images.
	 * @param array  $headings Headings map.
	 * @param array  $snippets Text snippets.
	 * @return bool
	 */
	protected function detect_js_heavy( $body, $images, $headings, $snippets ) {
		$score = 0;
		$body_l = strtolower( (string) $body );

		if ( preg_match( '/__NEXT_DATA__|window\.__NUXT__|ng-version|data-reactroot|id=["\']__nuxt|id=["\']root["\'].*react|webpackJsonp|parcelRequire/i', $body ) ) {
			$score += 3;
		}
		if ( preg_match( '/id=["\']app["\']|id=["\']__next["\']|id=["\']root["\']/i', $body ) && substr_count( $body_l, '<script' ) >= 5 ) {
			$score += 2;
		}
		if ( substr_count( $body_l, '<script' ) >= 12 ) {
			$score += 1;
		}
		if ( empty( $images ) ) {
			$score += 2;
		} elseif ( count( $images ) < 3 ) {
			$score += 1;
		}
		$h2_count = ( isset( $headings['h2'] ) && is_array( $headings['h2'] ) ) ? count( $headings['h2'] ) : 0;
		$h1_count = ( isset( $headings['h1'] ) && is_array( $headings['h1'] ) ) ? count( $headings['h1'] ) : 0;
		if ( 0 === $h1_count && $h2_count < 2 ) {
			$score += 1;
		}
		if ( count( $snippets ) < 3 && strlen( $body ) > 15000 ) {
			$score += 1;
		}
		// noscript empty + large body often SPA shell.
		if ( false !== strpos( $body_l, 'noscript' ) && count( $images ) < 2 && substr_count( $body_l, '<img' ) < 2 ) {
			$score += 1;
		}

		return $score >= 3;
	}

	/**
	 * AI fallback steps when raw HTML is thin / JS-heavy.
	 *
	 * @since 2.0.2
	 * @param bool  $js_heavy JS heavy flag.
	 * @param array $images   Images.
	 * @param array $headings Headings.
	 * @param array $sections Sections.
	 * @return string[]
	 */
	protected function build_fallback_guidance( $js_heavy, $images, $headings, $sections ) {
		$g = array();
		$g[] = 'Strict goal remains exact same design 100% even when assets are incomplete.';
		if ( empty( $images ) || $js_heavy ) {
			$g[] = 'image_urls empty or js_heavy_likely: use attached screenshot/PDF as the visual source of truth for media, colors, density, and composition.';
			$g[] = 'Keep the exact section structure from section_hints/headings/nav_items extracted from HTML (or visible in the screenshot).';
			$g[] = 'Rebuild the full page first from screenshot/PDF — do not stop at placeholders when a visual attachment is provided.';
			$g[] = 'Reuse any available image_urls; only request missing critical image URLs if absolutely required after a full rebuild attempt.';
			$g[] = 'Prefer high-quality free stand-ins only for non-critical decorative assets if reference URLs cannot be recovered.';
		} else {
			$g[] = 'Reuse extracted image_urls for hero/product/media blocks.';
			$g[] = 'If a screenshot/PDF is also attached, cross-check layout and fill any gaps not present in raw HTML.';
		}
		$g[] = 'Match nav labels, CTA texts, and heading hierarchy as extracted.';
		$g[] = 'Create as Draft, iterate until exact match quality, then publish; final reply = public_url only.';
		if ( ! empty( $sections ) ) {
			$g[] = 'Implement all section_hints roles in order where present.';
		}
		return $g;
	}

	/**
	 * @since 2.0.2
	 * @param string $html HTML.
	 * @return string[]
	 */
	protected function extract_ctas( $html ) {
		$texts = array();
		// Buttons.
		if ( preg_match_all( '#<button\b[^>]*>(.*?)</button>#is', $html, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$t = $this->clean_text( wp_strip_all_tags( $inner ) );
				if ( $t && strlen( $t ) < 60 ) {
					$texts[] = $t;
				}
			}
		}
		// Links that look like CTAs.
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$attrs = isset( $row[1] ) ? $row[1] : '';
				$inner = isset( $row[2] ) ? $row[2] : '';
				$t     = $this->clean_text( wp_strip_all_tags( $inner ) );
				if ( ! $t || strlen( $t ) > 48 ) {
					continue;
				}
				if ( preg_match( '/btn|button|cta|primary|buy|shop|get|start|order|add|learn|contact|subscribe/i', $attrs . ' ' . $t ) ) {
					$texts[] = $t;
				}
			}
		}
		return array_values( array_unique( array_slice( $texts, 0, 40 ) ) );
	}

	/**
	 * @since 2.0.2
	 * @param string $html Full HTML (styles intact).
	 * @return string[]
	 */
	protected function extract_color_hints( $html ) {
		$colors = array();
		if ( preg_match_all( '/#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $html, $m ) ) {
			foreach ( $m[0] as $hex ) {
				$colors[] = strtolower( $hex );
			}
		}
		if ( preg_match_all( '/rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(?:\s*,\s*[\d.]+\s*)?\)/i', $html, $m ) ) {
			foreach ( array_slice( $m[0], 0, 20 ) as $rgba ) {
				$colors[] = strtolower( preg_replace( '/\s+/', '', $rgba ) );
			}
		}
		// Frequency sort.
		$counts = array_count_values( $colors );
		arsort( $counts );
		return array_slice( array_keys( $counts ), 0, 24 );
	}

	/**
	 * @since 2.0.2
	 * @param string $html Clean HTML.
	 * @return string[]
	 */
	protected function extract_text_snippets( $html ) {
		$text = wp_strip_all_tags( $html );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = $this->clean_text( $text );
		if ( '' === $text ) {
			return array();
		}
		// Split into rough sentences / phrases.
		$parts = preg_split( '/(?<=[\.\!\?])\s+/', $text );
		$out   = array();
		if ( is_array( $parts ) ) {
			foreach ( $parts as $p ) {
				$p = $this->clean_text( $p );
				if ( strlen( $p ) < 20 || strlen( $p ) > 220 ) {
					continue;
				}
				$out[] = $p;
				if ( count( $out ) >= 25 ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * @since 2.0.2
	 * @param array $headings Headings map.
	 * @param array $snippets Snippets.
	 * @param array $nav      Nav items.
	 * @return array
	 */
	protected function build_section_hints( $headings, $snippets, $nav ) {
		$hints = array();
		$h2s   = isset( $headings['h2'] ) ? $headings['h2'] : array();
		$h1s   = isset( $headings['h1'] ) ? $headings['h1'] : array();

		$hints[] = array(
			'role'    => 'header_nav',
			'hint'    => 'Top navigation',
			'labels'  => $nav,
		);
		if ( ! empty( $h1s ) ) {
			$hints[] = array(
				'role'  => 'hero',
				'hint'  => 'Primary hero / headline',
				'title' => $h1s[0],
			);
		}
		foreach ( $h2s as $i => $h2 ) {
			$role = 'section';
			$low  = strtolower( $h2 );
			if ( preg_match( '/testimonial|review|customer|said/i', $low ) ) {
				$role = 'testimonials';
			} elseif ( preg_match( '/faq|question/i', $low ) ) {
				$role = 'faq';
			} elseif ( preg_match( '/about|founder|story|our mission/i', $low ) ) {
				$role = 'about';
			} elseif ( preg_match( '/product|shop|collection|ingredient|benefit|feature/i', $low ) ) {
				$role = 'features_or_products';
			} elseif ( preg_match( '/contact|subscribe|join|get started|buy|order/i', $low ) ) {
				$role = 'cta';
			}
			$hints[] = array(
				'role'  => $role,
				'hint'  => 'H2 section ' . ( $i + 1 ),
				'title' => $h2,
			);
		}
		$hints[] = array(
			'role' => 'footer',
			'hint' => 'Site footer with links / legal / contact',
		);
		return $hints;
	}

	/**
	 * Build ordered rebuild checklist for the AI agent.
	 *
	 * @since 2.0.2
	 * @param string $title    Title.
	 * @param array  $nav      Nav.
	 * @param array  $headings Headings.
	 * @param array  $sections Sections.
	 * @param array  $images   Images.
	 * @param array  $ctas     CTAs.
	 * @param bool   $js_heavy JS-heavy flag.
	 * @return string[]
	 */
	protected function build_rebuild_checklist( $title, $nav, $headings, $sections, $images, $ctas, $js_heavy = false ) {
		$list   = array();
		$list[] = 'Match page title closely: ' . ( $title ? $title : '(unknown)' );
		$list[] = 'Reproduce nav labels in the same order: ' . ( $nav ? implode( ' | ', $nav ) : '(extract from visual / screenshot)' );
		$list[] = 'Use the same H1/H2 hierarchy and section order from headings + section_hints.';
		if ( ! empty( $images ) ) {
			$list[] = 'Reuse image_urls from this analysis for product/hero media (exact assets preferred).';
			$list[] = 'Include at least ' . min( 6, count( $images ) ) . ' of the extracted image_urls in the rebuild.';
		} else {
			$list[] = 'No image_urls in raw HTML — use screenshot/PDF for media composition; keep structure exact.';
		}
		if ( $js_heavy ) {
			$list[] = 'js_heavy_likely=true: do not wait for more HTML assets; rebuild full page from screenshot/PDF + extracted structure.';
		}
		$list[] = 'Match CTA button labels: ' . ( $ctas ? implode( ' | ', array_slice( $ctas, 0, 12 ) ) : '(from visual / screenshot)' );
		$list[] = 'Match dark/light section rhythm, spacing density, and card styles from the reference.';
		$list[] = 'No text-only major sections — every major block needs media or strong graphic treatment.';
		$list[] = 'Strict 100% visual match goal: revise until exact same design quality before publish.';
		$list[] = 'If screenshot/PDF attached: rebuild the FULL page first — do not stop at placeholders.';
		$list[] = 'Create as Draft, improve with get_visual_analysis, publish only when match is exact.';
		$list[] = 'Final reply last line = page public_url only.';
		if ( ! empty( $sections ) ) {
			$list[] = 'Implement at least ' . count( $sections ) . ' major sections inferred from the reference structure.';
		}
		return $list;
	}

	/**
	 * Absolutize a possibly relative URL.
	 *
	 * @since 2.0.2
	 * @param string $src  Source.
	 * @param string $base Base URL.
	 * @return string Empty if invalid.
	 */
	protected function absolutize_url( $src, $base ) {
		$src = trim( html_entity_decode( (string) $src, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) || 0 === strpos( $src, 'javascript:' ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $src ) ) {
			return esc_url_raw( $src );
		}
		if ( 0 === strpos( $src, '//' ) ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
			$scheme = $scheme ? $scheme : 'https';
			return esc_url_raw( $scheme . ':' . $src );
		}

		$base_parts = wp_parse_url( $base );
		if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return '';
		}
		$origin = $base_parts['scheme'] . '://' . $base_parts['host'];
		if ( ! empty( $base_parts['port'] ) ) {
			$origin .= ':' . $base_parts['port'];
		}

		if ( 0 === strpos( $src, '/' ) ) {
			return esc_url_raw( $origin . $src );
		}

		$path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$dir  = preg_replace( '#/[^/]*$#', '/', $path );
		return esc_url_raw( $origin . $dir . $src );
	}

	/**
	 * @since 2.0.2
	 * @param string $text Text.
	 * @return string
	 */
	protected function clean_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}
}
