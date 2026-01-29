<?php
/**
 * Plugin Name: Studies Simplifying Access (CO360)
 * Description: Acceso por código + email para inscribir usuarios en estudios sin listarlos. Soporta código único por estudio o listado de códigos por investigador. Integración con Formidable Forms Pro (registro/inscripción), login propio por shortcode y redirección a CRD. Incluye URL de inscripción por estudio y página de login personalizada. Redirecciones robustas con cookie + modo HOLD (&ssa_debug=2).
 * Version: 0.8.5.6
 * Author: CO360
 * Requires at least: 6.1
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CO360_SSA_Plugin {
	/* === Constantes === */
	const OPT_KEY       = 'co360_ssa_options';
	const CT_STUDY      = 'co360_estudio';
	const DB_TABLE      = 'co360_ssa_inscripciones';
	const DB_CODES      = 'co360_ssa_codigos';
	const TOKEN_QUERY   = 'co360_ssa_token';
	const REDIRECT_FLAG = 'co360_ssa';

	private static $instance = null;
	public static function instance(){ if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }

	public function __construct(){
		// Núcleo
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_query_vars' ) );

		// Ejecutar when query está parseada (importante para after_login)
		add_action( 'template_redirect', array( $this, 'maybe_handle_after_login' ), 1 );

		// Shortcodes
		add_shortcode( 'acceso_estudio', array( $this, 'shortcode_access' ) );
		add_shortcode( 'co360_ssa_form_context', array( $this, 'shortcode_form_context' ) );
		add_shortcode( 'co360_ssa_enrollment', array( $this, 'shortcode_enrollment' ) );

		// Admin
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_study_metabox' ) );
		add_action( 'save_post_' . self::CT_STUDY, array( $this, 'save_study_metabox' ) );
		add_filter( 'manage_edit-' . self::CT_STUDY . '_columns', array( $this, 'add_study_columns' ) );
		add_action( 'manage_' . self::CT_STUDY . '_posts_custom_column', array( $this, 'render_study_column' ) , 10, 2 );
		add_action( 'admin_post_co360_ssa_export_csv', array( $this, 'handle_export_csv' ) );

		// Upgrade DB si hace falta
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );

		// Permitir hosts de redirección externos (login/registro/inscripción externos)
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_reg_host' ) );

		// Activación
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
	}

	/* === Activación / Upgrade === */
	public static function activate(){
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$ins = $wpdb->prefix . self::DB_TABLE;
		$sql1 = "CREATE TABLE IF NOT EXISTS `$ins` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			estudio_id BIGINT UNSIGNED NOT NULL,
			code_used VARCHAR(255) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY estudio_id (estudio_id)
		) $charset;";
		dbDelta( $sql1 );

		$codes = $wpdb->prefix . self::DB_CODES;
		$sql2 = "CREATE TABLE IF NOT EXISTS `$codes` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			estudio_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(190) NOT NULL,
			max_uses INT UNSIGNED NOT NULL DEFAULT 1,
			used_count INT UNSIGNED NOT NULL DEFAULT 0,
			assigned_email VARCHAR(190) NULL,
			expires_at DATETIME NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY estudio_code (estudio_id, code),
			KEY estudio_id (estudio_id),
			KEY active (active),
			KEY expires_at (expires_at)
		) $charset;";
		dbDelta( $sql2 );

		update_option( 'co360_ssa_dbver', '2' );
	}

	public function maybe_upgrade_db(){
		$ver = get_option( 'co360_ssa_dbver', '0' );
		if ( version_compare( $ver, '2', '<' ) ){
			self::activate();
		}
	}

	/* === Permitir hosts de redirección === */
	public function allow_reg_host( $hosts ){
		$o = $this->get_options();
		foreach ( array( 'registration_page_url','enrollment_page_url','login_page_url' ) as $key ) {
			if ( ! empty( $o[$key] ) ) {
				$h = parse_url( $o[$key], PHP_URL_HOST );
				if ( $h ) { $hosts[] = $h; }
			}
		}
		return array_unique( array_filter( $hosts ) );
	}

	/* === CPT Estudio === */
	public function register_post_type(){
		$labels = array(
			'name' => 'Estudios',
			'singular_name' => 'Estudio',
			'add_new_item' => 'Añadir estudio',
			'edit_item' => 'Editar estudio',
			'menu_name' => 'Estudios'
		);
		$args = array(
			'labels' => $labels,
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'supports' => array( 'title' ),
			'has_archive' => false,
			'publicly_queryable' => false,
			'show_in_rest' => false
		);
		register_post_type( self::CT_STUDY, $args );
	}
	public function add_study_metabox(){ add_meta_box( 'co360_ssa_study_meta', 'Parámetros del estudio (CO360)', array( $this, 'render_study_metabox' ), self::CT_STUDY, 'normal', 'default' ); }
	public function render_study_metabox( $post ){
		wp_nonce_field( 'co360_ssa_study_meta', 'co360_ssa_study_meta_nonce' );
		$prefijo = get_post_meta( $post->ID, '_co360_ssa_prefijo', true );
		$regex   = get_post_meta( $post->ID, '_co360_ssa_regex', true );
		$crd_url = get_post_meta( $post->ID, '_co360_ssa_crd_url', true );
		$activo  = get_post_meta( $post->ID, '_co360_ssa_activo', true );
		$enroll_form_id = get_post_meta( $post->ID, '_co360_ssa_enroll_form_id', true );
		$enroll_page_url = get_post_meta( $post->ID, '_co360_ssa_enroll_page_url', true );
		$code_mode = get_post_meta( $post->ID, '_co360_ssa_code_mode', true ); // single|list
		$lock_to_email = get_post_meta( $post->ID, '_co360_ssa_lock_email', true );

		echo '<p><strong>Opción 1 – Prefijo del código</strong><br/><input type="text" name="co360_ssa_prefijo" value="'. esc_attr($prefijo) .'" class="regular-text" /></p>';
		echo '<p><strong>Opción 2 – Regex del código</strong><br/><input type="text" name="co360_ssa_regex" value="'. esc_attr($regex) .'" class="regular-text" /></p>';
		echo '<p><strong>URL del CRD</strong><br/><input type="url" name="co360_ssa_crd_url" value="'. esc_attr($crd_url) .'" class="regular-text" placeholder="https://..." /></p>';
		echo '<p><strong>Formulario de inscripción (Formidable) — opcional</strong><br/><input type="number" name="co360_ssa_enroll_form_id" value="'. esc_attr($enroll_form_id) .'" class="small-text" />';
		echo '<p><strong>URL de inscripción (este estudio)</strong><br/><input type="url" name="co360_ssa_enroll_page_url" value="'. esc_attr($enroll_page_url) .'" class="regular-text" placeholder="https://tusitio.com/inscripcion-estudio-X/" /></p>';
		echo '<p><strong>Modo de código</strong><br/>';
		echo '<select name="co360_ssa_code_mode">';
		echo '<option value="single"'. selected($code_mode,'single',false) .'>Único por estudio (prefijo/regex)</option>';
		echo '<option value="list"'. selected($code_mode,'list',false) .'>Listado individual por investigador</option>';
		echo '</select></p>';
		echo '<p><label><input type="checkbox" name="co360_ssa_lock_email" value="1" '. checked($lock_to_email,'1',false) .'> Bloquear código al primer email que lo use</label></p>';
		echo '<p><label><input type="checkbox" name="co360_ssa_activo" value="1" '. checked($activo,'1',false) .'> Estudio activo</label></p>';
	}
	public function save_study_metabox( $post_id ){
		if ( ! isset($_POST['co360_ssa_study_meta_nonce']) || ! wp_verify_nonce( $_POST['co360_ssa_study_meta_nonce'], 'co360_ssa_study_meta' ) ) { return; }
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		update_post_meta( $post_id, '_co360_ssa_prefijo', isset($_POST['co360_ssa_prefijo']) ? sanitize_text_field($_POST['co360_ssa_prefijo']) : '' );
		update_post_meta( $post_id, '_co360_ssa_regex', isset($_POST['co360_ssa_regex']) ? sanitize_text_field($_POST['co360_ssa_regex']) : '' );
		update_post_meta( $post_id, '_co360_ssa_crd_url', isset($_POST['co360_ssa_crd_url']) ? esc_url_raw($_POST['co360_ssa_crd_url']) : '' );
		update_post_meta( $post_id, '_co360_ssa_enroll_form_id', isset($_POST['co360_ssa_enroll_form_id']) ? intval($_POST['co360_ssa_enroll_form_id']) : 0 );
		update_post_meta( $post_id, '_co360_ssa_enroll_page_url', isset($_POST['co360_ssa_enroll_page_url']) ? esc_url_raw($_POST['co360_ssa_enroll_page_url']) : '' );
		update_post_meta( $post_id, '_co360_ssa_code_mode', isset($_POST['co360_ssa_code_mode']) && $_POST['co360_ssa_code_mode']=='list' ? 'list' : 'single' );
		update_post_meta( $post_id, '_co360_ssa_lock_email', isset($_POST['co360_ssa_lock_email']) ? '1' : '0' );
		update_post_meta( $post_id, '_co360_ssa_activo', isset($_POST['co360_ssa_activo']) ? '1' : '0' );
	}

	/* === Ajustes === */
	public function admin_menu(){
		add_options_page( 'Studies Simplifying Access', 'Studies Access (CO360)', 'manage_options', 'co360-ssa', array( $this, 'render_settings_page' ) );
		add_submenu_page( 'edit.php?post_type=' . self::CT_STUDY, 'Inscripciones', 'Inscripciones', 'manage_options', 'co360-ssa-enrollments', array( $this, 'render_enrollments_page' ) );
		add_submenu_page( 'edit.php?post_type=' . self::CT_STUDY, 'Códigos', 'Códigos', 'manage_options', 'co360-ssa-codes', array( $this, 'render_codes_page' ) );
	}
	public function register_settings(){
		register_setting( 'co360_ssa_group', self::OPT_KEY, array( $this, 'sanitize_options' ) );
		add_settings_section( 'co360_ssa_main', 'Ajustes principales', '__return_false', 'co360-ssa' );
		add_settings_field( 'registration_form_id', 'Form ID de registro (Formidable)', array( $this, 'field_registration_form_id' ) , 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'registration_page_url', 'URL de la página de registro', array( $this, 'field_registration_page_url' ) , 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'enrollment_page_url', 'URL de la página de inscripción (global)', array( $this, 'field_enrollment_page_url' ) , 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'login_page_url', 'URL de la página de login (opcional)', array( $this, 'field_login_page_url' ) , 'co360-ssa', 'co360_ssa_main' );
		add_settings_field( 'context_ttl', 'TTL del token (minutos)', array( $this, 'field_context_ttl' ) , 'co360-ssa', 'co360_ssa_main' );
	}
	public function sanitize_options( $opts ){
		$clean = $this->get_options();
		$clean['registration_form_id'] = isset($opts['registration_form_id']) ? intval($opts['registration_form_id']) : 0;
		$clean['registration_page_url'] = isset($opts['registration_page_url']) ? esc_url_raw($opts['registration_page_url']) : '';
		$clean['enrollment_page_url'] = isset($opts['enrollment_page_url']) ? esc_url_raw($opts['enrollment_page_url']) : '';
		$clean['login_page_url'] = isset($opts['login_page_url']) ? esc_url_raw($opts['login_page_url']) : '';
		$clean['context_ttl'] = isset($opts['context_ttl']) ? max(5, intval($opts['context_ttl'])) : 60;
		return $clean;
	}
	public function field_registration_form_id(){ $o=$this->get_options(); echo '<input type="number" name="'. self::OPT_KEY .'[registration_form_id]" value="'. esc_attr($o['registration_form_id']) .'" min="1" />'; }
	public function field_registration_page_url(){ $o=$this->get_options(); echo '<input type="url" class="regular-text" name="'. self::OPT_KEY .'[registration_page_url]" value="'. esc_attr($o['registration_page_url']) .'" placeholder="https://tusitio.com/registro-investigadores/" />'; }
	public function field_enrollment_page_url(){ $o=$this->get_options(); echo '<input type="url" class="regular-text" name="'. self::OPT_KEY .'[enrollment_page_url]" value="'. esc_attr($o['enrollment_page_url']) .'" placeholder="https://tusitio.com/inscripcion-estudio/" />'; }
	public function field_login_page_url(){ $o=$this->get_options(); echo '<input type="url" class="regular-text" name="'. self::OPT_KEY .'[login_page_url]" value="'. esc_attr($o['login_page_url']) .'" placeholder="https://tusitio.com/acceder/" />'; echo '<p class="description">Debe aceptar el parámetro <code>redirect_to</code> tras el login (o usa nuestro shortcode).</p>'; }
	public function field_context_ttl(){ $o=$this->get_options(); echo '<input type="number" name="'. self::OPT_KEY .'[context_ttl]" value="'. esc_attr($o['context_ttl']) .'" min="5" />'; }
	public function render_settings_page(){
		echo '<div class="wrap"><h1>Studies Simplifying Access (CO360)</h1><form method="post" action="options.php">';
		settings_fields('co360_ssa_group'); do_settings_sections('co360-ssa'); submit_button();
		echo '</form><hr/><h2>Instrucciones rápidas</h2><ol>';
		echo '<li>Página de inicio con <code>[acceso_estudio]</code>.</li>';
		echo '<li>Página de registro con Formidable + campo HTML <code>[co360_ssa_form_context]</code>.</li>';
		echo '<li>Página(s) de inscripción con <code>[co360_ssa_enrollment]</code> (una global o una por estudio en su metabox).</li>';
		echo '<li>Página de login (tuya o con <code>[co360_ssa_login]</code>).</li>';
		echo '<li>En cada Estudio: Prefijo/Regex, URL del CRD, (opcional) Form ID inscripción, (opcional) URL inscripción propia, Modo de código, Activo.</li>';
		echo '</ol></div>';
	}

	/* Public para uso desde closures */
	public function get_options(){
		$d = array(
			'registration_form_id' => 0,
			'registration_page_url'=> '',
			'enrollment_page_url'  => '',
			'login_page_url'       => '',
			'context_ttl'          => 60
		);
		return wp_parse_args( get_option(self::OPT_KEY, array()), $d );
	}

	/* Helper: cookie de destino tras login */
	private function set_after_cookie( $url ){
		if ( headers_sent() ) {
			error_log('CO360_SSA: Headers sent, no se pudo setear cookie after en ' . $_SERVER['REQUEST_URI']); // Log para debug
			return false;
		}
		$secure = is_ssl();
		$domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : parse_url(home_url(), PHP_URL_HOST); // Mejor compat multisite
		setcookie(
			'co360_ssa_after',
			rawurlencode( $url ),
			time() + 600,
			COOKIEPATH ? COOKIEPATH : '/',
			$domain,
			$secure,
			true
		);
		return true;
	}

	/* === Shortcodes === */
	public function shortcode_access( $atts ){
		$atts = shortcode_atts( array( 'title' => 'Acceso a estudios' ), $atts, 'acceso_estudio' );
		$err = isset( $_GET['ssa_error'] ) ? sanitize_text_field( $_GET['ssa_error'] ) : ''; // Para errores de vuelta

		ob_start();
		echo '<div class="co360-ssa-access"><h3>'. esc_html($atts['title']) .'</h3>';
		if($err){ echo '<div class="notice notice-error" style="padding:8px">'. esc_html($err) .'</div>'; }
		echo '<form method="post" action="'. esc_url( get_permalink() ) .'">'; wp_nonce_field('co360_ssa_access','co360_ssa_nonce');
		echo '<input type="hidden" name="co360_ssa_action" value="access" />';
		echo '<p><label>Código de acceso<br/><input type="text" name="co360_ssa_code" required style="width:320px;max-width:100%"/></label></p>';
		echo '<p><label>Email<br/><input type="email" name="co360_ssa_email" required style="width:320px;max-width:100%"/></label></p>';
		echo '<p><button type="submit" class="button button-primary">Continuar</button></p></form></div>';
		return ob_get_clean();
	}

	public function shortcode_form_context() {
		$t = isset($_GET[self::TOKEN_QUERY]) ? sanitize_text_field($_GET[self::TOKEN_QUERY]) : '';
		if (!$t) return '<!-- sin token -->';
		return '<input type="hidden" name="'. esc_attr(self::TOKEN_QUERY) .'" value="'. esc_attr($t) .'" />';
	}

	public function shortcode_enrollment() {
		$t = isset($_GET[self::TOKEN_QUERY]) ? sanitize_text_field($_GET[self::TOKEN_QUERY]) : '';
		$c = $this->get_context_by_token($t);
		if (!$c) return '<div class="notice notice-error" style="padding:8px">Token inválido o caducado.</div>';
		$sid = intval($c['study_id']);
		$fid = intval(get_post_meta($sid, '_co360_ssa_enroll_form_id', true));
		if (!$fid) return '<div class="notice notice-warning" style="padding:8px">Este estudio no requiere inscripción adicional.</div>';
		return do_shortcode('[formidable id="'. $fid .'" ]');
	}

	public function register_query_vars() {
		add_filter('query_vars', function($v) {
			$v[] = self::REDIRECT_FLAG;
			$v[] = self::TOKEN_QUERY;
			return $v;
		});
	}

	private function is_user_enrolled( $user_id, $study_id ) {
		global $wpdb;
		$ins = $wpdb->prefix . self::DB_TABLE;
		$cnt = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $ins WHERE user_id=%d AND estudio_id=%d", $user_id, $study_id) );
		return $cnt > 0;
	}

	public function maybe_handle_after_login() {
		$DBG = ( isset($_GET['ssa_debug']) && $_GET['ssa_debug'] == '1' && current_user_can('manage_options') );
		$HOLD = ( isset($_GET['ssa_debug']) && $_GET['ssa_debug'] == '2' && current_user_can('manage_options') );

		$flag = isset($_GET[self::REDIRECT_FLAG]) ? sanitize_text_field($_GET[self::REDIRECT_FLAG]) : get_query_var(self::REDIRECT_FLAG);
		if ( $flag !== 'after_login' ) return;

		$token = isset($_GET[self::TOKEN_QUERY]) ? sanitize_text_field($_GET[self::TOKEN_QUERY]) : get_query_var(self::TOKEN_QUERY);
		$ctx = $this->get_context_by_token($token);

		if ( ! $ctx ) {
			error_log('CO360_SSA: after_login invalid_token');
			co360_ssa_hard_redirect( home_url('/'), 'after_login:invalid_token' );
		}

		if ( ! is_user_logged_in() ) {
			$after = add_query_arg(array(self::REDIRECT_FLAG => 'after_login', self::TOKEN_QUERY => $token), home_url('/'));
			$o = $this->get_options();
			$login_url = ! empty($o['login_page_url']) ? add_query_arg( array( 'redirect_to' => rawurlencode($after) ), $o['login_page_url'] ) : wp_login_url($after);
			error_log('CO360_SSA: after_login no logged, redir to ' . $login_url);
			$this->set_after_cookie( $after );
			co360_ssa_hard_redirect( $login_url, 'after_login:login' );
		}

		$c = wp_get_current_user();
		if ( strtolower($c->user_email) !== strtolower($ctx['email']) ) {
			error_log('CO360_SSA: after_login email mismatch: ' . $c->user_email . ' vs ' . $ctx['email']);
			$lo = wp_logout_url( add_query_arg('ssa_error', 'email_mismatch', home_url('/')) );
			co360_ssa_hard_redirect( $lo, 'after_login:logout_mismatch' );
		}

		$study_id = intval($ctx['study_id']);
		$form_id = intval(get_post_meta($study_id, '_co360_ssa_enroll_form_id', true));
		$o = $this->get_options();

		$per_study_url = trim( get_post_meta($study_id, '_co360_ssa_enroll_page_url', true) );
		$global_url = ! empty($o['enrollment_page_url']) ? trim($o['enrollment_page_url']) : '';
		$enroll_url = $per_study_url ? $per_study_url : ( $global_url ? $global_url : ( $form_id ? home_url('/') : '' ) );

		$already = $this->is_user_enrolled( get_current_user_id(), $study_id );
		$crd = get_post_meta($study_id, '_co360_ssa_crd_url', true);
		if (!$crd) { $crd = home_url('/'); }

		if ( $DBG ) {
			echo '<div style="padding:16px;border:2px solid #999;border-radius:8px;margin:12px;font:14px/1.4 system-ui,Arial">';
			echo '<h2>CO360 SSA – AFTER_LOGIN DEBUG</h2>';
			echo '<p><strong>Token:</strong> ' . esc_html($token) . '</p>';
			echo '<p><strong>Logged as:</strong> ' . esc_html($c->user_login) . ' (' . esc_html($c->user_email) . ')</p>';
			echo '<p><strong>Context email:</strong> ' . esc_html($ctx['email']) . '</p>';
			echo '<p><strong>Study ID:</strong> ' . intval($study_id) . '</p>';
			echo '<p><strong>Form ID:</strong> ' . ($form_id ? $form_id : '—') . '</p>';
			echo '<p><strong>Enroll URL:</strong> ' . ($enroll_url ? esc_html($enroll_url) : '—') . '</p>';
			echo '<p><strong>Already enrolled?:</strong> ' . ($already ? 'Sí' : 'No') . '</p>';
			echo '<p><strong>CRD URL:</strong> ' . esc_html($crd) . '</p>';
			echo '<p>Quita <code>&ssa_debug=1</code> para volver al flujo normal, o usa <code>&ssa_debug=2</code> para ver la parada HOLD.</p>';
			echo '</div>';
		}

		if ( $already ) {
			error_log('CO360_SSA: after_login already enrolled, to CRD: ' . $crd);
			co360_ssa_hard_redirect( $crd, 'after_login:crd' );
		}

		if ( $form_id && ! empty($enroll_url) ) {
			$url = add_query_arg(array(self::TOKEN_QUERY => $token), $enroll_url);
			error_log('CO360_SSA: after_login to enroll: ' . $url);
			co360_ssa_hard_redirect( $url, 'after_login:enroll' );
		}

		$this->enroll_user_in_study( get_current_user_id(), $study_id, $ctx['code'] );
		error_log('CO360_SSA: after_login auto_enrolled, to CRD: ' . $crd);
		co360_ssa_hard_redirect( $crd, 'after_login:auto_enrolled_to_crd' );
	}

	private function match_study_by_code( $code, $email = '' ) {
		$code = trim((string)$code);
		if ($code === '') return false;
		global $wpdb;
		$codes_t = $wpdb->prefix . self::DB_CODES;
		$cache_key = 'co360_ssa_match_' . md5($code . $email);
		$cached = wp_cache_get($cache_key);
		if ( $cached !== false ) return $cached;

		$row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $codes_t WHERE code=%s", $code), ARRAY_A );
		if ( $row ) {
			$study = get_post( (int)$row['estudio_id'] );
			if ( $study && get_post_meta($study->ID, '_co360_ssa_activo', true) === '1' ) {
				if ( intval($row['active']) !== 1 ) {
					error_log("CO360_SSA: match_study_by_code failed: code=$code inactive");
					wp_cache_set($cache_key, false);
					return false;
				}
				if ( ! empty($row['expires_at']) && strtotime($row['expires_at']) < time() ) {
					error_log("CO360_SSA: match_study_by_code failed: code=$code expired at {$row['expires_at']}");
					wp_cache_set($cache_key, false);
					return false;
				}
				if ( intval($row['used_count']) >= max(1, intval($row['max_uses'])) ) {
					$user = is_user_logged_in() ? wp_get_current_user() : get_user_by('email', $email);
					$user_id = $user ? $user->ID : 0;
					$is_enrolled = $user_id && $this->is_user_enrolled($user_id, $study->ID);
					$lock = get_post_meta($study->ID, '_co360_ssa_lock_email', true) === '1';
					if ( $is_enrolled ) {
						error_log("CO360_SSA: match_study_by_code allowing used code=$code for user_id=$user_id (already enrolled)");
						wp_cache_set($cache_key, $study);
						return $study;
					}
					if ( $lock && ! empty($row['assigned_email']) && $email && strtolower($row['assigned_email']) === strtolower($email) ) {
						error_log("CO360_SSA: match_study_by_code allowing used code=$code for email=$email (lock match)");
						wp_cache_set($cache_key, $study);
						return $study;
					}
					error_log("CO360_SSA: match_study_by_code failed: code=$code max uses reached ({$row['used_count']}/{$row['max_uses']})");
					wp_cache_set($cache_key, false);
					return false;
				}
				$lock = get_post_meta($study->ID, '_co360_ssa_lock_email', true) === '1';
				if ( $lock && ! empty($row['assigned_email']) && $email && strtolower($row['assigned_email']) !== strtolower($email) ) {
					error_log("CO360_SSA: match_study_by_code failed: code=$code email mismatch (expected {$row['assigned_email']}, got $email)");
					wp_cache_set($cache_key, false);
					return false;
				}
				error_log("CO360_SSA: match_study_by_code success: code=$code, study_id={$study->ID} (list mode)");
				wp_cache_set($cache_key, $study);
				return $study;
			}
			error_log("CO360_SSA: match_study_by_code failed: code=$code, study_id={$row['estudio_id']} not active or not found");
		}

		$q = new WP_Query(array(
			'post_type' => self::CT_STUDY,
			'post_status' => 'any',
			'posts_per_page' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
			'meta_query' => array( array( 'key' => '_co360_ssa_activo', 'value' => '1', 'compare' => '=' ) )
		));
		if ( ! $q->have_posts() ) {
			error_log("CO360_SSA: match_study_by_code failed: no active studies for single mode");
			wp_cache_set($cache_key, false);
			return false;
		}
		$found = false;
		foreach ( $q->posts as $p ) {
			$regex = get_post_meta($p->ID, '_co360_ssa_regex', true);
			if ( $regex ) {
				$pattern = '#' . str_replace('#', '\#', $regex) . '#i';
				if ( @preg_match($pattern, $code) && preg_match($pattern, $code) ) {
					$found = get_post($p->ID);
					error_log("CO360_SSA: match_study_by_code success: code=$code, study_id={$p->ID} (regex match)");
					break;
				}
			}
		}
		if ( ! $found ) {
			foreach ( $q->posts as $p ) {
				$pref = get_post_meta($p->ID, '_co360_ssa_prefijo', true);
				if ( $pref && stripos($code, $pref) === 0 ) {
					$found = get_post($p->ID);
					error_log("CO360_SSA: match_study_by_code success: code=$code, study_id={$p->ID} (prefix match)");
					break;
				}
			}
		}
		if ( ! $found ) {
			error_log("CO360_SSA: match_study_by_code failed: code=$code, no prefix/regex match");
		}
		wp_cache_set($cache_key, $found);
		return $found;
	}

	private function set_context_token( $email, $study_id, $code ) {
		$o = $this->get_options();
		$ttl = max(5, intval($o['context_ttl']));
		$payload = array( 'email' => strtolower($email), 'study_id' => intval($study_id), 'code' => sanitize_text_field($code), 'ts' => time() );
		$token = wp_hash( wp_json_encode($payload) . '|' . wp_generate_uuid4() );
		set_transient('co360_ssa_ctx_' . $token, $payload, MINUTE_IN_SECONDS * $ttl );
		return $token;
	}

	private function get_context_by_token( $token ) {
		if (empty($token)) return false;
		$ctx = get_transient('co360_ssa_ctx_' . sanitize_text_field($token));
		return is_array($ctx) ? $ctx : false;
	}

	private function enroll_user_in_study( $user_id, $study_id, $code_used = '' ) {
		global $wpdb;
		$ins = $wpdb->prefix . self::DB_TABLE;
		$codes = $wpdb->prefix . self::DB_CODES;
		$exists = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $ins WHERE user_id=%d AND estudio_id=%d", $user_id, $study_id) );
		if ( ! $exists ) {
			$wpdb->insert($ins, array(
				'user_id' => $user_id,
				'estudio_id' => $study_id,
				'code_used' => $code_used,
				'created_at' => current_time('mysql')
			));
			error_log("CO360_SSA: enroll_user_in_study: user_id=$user_id, study_id=$study_id, code_used=$code_used");
		}
		if ( $code_used ) {
			$row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $codes WHERE estudio_id=%d AND code=%s", $study_id, $code_used ), ARRAY_A );
			if ( $row ) {
				$lock = get_post_meta($study_id, '_co360_ssa_lock_email', true) === '1';
				$upd = array(
					'used_count' => min(PHP_INT_MAX, intval($row['used_count']) + 1),
					'last_used_at' => current_time('mysql')
				);
				$user = false;
				if ( $lock ) {
					$user = get_userdata($user_id);
					if ( $user && empty($row['assigned_email']) ) {
						$upd['assigned_email'] = strtolower($user->user_email);
					}
				}
				$wpdb->update($codes, $upd, array( 'id' => intval($row['id']) ));
				$cache_key = 'co360_ssa_match_' . md5($code_used . ($user ? $user->user_email : ''));
				wp_cache_delete($cache_key);
				error_log("CO360_SSA: enroll_user_in_study: updated code=$code_used, study_id=$study_id, used_count={$upd['used_count']}, cache cleared");
			}
		}
		$ids = get_user_meta($user_id, '_co360_ssa_estudios', true);
		if ( ! is_array($ids) ) $ids = array();
		if ( ! in_array($study_id, $ids, true) ) {
			$ids[] = $study_id;
			update_user_meta($user_id, '_co360_ssa_estudios', $ids);
		}
	}

	public function add_study_columns( $c ) {
		$c['co360_ssa_inscritos'] = 'Inscritos';
		return $c;
	}

	public function render_study_column( $col, $post_id ) {
		if ($col !== 'co360_ssa_inscritos') return;
		global $wpdb;
		$ins = $wpdb->prefix . self::DB_TABLE;
		$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ins WHERE estudio_id=%d", $post_id));
		$url = add_query_arg(array('page' => 'co360-ssa-enrollments', 'post_type' => self::CT_STUDY, 'study_id' => $post_id), admin_url('edit.php'));
		echo '<a href="' . esc_url($url) . '">' . esc_html($count) . '</a>';
	}

	public function render_enrollments_page() {
		if ( ! current_user_can('manage_options') ) return;
		global $wpdb;
		$ins = $wpdb->prefix . self::DB_TABLE;

		$sid = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
		$s = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
		$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		$pp = 50;
		$offset = ($paged - 1) * $pp;

		$where = '1=1';
		$args = array();

		if ( $sid ) { $where .= ' AND e.estudio_id=%d'; $args[] = $sid; }
		if ( $s ) {
			$where .= ' AND (u.user_email LIKE %s OR u.display_name LIKE %s)';
			$like = '%' . $wpdb->esc_like($s) . '%';
			$args[] = $like;
			$args[] = $like;
		}

		$sql_total = "SELECT COUNT(*) FROM $ins e JOIN {$wpdb->users} u ON u.ID=e.user_id WHERE $where";
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare($sql_total, $args) ) : $wpdb->get_var( $sql_total ) );

		$sql_rows = "SELECT e.*, u.user_email, u.display_name
			FROM $ins e
			JOIN {$wpdb->users} u ON u.ID=e.user_id
			WHERE $where
			ORDER BY e.created_at DESC
			LIMIT %d OFFSET %d";
		$args_rows = $args;
		$args_rows[] = $pp;
		$args_rows[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql_rows, $args_rows ) );

		$studies = get_posts(array(
			'post_type' => self::CT_STUDY,
			'numberposts' => -1,
			'post_status' => 'any',
			'orderby' => 'title',
			'order' => 'ASC'
		));

		$nonce = wp_create_nonce('co360_ssa_export');
		$export = add_query_arg(array(
			'action' => 'co360_ssa_export_csv',
			'study_id' => $sid,
			's' => $s,
			'_wpnonce' => $nonce
		), admin_url('admin-post.php'));

		echo '<div class="wrap"><h1>Inscripciones a estudios</h1>';

		echo '<form method="get" style="margin-bottom:10px">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr(self::CT_STUDY) . '" />';
		echo '<input type="hidden" name="page" value="co360-ssa-enrollments" />';

		echo '<label>Estudio: <select name="study_id"><option value="0">Todos</option>';
		foreach ($studies as $st) {
			echo '<option value="' . esc_attr($st->ID) . '" ' . selected($sid, $st->ID, false) . '>' . esc_html($st->post_title) . '</option>';
		}
		echo '</select></label> ';

		echo '<label>Buscar: <input type="search" name="s" value="' . esc_attr($s) . '" /></label> ';
		echo '<button class="button">Filtrar</button> ';
		echo '<a class="button button-secondary" href="' . esc_url($export) . '">Exportar CSV</a>';
		echo '</form>';

		echo '<p><em>Mostrando ' . intval(count($rows)) . ' de ' . intval($total) . ' inscripciones</em></p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>ID</th><th>Usuario</th><th>Email</th><th>Estudio</th><th>Fecha</th><th>Código</th>';
		echo '</tr></thead><tbody>';

		if ( empty($rows) ) {
			echo '<tr><td colspan="6">No hay inscripciones.</td></tr>';
		} else {
			foreach ($rows as $r) {
				$t = get_the_title($r->estudio_id);
				echo '<tr>';
				echo '<td>' . intval($r->id) . '</td>';
				echo '<td>' . esc_html($r->display_name) . ' (ID ' . intval($r->user_id) . ')</td>';
				echo '<td>' . esc_html($r->user_email) . '</td>';
				echo '<td>' . esc_html($t) . ' (ID ' . intval($r->estudio_id) . ')</td>';
				echo '<td>' . esc_html($r->created_at) . '</td>';
				echo '<td>' . esc_html($r->code_used) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		if ( $total > $pp ) {
			$pages = (int) ceil($total / $pp);
			$base = remove_query_arg('paged');
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ($i = 1; $i <= $pages; $i++) {
				$u = add_query_arg('paged', $i, $base);
				$cls = $i == $paged ? 'button button-primary' : 'button';
				echo '<a class="' . $cls . '" href="' . esc_url($u) . '">' . $i . '</a> ';
			}
			echo '</div></div>';
		}

		echo '</div>';
	}

	public function handle_export_csv() {
		if ( ! current_user_can('manage_options') ) { wp_die('No permitido'); }
		check_admin_referer('co360_ssa_export');

		global $wpdb;
		$ins = $wpdb->prefix . self::DB_TABLE;
		$sid = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
		$s = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

		$where = '1=1';
		$args = array();
		if ($sid) { $where .= ' AND e.estudio_id=%d'; $args[] = $sid; }
		if ($s) {
			$like = '%' . $wpdb->esc_like($s) . '%';
			$where .= ' AND (u.user_email LIKE %s OR u.display_name LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		$sql = "SELECT e.*, u.user_email, u.display_name
		        FROM $ins e
		        JOIN {$wpdb->users} u ON u.ID=e.user_id
		        WHERE $where
		        ORDER BY e.created_at DESC";

		if ( ! empty($args) ) { $sql = $wpdb->prepare($sql, $args); }

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=inscripciones-' . ($sid ? $sid . '-' : '') . date('Ymd-His') . '.csv');

		$out = fopen('php://output', 'w');
		fputcsv($out, array('ID', 'Usuario', 'Email', 'Estudio', 'Fecha', 'Código'));

		foreach ($rows as $r) {
			$t = get_the_title($r['estudio_id']);
			fputcsv($out, array(
				$r['id'],
				$r['display_name'] . ' (ID ' . $r['user_id'] . ')',
				$r['user_email'],
				$t . ' (ID ' . $r['estudio_id'] . ')',
				$r['created_at'],
				$r['code_used']
			));
		}
		fclose($out);
		exit;
	}

/**
 * Constructs WHERE clause and arguments for codes table queries.
 *
 * @param int $sid Study ID filter.
 * @param string $q Search query for code or email.
 * @param string $status Filter by active/inactive status.
 * @param string $exp Filter by expiration status.
 * @param wpdb $wpdb WordPress database object.
 * @param string $codes Table name for codes.
 * @return array Array containing WHERE clause and arguments.
 */
private function codes_where_args( $sid, $q, $status, $exp, $wpdb, $codes ) {
    $where = '1=1';
    $args = array();
    if ( $sid ) {
        $where .= ' AND estudio_id = %d';
        $args[] = $sid;
    }
    if ( $q ) {
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $where .= ' AND (code LIKE %s OR assigned_email LIKE %s)';
        $args[] = $like;
        $args[] = $like;
    }
    if ( $status === 'active' ) {
        $where .= ' AND active = 1';
    } elseif ( $status === 'inactive' ) {
        $where .= ' AND active = 0';
    }
    if ( $exp === 'expired' ) {
        $where .= ' AND expires_at IS NOT NULL AND expires_at < NOW()';
    } elseif ( $exp === 'valid' ) {
        $where .= ' AND (expires_at IS NULL OR expires_at >= NOW())';
    }
    return array( $where, $args );
}

/**
 * Renders the admin page for managing codes.
 */
public function render_codes_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    global $wpdb;
    $codes = $wpdb->prefix . self::DB_CODES;

    // Input sanitization
    $sid = isset( $_REQUEST['study_id'] ) ? intval( $_REQUEST['study_id'] ) : 0;
    $act = isset( $_REQUEST['co360_codes_action'] ) ? sanitize_text_field( $_REQUEST['co360_codes_action'] ) : '';
    $q = isset( $_REQUEST['q'] ) ? sanitize_text_field( $_REQUEST['q'] ) : '';
    $status = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';
    $exp = isset( $_REQUEST['exp'] ) ? sanitize_text_field( $_REQUEST['exp'] ) : '';
    $pp = isset( $_REQUEST['per_page'] ) ? max( 10, min( 200, intval( $_REQUEST['per_page'] ) ) ) : 50;
    $paged = isset( $_REQUEST['paged'] ) ? max( 1, intval( $_REQUEST['paged'] ) ) : 1;
    $offset = ( $paged - 1 ) * $pp;
    $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : 'id';
    $order = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_text_field( $_REQUEST['order'] ) ) : 'DESC';
    $allowed_orderby = array( 'id', 'estudio_id', 'code', 'max_uses', 'used_count', 'assigned_email', 'expires_at', 'active', 'created_at', 'last_used_at' );
    if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
        $orderby = 'id';
    }
    if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
        $order = 'DESC';
    }

    $studies = get_posts( array(
        'post_type' => self::CT_STUDY,
        'numberposts' => -1,
        'post_status' => 'any',
        'orderby' => 'title',
        'order' => 'ASC'
    ) );

    // Bulk actions
    if ( isset( $_POST['bulk_action'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'co360_codes_bulk' ) ) {
        $bulk = sanitize_text_field( $_POST['bulk_action'] );
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : array();
        if ( $bulk && $ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            if ( $bulk === 'activate' ) {
                $wpdb->query( $wpdb->prepare( "UPDATE $codes SET active = 1 WHERE id IN ($placeholders)", $ids ) );
            } elseif ( $bulk === 'deactivate' ) {
                $wpdb->query( $wpdb->prepare( "UPDATE $codes SET active = 0 WHERE id IN ($placeholders)", $ids ) );
            } elseif ( $bulk === 'reset' ) {
                $wpdb->query( $wpdb->prepare( "UPDATE $codes SET used_count = 0, last_used_at = NULL WHERE id IN ($placeholders)", $ids ) );
            } elseif ( $bulk === 'delete' ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM $codes WHERE id IN ($placeholders)", $ids ) );
            }
        }
        wp_safe_redirect( add_query_arg( array( 'updated' => '1' ), remove_query_arg( array( 'bulk_action', 'ids' ) ) ) );
        exit;
    }

    // Row actions
    if ( isset( $_GET['do'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'co360_codes_row' ) ) {
        $row_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        $do = sanitize_text_field( $_GET['do'] );
        if ( $row_id ) {
            if ( $do === 'toggle' ) {
                $cur = $wpdb->get_var( $wpdb->prepare( "SELECT active FROM $codes WHERE id = %d", $row_id ) );
                $wpdb->update( $codes, array( 'active' => $cur ? 0 : 1 ), array( 'id' => $row_id ) );
            } elseif ( $do === 'reset' ) {
                $wpdb->update( $codes, array( 'used_count' => 0, 'last_used_at' => null ), array( 'id' => $row_id ) );
            } elseif ( $do === 'delete' ) {
                $wpdb->delete( $codes, array( 'id' => $row_id ) );
            }
        }
        wp_safe_redirect( remove_query_arg( array( 'do', 'id', '_wpnonce' ) ) );
        exit;
    }

    // CSV export
    if ( $act === 'export' && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'co360_codes_export' ) ) {
        list( $where, $args ) = $this->codes_where_args( $sid, $q, $status, $exp, $wpdb, $codes );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $codes WHERE $where ORDER BY %s %s", array_merge( $args, array( $orderby, $order ) ) ), ARRAY_A );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=codigos-' . ( $sid ? $sid . '-' : '' ) . date( 'Ymd-His' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'id', 'estudio_id', 'estudio_titulo', 'code', 'max_uses', 'used_count', 'assigned_email', 'expires_at', 'active', 'created_at', 'last_used_at' ) );
        foreach ( $rows as $r ) {
            $title = get_the_title( (int) $r['estudio_id'] );
            fputcsv( $out, array( $r['id'], $r['estudio_id'], $title, $r['code'], $r['max_uses'], $r['used_count'], $r['assigned_email'], $r['expires_at'], $r['active'], $r['created_at'], $r['last_used_at'] ) );
        }
        fclose( $out );
        exit;
    }

    // Import codes
    if ( $act === 'import' ) {
        $sid_post = isset( $_POST['study_id'] ) ? intval( $_POST['study_id'] ) : 0;
        if ( $sid_post ) {
            $sid = $sid_post;
        }
    }
    if ( $act === 'import' && check_admin_referer( 'co360_codes_import' ) ) {
        if ( ! $sid ) {
            echo '<div class="notice notice-error"><p>Debes seleccionar un estudio antes de importar códigos.</p></div>';
        } else {
            $raw = isset( $_POST['codes_csv'] ) ? wp_unslash( $_POST['codes_csv'] ) : '';
            $lines = preg_split( "/\r?\n/", trim( $raw ) );
            $count = 0;
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( $line === '' ) {
                    continue;
                }
                $parts = array_map( 'trim', explode( ',', $line ) );
                $code = $parts[0];
                if ( ! $code ) {
                    continue;
                }
                $max = isset( $parts[1] ) && is_numeric( $parts[1] ) ? intval( $parts[1] ) : 1;
                $exp_v = isset( $parts[2] ) && $parts[2] !== '' ? $parts[2] . ' 23:59:59' : null;
                $assigned = isset( $parts[3] ) ? sanitize_email( $parts[3] ) : null;
                $wpdb->replace( $codes, array(
                    'estudio_id' => $sid,
                    'code' => sanitize_text_field( $code ),
                    'max_uses' => max( 1, $max ),
                    'assigned_email' => $assigned ? $assigned : null,
                    'expires_at' => $exp_v,
                    'active' => 1,
                    'created_at' => current_time( 'mysql' )
                ) );
                $count++;
            }
            echo '<div class="updated notice"><p>Importados ' . intval( $count ) . ' códigos en el estudio ID ' . intval( $sid ) . '.</p></div>';
        }
    }

    // Fetch totals and rows
    list( $where, $args ) = $this->codes_where_args( $sid, $q, $status, $exp, $wpdb, $codes );
    list( $where_base, $args_base ) = $this->codes_where_args( $sid, $q, '', '', $wpdb, $codes );

    $total_all = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $codes WHERE $where_base", $args_base ) );
    $total_active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $codes WHERE $where_base AND active = 1", $args_base ) );
    $total_inactive = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $codes WHERE $where_base AND active = 0", $args_base ) );
    $total_expired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $codes WHERE $where_base AND expires_at IS NOT NULL AND expires_at < NOW()", $args_base ) );
    $total_valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $codes WHERE $where_base AND (expires_at IS NULL OR expires_at >= NOW())", $args_base ) );

    $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $codes WHERE $where", $args ) );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $codes WHERE $where ORDER BY %s %s LIMIT %d OFFSET %d", array_merge( $args, array( $orderby, $order, $pp, $offset ) ) ) );

    // Render sortable headers
    $h = function ( $label, $key ) use ( $orderby, $order ) {
        $next = ( $orderby === $key && $order === 'ASC' ) ? 'DESC' : 'ASC';
        $url = add_query_arg( array( 'orderby' => $key, 'order' => $next ) );
        $indicator = $orderby === $key ? ' ' . ( $order === 'ASC' ? '↑' : '↓' ) : '';
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $indicator . '</a>';
    };

    echo '<div class="wrap"><h1>Códigos por investigador</h1>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 16px 0">';
    echo '<div style="padding:8px 12px;border:1px solid #ddd;border-radius:8px"><strong>Total</strong>: ' . intval( $total_all ) . '</div>';
    echo '<div style="padding:8px 12px;border:1px solid #ddd;border-radius:8px"><strong>Activos</strong>: ' . intval( $total_active ) . '</div>';
    echo '<div style="padding:8px 12px;border:1px solid #ddd;border-radius:8px"><strong>Inactivos</strong>: ' . intval( $total_inactive ) . '</div>';
    echo '<div style="padding:8px 12px;border:1px solid #ddd;border-radius:8px"><strong>Vigentes</strong>: ' . intval( $total_valid ) . '</div>';
    echo '<div style="padding:8px 12px;border:1px solid #ddd;border-radius:8px"><strong>Caducados</strong>: ' . intval( $total_expired ) . '</div>';
    echo '</div>';

    // Filters (GET)
    echo '<form method="get" style="margin-bottom:8px">';
    echo '<input type="hidden" name="post_type" value="' . esc_attr( self::CT_STUDY ) . '"/>';
    echo '<input type="hidden" name="page" value="co360-ssa-codes"/>';
    echo '<label>Estudio: <select name="study_id"><option value="0">Todos</option>';
    foreach ( $studies as $st ) {
        echo '<option value="' . esc_attr( $st->ID ) . '" ' . selected( $sid, $st->ID, false ) . '>' . esc_html( $st->post_title ) . '</option>';
    }
    echo '</select></label> ';
    echo '<label>Búsqueda: <input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="código o email"/></label> ';
    echo '<label>Estado: <select name="status">';
    echo '<option value="" ' . selected( $status, '', false ) . '>Todos</option>';
    echo '<option value="active" ' . selected( $status, 'active', false ) . '>Activos</option>';
    echo '<option value="inactive" ' . selected( $status, 'inactive', false ) . '>Inactivos</option>';
    echo '</select></label> ';
    echo '<label>Caducidad: <select name="exp">';
    echo '<option value="" ' . selected( $exp, '', false ) . '>Todas</option>';
    echo '<option value="expired" ' . selected( $exp, 'expired', false ) . '>Caducados</option>';
    echo '<option value="valid" ' . selected( $exp, 'valid', false ) . '>Vigentes</option>';
    echo '</select></label> ';
    echo '<label>Por página: <select name="per_page">';
    foreach ( array( 25, 50, 100, 200 ) as $opt ) {
        echo '<option value="' . $opt . '" ' . selected( $pp, $opt, false ) . '>' . $opt . '</option>';
    }
    echo '</select></label> ';
    echo '<button class="button">Aplicar filtros</button> ';
    $export = add_query_arg( array(
        'post_type' => self::CT_STUDY,
        'page' => 'co360-ssa-codes',
        'study_id' => $sid,
        'q' => $q,
        'status' => $status,
        'exp' => $exp,
        'per_page' => $pp,
        'orderby' => $orderby,
        'order' => $order,
        'co360_codes_action' => 'export',
        '_wpnonce' => wp_create_nonce( 'co360_codes_export' )
    ), admin_url( 'edit.php' ) );
    echo ' <a class="button button-secondary" href="' . esc_url( $export ) . '">Exportar CSV</a>';
    echo '</form><hr/>';

    // Import form
    echo '<h2>Importar/pegar códigos</h2><p>Selecciona el estudio y pega los códigos. Formato por línea: <code>CODIGO[,max_uses][,YYYY-MM-DD][,email_asignado]</code></p>';
    echo '<form method="post">';
    wp_nonce_field( 'co360_codes_import' );
    echo '<input type="hidden" name="co360_codes_action" value="import" />';
    echo '<label>Estudio: <select name="study_id" required><option value="">— Selecciona —</option>';
    foreach ( $studies as $st ) {
        echo '<option value="' . esc_attr( $st->ID ) . '" ' . selected( $sid, $st->ID, false ) . '>' . esc_html( $st->post_title ) . '</option>';
    }
    echo '</select></label>';
    echo '<br/><textarea name="codes_csv" rows="6" style="width:100%" placeholder="INV-ABC1,1,2025-12-31,investigador@ejemplo.com"></textarea><br/>';
    echo '<button class="button button-primary">Importar</button></form><hr/>';

    echo '<h2>Listado</h2>';
    echo '<form method="post">';
    echo '<div class="tablenav top"><div class="alignleft actions">';
    wp_nonce_field( 'co360_codes_bulk' );
    echo '<select name="bulk_action"><option value="">Acciones masivas…</option><option value="activate">Activar</option><option value="deactivate">Desactivar</option><option value="reset">Reset usos</option><option value="delete">Eliminar</option></select> ';
    echo '<button class="button">Aplicar</button>';
    echo '</div></div>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<td style="width:24px"><input type="checkbox" onclick="const c=this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\');c.forEach(function(x){x.checked=this.checked}.bind(this))"/></td>';
    echo '<th>' . $h( 'ID', 'id' ) . '</th>';
    echo '<th>' . $h( 'Estudio', 'estudio_id' ) . '</th>';
    echo '<th>' . $h( 'Código', 'code' ) . '</th>';
    echo '<th>' . $h( 'Usos', 'used_count' ) . '</th>';
    echo '<th>' . $h( 'Asignado a', 'assigned_email' ) . '</th>';
    echo '<th>' . $h( 'Expira', 'expires_at' ) . '</th>';
    echo '<th>' . $h( 'Activo', 'active' ) . '</th>';
    echo '<th>' . $h( 'Último uso', 'last_used_at' ) . '</th>';
    echo '<th>' . $h( 'Creado', 'created_at' ) . '</th>';
    echo '<th>Acciones</th></tr></thead><tbody>';

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="12">No hay códigos.</td></tr>';
    } else {
        foreach ( $rows as $r ) {
            $t = get_the_title( $r->estudio_id );
            $nonce_row = wp_create_nonce( 'co360_codes_row' );
            $common = array(
                'post_type' => self::CT_STUDY,
                'page' => 'co360-ssa-codes',
                'study_id' => $sid,
                'q' => $q,
                'status' => $status,
                'exp' => $exp,
                'per_page' => $pp,
                'paged' => $paged,
                'orderby' => $orderby,
                'order' => $order,
                '_wpnonce' => $nonce_row
            );
            $toggle = add_query_arg( array_merge( $common, array( 'do' => 'toggle', 'id' => $r->id ) ), admin_url( 'edit.php' ) );
            $reset = add_query_arg( array_merge( $common, array( 'do' => 'reset', 'id' => $r->id ) ), admin_url( 'edit.php' ) );
            $delete = add_query_arg( array_merge( $common, array( 'do' => 'delete', 'id' => $r->id ) ), admin_url( 'edit.php' ) );
            echo '<tr>';
            echo '<td><input type="checkbox" name="ids[]" value="' . intval( $r->id ) . '"/></td>';
            echo '<td>' . intval( $r->id ) . '</td>';
            echo '<td>' . esc_html( $t ) . ' (ID ' . intval( $r->estudio_id ) . ')</td>';
            echo '<td><code>' . esc_html( $r->code ) . '</code></td>';
            echo '<td>' . intval( $r->used_count ) . '/' . intval( $r->max_uses ) . '</td>';
            echo '<td>' . esc_html( $r->assigned_email ) . '</td>';
            echo '<td>' . esc_html( $r->expires_at ) . '</td>';
            echo '<td>' . ( intval( $r->active ) ? 'Sí' : 'No' ) . '</td>';
            echo '<td>' . esc_html( $r->last_used_at ) . '</td>';
            echo '<td>' . esc_html( $r->created_at ) . '</td>';
            echo '<td><a href="' . esc_url( $toggle ) . '" class="button">' . ( intval( $r->active ) ? 'Desactivar' : 'Activar' ) . '</a> ';
            echo '<a href="' . esc_url( $reset ) . '" class="button">Reset usos</a> ';
            echo '<a href="' . esc_url( $delete ) . '" class="button" onclick="return confirm(\'¿Eliminar este código?\')">Eliminar</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></form>';

    // Bottom pagination
    if ( $total > $pp ) {
        $pages = ceil( $total / $pp );
        echo '<div class="tablenav"><div class="tablenav-pages">Página ' . $paged . ' de ' . $pages . ' ';
        if ( $paged > 1 ) {
            $prev = add_query_arg( 'paged', $paged - 1 );
            echo '<a class="button" href="' . esc_url( $prev ) . '">«</a> ';
        }
        for ( $i = 1; $i <= $pages && $i <= 1000; $i++ ) {
            $u = add_query_arg( 'paged', $i );
            $cls = $i == $paged ? 'button button-primary' : 'button';
            echo '<a class="' . $cls . '" href="' . esc_url( $u ) . '">' . $i . '</a> ';
        }
        if ( $paged < $pages ) {
            $next = add_query_arg( 'paged', $paged + 1 );
            echo '<a class="button" href="' . esc_url( $next ) . '">»</a>';
        }
        echo '</div></div>';
    }

    echo '</div>';
}

// Inicializar
CO360_SSA_Plugin::instance();

/** Evitar redirect canónico de WP cuando hay token (evita rebotes a home). */
add_filter('redirect_canonical', function( $redirect, $requested ){
	if ( isset($_GET[ CO360_SSA_Plugin::TOKEN_QUERY ]) && ! empty($_GET[ CO360_SSA_Plugin::TOKEN_QUERY ]) ) {
		return false;
	}
	return $redirect;
}, 1, 2);

/** Evitar que WP trate la URL con token como 404 (algunos temas redirigen 404→home). */
add_filter('pre_handle_404', function( $preempt, $wp_query ){
	if ( is_admin() ) return $preempt;
	if ( isset($_GET[ CO360_SSA_Plugin::TOKEN_QUERY ]) && ! empty($_GET[ CO360_SSA_Plugin::TOKEN_QUERY ]) ) {
		return true; // no manejar como 404
	}
	return $preempt;
}, 1, 2);

/** Seguridad: en la página de registro no hacemos redirecciones del flujo. */
add_action('template_redirect', function(){
	if ( is_admin() ) return;
	$o = get_option( CO360_SSA_Plugin::OPT_KEY, array() );
	$reg  = isset($o['registration_page_url']) ? trim($o['registration_page_url']) : '';
	if ( ! $reg ) return;
	$reg_id = url_to_postid( $reg );
	if ( $reg_id && is_page( $reg_id ) ) {
		return; // estamos en /registro/, no tocar nada
	}
}, 1);

/* ============================
   Redirecciones robustas (helper + capturador global + login_redirect)
   ============================ */

/** Helper universal de redirección con modo HOLD (&ssa_debug=2) */
function co360_ssa_hard_redirect( $url, $label = '' ){
	$url = $url ? wp_validate_redirect( $url, home_url('/') ) : home_url('/');

	// HOLD: parar y mostrar destino si ssa_debug=2 y admin
	if ( isset($_GET['ssa_debug']) && $_GET['ssa_debug'] == '2' && current_user_can('manage_options') ) {
		nocache_headers();
		$html  = '<div style="font-family:system-ui,Arial;max-width:680px;margin:40px auto;padding:20px;border:2px solid #999;border-radius:10px">';
		$html .= '<h2>CO360 SSA – REDIRECT HOLD</h2>';
		if ($label) { $html .= '<p><strong>Motivo:</strong> '. esc_html($label) .'</p>'; }
		$html .= '<p><strong>Destino:</strong><br><code>'. esc_html($url) .'</code></p>';
		$html .= '<p><a class="button button-primary" style="display:inline-block;padding:8px 12px;background:#2271b1;color:#fff;border-radius:6px;text-decoration:none" href="'. esc_url($url) .'">Continuar</a></p>';
		$html .= '<p style="color:#666">Quita <code>&ssa_debug=2</code> para volver al flujo normal.</p>';
		$html .= '</div>';
		wp_die( $html, 'CO360 SSA', array('response'=>200) );
	}

	// Flujo normal
	error_log('CO360_SSA: Hard redirect to ' . $url . ' (' . $label . ')'); // Log para trace
	if ( ! headers_sent() ) {
		wp_safe_redirect( $url );
		exit;
	}
	echo '<script>location.replace('. json_encode($url) .');</script>';
	echo '<noscript><meta http-equiv="refresh" content="0;url='. esc_attr($url) .'"></noscript>';
	exit;
}

/** Capturador global por cookie: fuerza ir al after_login aunque otros plugins cambien redirect_to */
add_action('template_redirect', function(){
	// Sólo front, usuario ya logueado y cookie presente
	if ( is_admin() || ! is_user_logged_in() || empty($_COOKIE['co360_ssa_after']) ) return;

	// Evita bucle si ya estamos en el after_login
	$req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
	if ( stripos($req, 'co360_ssa=after_login') !== false ) return;

	$dest = rawurldecode( $_COOKIE['co360_ssa_after'] );
	$dest = $dest ? wp_validate_redirect( $dest, home_url('/') ) : '';

	if ( $dest ) {
		// Limpia cookie y redirige
		setcookie('co360_ssa_after','', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : parse_url(home_url(), PHP_URL_HOST), is_ssl(), true);
		error_log('CO360_SSA: Cookie capturada, redir to ' . $dest);
		co360_ssa_hard_redirect( $dest, 'cookie_after' );
	}
}, 0);

/** Refuerzo por login_redirect (si el login usa flujo nativo) */
add_filter('login_redirect', function($redirect_to, $requested, $user){
	if ( ! empty($_COOKIE['co360_ssa_after']) ) {
		$dest = rawurldecode( $_COOKIE['co360_ssa_after'] );
		$dest = $dest ? wp_validate_redirect( $dest, home_url('/') ) : '';
		if ( $dest ) {
			error_log('CO360_SSA: Override login_redirect to ' . $dest);
			return $dest;
		}
	}
	return $redirect_to;
}, 9999, 3);


/* === Integración Formidable: Registro === */
add_action( 'frm_after_create_entry', function( $entry_id, $form_id ){
	$plugin=CO360_SSA_Plugin::instance();
	$o = $plugin->get_options();

	$target = isset($o['registration_form_id'])? intval($o['registration_form_id']) : 0;
	If( ! $target || $form_id!=$target ) return;
	if( empty($_POST[ CO360_SSA_Plugin::TOKEN_QUERY ]) ) return;
	$token=sanitize_text_field($_POST[ CO360_SSA_Plugin::TOKEN_QUERY ]);

	$ref=new ReflectionClass($plugin);
	$m=$ref->getMethod('get_context_by_token'); $m->setAccessible(true);
	$ctx=$m->invoke($plugin,$token); if(!$ctx) return;

	$user_id=0;
	if(function_exists('frm_get_user_id_from_entry')){ $user_id=(int)frm_get_user_id_from_entry($entry_id); }
	if(!$user_id && is_user_logged_in()){ $user_id=get_current_user_id(); }
	if(!$user_id){ $u=get_user_by('email',$ctx['email']); if($u){$user_id=$u->ID;} }
	if(!$user_id) return;

	$sid=intval($ctx['study_id']);
	$fid=intval(get_post_meta($sid,'_co360_ssa_enroll_form_id',true));

	if( ! is_user_logged_in() ){ wp_set_current_user($user_id); wp_set_auth_cookie($user_id); }

	if( $fid ){
		$per_study_url = trim( get_post_meta($sid,'_co360_ssa_enroll_page_url',true) );
		$global_url    = ! empty($o['enrollment_page_url']) ? trim($o['enrollment_page_url']) : '';
		$enroll_url    = $per_study_url ? $per_study_url : $global_url;

		if ( ! empty($enroll_url) ){
			$url=add_query_arg(array( CO360_SSA_Plugin::TOKEN_QUERY=>$token ), $enroll_url);
			co360_ssa_hard_redirect( $url, 'frm_after_create_entry:to_enroll' );
		}
	}

	$m2=$ref->getMethod('enroll_user_in_study'); $m2->setAccessible(true);
	$m2->invoke($plugin,$user_id,$sid,$ctx['code']);
	$crd=get_post_meta($sid,'_co360_ssa_crd_url',true);
	if($crd){ co360_ssa_hard_redirect($crd, 'frm_after_create_entry:to_crd'); }
}, 30, 2 );

/* === Formidable: Inscripción por estudio === */
add_action( 'frm_before_create_entry', function( $form_id, $exclude, $vals ){
	$study=get_posts(array('post_type'=>CO360_SSA_Plugin::CT_STUDY,'numberposts'=>1,'post_status'=>'any','meta_query'=>array( array( 'key'=>'_co360_ssa_enroll_form_id','value'=>$form_id,'compare'=>'=' ) )));
	if(empty($study)) return;
	$sid=$study[0]->ID;
	$token= isset($_POST[ CO360_SSA_Plugin::TOKEN_QUERY ])? sanitize_text_field($_POST[ CO360_SSA_Plugin::TOKEN_QUERY ]) : '';

	$plugin=CO360_SSA_Plugin::instance(); $ref=new ReflectionClass($plugin);
	$m=$ref->getMethod('get_context_by_token'); $m->setAccessible(true);
	$ctx=$m->invoke($plugin,$token);
	if( ! $ctx || intval($ctx['study_id'])!==intval($sid) ){
		add_filter('frm_validate_entry', function($e){ $e['co360_ssa']='No se pudo validar el contexto de inscripción.'; return $e; });
		return;
	}
	if( ! is_user_logged_in() ){
		add_filter('frm_validate_entry', function($e){ $e['co360_ssa']='Debes estar autenticado para completar la inscripción.'; return $e; });
		return;
	}
	$c=wp_get_current_user();
	if( strtolower($c->user_email)!==strtolower($ctx['email']) ){
		add_filter('frm_validate_entry', function($e){ $e['co360_ssa']='El usuario autenticado no coincide con el email de acceso.'; return $e; });
		return;
	}
}, 10, 3 );
add_action( 'frm_after_create_entry', function( $entry_id, $form_id ){
	$study=get_posts(array('post_type'=>CO360_SSA_Plugin::CT_STUDY,'numberposts'=>1,'post_status'=>'any','meta_query'=>array( array( 'key'=>'_co360_ssa_enroll_form_id','value'=>$form_id,'compare'=>'=' ) )));
	if(empty($study)) return;
	$sid=$study[0]->ID;
	$token= isset($_POST[ CO360_SSA_Plugin::TOKEN_QUERY ])? sanitize_text_field($_POST[ CO360_SSA_Plugin::TOKEN_QUERY ]) : '';

	$plugin=CO360_SSA_Plugin::instance(); $ref=new ReflectionClass($plugin);
	$m=$ref->getMethod('get_context_by_token'); $m->setAccessible(true);
	$ctx=$m->invoke($plugin,$token); if( ! $ctx ) return;
	$uid=get_current_user_id(); if(! $uid) return;

	$m2=$ref->getMethod('enroll_user_in_study'); $m2->setAccessible(true);
	$m2->invoke($plugin,$uid,$sid,$ctx['code']);
	$crd=get_post_meta($sid,'_co360_ssa_crd_url',true);
	if($crd){ co360_ssa_hard_redirect($crd, 'frm_after_create_entry:to_crd2'); }
}, 40, 2 );

/* === Hardening / SEO === */
add_action( 'template_redirect', function(){ if( is_singular( CO360_SSA_Plugin::CT_STUDY ) ){ global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); include get_query_template('404'); exit; } });
add_action( 'wp_head', function(){ if( is_singular( CO360_SSA_Plugin::CT_STUDY ) ){ echo "<meta name=\"robots\" content=\"noindex,nofollow\">\n"; } });

