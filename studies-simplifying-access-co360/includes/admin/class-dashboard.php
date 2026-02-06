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
		$forms = is_array( $crd_info['forms'] ?? null ) ? $crd_info['forms'] : array();
		$debug_centers_enabled = isset( $_GET['ssa_debug'] ) && 'centers' === sanitize_text_field( wp_unslash( $_GET['ssa_debug'] ) );

		$center_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT center_code, center_name FROM {$centers_table} WHERE estudio_id = %d ORDER BY center_name ASC",
				$study_id
			),
			ARRAY_A
		);

		$investigator_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT center_code, COUNT(*) AS investigators_count FROM {$ins_table} WHERE estudio_id = %d GROUP BY center_code",
				$study_id
			),
			ARRAY_A
		);
		$investigator_counts_by_code = array();
		foreach ( $investigator_counts as $inv_row ) {
			$inv_code = trim( (string) ( $inv_row['center_code'] ?? '' ) );
			if ( '' !== $inv_code ) {
				$investigator_counts_by_code[ $inv_code ] = (int) ( $inv_row['investigators_count'] ?? 0 );
			}
		}

		$rows = array();
		$active_centers_count = 0;
		$centers_debug_rows = array();
		foreach ( $center_rows as $center_row ) {
			$center_code = trim( (string) ( $center_row['center_code'] ?? '' ) );
			$center_name = trim( (string) ( $center_row['center_name'] ?? '' ) );
			$label = trim( $center_name . ' (' . $center_code . ')' );
			$center_crd_data = $this->get_crd_count_for_center( $study_id, $center_code, $label, $forms, $debug_centers_enabled );
			$center_crd_count = (int) ( $center_crd_data['count'] ?? 0 );
			if ( $center_crd_count > 0 ) {
				$active_centers_count++;
			}

			$rows[] = array(
				'center_code' => $center_code,
				'center_name' => $center_name,
				'investigators_count' => (int) ( $investigator_counts_by_code[ $center_code ] ?? 0 ),
				'crds_count' => $center_crd_count,
			);

			if ( $debug_centers_enabled ) {
				$centers_debug_rows[] = array(
					'center_label' => $label,
					'count' => $center_crd_count,
					'entry_ids' => $center_crd_data['entry_ids'] ?? array(),
				);
			}
		}
		$center_rows = $rows;

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
			'active_centers_count' => $active_centers_count,
			'crd_sent_count' => $crd_info['count'],
			'crd_config_message' => $crd_info['message'],
			'last_enrollment_at' => $last_enrollment_at,
			'last_crd_at' => $crd_info['last_created_at'],
			'center_rows' => $center_rows,
			'centers_debug_rows' => $centers_debug_rows,
			'investigator_rows' => $investigator_rows,
		);

		set_transient( $key, $data, 60 );
		return $data;
	}

	private function get_crd_forms_config( $study_id ) {
		$mappings = StudyConfig::get_crd_mappings( $study_id );
		$forms = array();
		$auto_detected_messages = array();
		$missing_forms = array();

		foreach ( $mappings as $map ) {
			$form_id = absint( $map['form_id'] ?? 0 );
			if ( ! $form_id ) {
				continue;
			}

			$resolved = StudyConfig::resolve_study_id_field_id( $form_id, $map['study_id_field_id'] ?? 0 );
			$study_field_id = absint( $resolved['field_id'] ?? 0 );
			$source = (string) ( $resolved['source'] ?? 'missing' );
			if ( ! $study_field_id ) {
				$missing_forms[] = $form_id;
				continue;
			}

			if ( 'auto' === $source ) {
				$auto_detected_messages[] = sprintf(
					__( 'study_id detectado automáticamente (Form %1$d, Field ID %2$d)', CO360_SSA_TEXT_DOMAIN ),
					$form_id,
					$study_field_id
				);
			}

			$forms[] = array(
				'form_id' => $form_id,
				'study_id_field_id' => $study_field_id,
				'center_field_id' => absint( $map['center_field_id'] ?? 0 ),
				'center_code_field_id' => absint( $map['center_code_field_id'] ?? 0 ),
			);
		}

		return array(
			'forms' => $forms,
			'auto_detected_messages' => array_values( array_unique( $auto_detected_messages ) ),
			'missing_forms' => array_values( array_unique( $missing_forms ) ),
		);
	}

	private function get_crd_count_for_study( $study_id, $forms ) {
		global $wpdb;
		$frm_items = $wpdb->prefix . 'frm_items';
		$frm_metas = $wpdb->prefix . 'frm_item_metas';
		$total = 0;

		foreach ( $forms as $form ) {
			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT fi.id) FROM {$frm_items} fi INNER JOIN {$frm_metas} fm ON fm.item_id = fi.id WHERE fi.form_id = %d AND fm.field_id = %d AND fm.meta_value = %s",
					$form['form_id'],
					$form['study_id_field_id'],
					(string) $study_id
				)
			);
		}

		return $total;
	}

	private function get_crd_count_for_center( $study_id, $center_code, $center_label, $forms, $include_ids = false ) {
		global $wpdb;
		$frm_items = $wpdb->prefix . 'frm_items';
		$frm_metas = $wpdb->prefix . 'frm_item_metas';
		$total = 0;
		$entry_ids = array();

		foreach ( $forms as $form ) {
			$center_field_id = absint( $form['center_code_field_id'] ?? 0 );
			$center_value = (string) $center_code;
			if ( ! $center_field_id ) {
				$center_field_id = absint( $form['center_field_id'] ?? 0 );
				$center_value = (string) $center_label;
			}
			if ( ! $center_field_id || '' === trim( $center_value ) ) {
				continue;
			}

			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT fi.id)
					FROM {$frm_items} fi
					INNER JOIN {$frm_metas} ms ON ms.item_id = fi.id
					INNER JOIN {$frm_metas} mc ON mc.item_id = fi.id
					WHERE fi.form_id = %d
					AND ms.field_id = %d AND ms.meta_value = %s
					AND mc.field_id = %d AND mc.meta_value = %s",
					$form['form_id'],
					$form['study_id_field_id'],
					(string) $study_id,
					$center_field_id,
					$center_value
				)
			);

			if ( $include_ids ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT fi.id
						FROM {$frm_items} fi
						INNER JOIN {$frm_metas} ms ON ms.item_id = fi.id
						INNER JOIN {$frm_metas} mc ON mc.item_id = fi.id
						WHERE fi.form_id = %d
						AND ms.field_id = %d AND ms.meta_value = %s
						AND mc.field_id = %d AND mc.meta_value = %s
						LIMIT 20",
						$form['form_id'],
						$form['study_id_field_id'],
						(string) $study_id,
						$center_field_id,
						$center_value
					)
				);
				$entry_ids = array_merge( $entry_ids, array_map( 'absint', is_array( $ids ) ? $ids : array() ) );
			}
		}

		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );

		return array(
			'count' => $total,
			'entry_ids' => array_slice( $entry_ids, 0, 20 ),
		);
	}

	private function get_last_crd_created_at( $study_id, $forms ) {
		global $wpdb;
		$frm_items = $wpdb->prefix . 'frm_items';
		$frm_metas = $wpdb->prefix . 'frm_item_metas';
		$last_created = '';

		foreach ( $forms as $form ) {
			$last = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(fi.created_at) FROM {$frm_items} fi INNER JOIN {$frm_metas} fm ON fm.item_id = fi.id WHERE fi.form_id = %d AND fm.field_id = %d AND fm.meta_value = %s",
					$form['form_id'],
					$form['study_id_field_id'],
					(string) $study_id
				)
			);
			if ( $last && ( ! $last_created || strtotime( $last ) > strtotime( $last_created ) ) ) {
				$last_created = $last;
			}
		}

		return $last_created;
	}

	private function get_crd_count_info( $study_id ) {
		$form_config = $this->get_crd_forms_config( $study_id );
		$forms = $form_config['forms'];
		$auto_detected_messages = $form_config['auto_detected_messages'];
		$missing_forms = $form_config['missing_forms'];

		if ( empty( $forms ) ) {
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
				'forms' => array(),
			);
		}

		$message_parts = array();
		if ( ! empty( $auto_detected_messages ) ) {
			$message_parts[] = implode( ' · ', $auto_detected_messages );
		}
		if ( ! empty( $missing_forms ) ) {
			$message_parts[] = sprintf(
				__( 'Configuración pendiente (sin study_id detectable) en forms: %s.', CO360_SSA_TEXT_DOMAIN ),
				implode( ', ', array_map( 'absint', $missing_forms ) )
			);
		}

		return array(
			'count' => $this->get_crd_count_for_study( $study_id, $forms ),
			'message' => implode( ' ', $message_parts ),
			'last_created_at' => $this->get_last_crd_created_at( $study_id, $forms ),
			'forms' => $forms,
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
