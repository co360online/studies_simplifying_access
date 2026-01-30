<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'co360_ssa_options' );
delete_option( 'co360_ssa_dbver' );

if ( defined( 'CO360_SSA_PURGE_ON_UNINSTALL' ) && CO360_SSA_PURGE_ON_UNINSTALL ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'co360_ssa_inscripciones' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'co360_ssa_codigos' );
}
