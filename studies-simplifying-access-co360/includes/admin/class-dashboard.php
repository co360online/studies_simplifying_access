<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'co360-ssa',
			__( 'Dashboard Estudios', CO360_SSA_TEXT_DOMAIN ),
			__( 'Dashboard', CO360_SSA_TEXT_DOMAIN ),
			'manage_options',
			'co360-ssa-dashboard',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', CO360_SSA_TEXT_DOMAIN ) );
		}

		$selected_study_id = isset( $_GET['study_id'] ) ? absint( $_GET['study_id'] ) : 0;
		$studies = get_posts(
			array(
				'post_type' => CO360_SSA_CT_STUDY,
				'numberposts' => -1,
				'orderby' => 'title',
				'order' => 'ASC',
			)
		);

		$dashboard_data = $selected_study_id ? $this->get_dashboard_data( $selected_study_id ) : array();
		include CO360_SSA_PLUGIN_PATH . 'templates/admin-dashboard.php';
	}

	private function get_dashboard_data( $study_id ) {
		$study_id = absint( $study_id );
		if ( ! $study_id ) {
			return array();
		}

		$key = 'co360_ssa_dash_' . $study_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$ins_table = DB::table_name( CO360_SSA_DB_TABLE );
		$centers_table = DB::table_name( CO360_SSA_DB_CENTERS );

		$investigators_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$ins_table} WHERE estudio_id = %d",
				$study_id
			)
		);
		$centers_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$centers_table} WHERE estudio_id = %d",
				$study_id
			)
		);
		$last_enrollment_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(created_at) FROM {$ins_table} WHERE estudio_id = %d",
				$study_id
			)
		);

		$crd_info = $this->get_crd_count_info( $study_id );

		$center_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT center_code, center_name, COUNT(*) AS investigators_count FROM {$ins_table} WHERE estudio_id = %d GROUP BY center_code, center_name ORDER BY center_name ASC",
				$study_id
			),
			ARRAY_A
		);

		$investigator_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.user_id, i.investigator_code, i.center_code, i.center_name, i.created_at, u.user_email, um_fn.meta_value AS first_name, um_ln.meta_value AS last_name
				FROM {$ins_table} i
				LEFT JOIN {$wpdb->users} u ON u.ID = i.user_id
				LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = i.user_id AND um_fn.meta_key = 'first_name'
				LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = i.user_id AND um_ln.meta_key = 'last_name'
				WHERE i.estudio_id = %d
				ORDER BY i.created_at DESC
				LIMIT 20",
				$study_id
			),
			ARRAY_A
		);

		$data = array(
			'investigators_count' => $investigators_count,
			'centers_count' => $centers_count,
			'crd_sent_count' => $crd_info['count'],
			'crd_config_message' => $crd_info['message'],
			'last_enrollment_at' => $last_enrollment_at,
			'last_crd_at' => $crd_info['last_created_at'],
			'center_rows' => $center_rows,
			'investigator_rows' => $investigator_rows,
		);

		set_transient( $key, $data, 60 );
		return $data;
	}

	private function get_crd_count_info( $study_id ) {
		global $wpdb;
		$mappings = StudyConfig::get_crd_mappings( $study_id );
		$field_map = array();
		$auto_detected_messages = array();
		$missing_forms = array();

		foreach ( $mappings as $map ) {
			$form_id = absint( $map['form_id'] ?? 0 );
			if ( ! $form_id ) {
				continue;
			}

			$resolved = StudyConfig::resolve_study_id_field_id( $form_id, $map['study_id_field_id'] ?? 0 );
			$field_id = absint( $resolved['field_id'] ?? 0 );
			$source = (string) ( $resolved['source'] ?? 'missing' );

			if ( $field_id ) {
				$field_map[] = array(
					'field_id' => $field_id,
					'form_id' => $form_id,
				);
				if ( 'auto' === $source ) {
					$auto_detected_messages[] = sprintf(
						__( 'study_id detectado automáticamente (Form %1$d, Field ID %2$d)', CO360_SSA_TEXT_DOMAIN ),
						$form_id,
						$field_id
					);
				}
			} else {
				$missing_forms[] = $form_id;
			}
		}

		if ( empty( $field_map ) ) {
			$message = __( 'No se pudo contar CRDs: configura study_id Field ID o añade un campo hidden study_id detectable.', CO360_SSA_TEXT_DOMAIN );
			if ( ! empty( $missing_forms ) ) {
				$message .= ' ' . sprintf(
					__( 'Formularios afectados: %s.', CO360_SSA_TEXT_DOMAIN ),
					implode( ', ', array_map( 'absint', $missing_forms ) )
				);
			}
			return array(
				'count' => null,
				'message' => $message,
				'last_created_at' => '',
			);
		}

		$frm_items = $wpdb->prefix . 'frm_items';
		$frm_metas = $wpdb->prefix . 'frm_item_metas';
		$total = 0;
		$last_created = '';
		foreach ( $field_map as $fm ) {
			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$frm_items} fi INNER JOIN {$frm_metas} fm ON fm.item_id = fi.id WHERE fi.form_id = %d AND fm.field_id = %d AND fm.meta_value = %s",
					$fm['form_id'],
					$fm['field_id'],
					(string) $study_id
				)
			);
			$last = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(fi.created_at) FROM {$frm_items} fi INNER JOIN {$frm_metas} fm ON fm.item_id = fi.id WHERE fi.form_id = %d AND fm.field_id = %d AND fm.meta_value = %s",
					$fm['form_id'],
					$fm['field_id'],
					(string) $study_id
				)
			);
			if ( $last && ( ! $last_created || strtotime( $last ) > strtotime( $last_created ) ) ) {
				$last_created = $last;
			}
		}

		$message_parts = array();
		if ( ! empty( $auto_detected_messages ) ) {
			$message_parts[] = implode( ' · ', array_unique( $auto_detected_messages ) );
		}
		if ( ! empty( $missing_forms ) ) {
			$message_parts[] = sprintf(
				__( 'Configuración pendiente (sin study_id detectable) en forms: %s.', CO360_SSA_TEXT_DOMAIN ),
				implode( ', ', array_map( 'absint', $missing_forms ) )
			);
		}

		return array(
			'count' => $total,
			'message' => implode( ' ', $message_parts ),
			'last_created_at' => $last_created,
		);
	}

	public static function flush_study_cache( $study_id ) {
		$study_id = absint( $study_id );
		if ( ! $study_id ) {
			return;
		}
		delete_transient( 'co360_ssa_dash_' . $study_id );
	}
}
