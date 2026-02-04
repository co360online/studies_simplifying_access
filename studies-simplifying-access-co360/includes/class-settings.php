<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting( 'co360_ssa_group', CO360_SSA_OPT_KEY, array( $this, 'sanitize_options' ) );
		add_settings_section( 'co360_ssa_main', __( 'Ajustes principales', CO360_SSA_TEXT_DOMAIN ), '__return_false', 'co360-ssa' );

		add_settings_field( 'registration_form_id', __( 'Form ID de registro (Formidable)', CO360_SSA_TEXT_DOMAIN ), array( $this, 'field_registration_form_id' ), 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'registration_page_url', __( 'URL de la página de registro', CO360_SSA_TEXT_DOMAIN ), array( $this, 'field_registration_page_url' ), 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'enrollment_page_url', __( 'URL de la página de inscripción (global)', CO360_SSA_TEXT_DOMAIN ), array( $this, 'field_enrollment_page_url' ), 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'login_page_url', __( 'URL de la página de login (opcional)', CO360_SSA_TEXT_DOMAIN ), array( $this, 'field_login_page_url' ), 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'context_ttl', __( 'TTL del token (minutos)', CO360_SSA_TEXT_DOMAIN ), array( $this, 'field_context_ttl' ), 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'investigator_code_field_ids', __( 'Field IDs para investigator_code en CRDs (CSV)', CO360_SSA_TEXT_DOMAIN ), array( $this, 'field_investigator_code_field_ids' ), 'co360-ssa', 'co360_ssa_main' );
	}

	public function sanitize_options( $opts ) {
		$clean = Utils::get_options();
		$clean['registration_form_id'] = isset( $opts['registration_form_id'] ) ? absint( $opts['registration_form_id'] ) : 0;
		$clean['registration_page_url'] = isset( $opts['registration_page_url'] ) ? Utils::sanitize_url( $opts['registration_page_url'] ) : '';
		$clean['enrollment_page_url'] = isset( $opts['enrollment_page_url'] ) ? Utils::sanitize_url( $opts['enrollment_page_url'] ) : '';
		$clean['login_page_url'] = isset( $opts['login_page_url'] ) ? Utils::sanitize_url( $opts['login_page_url'] ) : '';
		$clean['context_ttl'] = isset( $opts['context_ttl'] ) ? max( 5, absint( $opts['context_ttl'] ) ) : 60;
		$field_ids = Utils::parse_field_ids_option( $opts['investigator_code_field_ids'] ?? '' );
		$clean['investigator_code_field_ids'] = $field_ids ? implode( ',', $field_ids ) : '';
		return $clean;
	}

	public function field_registration_form_id() {
		$options = Utils::get_options();
		printf(
			'<input type="number" name="%1$s[registration_form_id]" value="%2$s" class="small-text">',
			esc_attr( CO360_SSA_OPT_KEY ),
			esc_attr( $options['registration_form_id'] )
		);
	}

	public function field_registration_page_url() {
		$options = Utils::get_options();
		printf(
			'<input type="url" name="%1$s[registration_page_url]" value="%2$s" class="regular-text">',
			esc_attr( CO360_SSA_OPT_KEY ),
			esc_attr( $options['registration_page_url'] )
		);
	}

	public function field_enrollment_page_url() {
		$options = Utils::get_options();
		printf(
			'<input type="url" name="%1$s[enrollment_page_url]" value="%2$s" class="regular-text">',
			esc_attr( CO360_SSA_OPT_KEY ),
			esc_attr( $options['enrollment_page_url'] )
		);
	}

	public function field_login_page_url() {
		$options = Utils::get_options();
		printf(
			'<input type="url" name="%1$s[login_page_url]" value="%2$s" class="regular-text">',
			esc_attr( CO360_SSA_OPT_KEY ),
			esc_attr( $options['login_page_url'] )
		);
	}

	public function field_context_ttl() {
		$options = Utils::get_options();
		printf(
			'<input type="number" name="%1$s[context_ttl]" value="%2$s" class="small-text" min="5">',
			esc_attr( CO360_SSA_OPT_KEY ),
			esc_attr( $options['context_ttl'] )
		);
	}

	public function field_investigator_code_field_ids() {
		$options = Utils::get_options();
		printf(
			'<input type="text" name="%1$s[investigator_code_field_ids]" value="%2$s" class="regular-text" placeholder="87,102,155">',
			esc_attr( CO360_SSA_OPT_KEY ),
			esc_attr( $options['investigator_code_field_ids'] )
		);
	}
}
