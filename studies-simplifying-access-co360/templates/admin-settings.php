<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap co360-ssa-admin">
	<h1><?php esc_html_e( 'Studies Simplifying Access', CO360_SSA_TEXT_DOMAIN ); ?></h1>
	<form method="post" action="options.php">
		<?php
		settings_fields( 'co360_ssa_group' );
		do_settings_sections( 'co360-ssa' );
		submit_button();
		?>
	</form>

	<div class="co360-ssa-help">
		<h2><?php esc_html_e( 'Guía rápida', CO360_SSA_TEXT_DOMAIN ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'Crea un Estudio (CPT co360_estudio) y configura prefijo/regex o lista de códigos.', CO360_SSA_TEXT_DOMAIN ); ?></li>
			<li><?php esc_html_e( 'Configura URLs y TTL del contexto en esta pantalla.', CO360_SSA_TEXT_DOMAIN ); ?></li>
			<li><?php esc_html_e( 'Inserta los shortcodes en las páginas públicas según el flujo recomendado.', CO360_SSA_TEXT_DOMAIN ); ?></li>
		</ol>
	</div>
</div>
