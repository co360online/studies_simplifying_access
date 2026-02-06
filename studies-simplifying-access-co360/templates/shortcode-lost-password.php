<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="co360-ssa-login">
	<div class="co360-ssa-auth-wrap">
		<div class="co360-ssa-auth-card">
			<h2><?php esc_html_e( 'Recuperar contraseña', CO360_SSA_TEXT_DOMAIN ); ?></h2>

			<?php foreach ( $errors as $error ) : ?>
				<p class="co360-ssa-notice co360-ssa-notice--error"><?php echo esc_html( $error ); ?></p>
			<?php endforeach; ?>

			<?php foreach ( $notices as $notice ) : ?>
				<p class="co360-ssa-notice co360-ssa-notice--success"><?php echo esc_html( $notice ); ?></p>
			<?php endforeach; ?>

			<form method="post" class="co360-ssa-login-form">
				<?php wp_nonce_field( 'co360_ssa_lostpass_form', 'co360_ssa_lostpass_nonce' ); ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<label class="co360-ssa-form-row">
					<?php esc_html_e( 'Email o usuario', CO360_SSA_TEXT_DOMAIN ); ?>
					<input class="co360-ssa-input" type="text" name="co360_ssa_user" required>
				</label>
				<button type="submit" name="co360_ssa_lostpass_submit" class="button button-primary co360-ssa-btn">
					<?php esc_html_e( 'Enviar enlace', CO360_SSA_TEXT_DOMAIN ); ?>
				</button>
				<a class="co360-ssa-link" href="<?php echo esc_url( add_query_arg( array( 'redirect_to' => $redirect_to ), get_permalink( get_queried_object_id() ) ) ); ?>">
					<?php esc_html_e( 'Volver a iniciar sesión', CO360_SSA_TEXT_DOMAIN ); ?>
				</a>
			</form>
		</div>
	</div>
</div>
