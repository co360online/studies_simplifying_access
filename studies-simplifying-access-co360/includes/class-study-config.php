<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StudyConfig {
	private const CRD_META_KEY = '_co360_ssa_crd_mappings';

	public static function get_crd_mappings( $study_id ) {
		$study_id = absint( $study_id );
		if ( ! $study_id ) {
			return array();
		}
		$raw = get_post_meta( $study_id, self::CRD_META_KEY, true );
		return self::sanitize_crd_mappings( $raw );
	}

	public static function sanitize_crd_mappings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$form_id = absint( $row['form_id'] ?? 0 );
			if ( ! $form_id ) {
				continue;
			}
			$entry = array(
				'form_id' => $form_id,
				'investigator_code_field_id' => absint( $row['investigator_code_field_id'] ?? 0 ),
				'center_field_id' => absint( $row['center_field_id'] ?? 0 ),
				'center_code_field_id' => absint( $row['center_code_field_id'] ?? 0 ),
				'code_used_field_id' => absint( $row['code_used_field_id'] ?? 0 ),
			);
			$clean[] = $entry;
		}
		return $clean;
	}
}