/* === Shortcode de métricas === */
add_shortcode('co360_ssa_stats', function($atts){
	$a = shortcode_atts(array('study_id'=>0,'days'=>30,'chart'=>'line','form_id'=>0,'show_totals'=>1), $atts, 'co360_ssa_stats');
	$sid   = intval($a['study_id']);
	if(!$sid){ return '<div class="notice notice-error" style="padding:8px">Debes indicar <code>study_id</code>.</div>'; }
	$days  = max(1, intval($a['days']));
	$chart = in_array($a['chart'], array('line','bar','none'), true) ? $a['chart'] : 'line';
	$form  = intval($a['form_id']);
	$totals= intval($a['show_totals']) === 1;

	global $wpdb;
	$ins = $wpdb->prefix . CO360_SSA_Plugin::DB_TABLE;

	$total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ins WHERE estudio_id=%d", $sid));

	$labels = array();
	$start  = new DateTime('today');
	$start->modify('-'.($days-1).' days');
	for($i=0;$i<$days;$i++){
		if($i>0) $start->modify('+1 day');
		$labels[] = $start->format('Y-m-d');
	}
	$from = (new DateTime($labels[0]))->format('Y-m-d 00:00:00');
	$rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM $ins WHERE estudio_id=%d AND created_at >= %s GROUP BY DATE(created_at)", $sid, $from), ARRAY_A);
	$map  = array();
	foreach($rows as $r){ $map[$r['d']] = (int)$r['c']; }
	$data = array();
	foreach($labels as $d){ $data[] = isset($map[$d]) ? (int)$map[$d] : 0; }

	$frm      = array();
	$total_f  = 0;
	if($form){
		$items = $wpdb->prefix.'frm_items';
		$total_f = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $items WHERE form_id=%d", $form));
		$rows2 = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM $items WHERE form_id=%d AND created_at >= %s GROUP BY DATE(created_at)", $form, $from), ARRAY_A);
		$m2 = array();
		foreach($rows2 as $r){ $m2[$r['d']] = (int)$r['c']; }
		foreach($labels as $d){ $frm[] = isset($m2[$d]) ? (int)$m2[$d] : 0; }
	}

	$id      = 'co360ssa_stats_'. wp_generate_uuid4();
	$L       = wp_json_encode($labels);
	$D       = wp_json_encode($data);
	$Fseries = $form ? (',{"label":"Formidable (envíos)","data":'. wp_json_encode($frm) .',"borderDash":[5,5],"tension":0.2}') : '';
	$type    = esc_js($chart);

	ob_start();
	?>
	<div id="<?php echo esc_attr($id); ?>" class="co360-ssa-stats" style="margin:1rem 0;">
		<?php if($totals): ?>
		<div style="display:flex;gap:12px;flex-wrap:wrap;">
			<div style="padding:12px 16px;border:1px solid #ddd;border-radius:8px;min-width:180px;">
				<div style="font-size:12px;color:#555;">Inscritos (totales)</div>
				<div style="font-size:24px;font-weight:700;"><?php echo esc_html(number_format_i18n($total)); ?></div>;
			</div>
			<?php if($form): ?>
			<div style="padding:12px 16px;border:1px solid #ddd;border-radius:8px;min-width:180px;">
				<div style="font-size:12px;color:#555;">Inscripciones (Formidable)</div>;
				<div style="font-size:24px;font-weight:700;"><?php echo esc_html(number_format_i18n($total_f)); ?></div>;
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if($chart!=='none'): ?>
			<canvas style="max-width:100%;height:300px" aria-label="Serie de inscripciones" role="img"></canvas>
		<?php endif; ?>
	</div>
	<?php if($chart!=='none'): ?>
	<script>
	(function(){
		function ready(f){if(document.readyState!=="loading"){f()}else{document.addEventListener("DOMContentLoaded",f)}}
		ready(function(){
			function ensure(cb){
				if(window.Chart) return cb();
				var s=document.createElement("script");
				s.src="https://cdn.jsdelivr.net/npm/chart.js";
				s.onload=cb; document.head.appendChild(s);
			}
			ensure(function(){
				var root=document.getElementById(<?php echo json_encode($id); ?>);
				if(!root) return;
				var ctx=root.querySelector("canvas").getContext("2d");
				new Chart(ctx,{
					type: <?php echo json_encode($type); ?>,
					data:{
						labels: <?php echo $L; ?>,
						datasets:[
							{"label":"Inscritos","data":<?php echo $D; ?>,"tension":0.2}<?php echo $Fseries; ?>
						]
					},
					options:{responsive:true,scales:{y:{beginAtZero:true,precision:0}},plugins:{legend:{display:true}}}
				});
			});
		});
	})();
	</script>
	<?php endif; ?>
	<?php
	return ob_get_clean();
});

