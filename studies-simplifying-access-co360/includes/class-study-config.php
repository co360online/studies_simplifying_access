<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StudyConfig {
	private const CRD_META_KEY = '_co360_ssa_crd_mappings';
	private const CRD_STUDY_ID_FIELD_NAME = 'study_id';

	public static function get_crd_map( $study_id, $form_id ) {
		$study_id = absint( $study_id );
		$form_id = absint( $form_id );
		if ( ! $study_id || ! $form_id ) {
			return null;
		}
		$mappings = self::get_crd_mappings( $study_id );
		foreach ( $mappings as $mapping ) {
			if ( $form_id === (int) ( $mapping['form_id'] ?? 0 ) ) {
				return $mapping;
			}
		}
		return null;
	}

	public static function is_crd_form( $study_id, $form_id ) {
		return null !== self::get_crd_map( $study_id, $form_id );
	}

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
				'study_id_field_id' => absint( $row['study_id_field_id'] ?? 0 ),
			);
			$clean[] = $entry;
		}
		return $clean;
	}

	public static function detect_study_id_field_id( $form_id ) {
		global $wpdb;

		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return 0;
		}

		$fields_table = $wpdb->prefix . 'frm_fields';
		$field_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$fields_table} WHERE form_id = %d AND type = %s AND (LOWER(field_key) = %s OR LOWER(name) = %s) ORDER BY id ASC LIMIT 1",
				$form_id,
				'hidden',
				self::CRD_STUDY_ID_FIELD_NAME,
				self::CRD_STUDY_ID_FIELD_NAME
			)
		);

		return $field_id > 0 ? $field_id : 0;
	}

	public static function resolve_study_id_field_id( $form_id, $study_id_field_id = 0 ) {
		$manual_field_id = absint( $study_id_field_id );
		if ( $manual_field_id ) {
			return array(
				'field_id' => $manual_field_id,
				'source' => 'manual',
			);
		}

		$detected_field_id = self::detect_study_id_field_id( $form_id );
		if ( $detected_field_id ) {
			return array(
				'field_id' => $detected_field_id,
				'source' => 'auto',
			);
		}

		return array(
			'field_id' => 0,
			'source' => 'missing',
		);
	}
}
