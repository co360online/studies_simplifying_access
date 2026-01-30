<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {
	public static function activate() {
		DB::install();
		if ( ! get_option( CO360_SSA_OPT_KEY ) ) {
			add_option( CO360_SSA_OPT_KEY, Utils::defaults() );
		}
	}
}
