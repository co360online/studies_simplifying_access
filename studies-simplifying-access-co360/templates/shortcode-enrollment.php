<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="co360-ssa-enrollment">
	<?php if ( ! empty( $errors ) ) : ?>
		<div class="co360-ssa-notices">
			<?php foreach ( $errors as $error ) : ?>
				<p class="co360-ssa-error"><?php echo esc_html( $error ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $errors ) && $form_id ) : ?>
		<?php echo do_shortcode( '[formidable id=' . absint( $form_id ) . ']' ); ?>
	<?php elseif ( empty( $errors ) ) : ?>
		<p class="co360-ssa-error"><?php esc_html_e( 'Formulario de inscripción no configurado.', CO360_SSA_TEXT_DOMAIN ); ?></p>
	<?php endif; ?>
</div>
