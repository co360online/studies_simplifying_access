<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No autorizado.', CO360_SSA_TEXT_DOMAIN ) );
}

global $wpdb;
$table = CO360\SSA\DB::table_name( CO360_SSA_DB_TABLE );

$study_id = isset( $_GET['study_id'] ) ? absint( $_GET['study_id'] ) : 0;
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
$end = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';

$where = '1=1';
$args = array();
if ( $study_id ) {
	$where .= ' AND estudio_id = %d';
	$args[] = $study_id;
}
if ( $start ) {
	$where .= ' AND created_at >= %s';
	$args[] = $start . ' 00:00:00';
}
if ( $end ) {
	$where .= ' AND created_at <= %s';
	$args[] = $end . ' 23:59:59';
}

$join = " LEFT JOIN {$wpdb->users} AS u ON u.ID = {$table}.user_id";
if ( $search ) {
	$where .= ' AND u.user_email LIKE %s';
	$args[] = '%' . $wpdb->esc_like( $search ) . '%';
}
$sql = "SELECT {$table}.* FROM {$table}{$join} WHERE {$where} ORDER BY created_at DESC";
$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

$studies = get_posts(
	array(
		'post_type' => CO360_SSA_CT_STUDY,
		'numberposts' => -1,
	)
);
?>
<div class="wrap co360-ssa-admin">
	<h1><?php esc_html_e( 'Inscripciones', CO360_SSA_TEXT_DOMAIN ); ?></h1>
	<form method="get" class="co360-ssa-filters">
		<input type="hidden" name="page" value="co360-ssa-enrollments">
		<label>
			<?php esc_html_e( 'Estudio:', CO360_SSA_TEXT_DOMAIN ); ?>
			<select name="study_id">
				<option value="0"><?php esc_html_e( 'Todos', CO360_SSA_TEXT_DOMAIN ); ?></option>
				<?php foreach ( $studies as $study ) : ?>
					<option value="<?php echo esc_attr( $study->ID ); ?>" <?php selected( $study_id, $study->ID ); ?>><?php echo esc_html( $study->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<?php esc_html_e( 'Desde:', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="date" name="start_date" value="<?php echo esc_attr( $start ); ?>">
		</label>
		<label>
			<?php esc_html_e( 'Hasta:', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="date" name="end_date" value="<?php echo esc_attr( $end ); ?>">
		</label>
		<label>
			<?php esc_html_e( 'Email:', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="usuario@dominio.com">
		</label>
		<button class="button" type="submit"><?php esc_html_e( 'Filtrar', CO360_SSA_TEXT_DOMAIN ); ?></button>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=co360_ssa_export_csv&_wpnonce=' . wp_create_nonce( 'co360_ssa_export_csv' ) ) ); ?>"><?php esc_html_e( 'Exportar CSV', CO360_SSA_TEXT_DOMAIN ); ?></a>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Fecha', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Usuario', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Email', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Estudio', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Código', CO360_SSA_TEXT_DOMAIN ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Sin inscripciones.', CO360_SSA_TEXT_DOMAIN ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
						$user = get_user_by( 'id', $row->user_id );
						$study = get_post( $row->estudio_id );
						?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '-' ); ?></td>
						<td><?php echo esc_html( $user ? $user->user_email : '-' ); ?></td>
						<td><?php echo esc_html( $study ? $study->post_title : '-' ); ?></td>
						<td><?php echo esc_html( $row->code_used ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
