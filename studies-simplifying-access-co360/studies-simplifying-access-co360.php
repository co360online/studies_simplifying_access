<?php
/**
 * Plugin Name: Studies Simplifying Access (CO360)
 * Description: Acceso por código + email para inscribir usuarios en estudios con contexto por token y control de inscripción.
 * Version: 1.0.0
 * Author: CO360
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Text Domain: co360-ssa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CO360_SSA_VERSION', '1.0.0' );
define( 'CO360_SSA_PLUGIN_FILE', __FILE__ );
define( 'CO360_SSA_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CO360_SSA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'CO360_SSA_TEXT_DOMAIN', 'co360-ssa' );

define( 'CO360_SSA_OPT_KEY', 'co360_ssa_options' );
define( 'CO360_SSA_DBVER_KEY', 'co360_ssa_dbver' );
define( 'CO360_SSA_TOKEN_QUERY', 'co360_ssa_token' );

define( 'CO360_SSA_CT_STUDY', 'co360_estudio' );
define( 'CO360_SSA_DB_TABLE', 'co360_ssa_inscripciones' );
define( 'CO360_SSA_DB_CODES', 'co360_ssa_codigos' );

define( 'CO360_SSA_REDIRECT_COOKIE', 'co360_ssa_redirect' );

define( 'CO360_SSA_DEBUG_QUERY', 'ssa_debug' );

define( 'CO360_SSA_PURGE_ON_UNINSTALL', false );

require_once CO360_SSA_PLUGIN_PATH . 'includes/class-utils.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-db.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-activator.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-redirect.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-cpt-study.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-settings.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-admin.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-auth.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-formidable.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-shortcodes.php';
require_once CO360_SSA_PLUGIN_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'CO360\\SSA\\Activator', 'activate' ) );

CO360\SSA\Plugin::instance();
