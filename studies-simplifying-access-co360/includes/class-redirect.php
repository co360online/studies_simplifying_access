<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redirect {
	public function register() {
		add_action( 'template_redirect', array( $this, 'handle_pending_redirect' ), 2 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_hosts' ) );
	}

	public function allow_hosts( $hosts ) {
		$options = Utils::get_options();
		foreach ( array( 'registration_page_url', 'enrollment_page_url', 'login_page_url' ) as $key ) {
			if ( empty( $options[ $key ] ) ) {
				continue;
			}
			$host = parse_url( $options[ $key ], PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = $host;
			}
		}
		return array_unique( array_filter( $hosts ) );
	}

	public function safe_redirect( $url, $status = 302 ) {
		if ( Utils::get_debug_level() === 2 ) {
			return;
		}

		if ( ! headers_sent() ) {
			wp_safe_redirect( $url, $status );
			exit;
		}

		setcookie( CO360_SSA_REDIRECT_COOKIE, esc_url_raw( $url ), time() + 60, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		echo '<!doctype html><html><head>';
		echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '">';
		echo '</head><body>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p>' . esc_html__( 'Redirigiendo...', CO360_SSA_TEXT_DOMAIN ) . '</p>';
		echo '<script>window.location.href=' . wp_json_encode( esc_url_raw( $url ) ) . ';</script>';
		echo '</body></html>';
		exit;
	}

	public function handle_pending_redirect() {
		if ( Utils::get_debug_level() === 2 ) {
			return;
		}
		if ( empty( $_COOKIE[ CO360_SSA_REDIRECT_COOKIE ] ) ) {
			return;
		}
		$url = esc_url_raw( wp_unslash( $_COOKIE[ CO360_SSA_REDIRECT_COOKIE ] ) );
		setcookie( CO360_SSA_REDIRECT_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
		if ( $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}
}
