<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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
				<label class="co360-ssa-form-row">
					<?php if ( $atts['show_labels'] ) : ?><?php esc_html_e( 'Usuario o email', CO360_SSA_TEXT_DOMAIN ); ?><?php endif; ?>
					<input class="co360-ssa-input" type="text" name="username" required>
				</label>
				<label class="co360-ssa-form-row">
					<?php if ( $atts['show_labels'] ) : ?><?php esc_html_e( 'Contraseña', CO360_SSA_TEXT_DOMAIN ); ?><?php endif; ?>
					<input class="co360-ssa-input" type="password" name="password" required>
				</label>
				<?php if ( $atts['show_remember'] ) : ?>
					<label class="co360-ssa-remember co360-ssa-form-row">
						<input type="checkbox" name="remember" value="1">
						<?php esc_html_e( 'Recordarme', CO360_SSA_TEXT_DOMAIN ); ?>
					</label>
				<?php endif; ?>
				<div class="co360-ssa-login-error" style="display:none;"></div>
				<button type="submit" name="co360_ssa_login_submit" class="button button-primary co360-ssa-btn"><?php esc_html_e( 'Ingresar', CO360_SSA_TEXT_DOMAIN ); ?></button>
				<a class="co360-ssa-link" href="<?php echo esc_url( add_query_arg( array( 'mode' => 'lost', 'redirect_to' => $redirect_to ), get_permalink( get_queried_object_id() ) ) ); ?>">
					<?php esc_html_e( '¿Has olvidado tu contraseña?', CO360_SSA_TEXT_DOMAIN ); ?>
				</a>
			</form>
		</div>
	</div>
</div>
