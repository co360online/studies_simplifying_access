<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Context {
	public static function set_current_study_id( $study_id ) {
		$study_id = absint( $study_id );
		if ( ! $study_id ) {
			return;
		}
		if ( ! defined( 'CO360_SSA_CURRENT_STUDY_ID' ) ) {
			define( 'CO360_SSA_CURRENT_STUDY_ID', $study_id );
		}
		$GLOBALS['co360_ssa_current_study_id'] = $study_id;
	}

	public static function get_current_study_id() {
		if ( defined( 'CO360_SSA_CURRENT_STUDY_ID' ) ) {
			return absint( CO360_SSA_CURRENT_STUDY_ID );
		}
		if ( isset( $GLOBALS['co360_ssa_current_study_id'] ) ) {
			return absint( $GLOBALS['co360_ssa_current_study_id'] );
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ CO360_SSA_TOKEN_QUERY ] ?? $_POST[ CO360_SSA_TOKEN_QUERY ] ?? '' ) );
		if ( '' === $token ) {
			return 0;
		}
		$context = ( new Auth() )->get_context_by_token( $token );
		if ( ! $context ) {
			return 0;
		}
		return absint( $context['study_id'] ?? 0 );
	}
}
