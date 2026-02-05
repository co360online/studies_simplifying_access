<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap co360-ssa-admin">
	<h1><?php esc_html_e( 'Dashboard Estudios', CO360_SSA_TEXT_DOMAIN ); ?></h1>
	<form method="get" style="margin:10px 0 20px;">
		<input type="hidden" name="page" value="co360-ssa-dashboard">
		<label>
			<?php esc_html_e( 'Estudio:', CO360_SSA_TEXT_DOMAIN ); ?>
			<select name="study_id">
				<option value="0"><?php esc_html_e( 'Selecciona un estudio', CO360_SSA_TEXT_DOMAIN ); ?></option>
				<?php foreach ( $studies as $study ) : ?>
					<option value="<?php echo esc_attr( $study->ID ); ?>" <?php selected( $selected_study_id, $study->ID ); ?>><?php echo esc_html( $study->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Ver dashboard', CO360_SSA_TEXT_DOMAIN ); ?></button>
	</form>

	<?php if ( ! $selected_study_id ) : ?>
		<p><?php esc_html_e( 'Selecciona un estudio.', CO360_SSA_TEXT_DOMAIN ); ?></p>
		<?php return; ?>
	<?php endif; ?>

	<div style="display:grid; grid-template-columns:repeat(4,minmax(180px,1fr)); gap:12px; margin-bottom:20px;">
		<div class="card"><h3><?php esc_html_e( 'Investigadores inscritos', CO360_SSA_TEXT_DOMAIN ); ?></h3><p><strong><?php echo esc_html( (string) ( $dashboard_data['investigators_count'] ?? 0 ) ); ?></strong></p></div>
		<div class="card"><h3><?php esc_html_e( 'Centros', CO360_SSA_TEXT_DOMAIN ); ?></h3><p><strong><?php echo esc_html( (string) ( $dashboard_data['centers_count'] ?? 0 ) ); ?></strong></p></div>
		<div class="card"><h3><?php esc_html_e( 'CRDs enviados', CO360_SSA_TEXT_DOMAIN ); ?></h3><p><strong><?php echo null === ( $dashboard_data['crd_sent_count'] ?? null ) ? esc_html__( 'Configurar', CO360_SSA_TEXT_DOMAIN ) : esc_html( (string) $dashboard_data['crd_sent_count'] ); ?></strong></p><?php if ( ! empty( $dashboard_data['crd_config_message'] ) ) : ?><small><?php echo esc_html( $dashboard_data['crd_config_message'] ); ?></small><?php endif; ?></div>
		<div class="card"><h3><?php esc_html_e( 'Última actividad', CO360_SSA_TEXT_DOMAIN ); ?></h3><p><strong><?php echo esc_html( $dashboard_data['last_crd_at'] ?: ( $dashboard_data['last_enrollment_at'] ?: '—' ) ); ?></strong></p></div>
	</div>

	<p>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=co360-ssa-enrollments&study_id=' . $selected_study_id ) ); ?>"><?php esc_html_e( 'Ver Inscripciones', CO360_SSA_TEXT_DOMAIN ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=co360-ssa-codes&study_id=' . $selected_study_id ) ); ?>"><?php esc_html_e( 'Ver Centros/Códigos', CO360_SSA_TEXT_DOMAIN ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=co360_ssa_export_csv&_wpnonce=' . wp_create_nonce( 'co360_ssa_export_csv' ) . '&study_id=' . $selected_study_id ) ); ?>"><?php esc_html_e( 'Exportar CSV Inscripciones', CO360_SSA_TEXT_DOMAIN ); ?></a>
	</p>

	<h2><?php esc_html_e( 'Centros', CO360_SSA_TEXT_DOMAIN ); ?></h2>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Centro', CO360_SSA_TEXT_DOMAIN ); ?></th><th><?php esc_html_e( 'Nº investigadores', CO360_SSA_TEXT_DOMAIN ); ?></th><th><?php esc_html_e( 'CRDs', CO360_SSA_TEXT_DOMAIN ); ?></th></tr></thead>
		<tbody>
			<?php if ( empty( $dashboard_data['center_rows'] ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'Sin datos.', CO360_SSA_TEXT_DOMAIN ); ?></td></tr>
			<?php else : foreach ( $dashboard_data['center_rows'] as $row ) : ?>
				<tr>
					<td><?php echo esc_html( trim( ( $row['center_name'] ?: '' ) . ' (' . ( $row['center_code'] ?: '' ) . ')' ) ); ?></td>
					<td><?php echo esc_html( (string) $row['investigators_count'] ); ?></td>
					<td><?php echo esc_html( '—' ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>
	<p><em><?php esc_html_e( 'Para habilitar CRDs por centro, guarda center_code/center_name en las entradas CRD.', CO360_SSA_TEXT_DOMAIN ); ?></em></p>

	<h2><?php esc_html_e( 'Investigadores (últimos 20)', CO360_SSA_TEXT_DOMAIN ); ?></h2>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Nombre', CO360_SSA_TEXT_DOMAIN ); ?></th><th><?php esc_html_e( 'Email', CO360_SSA_TEXT_DOMAIN ); ?></th><th><?php esc_html_e( 'investigator_code', CO360_SSA_TEXT_DOMAIN ); ?></th><th><?php esc_html_e( 'Centro', CO360_SSA_TEXT_DOMAIN ); ?></th><th><?php esc_html_e( 'Fecha inscripción', CO360_SSA_TEXT_DOMAIN ); ?></th></tr></thead>
		<tbody>
			<?php if ( empty( $dashboard_data['investigator_rows'] ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Sin datos.', CO360_SSA_TEXT_DOMAIN ); ?></td></tr>
			<?php else : foreach ( $dashboard_data['investigator_rows'] as $row ) : ?>
				<tr>
					<td><?php echo esc_html( trim( ( $row['first_name'] ?: '' ) . ' ' . ( $row['last_name'] ?: '' ) ) ?: '—' ); ?></td>
					<td><?php echo esc_html( $row['user_email'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $row['investigator_code'] ?: '—' ); ?></td>
					<td><?php echo esc_html( trim( ( $row['center_name'] ?: '' ) . ' (' . ( $row['center_code'] ?: '' ) . ')' ) ); ?></td>
					<td><?php echo esc_html( $row['created_at'] ?: '—' ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
