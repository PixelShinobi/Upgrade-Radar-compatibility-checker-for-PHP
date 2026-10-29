<?php
/**
 * Plugin Name: Classic Fixture
 * Requires PHP: 7.4
 *
 * Fixture: PHP 7.4 era WordPress plugin code that keeps working on
 * PHP 8.1 through 8.5. The scanner must stay quiet here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-classic-widget.php';
require_once __DIR__ . '/includes/guarded.php';

function classic_fixture_init() {
	add_shortcode( 'classic_fixture', 'classic_fixture_shortcode' );
	register_widget( 'Classic_Widget' );
}
add_action( 'init', 'classic_fixture_init' );

function classic_fixture_shortcode( $atts ) {
	$atts  = shortcode_atts( array( 'title' => 'Hello' ), $atts );
	$items = array_map( fn ( $item ) => strtoupper( $item ), array( 'a', 'b' ) );
	$extra = array( ...$items, 'c' );
	$title = $atts['title'] ?? '';
	$text  = sprintf( '%s: %s', $title, implode( ', ', $extra ) );

	ob_start();
	include __DIR__ . '/templates/view.php';
	return ob_get_clean();
}

function classic_fixture_excerpt( ?WP_Post $post = null, string $more = '...' ): string {
	$post = $post ?? get_post();
	$text = wp_strip_all_tags( $post ? $post->post_content : '' );
	return mb_substr( $text, 0, 55, 'UTF-8' ) . $more;
}

function classic_fixture_dates( int $timestamp ): array {
	return array(
		'iso'   => gmdate( 'c', $timestamp ),
		'local' => wp_date( get_option( 'date_format' ), $timestamp ),
	);
}
