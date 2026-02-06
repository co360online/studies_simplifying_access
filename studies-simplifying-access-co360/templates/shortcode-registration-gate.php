<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="co360-ssa-registration-gate">
	<?php if ( $is_valid ) : ?>
		<p class="co360-ssa-notice co360-ssa-notice--success success">
			<?php echo esc_html( $atts['message_ok'] ); ?>
		</p>
		<?php echo do_shortcode( '[co360_ssa_form_context]' ); ?>
	<?php else : ?>
		<p class="co360-ssa-notice co360-ssa-notice--error error">
			<?php echo esc_html( $atts['message_fail'] ); ?>
		</p>
		<?php if ( ! $strict ) : ?>
			<?php echo do_shortcode( '[co360_ssa_form_context]' ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
