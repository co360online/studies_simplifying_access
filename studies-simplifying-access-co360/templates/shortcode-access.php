<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="co360-ssa-access">
	<?php if ( ! empty( $atts['title'] ) ) : ?>
		<h2><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>

<?php if ( ! empty( $notices ) ) : ?>
		<div class="co360-ssa-notices">
			<?php foreach ( $notices as $notice ) : ?>
				<p class="co360-ssa-error"><?php echo esc_html( $notice ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<form method="post" class="co360-ssa-form">
		<?php wp_nonce_field( 'co360_ssa_access', 'co360_ssa_access_nonce' ); ?>
		<label>
			<?php esc_html_e( 'Email', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="email" name="co360_ssa_email" required>
		</label>
		<?php if ( $atts['require_code'] ) : ?>
			<label>
				<?php esc_html_e( 'Código', CO360_SSA_TEXT_DOMAIN ); ?>
				<input type="text" name="co360_ssa_code" required>
			</label>
		<?php endif; ?>
		<button type="submit" class="button button-primary"><?php echo esc_html( $atts['button_text'] ); ?></button>
	</form>
</div>
