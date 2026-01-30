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
	}

	public function validate_entry( $errors, $values, $exclude ) {
		if ( ! class_exists( 'FrmEntry' ) ) {
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
		if ( $study_form_id && $study_form_id !== absint( $form_id ) ) {
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

		$study_page_id = absint( $meta['study_page_id'] );
		if ( $study_page_id ) {
			( new Redirect() )->safe_redirect( get_permalink( $study_page_id ) );
		}

		if ( ! empty( $meta['crd_url'] ) ) {
			( new Redirect() )->safe_redirect( $meta['crd_url'] );
		}
	}
}
