<?php
/**
 * Fixture: a template that mixes HTML and PHP.
 *
 * @var string $text
 */
?>
<div class="classic-fixture">
	<p><?php echo esc_html( $text ); ?></p>
	<?php if ( is_user_logged_in() ) : ?>
		<p><?php esc_html_e( 'Welcome back.', 'classic-fixture' ); ?></p>
	<?php endif; ?>
</div>
