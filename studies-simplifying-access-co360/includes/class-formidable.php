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
		if ( ! class_exists( 'FrmEntry' ) ) {
			return $errors;
		}

		$form_id = isset( $values['form_id'] ) ? absint( $values['form_id'] ) : 0;
		$options = Utils::get_options();
		$registration_form_id = absint( $options['registration_form_id'] );
		if ( $registration_form_id && $form_id === $registration_form_id ) {
			return $errors;
		}

		$token = isset( $values['co360_ssa_token'] ) ? sanitize_text_field( $values['co360_ssa_token'] ) : '';
		if ( empty( $token ) ) {
			return $errors;
		}

		$context = $this->auth->get_context_by_token( $token );
		if ( ! $context ) {
			$errors['co360_ssa_context'] = __( 'No se pudo validar el contexto de inscripción.', CO360_SSA_TEXT_DOMAIN );
			return $errors;
		}

		$study_id = absint( $context['study_id'] );
		$enroll_form_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) );
		if ( ! $enroll_form_id || $form_id !== $enroll_form_id ) {
			return $errors;
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
		if ( ! class_exists( 'FrmEntry' ) ) {
			return;
		}

		$options = Utils::get_options();
		$registration_form_id = absint( $options['registration_form_id'] );
		if ( $registration_form_id && $registration_form_id === absint( $form_id ) ) {
			return;
		}

		$token = isset( $_POST[ CO360_SSA_TOKEN_QUERY ] ) ? sanitize_text_field( wp_unslash( $_POST[ CO360_SSA_TOKEN_QUERY ] ) ) : '';
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
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

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
		if ( ! class_exists( 'FrmEntry' ) ) {
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
		$entry = FrmEntry::getOne( $entry_id );
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
}
