<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
?>
<div class="co360-ssa-login">
	<?php if ( ! empty( $atts['title'] ) ) : ?>
		<h2><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>
	<form class="co360-ssa-login-form" data-redirect="<?php echo esc_attr( $redirect_to ); ?>">
		<label>
			<?php if ( $atts['show_labels'] ) : ?><?php esc_html_e( 'Usuario o email', CO360_SSA_TEXT_DOMAIN ); ?><?php endif; ?>
			<input type="text" name="username" required>
		</label>
		<label>
			<?php if ( $atts['show_labels'] ) : ?><?php esc_html_e( 'Contraseña', CO360_SSA_TEXT_DOMAIN ); ?><?php endif; ?>
			<input type="password" name="password" required>
		</label>
		<?php if ( $atts['show_remember'] ) : ?>
			<label class="co360-ssa-remember">
				<input type="checkbox" name="remember" value="1">
				<?php esc_html_e( 'Recordarme', CO360_SSA_TEXT_DOMAIN ); ?>
			</label>
		<?php endif; ?>
		<div class="co360-ssa-login-error" style="display:none;"></div>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Ingresar', CO360_SSA_TEXT_DOMAIN ); ?></button>
	</form>
</div>
