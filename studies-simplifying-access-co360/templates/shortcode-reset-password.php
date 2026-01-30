<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$login = sanitize_text_field( wp_unslash( $_GET['login'] ?? '' ) );
$key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
?>
<div class="co360-ssa-login">
	<div class="co360-ssa-auth-wrap">
		<div class="co360-ssa-auth-card">
			<h2><?php esc_html_e( 'Nueva contraseña', CO360_SSA_TEXT_DOMAIN ); ?></h2>

			<?php foreach ( $errors as $error ) : ?>
				<p class="co360-ssa-notice co360-ssa-notice--error"><?php echo esc_html( $error ); ?></p>
			<?php endforeach; ?>

			<?php if ( empty( $errors ) ) : ?>
				<form method="post" class="co360-ssa-login-form">
					<?php wp_nonce_field( 'co360_ssa_resetpass_form', 'co360_ssa_resetpass_nonce' ); ?>
					<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">
					<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
					<label class="co360-ssa-form-row">
						<?php esc_html_e( 'Nueva contraseña', CO360_SSA_TEXT_DOMAIN ); ?>
						<input class="co360-ssa-input" type="password" name="password_1" required>
					</label>
					<label class="co360-ssa-form-row">
						<?php esc_html_e( 'Repite la contraseña', CO360_SSA_TEXT_DOMAIN ); ?>
						<input class="co360-ssa-input" type="password" name="password_2" required>
					</label>
					<button type="submit" name="co360_ssa_resetpass_submit" class="button button-primary co360-ssa-btn">
						<?php esc_html_e( 'Guardar nueva contraseña', CO360_SSA_TEXT_DOMAIN ); ?>
					</button>
				</form>
			<?php endif; ?>

			<a class="co360-ssa-link" href="<?php echo esc_url( add_query_arg( array( 'mode' => 'lost', 'redirect_to' => $redirect_to ), get_permalink( get_queried_object_id() ) ) ); ?>">
				<?php esc_html_e( 'Recuperar contraseña', CO360_SSA_TEXT_DOMAIN ); ?>
			</a>
			<a class="co360-ssa-link" href="<?php echo esc_url( add_query_arg( array( 'redirect_to' => $redirect_to ), get_permalink( get_queried_object_id() ) ) ); ?>">
				<?php esc_html_e( 'Volver a iniciar sesión', CO360_SSA_TEXT_DOMAIN ); ?>
			</a>
		</div>
	</div>
</div>
