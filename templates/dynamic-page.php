<?php
/**
 * Template: PromptWeb dynamic PHP page (pages/dynamic/*.php).
 *
 * Loads the page file in full WordPress context so AI-authored templates may use
 * loops, queries, hooks, and template tags.
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
	wp_die( esc_html__( 'Page not found.', 'promptweb' ), '', array( 'response' => 404 ) );
}

$pages = function_exists( 'promptweb' ) && isset( promptweb()->pages )
	? promptweb()->pages
	: ( class_exists( 'PromptWeb_Pages' ) ? new PromptWeb_Pages() : null );

$path = ( $pages instanceof PromptWeb_Pages ) ? $pages->get_page_file_path( $meta ) : '';

if ( ! $path || ! is_readable( $path ) ) {
	status_header( 404 );
	wp_die( esc_html__( 'This dynamic page file is missing.', 'promptweb' ), '', array( 'response' => 404 ) );
}

/**
 * Fires before a dynamic design page PHP file is included.
 *
 * @since 2.0.0
 * @param array  $meta Page meta.
 * @param string $path Absolute file path.
 */
do_action( 'promptweb_before_dynamic_page', $meta, $path );

// Expose meta to the template as $promptweb_page.
$promptweb_page = $meta;

// Optional draft notice for admins (output buffering if template is a full document).
$is_draft = ( isset( $meta['status'] ) && 'draft' === $meta['status'] && is_user_logged_in() );

if ( $is_draft ) {
	ob_start();
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_include -- intentional design template include.
include $path;

if ( $is_draft ) {
	$output = (string) ob_get_clean();
	$banner = '<div id="promptweb-draft-banner" style="position:fixed;z-index:99999;left:0;right:0;top:0;background:#b45309;color:#fff;font:600 13px/1.4 system-ui,sans-serif;padding:8px 16px;text-align:center;">'
		. esc_html__( 'PromptWeb draft preview — not visible to the public until published.', 'promptweb' )
		. '</div><div style="height:36px"></div>';
	if ( false !== stripos( $output, '<body' ) ) {
		$output = preg_replace( '/(<body[^>]*>)/i', '$1' . $banner, $output, 1 );
	} else {
		$output = $banner . $output;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $output;
}

/**
 * Fires after a dynamic design page PHP file is included.
 *
 * @since 2.0.0
 * @param array  $meta Page meta.
 * @param string $path Absolute file path.
 */
do_action( 'promptweb_after_dynamic_page', $meta, $path );
