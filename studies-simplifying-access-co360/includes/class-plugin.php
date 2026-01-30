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

	public function maybe_upgrade() {
		DB::maybe_upgrade();
	}
}