/* === Shortcode de Login propio: [co360_ssa_login] === */
add_shortcode('co360_ssa_login', function($atts){
	$a = shortcode_atts(array(
		'title'        => 'Acceso',
		'show_labels'  => 1,
		'show_remember'=> 1
	), $atts, 'co360_ssa_login');

	$redirect_to = isset($_GET['redirect_to']) ? trim($_GET['redirect_to']) : '';
	$redirect_to = $redirect_to ? wp_validate_redirect($redirect_to, home_url('/')) : '';

	// Ya logueado
	if ( is_user_logged_in() ) {
		if ( $redirect_to ) { co360_ssa_hard_redirect($redirect_to, 'login_shortcode:already'); }
		return '<div class="notice notice-success" style="padding:8px">Ya has iniciado sesión.</div>';
	}

	$err_msg = '';
	if ( isset($_POST['co360_login_action']) && $_POST['co360_login_action']==='login' ) {
		check_admin_referer('co360_ssa_login','co360_ssa_login_nonce');

		$user_login = isset($_POST['user_login']) ? sanitize_text_field(wp_unslash($_POST['user_login'])) : '';
		$user_pass  = isset($_POST['user_pass'])  ? $_POST['user_pass'] : '';
		$remember   = ! empty($_POST['rememberme']);

		if ( empty($user_login) || empty($user_pass) ) {
			$err_msg = 'Introduce usuario/email y contraseña.';
		} else {
			// Permitir email o usuario
			if ( strpos($user_login, '@') !== false ) {
				$u = get_user_by('email', $user_login);
				if ( $u ) { $user_login = $u->user_login; }
			}
			$creds = array(
				'user_login'    => $user_login,
				'user_password' => $user_pass,
				'remember'      => $remember
			);
			$user = wp_signon( $creds, is_ssl() );
			if ( is_wp_error($user) ) {
				$err_msg = wp_strip_all_tags( $user->get_error_message() );
			} else {
				$dest = $redirect_to ? $redirect_to : home_url('/');
				co360_ssa_hard_redirect($dest, 'login_shortcode:success');
			}
		}
	}

	ob_start();
	echo '<div class="co360-ssa-login" style="max-width:420px">';
	echo '<h3>'. esc_html($a['title']) .'</h3>';
	if ( $err_msg ) {
		echo '<div class="notice notice-error" style="padding:8px;margin-bottom:12px">'. esc_html($err_msg) .'</div>';
	}
	echo '<form method="post">';
	wp_nonce_field('co360_ssa_login','co360_ssa_login_nonce');
	echo '<input type="hidden" name="co360_login_action" value="login" />';
	if ( $redirect_to ) { echo '<input type="hidden" name="redirect_to" value="'. esc_attr($redirect_to) .'" />'; }

	$show_labels = intval($a['show_labels'])===1;

	echo '<p>';
	if ($show_labels) echo '<label for="co360_user_login">Usuario o email</label><br/>';
	echo '<input type="text" id="co360_user_login" name="user_login" required style="width:100%;max-width:100%" />';
	echo '</p>';

	echo '<p>';
	if ($show_labels) echo '<label for="co360_user_pass">Contraseña</label><br/>';
	echo '<input type="password" id="co360_user_pass" name="user_pass" required style="width:100%;max-width:100%" />';
	echo '</p>';

	if ( intval($a['show_remember'])===1 ) {
		echo '<p><label><input type="checkbox" name="rememberme" value="1" /> Recuérdame</label></p>';
	}

	echo '<p><button type="submit" class="button button-primary" style="width:100%">Entrar</button></p>';

	$lost = wp_lostpassword_url( $redirect_to ? $redirect_to : '' );
	echo '<p style="margin-top:8px"><a href="'. esc_url($lost) .'">¿Has olvidado la contraseña?</a></p>';

	echo '</form></div>';

	return ob_get_clean();
});

/* === AJAX público para comprobar sesión (por si se necesitara en el futuro) === */
add_action('wp_ajax_nopriv_co360_ssa_check_login', function(){
	wp_send_json_success( array( 'logged_in' => is_user_logged_in() ? 1 : 0 ) );
});