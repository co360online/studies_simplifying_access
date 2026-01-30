<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static $instance;

	private $auth;
	private $redirect;
	private $shortcodes;
	private $formidable;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->auth = new Auth();
		$this->redirect = new Redirect();
		$this->shortcodes = new Shortcodes( $this->auth, $this->redirect );
		$this->formidable = new Formidable( $this->auth );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'template_redirect', array( $this, 'handle_after_login' ), 1 );

		( new CPT_Study() )->register();
		( new Settings() )->register();
		( new Admin() )->register();
		$this->auth->register();
		$this->redirect->register();
		$this->shortcodes->register();
		$this->formidable->register();
	}

	public function load_textdomain() {
		load_plugin_textdomain( CO360_SSA_TEXT_DOMAIN, false, dirname( plugin_basename( CO360_SSA_PLUGIN_FILE ) ) . '/languages' );
	}


	public function handle_after_login() {
		$flag = get_query_var( CO360_SSA_REDIRECT_FLAG );
		if ( 'after_login' !== $flag ) {
			return;
		}

		$token = get_query_var( CO360_SSA_TOKEN_QUERY );
		$context = $this->auth->get_context_by_token( $token );
		$debug = Utils::get_debug_level();
		$user = wp_get_current_user();

		if ( 2 === $debug ) {
			$this->render_after_login_debug( $context, $token, $user );
		} elseif ( 1 === $debug ) {
			Utils::log( 'after_login debug: token=' . $token );
		}

		if ( ! $context ) {
			$this->redirect->safe_redirect( home_url( '/' ) );
		}

		if ( ! $user || ! $user->ID ) {
			$after = add_query_arg(
				array(
					CO360_SSA_REDIRECT_FLAG => 'after_login',
					CO360_SSA_TOKEN_QUERY => $token,
				),
				home_url( '/' )
			);
			$options = Utils::get_options();
			if ( ! empty( $options['login_page_url'] ) ) {
				$login_url = add_query_arg( 'redirect_to', $after, $options['login_page_url'] );
			} else {
				$login_url = wp_login_url( $after );
			}
			$this->redirect->safe_redirect( $login_url );
		}

		if ( Utils::normalize_email( $user->user_email ) !== Utils::normalize_email( $context['email'] ) ) {
			wp_logout();
			$after_url = add_query_arg(
				array(
					CO360_SSA_REDIRECT_FLAG => 'after_login',
					CO360_SSA_TOKEN_QUERY => $token,
					'ssa_error' => 'email_mismatch',
				),
				home_url( '/' )
			);
			$options = Utils::get_options();
			if ( ! empty( $options['login_page_url'] ) ) {
				$login_url = add_query_arg( 'redirect_to', $after_url, $options['login_page_url'] );
			} else {
				$login_url = wp_login_url( $after_url );
			}
			$this->redirect->safe_redirect( $login_url );
		}

		$study_id = absint( $context['study_id'] );
		if ( ! $study_id ) {
			$this->redirect->safe_redirect( home_url( '/' ) );
		}

		$meta = Utils::get_study_meta( $study_id );
		$form_id = absint( $meta['enroll_form_id'] );
		$enroll_url = ! empty( $meta['enroll_page_url'] ) ? $meta['enroll_page_url'] : Utils::get_options()['enrollment_page_url'];
		$crd_url = ! empty( $meta['crd_url'] ) ? $meta['crd_url'] : home_url( '/' );

		if ( $this->user_has_enrollment( $user->ID, $study_id ) ) {
			$this->redirect->safe_redirect( $crd_url );
		}

		if ( $form_id && $enroll_url ) {
			$target = Utils::add_query_arg_token( $enroll_url, $token );
			$this->redirect->safe_redirect( $target );
		}

		$this->insert_enrollment_if_missing( $user->ID, $study_id, $context['code'] );
		$this->redirect->safe_redirect( $crd_url );
	}

	private function render_after_login_debug( $context, $token, $user ) {
		status_header( 200 );
		echo '<div class="co360-ssa-debug">';
		echo '<h3>' . esc_html__( 'Modo debug HOLD', CO360_SSA_TEXT_DOMAIN ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Token:', CO360_SSA_TEXT_DOMAIN ) . '</strong> ' . esc_html( $token ) . '</p>';
		if ( $user && $user->ID ) {
			echo '<p><strong>' . esc_html__( 'Usuario:', CO360_SSA_TEXT_DOMAIN ) . '</strong> ' . esc_html( $user->user_email ) . '</p>';
		}
		echo '<pre>' . esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT ) ) . '</pre>';
		echo '</div>';
		exit;
	}

	private function user_has_enrollment( $user_id, $study_id ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND estudio_id = %d",
				$user_id,
				$study_id
			)
		);
		return (bool) $count;
	}

	private function insert_enrollment_if_missing( $user_id, $study_id, $code ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND estudio_id = %d",
				$user_id,
				$study_id
			)
		);
		if ( $existing ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'user_id' => $user_id,
				'estudio_id' => $study_id,
				'code_used' => sanitize_text_field( $code ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		$meta = Utils::get_study_meta( $study_id );
		if ( 'list' === $meta['code_mode'] ) {
			$this->auth->finalize_list_code_usage( $study_id, $code );
		}
	}

	public function maybe_upgrade() {
		DB::maybe_upgrade();
	}
}
