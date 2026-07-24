<?php
/**
 * Template: PromptWeb static HTML page (pages/static/*.html).
 *
 * Serves full HTML documents as-is when they include <!DOCTYPE or <html>.
 * Fragment HTML is wrapped in a minimal shell.
 *
 * Draft pages reach this template only after can_view_page() (admins).
 *
 * @package PromptWeb
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$meta = isset( $GLOBALS['promptweb_current_page_meta'] ) && is_array( $GLOBALS['promptweb_current_page_meta'] )
	? $GLOBALS['promptweb_current_page_meta']
	: null;

$frontend = function_exists( 'promptweb' ) && isset( promptweb()->frontend )
	? promptweb()->frontend
	: null;

if ( ! is_array( $meta ) && $frontend instanceof PromptWeb_Frontend ) {
	$meta = $frontend->resolve_design_page_meta( $frontend->get_current_slug() );
}

if ( ! is_array( $meta ) ) {
	status_header( 404 );
	nocache_headers();
	echo '<!DOCTYPE html><html><body><p>' . esc_html__( 'Page not found.', 'promptweb' ) . '</p></body></html>';
	return;
}

$pages = function_exists( 'promptweb' ) && isset( promptweb()->pages )
	? promptweb()->pages
	: ( class_exists( 'PromptWeb_Pages' ) ? new PromptWeb_Pages() : null );

$html = '';
if ( $pages instanceof PromptWeb_Pages ) {
	$code = $pages->read_page_file( $meta['file'] );
	if ( ! is_wp_error( $code ) ) {
		$html = $code;
	}
}

if ( '' === trim( $html ) ) {
	status_header( 404 );
	echo '<!DOCTYPE html><html><body><p>' . esc_html__( 'This page has no content yet.', 'promptweb' ) . '</p></body></html>';
	return;
}

// Admin draft banner (non-destructive, injected before </body> when possible).
$draft_banner = '';
if ( isset( $meta['status'] ) && 'draft' === $meta['status'] && is_user_logged_in() ) {
	$draft_banner = '<div id="promptweb-draft-banner" style="position:fixed;z-index:99999;left:0;right:0;top:0;background:#b45309;color:#fff;font:600 13px/1.4 system-ui,sans-serif;padding:8px 16px;text-align:center;">'
		. esc_html__( 'PromptWeb draft preview — not visible to the public until published.', 'promptweb' )
		. '</div><div style="height:36px"></div>';
}

$is_full_doc = (bool) preg_match( '/<!DOCTYPE\s+html|<html[\s>]/i', $html );

/**
 * Filters static page HTML before output.
 *
 * @since 2.0.0
 * @param string $html HTML source.
 * @param array  $meta Page meta.
 */
$html = (string) apply_filters( 'promptweb_static_page_html', $html, $meta );

if ( $is_full_doc ) {
	if ( $draft_banner ) {
		if ( false !== stripos( $html, '<body' ) ) {
			$html = preg_replace( '/(<body[^>]*>)/i', '$1' . $draft_banner, $html, 1 );
		} else {
			$html = $draft_banner . $html;
		}
	}
	// Full document: output as-is (AI has full creative freedom; includes Tailwind CDN etc.).
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional full-page design output.
	echo $html;
	return;
}

// Fragment: wrap in minimal document so CSS/JS still work.
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( ! empty( $meta['title'] ) ? $meta['title'] : get_bloginfo( 'name' ) ); ?></title>
	<script src="https://cdn.tailwindcss.com"></script>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'promptweb-static-fragment' ); ?>>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $draft_banner;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $html;
wp_footer();
?>
</body>
</html>
