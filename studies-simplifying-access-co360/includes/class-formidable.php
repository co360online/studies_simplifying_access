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
		add_action( 'frm_after_create_entry', array( $this, 'after_create_entry' ), 10, 2 );
		add_action( 'frm_after_create_entry', array( $this, 'handle_registration_after_entry' ), 30, 2 );
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

		$study_id = absint( $context['study_id'] );
		$enroll_form_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) );
		if ( ! $enroll_form_id || $form_id !== $enroll_form_id ) {
			return $errors;
		}

		$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_select_field_id', true ) );
		if ( ! $center_select_field_id ) {
			$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_field_id', true ) );
		}
		$center_other_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_other_field_id', true ) );
		$center_selection = '';
		if ( isset( $_POST['item_meta'][ $center_select_field_id ] ) ) {
			$center_selection = sanitize_text_field( wp_unslash( $_POST['item_meta'][ $center_select_field_id ] ) );
		}
		$center_other_name = '';
		if ( isset( $_POST['item_meta'][ $center_other_field_id ] ) ) {
			$center_other_name = sanitize_text_field( wp_unslash( $_POST['item_meta'][ $center_other_field_id ] ) );
		}
		$center_validation = $this->validate_center_selection( $study_id, $center_selection, $center_other_name );
		if ( is_wp_error( $center_validation ) ) {
			$errors['co360_ssa_center'] = $center_validation->get_error_message();
			return $errors;
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

		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
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

		$token = $this->get_token_from_request();
		if ( empty( $token ) ) {
			return;
		}
		$context = $this->auth->get_context_by_token( $token );
		if ( ! $context ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}

		$study_id = absint( $context['study_id'] );
		$study_form_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) );
		if ( ! $study_form_id || $study_form_id !== absint( $form_id ) ) {
			return;
		}

		$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_select_field_id', true ) );
		if ( ! $center_select_field_id ) {
			$center_select_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_field_id', true ) );
		}
		$center_other_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_other_field_id', true ) );
		$center_selection = $this->get_center_code_from_request( $entry_id, $center_select_field_id );
		$center_other_name = $this->get_center_code_from_request( $entry_id, $center_other_field_id );
		$center_row = $this->resolve_center_row( $study_id, $center_selection, $center_other_name, $user->ID );
		if ( ! $center_row ) {
			return;
		}

		$investigator_code = $this->generate_investigator_code( $study_id, $center_row['center_code'] );
		if ( ! $investigator_code ) {
			return;
		}

		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND estudio_id = %d",
				$user->ID,
				$study_id
			)
		);

		if ( $existing ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'user_id' => $user->ID,
				'estudio_id' => $study_id,
				'code_used' => sanitize_text_field( $context['code'] ),
				'center_id' => $center_row['id'],
				'center_code' => $center_row['center_code'],
				'center_name' => $center_row['center_name'],
				'investigator_code' => $investigator_code,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( class_exists( '\FrmEntryMeta' ) && method_exists( '\FrmEntryMeta', 'update_entry_meta' ) ) {
			\FrmEntryMeta::update_entry_meta( $entry_id, 0, $investigator_code, 'co360_ssa_investigator_code' );
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
}
