<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="co360-ssa-access">
	<?php if ( ! empty( $atts['title'] ) ) : ?>
		<h2><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( $user && $user->ID ) : ?>
		<div class="co360-ssa-access-user">
			<p><?php echo esc_html( sprintf( __( 'Estás conectado como %s', CO360_SSA_TEXT_DOMAIN ), $user->user_email ) ); ?></p>
			<?php if ( ! empty( $my_studies_url ) ) : ?>
				<a class="button" href="<?php echo esc_url( $my_studies_url ); ?>"><?php esc_html_e( 'Ver mis estudios', CO360_SSA_TEXT_DOMAIN ); ?></a>
			<?php endif; ?>
		</div>
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
