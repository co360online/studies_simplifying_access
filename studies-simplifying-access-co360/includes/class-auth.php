<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Auth {
	public function register() {
		add_action( 'init', array( $this, 'register_query_vars' ) );
	}

	public function register_query_vars() {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
	}

	public function add_query_var( $vars ) {
		$vars[] = CO360_SSA_TOKEN_QUERY;
		return $vars;
	}

	public function set_context_token( $email, $study_id, $code ) {
		$token = wp_hash( wp_generate_uuid4() . '|' . microtime( true ) );
		$payload = array(
			'email' => Utils::normalize_email( $email ),
			'study_id' => absint( $study_id ),
			'code' => sanitize_text_field( $code ),
			'ts' => time(),
		);
		$ttl = Utils::get_options()['context_ttl'] * MINUTE_IN_SECONDS;
		set_transient( 'co360_ssa_ctx_' . $token, $payload, $ttl );
		return $token;
	}

	public function get_context_by_token( $token ) {
		if ( empty( $token ) ) {
			return false;
		}
		$token = sanitize_text_field( $token );
		$data = get_transient( 'co360_ssa_ctx_' . $token );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}
		return $data;
	}

	public function validate_code_for_study( $study_id, $email, $code ) {
		$study_id = absint( $study_id );
		$meta = Utils::get_study_meta( $study_id );
		$code = trim( $code );
		$email = Utils::normalize_email( $email );

		if ( 'list' === $meta['code_mode'] ) {
			return $this->validate_list_code( $study_id, $email, $code, $meta['lock_email'] );
		}

		$has_prefix = ! empty( $meta['prefijo'] );
		$has_regex = ! empty( $meta['regex'] );

		if ( ! $has_prefix && ! $has_regex ) {
			return new \WP_Error( 'invalid_code', __( 'No hay reglas de código configuradas.', CO360_SSA_TEXT_DOMAIN ) );
		}

		// Regla: si existen prefijo y regex, se acepta si cumple cualquiera de los dos.
		$matches = false;
		if ( $has_prefix && 0 === stripos( $code, $meta['prefijo'] ) ) {
			$matches = true;
		}
		if ( ! $matches && $has_regex ) {
			$pattern = '/' . str_replace( '/', '\\/', $meta['regex'] ) . '/';
			$matches = (bool) preg_match( $pattern, $code );
		}
		if ( ! $matches ) {
			return new \WP_Error( 'invalid_code', __( 'El código no cumple las reglas del estudio.', CO360_SSA_TEXT_DOMAIN ) );
		}

		return true;
	}

	private function validate_list_code( $study_id, $email, $code, $lock_email ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CODES );
		$now = current_time( 'mysql' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE estudio_id = %d AND code = %s AND active = 1 LIMIT 1",
				$study_id,
				$code
			)
		);

		if ( ! $row ) {
			return new \WP_Error( 'invalid_code', __( 'Código inválido o inactivo.', CO360_SSA_TEXT_DOMAIN ) );
		}

		if ( $row->expires_at && $row->expires_at < $now ) {
			return new \WP_Error( 'expired_code', __( 'El código ha expirado.', CO360_SSA_TEXT_DOMAIN ) );
		}

		if ( $row->used_count >= $row->max_uses ) {
			return new \WP_Error( 'max_uses', __( 'El código ya alcanzó su límite de usos.', CO360_SSA_TEXT_DOMAIN ) );
		}

		if ( '1' === (string) $lock_email ) {
			if ( $row->assigned_email && Utils::normalize_email( $row->assigned_email ) !== $email ) {
				return new \WP_Error( 'email_locked', __( 'El código está bloqueado para otro email.', CO360_SSA_TEXT_DOMAIN ) );
			}
			if ( empty( $row->assigned_email ) ) {
				$wpdb->update(
					$table,
					array( 'assigned_email' => $email ),
					array( 'id' => $row->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		return true;
	}

	public function finalize_list_code_usage( $study_id, $code ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CODES );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used_count = used_count + 1, last_used_at = %s WHERE estudio_id = %d AND code = %s",
				current_time( 'mysql' ),
				$study_id,
				$code
			)
		);
	}
}
