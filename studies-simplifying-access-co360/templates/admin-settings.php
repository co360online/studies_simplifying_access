<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<div class="wrap co360-ssa-admin">
		<h1><?php esc_html_e( 'Studies Simplifying Access', CO360_SSA_TEXT_DOMAIN ); ?></h1>
		<?php
		$options = CO360\SSA\Utils::get_options();
		$has_global_crd_config = ! empty( $options['crd_field_ids_investigator_code'] )
			|| ! empty( $options['crd_field_ids_center_name'] )
			|| ! empty( $options['crd_field_ids_center_code'] )
			|| ! empty( $options['crd_field_ids_code_used'] );
		if ( $has_global_crd_config ) :
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Configuración global CRD detectada. Se recomienda configurar el autorrelleno por estudio en el metabox “CRD – Autorrelleno”.', CO360_SSA_TEXT_DOMAIN ); ?></p>
			</div>
		<?php endif; ?>
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
				<li><?php esc_html_e( 'En cada Estudio define los CRD forms y Field IDs a autopoblar en el metabox “CRD – Autorrelleno”.', CO360_SSA_TEXT_DOMAIN ); ?></li>
				<li><?php esc_html_e( 'En Formidable, marca los campos CRD como Read-only para mostrarlos sin permitir edición.', CO360_SSA_TEXT_DOMAIN ); ?></li>
				<li><?php esc_html_e( 'Inserta los shortcodes en las páginas públicas según el flujo recomendado.', CO360_SSA_TEXT_DOMAIN ); ?></li>
			</ol>
		</div>
	</div>
