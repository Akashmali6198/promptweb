<?php
/**
 * Frontend template: PromptWeb blueprint page.
 *
 * Loaded via PromptWeb_Frontend::maybe_template_include().
 * Renders pages → sections → elements through PromptWeb_Renderer.
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$frontend = function_exists( 'promptweb' ) && isset( promptweb()->frontend )
	? promptweb()->frontend
	: null;

$slug = ( $frontend instanceof PromptWeb_Frontend ) ? $frontend->get_current_slug() : null;
$html = ( $frontend instanceof PromptWeb_Frontend ) ? $frontend->render_page( $slug ) : '';

$blueprint = class_exists( 'PromptWeb_Settings' ) ? PromptWeb_Settings::get_blueprint() : array();
$site      = ( ! empty( $blueprint['site'] ) && is_array( $blueprint['site'] ) ) ? $blueprint['site'] : array();

$page_title = ( $frontend instanceof PromptWeb_Frontend ) ? $frontend->get_page_title( $slug ) : get_bloginfo( 'name' );

/**
 * Optional wrapper class for the body (filterable).
 *
 * @since 1.0.0
 * @param string[] $classes Body classes.
 */
$body_classes = apply_filters(
	'promptweb_frontend_body_class',
	array(
		'promptweb-frontend',
		'promptweb-frontend-template',
	)
);
$body_classes = array_map( 'sanitize_html_class', (array) $body_classes );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( implode( ' ', $body_classes ) ); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) {
	wp_body_open();
}
?>

<div class="promptweb-site">
	<header class="promptweb-site-header" role="banner">
		<div class="promptweb-site-header__inner">
			<a class="promptweb-site-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="promptweb-site-brand__title">
					<?php
					echo esc_html(
						! empty( $site['title'] )
							? $site['title']
							: get_bloginfo( 'name' )
					);
					?>
				</span>
				<?php if ( ! empty( $site['tagline'] ) ) : ?>
					<span class="promptweb-site-brand__tagline"><?php echo esc_html( $site['tagline'] ); ?></span>
				<?php endif; ?>
			</a>

			<?php
			// Simple nav from blueprint pages.
			$pages = ( ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] ) ) ? $blueprint['pages'] : array();
			if ( ! empty( $pages ) && $frontend instanceof PromptWeb_Frontend ) :
				?>
				<nav class="promptweb-site-nav" aria-label="<?php esc_attr_e( 'Site', 'promptweb' ); ?>">
					<ul class="promptweb-site-nav__list">
						<?php foreach ( $pages as $nav_page ) : ?>
							<?php
							if ( ! is_array( $nav_page ) ) {
								continue;
							}
							$nav_slug  = isset( $nav_page['slug'] ) ? sanitize_title( (string) $nav_page['slug'] ) : '';
							$nav_title = isset( $nav_page['title'] ) ? (string) $nav_page['title'] : $nav_slug;
							if ( '' === $nav_slug && empty( $nav_page['is_front_page'] ) ) {
								continue;
							}
							$is_front = ! empty( $nav_page['is_front_page'] );
							$href     = $is_front
								? home_url( '/' )
								: $frontend->get_page_url( $nav_slug );
							$current  = ( null === $slug && $is_front )
								|| ( null !== $slug && $slug === $nav_slug );
							?>
							<li class="promptweb-site-nav__item<?php echo $current ? ' is-current' : ''; ?>">
								<a href="<?php echo esc_url( $href ); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>>
									<?php echo esc_html( $nav_title ? $nav_title : $nav_slug ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
		</div>
	</header>

	<main id="promptweb-main" class="promptweb-site-main" role="main">
		<?php if ( $html ) : ?>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer already escapes.
			echo $html;
			?>
		<?php else : ?>
			<div class="promptweb-empty">
				<p><?php esc_html_e( 'This PromptWeb page has no content yet. Sync a blueprint from GitHub or add pages in the repository.', 'promptweb' ); ?></p>
			</div>
		<?php endif; ?>
	</main>

	<footer class="promptweb-site-footer" role="contentinfo">
		<div class="promptweb-site-footer__inner">
			<p class="promptweb-site-footer__copy">
				&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
				<?php
				echo esc_html(
					! empty( $site['title'] )
						? $site['title']
						: get_bloginfo( 'name' )
				);
				?>
			</p>
		</div>
	</footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
