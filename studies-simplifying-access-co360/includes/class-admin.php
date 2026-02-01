<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_co360_ssa_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Studies Simplifying Access', CO360_SSA_TEXT_DOMAIN ),
			__( 'Studies Simplifying Access', CO360_SSA_TEXT_DOMAIN ),
			'manage_options',
			'co360-ssa',
			array( $this, 'render_settings_page' ),
			'dashicons-admin-network'
		);

		add_submenu_page(
			'co360-ssa',
			__( 'Ajustes', CO360_SSA_TEXT_DOMAIN ),
			__( 'Ajustes', CO360_SSA_TEXT_DOMAIN ),
			'manage_options',
			'co360-ssa',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'co360-ssa',
			__( 'Inscripciones', CO360_SSA_TEXT_DOMAIN ),
			__( 'Inscripciones', CO360_SSA_TEXT_DOMAIN ),
			'manage_options',
			'co360-ssa-enrollments',
			array( $this, 'render_enrollments_page' )
		);

		add_submenu_page(
			'co360-ssa',
			__( 'Códigos', CO360_SSA_TEXT_DOMAIN ),
			__( 'Códigos', CO360_SSA_TEXT_DOMAIN ),
			'manage_options',
			'co360-ssa-codes',
			array( $this, 'render_codes_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'co360-ssa' ) ) {
			return;
		}
		wp_enqueue_style( 'co360-ssa-admin', CO360_SSA_PLUGIN_URL . 'assets/css/ssa-admin.css', array(), CO360_SSA_VERSION );
	}

	public function render_settings_page() {
		include CO360_SSA_PLUGIN_PATH . 'templates/admin-settings.php';
	}

	public function render_enrollments_page() {
		include CO360_SSA_PLUGIN_PATH . 'templates/admin-enrollments.php';
	}

	public function render_codes_page() {
		include CO360_SSA_PLUGIN_PATH . 'templates/admin-codes.php';
	}

	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', CO360_SSA_TEXT_DOMAIN ) );
		}
		check_admin_referer( 'co360_ssa_export_csv' );

		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );

		$study_id = isset( $_GET['study_id'] ) ? absint( $_GET['study_id'] ) : 0;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
		$end = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';
		$center_code = isset( $_GET['center_code'] ) ? sanitize_text_field( wp_unslash( $_GET['center_code'] ) ) : '';
		$investigator_code = isset( $_GET['investigator_code'] ) ? sanitize_text_field( wp_unslash( $_GET['investigator_code'] ) ) : '';

		$where = '1=1';
		$args  = array();
		if ( $study_id ) {
			$where .= ' AND ' . $table . '.estudio_id = %d';
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
		if ( $search ) {
			$where .= ' AND u.user_email LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql = "SELECT {$table}.user_id, {$table}.estudio_id, {$table}.code_used, {$table}.created_at, {$table}.center_code, {$table}.center_name, {$table}.investigator_code, {$table}.entry_id, u.user_email, um_fn.meta_value AS first_name, um_ln.meta_value AS last_name FROM {$table}{$join} WHERE {$where} ORDER BY {$table}.created_at DESC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="co360-ssa-enrollments.csv"' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'user_id', 'email', 'first_name', 'last_name', 'study_id', 'study_title', 'code_used', 'center_code', 'center_name', 'investigator_code', 'entry_id', 'created_at' ) );
		foreach ( $rows as $row ) {
			$study = get_post( $row['estudio_id'] );
			fputcsv(
				$output,
				array(
					$row['user_id'],
					$row['user_email'] ?? '',
					$row['first_name'] ?? '',
					$row['last_name'] ?? '',
					$row['estudio_id'],
					$study ? $study->post_title : '',
					$row['code_used'],
					$row['center_code'],
					$row['center_name'],
					$row['investigator_code'],
					$row['entry_id'],
					$row['created_at'],
				)
			);
		}
		fclose( $output );
		exit;
	}
}
