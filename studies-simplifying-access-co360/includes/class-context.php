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

		if ( is_page() ) {
			$page_id = get_queried_object_id();
			if ( $page_id ) {
				$studies = get_posts(
					array(
						'post_type' => CO360_SSA_CT_STUDY,
						'numberposts' => -1,
						'fields' => 'ids',
						'meta_query' => array(
							array(
								'key' => '_co360_ssa_activo',
								'value' => '1',
							),
						),
					)
				);
				foreach ( $studies as $study_id ) {
					$protected_pages = get_post_meta( $study_id, '_co360_ssa_protected_pages', true );
					$protected_pages = is_array( $protected_pages ) ? array_map( 'absint', $protected_pages ) : array();
					if ( in_array( $page_id, $protected_pages, true ) ) {
						self::set_current_study_id( $study_id );
						return absint( $study_id );
					}
				}
			}
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ CO360_SSA_TOKEN_QUERY ] ?? $_POST[ CO360_SSA_TOKEN_QUERY ] ?? '' ) );
		if ( '' !== $token ) {
			$context = ( new Auth() )->get_context_by_token( $token );
			if ( $context ) {
				$study_id = absint( $context['study_id'] ?? 0 );
				if ( $study_id ) {
					self::set_current_study_id( $study_id );
					return $study_id;
				}
			}
		}

		return 0;
	}
}
