<?php
/**
 * Fixture: a widget with a PHP 5+ style constructor.
 */

class Classic_Widget extends WP_Widget {
	private array $defaults = array( 'title' => '' );

	public function __construct() {
		parent::__construct( 'classic_widget', 'Classic Widget' );
	}

	public function widget( $args, $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults );
		echo $args['before_widget'] . esc_html( $instance['title'] ) . $args['after_widget'];
	}

	public function update( $new_instance, $old_instance ) {
		$instance          = $old_instance;
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		return $instance;
	}
}
