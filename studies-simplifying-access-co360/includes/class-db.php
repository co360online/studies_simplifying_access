<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB {
	const DB_VERSION = '1.0.0';

	public static function table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$inscriptions = self::table_name( CO360_SSA_DB_TABLE );
		$codes = self::table_name( CO360_SSA_DB_CODES );

		$sql1 = "CREATE TABLE {$inscriptions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			estudio_id BIGINT UNSIGNED NOT NULL,
			code_used VARCHAR(255) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY estudio_id (estudio_id)
		) {$charset};";

		dbDelta( $sql1 );

		$sql2 = "CREATE TABLE {$codes} (
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
		) {$charset};";

		dbDelta( $sql2 );

		update_option( CO360_SSA_DBVER_KEY, self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		$current = get_option( CO360_SSA_DBVER_KEY, '0' );
		if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}
}
