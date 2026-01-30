<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No autorizado.', CO360_SSA_TEXT_DOMAIN ) );
}

global $wpdb;
$table = CO360\SSA\DB::table_name( CO360_SSA_DB_CODES );

$studies = get_posts(
	array(
		'post_type' => CO360_SSA_CT_STUDY,
		'numberposts' => -1,
	)
);

$selected_study = isset( $_GET['study_id'] ) ? absint( $_GET['study_id'] ) : 0;

if ( isset( $_POST['co360_ssa_codes_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['co360_ssa_codes_nonce'] ) ), 'co360_ssa_codes' ) ) {
	$action = sanitize_text_field( wp_unslash( $_POST['co360_ssa_codes_action'] ?? '' ) );

	if ( 'add_single' === $action ) {
		$study_id = absint( $_POST['study_id'] ?? 0 );
		$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$max_uses = absint( $_POST['max_uses'] ?? 1 );
		$expires_at = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );
		$active = isset( $_POST['active'] ) ? 1 : 0;

		if ( $study_id && $code ) {
			$wpdb->insert(
				$table,
				array(
					'estudio_id' => $study_id,
					'code' => $code,
					'max_uses' => $max_uses,
					'used_count' => 0,
					'expires_at' => $expires_at ? $expires_at . ' 23:59:59' : null,
					'active' => $active,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%d', '%d', '%s', '%d', '%s' )
			);
		}
	}

	if ( 'add_bulk' === $action ) {
		$study_id = absint( $_POST['study_id'] ?? 0 );
		$amount = max( 1, absint( $_POST['amount'] ?? 1 ) );
		$length = max( 6, absint( $_POST['length'] ?? 8 ) );
		$max_uses = absint( $_POST['max_uses'] ?? 1 );
		$active = isset( $_POST['active'] ) ? 1 : 0;

		if ( $study_id ) {
			for ( $i = 0; $i < $amount; $i++ ) {
				$code = strtoupper( wp_generate_password( $length, false, false ) );
				$wpdb->insert(
					$table,
					array(
						'estudio_id' => $study_id,
						'code' => $code,
						'max_uses' => $max_uses,
						'used_count' => 0,
						'active' => $active,
						'created_at' => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%d', '%d', '%d', '%s' )
				);
			}
		}
	}

	if ( 'deactivate' === $action ) {
		$code_id = absint( $_POST['code_id'] ?? 0 );
		if ( $code_id ) {
			$wpdb->update( $table, array( 'active' => 0 ), array( 'id' => $code_id ), array( '%d' ), array( '%d' ) );
		}
	}

	if ( 'reset' === $action ) {
		$code_id = absint( $_POST['code_id'] ?? 0 );
		if ( $code_id ) {
			$wpdb->update(
				$table,
				array( 'used_count' => 0, 'last_used_at' => null ),
				array( 'id' => $code_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
	}
}

$where = '1=1';
$args = array();
if ( $selected_study ) {
	$where .= ' AND estudio_id = %d';
	$args[] = $selected_study;
}

$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC";
$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
?>
<div class="wrap co360-ssa-admin">
	<h1><?php esc_html_e( 'Códigos', CO360_SSA_TEXT_DOMAIN ); ?></h1>
	<p class="description"><?php esc_html_e( 'La configuración de inscripción y páginas (formulario, URL de inscripción, página del estudio y páginas protegidas) se define en cada estudio.', CO360_SSA_TEXT_DOMAIN ); ?></p>
	<form method="get" class="co360-ssa-filters">
		<input type="hidden" name="page" value="co360-ssa-codes">
		<label>
			<?php esc_html_e( 'Estudio:', CO360_SSA_TEXT_DOMAIN ); ?>
			<select name="study_id">
				<option value="0"><?php esc_html_e( 'Todos', CO360_SSA_TEXT_DOMAIN ); ?></option>
				<?php foreach ( $studies as $study ) : ?>
					<option value="<?php echo esc_attr( $study->ID ); ?>" <?php selected( $selected_study, $study->ID ); ?>><?php echo esc_html( $study->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button class="button" type="submit"><?php esc_html_e( 'Filtrar', CO360_SSA_TEXT_DOMAIN ); ?></button>
	</form>

	<h2><?php esc_html_e( 'Crear código manual', CO360_SSA_TEXT_DOMAIN ); ?></h2>
	<form method="post" class="co360-ssa-form">
		<?php wp_nonce_field( 'co360_ssa_codes', 'co360_ssa_codes_nonce' ); ?>
		<input type="hidden" name="co360_ssa_codes_action" value="add_single">
		<label>
			<?php esc_html_e( 'Estudio', CO360_SSA_TEXT_DOMAIN ); ?>
			<select name="study_id" required>
				<?php foreach ( $studies as $study ) : ?>
					<option value="<?php echo esc_attr( $study->ID ); ?>"><?php echo esc_html( $study->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<?php esc_html_e( 'Código', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="text" name="code" required>
		</label>
		<label>
			<?php esc_html_e( 'Max usos', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="number" name="max_uses" value="1" min="1">
		</label>
		<label>
			<?php esc_html_e( 'Expira', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="date" name="expires_at">
		</label>
		<label>
			<input type="checkbox" name="active" checked> <?php esc_html_e( 'Activo', CO360_SSA_TEXT_DOMAIN ); ?>
		</label>
		<button class="button button-primary" type="submit"><?php esc_html_e( 'Crear', CO360_SSA_TEXT_DOMAIN ); ?></button>
	</form>

	<h2><?php esc_html_e( 'Generar códigos masivos', CO360_SSA_TEXT_DOMAIN ); ?></h2>
	<form method="post" class="co360-ssa-form">
		<?php wp_nonce_field( 'co360_ssa_codes', 'co360_ssa_codes_nonce' ); ?>
		<input type="hidden" name="co360_ssa_codes_action" value="add_bulk">
		<label>
			<?php esc_html_e( 'Estudio', CO360_SSA_TEXT_DOMAIN ); ?>
			<select name="study_id" required>
				<?php foreach ( $studies as $study ) : ?>
					<option value="<?php echo esc_attr( $study->ID ); ?>"><?php echo esc_html( $study->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<?php esc_html_e( 'Cantidad', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="number" name="amount" value="5" min="1">
		</label>
		<label>
			<?php esc_html_e( 'Longitud', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="number" name="length" value="8" min="6">
		</label>
		<label>
			<?php esc_html_e( 'Max usos', CO360_SSA_TEXT_DOMAIN ); ?>
			<input type="number" name="max_uses" value="1" min="1">
		</label>
		<label>
			<input type="checkbox" name="active" checked> <?php esc_html_e( 'Activo', CO360_SSA_TEXT_DOMAIN ); ?>
		</label>
		<button class="button button-primary" type="submit"><?php esc_html_e( 'Generar', CO360_SSA_TEXT_DOMAIN ); ?></button>
	</form>

	<h2><?php esc_html_e( 'Listado de códigos', CO360_SSA_TEXT_DOMAIN ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Estudio', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Código', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Usos', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Max usos', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Asignado a', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Último uso', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Activo', CO360_SSA_TEXT_DOMAIN ); ?></th>
				<th><?php esc_html_e( 'Acciones', CO360_SSA_TEXT_DOMAIN ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'Sin códigos.', CO360_SSA_TEXT_DOMAIN ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php $study = get_post( $row->estudio_id ); ?>
					<tr>
						<td><?php echo esc_html( $study ? $study->post_title : '-' ); ?></td>
						<td><?php echo esc_html( $row->code ); ?></td>
						<td><?php echo esc_html( $row->used_count ); ?></td>
						<td><?php echo esc_html( $row->max_uses ); ?></td>
						<td><?php echo esc_html( $row->assigned_email ); ?></td>
						<td><?php echo esc_html( $row->last_used_at ); ?></td>
						<td><?php echo esc_html( $row->active ? __( 'Sí', CO360_SSA_TEXT_DOMAIN ) : __( 'No', CO360_SSA_TEXT_DOMAIN ) ); ?></td>
						<td>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'co360_ssa_codes', 'co360_ssa_codes_nonce' ); ?>
								<input type="hidden" name="co360_ssa_codes_action" value="reset">
								<input type="hidden" name="code_id" value="<?php echo esc_attr( $row->id ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Resetear', CO360_SSA_TEXT_DOMAIN ); ?></button>
							</form>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'co360_ssa_codes', 'co360_ssa_codes_nonce' ); ?>
								<input type="hidden" name="co360_ssa_codes_action" value="deactivate">
								<input type="hidden" name="code_id" value="<?php echo esc_attr( $row->id ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Desactivar', CO360_SSA_TEXT_DOMAIN ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
