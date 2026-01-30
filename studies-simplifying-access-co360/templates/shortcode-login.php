<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$redirect_to_raw = wp_unslash( $_GET['redirect_to'] ?? '' );
$token = sanitize_text_field( wp_unslash( $_GET['co360_ssa_token'] ?? '' ) );
if ( $token && false !== strpos( $redirect_to_raw, 'co360_ssa=after_login' ) && false === strpos( $redirect_to_raw, 'co360_ssa_token=' ) ) {
	$redirect_to_raw = add_query_arg( 'co360_ssa_token', $token, $redirect_to_raw );
}
$redirect_to = wp_validate_redirect( esc_url_raw( $redirect_to_raw ), home_url( '/' ) );
?>
<div class="co360-ssa-login">
	<div class="co360-ssa-auth-wrap">
		<div class="co360-ssa-auth-card">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h2><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<form class="co360-ssa-login-form" data-redirect="<?php echo esc_attr( $redirect_to ); ?>" method="post">
				<?php wp_nonce_field( "co360_ssa_login_form", "co360_ssa_login_nonce" ); ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
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
				<button type="submit" name="co360_ssa_login_submit" class="button button-primary"><?php esc_html_e( 'Ingresar', CO360_SSA_TEXT_DOMAIN ); ?></button>
			</form>
		</div>
	</div>
</div>
