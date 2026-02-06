<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No autorizado.', CO360_SSA_TEXT_DOMAIN ) );
}

global $wpdb;
$table = CO360\SSA\DB::table_name( CO360_SSA_DB_TABLE );
$centers_table = CO360\SSA\DB::table_name( CO360_SSA_DB_CENTERS );

$study_id = isset( $_GET['study_id'] ) ? absint( $_GET['study_id'] ) : 0;
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$name_query = isset( $_GET['name_query'] ) ? sanitize_text_field( wp_unslash( $_GET['name_query'] ) ) : '';
$start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
$end = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';
$center_code = isset( $_GET['center_code'] ) ? sanitize_text_field( wp_unslash( $_GET['center_code'] ) ) : '';
$investigator_code = isset( $_GET['investigator_code'] ) ? sanitize_text_field( wp_unslash( $_GET['investigator_code'] ) ) : '';

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
if ( $center_code ) {
	$where .= ' AND ' . $table . '.center_code = %s';
	$args[] = $center_code;
}
if ( $investigator_code ) {
	$where .= ' AND ' . $table . '.investigator_code LIKE %s';
	$args[] = '%' . $wpdb->esc_like( $investigator_code ) . '%';
}

$join = " LEFT JOIN {$wpdb->users} AS u ON u.ID = {$table}.user_id";
$join .= " LEFT JOIN {$wpdb->usermeta} AS um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'";
$join .= " LEFT JOIN {$wpdb->usermeta} AS um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'";
$join .= " LEFT JOIN {$wpdb->posts} AS p ON p.ID = {$table}.estudio_id";
if ( $search ) {
	$where .= ' AND u.user_email LIKE %s';
	$args[] = '%' . $wpdb->esc_like( $search ) . '%';
}
if ( $name_query ) {
	$like = '%' . $wpdb->esc_like( $name_query ) . '%';
	$where .= ' AND ( um_fn.meta_value LIKE %s OR um_ln.meta_value LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s )';
	$args[] = $like;
	$args[] = $like;
	$args[] = $like;
	$args[] = $like;
}
$sql = "SELECT {$table}.*, u.user_login, u.user_email, u.display_name, um_fn.meta_value AS first_name, um_ln.meta_value AS last_name, p.post_title AS study_title FROM {$table}{$join} WHERE {$where} ORDER BY {$table}.created_at DESC";
$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

$studies = get_posts(
	array(
		'post_type' => CO360_SSA_CT_STUDY,
		'numberposts' => -1,
	)
);
$center_where = '1=1';
$center_args = array();
if ( $study_id ) {
	$center_where = 'estudio_id = %d';
	$center_args[] = $study_id;
}
$center_sql = "SELECT center_code, center_name FROM {$centers_table} WHERE {$center_where} ORDER BY center_name ASC";
$center_rows = $center_args ? $wpdb->get_results( $wpdb->prepare( $center_sql, $center_args ), ARRAY_A ) : $wpdb->get_results( $center_sql, ARRAY_A );
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
			<?php esc_html_e( 'Centro:', CO360_SSA_TEXT_DOMAIN ); ?>
			<select name="center_code">
				<option value=""><?php esc_html_e( 'Todos', CO360_SSA_TEXT_DOMAIN ); ?></option>
				<?php foreach ( $center_rows as $center_row ) : ?>
					<?php
					$option_value = (string) $center_row['center_code'];
					$option_label = $center_row['center_name'] . ' (' . $center_row['center_code'] . ')';
					?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $center_code, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<?php esc_html_e( 'Email:', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="usuario@dominio.com">
		</label>
		<label>
			<?php esc_html_e( 'Nombre contiene:', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="text" name="name_query" value="<?php echo esc_attr( $name_query ); ?>" placeholder="Nombre o apellido">
		</label>
		<label>
			<?php esc_html_e( 'Investigator code:', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="text" name="investigator_code" value="<?php echo esc_attr( $investigator_code ); ?>" placeholder="001-00001">
		</label>
		<button class="button" type="submit"><?php esc_html_e( 'Filtrar', CO360_SSA_TEXT_DOMAIN ); ?></button>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=co360_ssa_export_csv&_wpnonce=' . wp_create_nonce( 'co360_ssa_export_csv' ) . '&study_id=' . $study_id . '&start_date=' . urlencode( $start ) . '&end_date=' . urlencode( $end ) . '&center_code=' . urlencode( $center_code ) . '&investigator_code=' . urlencode( $investigator_code ) . '&s=' . urlencode( $search ) . '&name_query=' . urlencode( $name_query ) ) ); ?>"><?php esc_html_e( 'Exportar CSV', CO360_SSA_TEXT_DOMAIN ); ?></a>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Investigator code', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Centro', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Fecha', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Usuario', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Nombre', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Apellidos', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Email', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Estudio', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Código', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Entry', CO360_SSA_TEXT_DOMAIN ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="10"><?php esc_html_e( 'Sin inscripciones.', CO360_SSA_TEXT_DOMAIN ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
						$entry_id = isset( $row->entry_id ) ? absint( $row->entry_id ) : 0;
						$center_label = trim( sprintf( '%s (%s)', (string) $row->center_name, (string) $row->center_code ) );
						if ( '' === trim( (string) $row->center_name ) && '' === trim( (string) $row->center_code ) ) {
							$center_label = '-';
						}
						$first_name = $row->first_name ? $row->first_name : '';
						$last_name = $row->last_name ? $row->last_name : '';
						$display_name = $row->display_name ? $row->display_name : '';
						$entry_link = $entry_id ? admin_url( 'admin.php?page=formidable-entries&frm_action=show&id=' . $entry_id ) : '';
						?>
					<tr>
						<td><?php echo esc_html( $row->investigator_code ?: '-' ); ?></td>
						<td><?php echo esc_html( $center_label ); ?></td>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $row->user_login ?: '-' ); ?></td>
						<td><?php echo esc_html( $first_name ?: ( $display_name ?: '—' ) ); ?></td>
						<td><?php echo esc_html( $last_name ?: '—' ); ?></td>
						<td><?php echo esc_html( $row->user_email ?: '-' ); ?></td>
						<td><?php echo esc_html( $row->study_title ?: '-' ); ?></td>
						<td><?php echo esc_html( $row->code_used ); ?></td>
						<td>
							<?php if ( $entry_link ) : ?>
								<a href="<?php echo esc_url( $entry_link ); ?>"><?php esc_html_e( 'Ver entry', CO360_SSA_TEXT_DOMAIN ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $entry_id ? $entry_id : '-' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
