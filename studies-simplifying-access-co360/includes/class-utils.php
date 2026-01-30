<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Utils {
	public static function defaults() {
		return array(
			'registration_form_id' => 0,
			'registration_page_url' => '',
			'enrollment_page_url' => '',
			'login_page_url' => '',
			'context_ttl' => 60,
		);
	}

	public static function get_options() {
		$stored = get_option( CO360_SSA_OPT_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	public static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[CO360_SSA] ' . $message );
		}
	}

	public static function sanitize_text( $value ) {
		return sanitize_text_field( wp_unslash( $value ) );
	}

	public static function sanitize_email( $value ) {
		return sanitize_email( wp_unslash( $value ) );
	}

	public static function sanitize_url( $value ) {
		return esc_url_raw( wp_unslash( $value ) );
	}

	public static function normalize_email( $email ) {
		return strtolower( trim( $email ) );
	}

	public static function create_or_get_user( $email ) {
		$email = self::normalize_email( $email );
		$user  = get_user_by( 'email', $email );
		if ( $user ) {
			return $user;
		}

		$username = self::generate_username_from_email( $email );
		$password = wp_generate_password( 20, true, true );

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return get_user_by( 'id', $user_id );
	}

	private static function generate_username_from_email( $email ) {
		$base = sanitize_user( strstr( $email, '@', true ), true );
		$base = $base ? $base : 'user';
		$username = $base;
		$index = 1;
		while ( username_exists( $username ) ) {
			$index++;
			$username = $base . $index;
		}
		return $username;
	}

	public static function get_study_meta( $study_id ) {
		return array(
			'prefijo' => get_post_meta( $study_id, '_co360_ssa_prefijo', true ),
			'regex' => get_post_meta( $study_id, '_co360_ssa_regex', true ),
			'crd_url' => get_post_meta( $study_id, '_co360_ssa_crd_url', true ),
			'activo' => get_post_meta( $study_id, '_co360_ssa_activo', true ),
			'enroll_form_id' => absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) ),
			'center_select_field_id' => absint( get_post_meta( $study_id, '_co360_ssa_center_select_field_id', true ) ),
			'center_other_field_id' => absint( get_post_meta( $study_id, '_co360_ssa_center_other_field_id', true ) ),
			'centers_seed' => get_post_meta( $study_id, '_co360_ssa_centers_seed', true ),
			'enroll_page_id' => absint( get_post_meta( $study_id, '_co360_ssa_enroll_page_id', true ) ),
			'code_mode' => get_post_meta( $study_id, '_co360_ssa_code_mode', true ),
			'lock_email' => get_post_meta( $study_id, '_co360_ssa_lock_email', true ),
			'study_page_id' => absint( get_post_meta( $study_id, '_co360_ssa_study_page_id', true ) ),
			'protected_pages' => get_post_meta( $study_id, '_co360_ssa_protected_pages', true ),
		);
	}

	public static function normalize_center_name( $name ) {
		$name = preg_replace( '/[\r\n\t]+/', ' ', (string) $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		return $name;
	}

	public static function center_slug( $name ) {
		$slug = remove_accents( strtolower( (string) $name ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( $slug, '-' );
	}

	public static function parse_centers_seed( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$centers = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( count( $parts ) > 1 ) {
				$code = $parts[0];
				$name = $parts[1] ?? '';
			} else {
				$code = '';
				$name = $parts[0];
			}
			$name = self::normalize_center_name( $name );
			if ( '' === $name ) {
				continue;
			}
			$centers[] = array(
				'code' => $code,
				'name' => $name,
			);
		}
		return $centers;
	}

	public static function get_centers_seed( $study_id ) {
		$raw = get_post_meta( $study_id, '_co360_ssa_centers_seed', true );
		return self::parse_centers_seed( $raw );
	}

	public static function get_centers_for_study( $study_id ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTERS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, center_code, center_name, center_slug FROM {$table} WHERE estudio_id = %d ORDER BY center_name ASC",
				$study_id
			),
			ARRAY_A
		);
		return $rows;
	}

	public static function get_debug_level() {
		if ( isset( $_GET[ CO360_SSA_DEBUG_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( $_GET[ CO360_SSA_DEBUG_QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return 0;
	}

	public static function get_redirect_target_for_study( $study_id ) {
		$options = self::get_options();
		$study_page_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_page_id', true ) );
		if ( $study_page_id ) {
			return get_permalink( $study_page_id );
		}
		if ( ! empty( $options['enrollment_page_url'] ) ) {
			return $options['enrollment_page_url'];
		}
		return $options['registration_page_url'];
	}

	public static function add_query_arg_token( $url, $token ) {
		return add_query_arg( CO360_SSA_TOKEN_QUERY, rawurlencode( $token ), $url );
	}
}
