<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formidable {
	private $auth;

	public function __construct( Auth $auth ) {
		$this->auth = $auth;
	}

	public function register() {
		add_filter( 'frm_validate_entry', array( $this, 'validate_entry' ), 10, 3 );
		add_filter( 'frm_setup_new_fields_vars', array( $this, 'centers_field_vars' ), 20, 2 );
		add_filter( 'frm_setup_edit_fields_vars', array( $this, 'centers_field_vars' ), 20, 2 );
		add_filter( 'frm_setup_new_fields_vars', array( $this, 'prepopulate_crd_fields' ), 20, 2 );
		add_filter( 'frm_setup_edit_fields_vars', array( $this, 'prepopulate_crd_fields' ), 20, 2 );
		add_action( 'frm_after_create_entry', array( $this, 'after_create_entry' ), 10, 2 );
		add_action( 'frm_after_create_entry', array( $this, 'crd_after_create_entry' ), 30, 2 );
		add_action( 'frm_after_create_entry', array( $this, 'handle_registration_after_entry' ), 30, 2 );
	}

	public function prepopulate_crd_fields( $values, $field ) {
		$study_id = Context::get_current_study_id();
		if ( ! $study_id ) {
			return $values;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $values;
		}

		$field_id = isset( $field->id ) ? (int) $field->id : 0;
		if ( ! $field_id ) {
			return $values;
		}

		$form_id = isset( $field->form_id ) ? (int) $field->form_id : 0;
		if ( ! $form_id ) {
			$form_id = isset( $values['form_id'] ) ? (int) $values['form_id'] : 0;
		}
		if ( ! $form_id ) {
			return $values;
		}

		$mapping = StudyConfig::get_crd_map( $study_id, $form_id );
		$row = $mapping ? $this->get_latest_enrollment_row( $user_id, $study_id ) : array();

		if ( 2 === Utils::get_debug_level() ) {
			$page_id = is_page() ? absint( get_queried_object_id() ) : 0;
			error_log( '[SSA CRD] page_id=' . $page_id . ' study_id=' . $study_id . ' user_id=' . $user_id . ' form_id=' . $form_id . ' field_id=' . $field_id . ' map_found=' . ( $mapping ? 1 : 0 ) . ' insc_found=' . ( empty( $row ) ? 0 : 1 ) );
		}

		if ( ! $mapping || empty( $row ) ) {
			return $values;
		}

		$value = '';
		if ( $field_id === (int) ( $mapping['investigator_code_field_id'] ?? 0 ) ) {
			$value = $row['investigator_code'];
		} elseif ( $field_id === (int) ( $mapping['center_field_id'] ?? 0 ) ) {
			$value = $this->format_center_label( $row['center_name'], $row['center_code'] );
		} elseif ( $field_id === (int) ( $mapping['center_code_field_id'] ?? 0 ) ) {
			$value = $row['center_code'];
		} elseif ( $field_id === (int) ( $mapping['code_used_field_id'] ?? 0 ) ) {
			$value = $row['code_used'];
		} else {
			$study_field_id = $this->get_crd_study_id_field_id( $form_id, $mapping );
			if ( $study_field_id && $field_id === $study_field_id ) {
				$value = (string) $study_id;
			}
		}

		if ( '' === $value ) {
			return $values;
		}
		if ( 2 === Utils::get_debug_level() ) {
			error_log( '[SSA CRD] fill form_id=' . $form_id . ' field_id=' . $field_id . ' value=' . $value );
		}

		$values['value'] = $value;
		$values['default_value'] = $value;

		return $values;
	}

	private function get_latest_enrollment_row( $user_id, $study_id ) {
		static $cached = array();
		$cache_key = $user_id . ':' . $study_id;
		if ( array_key_exists( $cache_key, $cached ) ) {
			return $cached[ $cache_key ];
		}

		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT investigator_code, center_code, center_name, code_used FROM {$table} WHERE user_id = %d AND estudio_id = %d ORDER BY created_at DESC LIMIT 1",
				$user_id,
				$study_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$cached[ $cache_key ] = array();
			return array();
		}

		$cached[ $cache_key ] = array(
			'investigator_code' => sanitize_text_field( $row['investigator_code'] ?? '' ),
			'center_code' => sanitize_text_field( $row['center_code'] ?? '' ),
			'center_name' => sanitize_text_field( $row['center_name'] ?? '' ),
			'code_used' => sanitize_text_field( $row['code_used'] ?? '' ),
		);

		return $cached[ $cache_key ];
	}

	private function format_center_label( $center_name, $center_code ) {
		$center_name = trim( (string) $center_name );
		$center_code = trim( (string) $center_code );
		if ( '' === $center_name && '' === $center_code ) {
			return '';
		}
		if ( '' !== $center_name && '' !== $center_code ) {
			return sprintf( '%s (%s)', $center_name, $center_code );
		}
		return $center_name ? $center_name : $center_code;
	}

	public function validate_entry( $errors, $values, $exclude ) {
		if ( ! class_exists( '\FrmEntry' ) ) {
			return $errors;
		}

		$form_id = isset( $values['form_id'] ) ? absint( $values['form_id'] ) : 0;
		$options = Utils::get_options();
		$registration_form_id = absint( $options['registration_form_id'] );
		if ( $registration_form_id && $form_id === $registration_form_id ) {
			return $errors;
		}

		$study_id = Context::get_current_study_id();
		$is_enrollment_form = false;
		$is_crd_form = false;
		if ( $study_id && $form_id ) {
			$enroll_form_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) );
			$is_enrollment_form = $enroll_form_id && $form_id === $enroll_form_id;
			$is_crd_form = StudyConfig::is_crd_form( $study_id, $form_id );
		}

		$user = wp_get_current_user();
		$user_id = $user && $user->ID ? (int) $user->ID : 0;

		if ( 2 === Utils::get_debug_level() ) {
			$access_ok = 0;
			if ( $study_id && $user_id ) {
				$access_ok = $this->user_is_enrolled_in_study( $user_id, $study_id ) ? 1 : 0;
			}
			Utils::log(
				sprintf(
					'Debug CRD validate: form_id=%d current_study_id=%d is_crd_form=%d is_enrollment_form=%d user_id=%d acceso_ok=%d',
					$form_id,
					$study_id,
					$is_crd_form ? 1 : 0,
					$is_enrollment_form ? 1 : 0,
					$user_id,
					$access_ok
				)
			);
		}

		if ( $is_crd_form ) {
			if ( ! $user_id || ! $this->user_is_enrolled_in_study( $user_id, $study_id ) ) {
				$errors['co360_ssa_access'] = __( 'No tienes acceso a este estudio.', CO360_SSA_TEXT_DOMAIN );
			}
			return $errors;
		}

		if ( ! $is_enrollment_form ) {
			return $errors;
		}

		$token = $this->get_token_from_request();
		if ( empty( $token ) ) {
			$errors['co360_ssa_context'] = __( 'Token inválido o expirado.', CO360_SSA_TEXT_DOMAIN );
			return $errors;
		}

		$context = $this->auth->get_context_by_token( $token );
		if ( ! $context ) {
			$errors['co360_ssa_context'] = __( 'Token inválido o expirado.', CO360_SSA_TEXT_DOMAIN );
			return $errors;
		}

		$context_study_id = absint( $context['study_id'] );
		if ( $context_study_id && $context_study_id !== $study_id ) {
			$study_id = $context_study_id;
		}

		$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_select_field_id', true ) );
		if ( ! $center_select_field_id ) {
			$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_field_id', true ) );
		}
		$center_other_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_other_field_id', true ) );
		$center_selection = sanitize_text_field( wp_unslash( $_POST['item_meta'][ $center_select_field_id ] ?? '' ) );
		$center_other_name = sanitize_text_field( wp_unslash( $_POST['item_meta'][ $center_other_field_id ] ?? '' ) );
		$center_other_normalized = preg_replace( '/\s+/', ' ', trim( $center_other_name ) );
		if ( 2 === Utils::get_debug_level() ) {
			Utils::log(
				sprintf(
					'Debug inscripción: select_field_id=%d other_field_id=%d selected=%s other=%s',
					$center_select_field_id,
					$center_other_field_id,
					$center_selection,
					$center_other_normalized
				)
			);
		}
		if ( 'other' === $center_selection ) {
			if ( strlen( $center_other_normalized ) < 3 ) {
				$errors[ $center_other_field_id ] = __( 'Escribe el nombre del centro (mínimo 3 caracteres).', CO360_SSA_TEXT_DOMAIN );
				return $errors;
			}
		} else {
			$center_validation = $this->validate_center_selection( $study_id, $center_selection, $center_other_normalized );
			if ( is_wp_error( $center_validation ) ) {
				$errors['co360_ssa_center'] = $center_validation->get_error_message();
				return $errors;
			}
		}
		if ( 2 === Utils::get_debug_level() ) {
			$center_valid = $this->get_center_by_code( $study_id, Utils::format_center_code( (string) $center_selection ) ) ? '1' : '0';
			Utils::log(
				sprintf(
					'Debug inscripción: token=%s study_id=%d center_field_id=%d selected=%s center_exists=%s',
					$token,
					$study_id,
					$center_select_field_id,
					$center_selection,
					$center_valid
				)
			);
		}

		if ( ! $user_id ) {
			$errors['co360_ssa_auth'] = __( 'Debes estar autenticado para inscribirte.', CO360_SSA_TEXT_DOMAIN );
			return $errors;
		}

		if ( Utils::normalize_email( $user->user_email ) !== Utils::normalize_email( $context['email'] ) ) {
			$errors['co360_ssa_email'] = __( 'El email del usuario no coincide con el acceso.', CO360_SSA_TEXT_DOMAIN );
		}

		return $errors;
	}

	/**
	 * Hook elegido: frm_after_create_entry es el punto estable post-creación para registrar inscripciones.
	 * Se usa porque garantiza que la entrada existe y evita depender de hooks de validación.
	 */
	public function after_create_entry( $entry_id, $form_id ) {
		if ( ! class_exists( '\FrmEntry' ) ) {
			return;
		}

		$options = Utils::get_options();
		$registration_form_id = absint( $options['registration_form_id'] );
		if ( $registration_form_id && $registration_form_id === absint( $form_id ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_POST[ CO360_SSA_TOKEN_QUERY ] ?? $_GET[ CO360_SSA_TOKEN_QUERY ] ?? '' ) );
		if ( '' === $token ) {
			return;
		}
		$context = $this->auth->get_context_by_token( $token );
		if ( ! $context ) {
			return;
		}

		$study_id = absint( $context['study_id'] );
		$study_form_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) );
		if ( ! $study_form_id || $study_form_id !== absint( $form_id ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$user = get_user_by( 'email', $context['email'] );
			$user_id = $user ? (int) $user->ID : 0;
		}
		if ( ! $user_id ) {
			return;
		}

		$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_select_field_id', true ) );
		if ( ! $center_select_field_id ) {
			$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_field_id', true ) );
		}
		$center_other_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_other_field_id', true ) );
		$center_selection = sanitize_text_field( wp_unslash( $_POST['item_meta'][ $center_select_field_id ] ?? '' ) );

		$center_code = '';
		$center_name = '';
		$center_id = 0;
		$center_other_normalized = '';

		if ( '' !== $center_selection && 'other' !== $center_selection ) {
			$center_code = $center_selection;
			$center_row = $this->get_center_by_code( $study_id, $center_code );
			if ( ! $center_row ) {
				Utils::log(
					sprintf(
						'Centro inválido en inscripción: study_id=%d center_code=%s',
						$study_id,
						$center_code
					)
				);
				return;
			}
			$center_name = sanitize_text_field( $center_row['center_name'] );
			$center_id = (int) $center_row['id'];
		} elseif ( 'other' === $center_selection ) {
			$center_other_raw = sanitize_text_field( wp_unslash( $_POST['item_meta'][ $center_other_field_id ] ?? '' ) );
			$center_other_normalized = preg_replace( '/\s+/', ' ', trim( $center_other_raw ) );
			$slug = Utils::center_slug( $center_other_normalized );
			$existing = $this->get_center_by_slug( $study_id, $slug );
			if ( $existing ) {
				$center_code = sanitize_text_field( $existing['center_code'] );
				$center_name = sanitize_text_field( $existing['center_name'] );
				$center_id = (int) $existing['id'];
			} else {
				$new_center_code = $this->next_center_code( $study_id );
				if ( ! $new_center_code ) {
					return;
				}
				$inserted = $this->insert_center( $study_id, $new_center_code, $center_other_normalized, $slug, 'user', $user_id );
				if ( ! $inserted ) {
					$existing = $this->get_center_by_slug( $study_id, $slug );
				}
				if ( ! $existing && $inserted ) {
					$existing = $this->get_center_by_slug( $study_id, $slug );
				}
				if ( ! $existing ) {
					return;
				}
				$center_code = sanitize_text_field( $existing['center_code'] );
				$center_name = sanitize_text_field( $existing['center_name'] );
				$center_id = (int) $existing['id'];
			}
		} else {
			return;
		}

		$investigator_code = $this->generate_investigator_code( $study_id, $center_code );
		if ( ! $investigator_code ) {
			return;
		}

		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND estudio_id = %d",
				$user_id,
				$study_id
			)
		);

		$insert_ok = false;
		if ( $existing_id ) {
			$updated = $wpdb->update(
				$table,
				array(
					'code_used' => sanitize_text_field( $context['code'] ),
					'center_id' => $center_id,
					'center_code' => $center_code,
					'center_name' => $center_name,
					'investigator_code' => $investigator_code,
					'entry_id' => (int) $entry_id,
					'created_at' => current_time( 'mysql' ),
				),
				array(
					'id' => (int) $existing_id,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			$insert_ok = false !== $updated;
		} else {
			$insert_ok = (bool) $wpdb->insert(
				$table,
				array(
					'user_id' => $user_id,
					'estudio_id' => $study_id,
					'code_used' => sanitize_text_field( $context['code'] ),
					'center_id' => $center_id,
					'center_code' => $center_code,
					'center_name' => $center_name,
					'investigator_code' => $investigator_code,
					'entry_id' => (int) $entry_id,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		$center_name_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_name_field_id', true ) );
		if ( $center_name_field_id > 0 ) {
			if ( class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'update_entry_meta' ) ) {
				\FrmEntryMeta::update_entry_meta( $entry_id, $center_name_field_id, null, $center_name );
			} else {
				update_post_meta( $entry_id, $center_name_field_id, $center_name );
			}
		}

		$investigator_code_field_id = absint( get_post_meta( $study_id, '_co360_ssa_investigator_code_field_id', true ) );
		if ( $investigator_code_field_id > 0 ) {
			if ( class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'update_entry_meta' ) ) {
				\FrmEntryMeta::update_entry_meta( $entry_id, $investigator_code_field_id, null, $investigator_code );
			} else {
				update_post_meta( $entry_id, $investigator_code_field_id, $investigator_code );
			}
		} elseif ( class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'update_entry_meta' ) ) {
			\FrmEntryMeta::update_entry_meta( $entry_id, 0, $investigator_code, 'co360_ssa_investigator_code' );
		}

		if ( 2 === Utils::get_debug_level() ) {
			Utils::log(
				sprintf(
					'Debug inscripción: token=%s study_id=%d user_id=%d selected=%s other=%s center_code=%s investigator_code=%s insert_ok=%s',
					$token,
					$study_id,
					$user_id,
					$center_selection,
					$center_other_normalized,
					$center_code,
					$investigator_code,
					$insert_ok ? '1' : '0'
				)
			);
		}

		$meta = Utils::get_study_meta( $study_id );
		if ( 'list' === $meta['code_mode'] ) {
			$this->auth->finalize_list_code_usage( $study_id, $context['code'] );
		}

		// Use page IDs (_co360_ssa_*_page_id) and get_permalink() to avoid URL inconsistencies.
		$study_page_id = absint( get_post_meta( $study_id, '_co360_ssa_study_page_id', true ) );
		$crd_url = (string) get_post_meta( $study_id, '_co360_ssa_crd_url', true );
		if ( $study_page_id > 0 ) {
			( new Redirect() )->safe_redirect( get_permalink( $study_page_id ) );
		}
		if ( ! empty( $crd_url ) ) {
			( new Redirect() )->safe_redirect( $crd_url );
		}
		( new Redirect() )->safe_redirect( home_url( '/' ) );
	}

	public function crd_after_create_entry( $entry_id, $form_id ) {
		$study_id = Context::get_current_study_id();
		if ( ! $study_id ) {
			return;
		}

		$map = StudyConfig::get_crd_map( $study_id, $form_id );
		if ( ! $map ) {
			if ( 2 === Utils::get_debug_level() ) {
				$page_id = is_page() ? absint( get_queried_object_id() ) : 0;
				error_log( '[SSA CRD] persist page_id=' . $page_id . ' study_id=' . $study_id . ' form_id=' . $form_id . ' map_found=0' );
			}
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id && class_exists( '\FrmEntry' ) ) {
			$entry = \FrmEntry::getOne( $entry_id );
			if ( $entry && isset( $entry->user_id ) ) {
				$user_id = absint( $entry->user_id );
			}
		}
		if ( ! $user_id ) {
			return;
		}

		$study_field_id = $this->get_crd_study_id_field_id( $form_id, $map );
		if ( $study_field_id ) {
			$this->force_entry_meta_value( $entry_id, $study_field_id, (string) $study_id );
		}

		$row = $this->get_latest_enrollment_row( $user_id, $study_id );
		if ( 2 === Utils::get_debug_level() ) {
			$page_id = is_page() ? absint( get_queried_object_id() ) : 0;
			error_log( '[SSA CRD] persist page_id=' . $page_id . ' study_id=' . $study_id . ' user_id=' . $user_id . ' form_id=' . $form_id . ' map_found=1 insc_found=' . ( empty( $row ) ? 0 : 1 ) );
		}
		if ( empty( $row ) ) {
			return;
		}

		$values = array(
			'investigator_code_field_id' => $row['investigator_code'],
			'center_field_id' => $this->format_center_label( $row['center_name'], $row['center_code'] ),
			'center_code_field_id' => $row['center_code'],
			'code_used_field_id' => $row['code_used'],
		);

		foreach ( $values as $map_key => $value ) {
			$field_id = absint( $map[ $map_key ] ?? 0 );
			if ( ! $field_id || '' === $value ) {
				continue;
			}
			$this->update_entry_meta_if_empty( $entry_id, $field_id, $value );
		}

		if ( 2 === Utils::get_debug_level() ) {
			error_log( '[SSA CRD] persist entry_id=' . $entry_id . ' form_id=' . $form_id . ' map=' . wp_json_encode( $map ) . ' values=' . wp_json_encode( $values ) );
		}
	}

	public function handle_registration_after_entry( $entry_id, $form_id ) {
		if ( ! class_exists( '\FrmEntry' ) ) {
			return;
		}

		$options = Utils::get_options();
		$registration_form_id = absint( $options['registration_form_id'] );
		if ( ! $registration_form_id || $registration_form_id !== absint( $form_id ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ CO360_SSA_TOKEN_QUERY ] ?? $_POST[ CO360_SSA_TOKEN_QUERY ] ?? '' ) );
		if ( empty( $token ) ) {
			return;
		}

		$context = $this->auth->get_context_by_token( $token );
		if ( ! $context ) {
			return;
		}

		$new_user_id = 0;
		$entry = \FrmEntry::getOne( $entry_id );
		if ( $entry && isset( $entry->user_id ) ) {
			$new_user_id = (int) $entry->user_id;
		}
		if ( ! $new_user_id ) {
			$user = get_user_by( 'email', $context['email'] );
			$new_user_id = $user ? (int) $user->ID : 0;
		}

		if ( ! $new_user_id ) {
			return;
		}

		$user_data = get_userdata( $new_user_id );
		if ( ! $user_data || Utils::normalize_email( $user_data->user_email ) !== Utils::normalize_email( $context['email'] ) ) {
			return;
		}

		wp_set_current_user( $new_user_id );
		wp_set_auth_cookie( $new_user_id, true );

		$after_url = add_query_arg(
			array(
				CO360_SSA_REDIRECT_FLAG => 'after_login',
				CO360_SSA_TOKEN_QUERY => $token,
			),
			home_url( '/' )
		);
		( new Redirect() )->safe_redirect( $after_url );
	}


	private function user_is_enrolled_in_study( $user_id, $study_id ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE user_id = %d AND estudio_id = %d LIMIT 1",
				$user_id,
				$study_id
			)
		);
		return (bool) $found;
	}

	private function get_center_code_from_values( $values, $field_id ) {
		if ( ! $field_id ) {
			return '';
		}
		$meta = $values['item_meta'] ?? array();
		if ( isset( $meta[ $field_id ] ) ) {
			return sanitize_text_field( wp_unslash( $meta[ $field_id ] ) );
		}
		return '';
	}

	public function centers_field_vars( $values, $field ) {
		if ( ! class_exists( '\FrmEntry' ) ) {
			return $values;
		}
		if ( empty( $field->type ) || ! in_array( $field->type, array( 'select', 'dropdown' ), true ) ) {
			return $values;
		}
		$form_id = 0;
		if ( isset( $field->form_id ) ) {
			$form_id = absint( $field->form_id );
		} elseif ( isset( $values['form_id'] ) ) {
			$form_id = absint( $values['form_id'] );
		}
		if ( ! $form_id ) {
			return $values;
		}
		$token = $this->get_token_from_request();
		if ( '' === $token ) {
			return $values;
		}
		$context = $this->auth->get_context_by_token( $token );
		if ( ! $context ) {
			return $values;
		}
		$study_id = absint( $context['study_id'] );
		$study_form_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) );
		if ( ! $study_form_id || $study_form_id !== $form_id ) {
			return $values;
		}
		$center_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_select_field_id', true ) );
		if ( ! $center_field_id ) {
			$center_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_field_id', true ) );
		}
		if ( ! $center_field_id || (int) $field->id !== $center_field_id ) {
			return $values;
		}
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTERS );
		$centers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT center_code, center_name FROM {$table} WHERE estudio_id = %d ORDER BY center_name ASC",
				$study_id
			),
			ARRAY_A
		);
		$options = array(
			'' => __( 'Selecciona un centro', CO360_SSA_TEXT_DOMAIN ),
		);
		foreach ( $centers as $center ) {
			$label = $center['center_name'] . ' (' . $center['center_code'] . ')';
			$options[ (string) $center['center_code'] ] = $label;
		}
		$options['other'] = __( 'Mi centro no está en la lista', CO360_SSA_TEXT_DOMAIN );
		$values['options'] = $options;
		// Formidable validates select values server-side; use_key + associative options ensure submitted value is accepted.
		$values['use_key'] = true;
		return $values;
	}

	private function get_center_code_from_request( $entry_id, $field_id ) {
		if ( $field_id && class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'get_entry_meta_by_field' ) ) {
			$value = \FrmEntryMeta::get_entry_meta_by_field( $entry_id, $field_id, true );
			if ( $value ) {
				return sanitize_text_field( $value );
			}
		}
		$meta = $_POST['item_meta'] ?? array();
		if ( $field_id && isset( $meta[ $field_id ] ) ) {
			return sanitize_text_field( wp_unslash( $meta[ $field_id ] ) );
		}
		return '';
	}

	private function get_token_from_request() {
		$token = sanitize_text_field( wp_unslash( $_POST[ CO360_SSA_TOKEN_QUERY ] ?? '' ) );
		if ( '' !== $token ) {
			return $token;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ CO360_SSA_TOKEN_QUERY ] ?? '' ) );
		if ( '' !== $token ) {
			return $token;
		}
		$referer = sanitize_text_field( wp_unslash( $_POST['_wp_http_referer'] ?? '' ) );
		if ( '' !== $referer ) {
			$parsed = wp_parse_url( $referer );
			if ( ! empty( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $query_vars );
				if ( ! empty( $query_vars[ CO360_SSA_TOKEN_QUERY ] ) ) {
					return sanitize_text_field( wp_unslash( $query_vars[ CO360_SSA_TOKEN_QUERY ] ) );
				}
			}
		}
		return '';
	}

	private function validate_center_selection( $study_id, $selection, $other_name ) {
		if ( empty( $selection ) ) {
			return new \WP_Error( 'center_required', __( 'Selecciona un centro de la lista.', CO360_SSA_TEXT_DOMAIN ) );
		}
		if ( 'other' === $selection ) {
			$name = Utils::normalize_center_name( $other_name );
			if ( strlen( $name ) < 3 ) {
				return new \WP_Error( 'center_other_required', __( 'Escribe el nombre del centro (mínimo 3 caracteres).', CO360_SSA_TEXT_DOMAIN ) );
			}
			return true;
		}
		$center_code = Utils::format_center_code( (string) $selection );
		if ( '' === $center_code ) {
			return new \WP_Error( 'center_invalid', __( 'Selecciona un centro válido de la lista.', CO360_SSA_TEXT_DOMAIN ) );
		}
		$center = $this->get_center_by_code( $study_id, $center_code );
		if ( ! $center ) {
			return new \WP_Error( 'center_invalid', __( 'Selecciona un centro válido de la lista.', CO360_SSA_TEXT_DOMAIN ) );
		}
		return true;
	}

	private function resolve_center_row( $study_id, $selection, $other_name, $user_id ) {
		if ( 'other' === $selection ) {
			$name = Utils::normalize_center_name( $other_name );
			if ( strlen( $name ) < 3 ) {
				return null;
			}
			$slug = Utils::center_slug( $name );
			$existing = $this->get_center_by_slug( $study_id, $slug );
			if ( $existing ) {
				return $existing;
			}
			$center_code = $this->next_center_code( $study_id );
			if ( ! $center_code ) {
				return null;
			}
			$inserted = $this->insert_center( $study_id, $center_code, $name, $slug, 'user', $user_id );
			if ( ! $inserted ) {
				return $this->get_center_by_slug( $study_id, $slug );
			}
			return $this->get_center_by_slug( $study_id, $slug );
		}

		$center_code = Utils::format_center_code( (string) $selection );
		if ( '' === $center_code ) {
			return null;
		}
		return $this->get_center_by_code( $study_id, $center_code );
	}

	private function get_center_by_id( $study_id, $center_id ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTERS );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, center_code, center_name FROM {$table} WHERE estudio_id = %d AND id = %d",
				$study_id,
				$center_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	private function get_center_by_code( $study_id, $center_code ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTERS );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, center_code, center_name FROM {$table} WHERE estudio_id = %d AND center_code = %s",
				$study_id,
				$center_code
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	private function get_center_by_slug( $study_id, $slug ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTERS );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, center_code, center_name FROM {$table} WHERE estudio_id = %d AND center_slug = %s",
				$study_id,
				$slug
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	private function insert_center( $study_id, $center_code, $center_name, $center_slug, $source, $created_by ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTERS );
		$inserted = $wpdb->insert(
			$table,
			array(
				'estudio_id' => $study_id,
				'center_code' => $center_code,
				'center_name' => $center_name,
				'center_slug' => $center_slug,
				'source' => $source,
				'created_by' => $created_by,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		return (bool) $inserted;
	}

	private function next_center_code( $study_id ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_STUDY_SEQ );
		for ( $attempts = 0; $attempts < 5; $attempts++ ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (estudio_id, last_center_num, updated_at)
					VALUES (%d, 0, NOW())
					ON DUPLICATE KEY UPDATE last_center_num = LAST_INSERT_ID(last_center_num + 1), updated_at = NOW()",
					$study_id
				)
			);
			$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
			$code = Utils::format_center_code( (string) $seq );
			if ( '' !== $code && Utils::is_center_code_valid( $code, $study_id ) ) {
				return $code;
			}
		}
		Utils::log( 'No se pudo generar center_code válido para estudio ' . (int) $study_id );
		return '';
	}

	private function generate_investigator_code( $study_id, $center_code ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTER_SEQ );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (estudio_id, center_code, last_seq, updated_at)
				VALUES (%d, %s, 0, NOW())
				ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1), updated_at = NOW()",
				$study_id,
				$center_code
			)
		);
		$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		$seq_str = Utils::format_investigator_seq( $seq );
		if ( '' === $seq_str ) {
			return '';
		}
		return $center_code . '-' . $seq_str;
	}

	private function get_crd_study_id_field_id( $form_id, $mapping ) {
		$field_id = absint( $mapping['study_id_field_id'] ?? 0 );
		if ( $field_id ) {
			return $field_id;
		}
		return $this->detect_study_id_field_id_by_key( $form_id );
	}

	private function detect_study_id_field_id_by_key( $form_id ) {
		if ( ! class_exists( '\FrmField' ) || ! method_exists( '\FrmField', 'get_all_for_form' ) ) {
			return 0;
		}
		$fields = \FrmField::get_all_for_form( absint( $form_id ), '', 'include' );
		if ( ! is_array( $fields ) ) {
			return 0;
		}
		foreach ( $fields as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$key = isset( $field->field_key ) ? strtolower( (string) $field->field_key ) : '';
			$name = isset( $field->name ) ? strtolower( (string) $field->name ) : '';
			if ( 'study_id' === $key || 'study_id' === $name ) {
				return absint( $field->id ?? 0 );
			}
		}
		return 0;
	}

	private function force_entry_meta_value( $entry_id, $field_id, $value ) {
		$value = sanitize_text_field( $value );
		if ( '' === $value || ! $field_id ) {
			return;
		}
		if ( class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'update_entry_meta' ) ) {
			\FrmEntryMeta::update_entry_meta( $entry_id, $field_id, null, $value );
			return;
		}
		$this->upsert_entry_meta_fallback( $entry_id, $field_id, $value );
	}

	private function update_entry_meta_if_empty( $entry_id, $field_id, $value ) {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return;
		}

		$existing = '';
		if ( class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'get_entry_meta_by_field' ) ) {
			$existing = (string) \FrmEntryMeta::get_entry_meta_by_field( $entry_id, $field_id, true );
		}
		if ( '' !== trim( $existing ) ) {
			return;
		}

		if ( class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'update_entry_meta' ) ) {
			\FrmEntryMeta::update_entry_meta( $entry_id, $field_id, null, $value );
			return;
		}

		$this->upsert_entry_meta_fallback( $entry_id, $field_id, $value );
	}

	private function upsert_entry_meta_fallback( $entry_id, $field_id, $value ) {
		global $wpdb;
		$table = $wpdb->prefix . 'frm_item_metas';
		$meta_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE item_id = %d AND field_id = %d LIMIT 1",
				$entry_id,
				$field_id
			)
		);
		if ( $meta_id ) {
			$wpdb->update(
				$table,
				array( 'meta_value' => $value ),
				array( 'id' => (int) $meta_id ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'item_id' => $entry_id,
				'field_id' => $field_id,
				'meta_value' => $value,
			),
			array( '%d', '%d', '%s' )
		);
	}

}
