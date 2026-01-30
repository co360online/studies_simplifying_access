<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcodes {
	private $auth;
	private $redirect;

	public function __construct( Auth $auth, Redirect $redirect ) {
		$this->auth = $auth;
		$this->redirect = $redirect;
	}

	public function register() {
		add_shortcode( 'acceso_estudio', array( $this, 'shortcode_access' ) );
		add_shortcode( 'co360_ssa_form_context', array( $this, 'shortcode_form_context' ) );
		add_shortcode( 'co360_ssa_enrollment', array( $this, 'shortcode_enrollment' ) );
		add_shortcode( 'co360_ssa_stats', array( $this, 'shortcode_stats' ) );
		add_shortcode( 'co360_ssa_login', array( $this, 'shortcode_login' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_nopriv_co360_ssa_check_login', array( $this, 'ajax_check_login' ) );
	}

	public function register_assets() {
		wp_register_script( 'co360-ssa-login', CO360_SSA_PLUGIN_URL . 'assets/js/ssa-login.js', array( 'jquery' ), CO360_SSA_VERSION, true );
		wp_register_script( 'co360-ssa-stats', CO360_SSA_PLUGIN_URL . 'assets/js/ssa-stats.js', array(), CO360_SSA_VERSION, true );
		wp_register_style( 'co360-ssa-front', CO360_SSA_PLUGIN_URL . 'assets/css/ssa-front.css', array(), CO360_SSA_VERSION );
	}

	public function shortcode_access( $atts ) {
		$atts = shortcode_atts(
			array(
				'study_id' => 0,
				'title' => __( 'Acceso al estudio', CO360_SSA_TEXT_DOMAIN ),
				'button_text' => __( 'Acceder', CO360_SSA_TEXT_DOMAIN ),
				'require_code' => 1,
			),
			$atts,
			'acceso_estudio'
		);

		$study_id = absint( $atts['study_id'] );
		if ( ! $study_id ) {
			return '<div class="co360-ssa-error">' . esc_html__( 'Study ID requerido.', CO360_SSA_TEXT_DOMAIN ) . '</div>';
		}
		$study = get_post( $study_id );
		if ( ! $study || CO360_SSA_CT_STUDY !== $study->post_type ) {
			return '<div class="co360-ssa-error">' . esc_html__( 'Estudio inválido.', CO360_SSA_TEXT_DOMAIN ) . '</div>';
		}
		$meta = Utils::get_study_meta( $study_id );
		if ( '1' !== (string) $meta['activo'] ) {
			return '<div class="co360-ssa-error">' . esc_html__( 'Estudio inactivo.', CO360_SSA_TEXT_DOMAIN ) . '</div>';
		}

		$notices = array();

		if ( isset( $_POST['co360_ssa_access_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['co360_ssa_access_nonce'] ), 'co360_ssa_access' ) ) {
			$email = Utils::sanitize_email( $_POST['co360_ssa_email'] ?? '' );
			$code = Utils::sanitize_text( $_POST['co360_ssa_code'] ?? '' );

			if ( ! is_email( $email ) ) {
				$notices[] = __( 'Email inválido.', CO360_SSA_TEXT_DOMAIN );
			}
			if ( $atts['require_code'] && empty( $code ) ) {
				$notices[] = __( 'Debes ingresar un código.', CO360_SSA_TEXT_DOMAIN );
			}

			if ( empty( $notices ) && ( $atts['require_code'] || $code ) ) {
				$validation = $this->auth->validate_code_for_study( $study_id, $email, $code );
				if ( is_wp_error( $validation ) ) {
					$notices[] = $validation->get_error_message();
				}
			}

			if ( empty( $notices ) ) {
				$user = Utils::create_or_get_user( $email );
				if ( is_wp_error( $user ) ) {
					$notices[] = $user->get_error_message();
				} else {
					wp_set_current_user( $user->ID );
					wp_set_auth_cookie( $user->ID, true );

					$token = $this->auth->set_context_token( $email, $study_id, $code );
					$target = Utils::get_redirect_target_for_study( $study_id );
					$target = Utils::add_query_arg_token( $target, $token );

					if ( 2 === Utils::get_debug_level() ) {
						return $this->render_debug_panel( $target, $token );
					}

					$this->redirect->safe_redirect( $target );
				}
			}
		}

		ob_start();
		wp_enqueue_style( 'co360-ssa-front' );
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-access.php';
		return ob_get_clean();
	}

	private function render_debug_panel( $target, $token ) {
		ob_start();
		?>
		<div class="co360-ssa-debug">
			<h3><?php esc_html_e( 'Modo debug HOLD', CO360_SSA_TEXT_DOMAIN ); ?></h3>
			<p><strong><?php esc_html_e( 'Destino:', CO360_SSA_TEXT_DOMAIN ); ?></strong> <?php echo esc_html( $target ); ?></p>
			<p><strong><?php esc_html_e( 'Token:', CO360_SSA_TEXT_DOMAIN ); ?></strong> <?php echo esc_html( $token ); ?></p>
			<pre><?php echo esc_html( wp_json_encode( $this->auth->get_context_by_token( $token ), JSON_PRETTY_PRINT ) ); ?></pre>
		</div>
		<?php
		return ob_get_clean();
	}

	public function shortcode_form_context() {
		$token = get_query_var( CO360_SSA_TOKEN_QUERY );
		$context = $this->auth->get_context_by_token( $token );
		if ( ! $context ) {
			return '';
		}
		ob_start();
		?>
		<input type="hidden" name="co360_ssa_email" value="<?php echo esc_attr( $context['email'] ); ?>">
		<input type="hidden" name="co360_ssa_study_id" value="<?php echo esc_attr( $context['study_id'] ); ?>">
		<input type="hidden" name="co360_ssa_code" value="<?php echo esc_attr( $context['code'] ); ?>">
		<input type="hidden" name="<?php echo esc_attr( CO360_SSA_TOKEN_QUERY ); ?>" value="<?php echo esc_attr( $token ); ?>">
		<?php
		return ob_get_clean();
	}

	public function shortcode_enrollment( $atts ) {
		$atts = shortcode_atts(
			array(
				'study_id' => 0,
				'form_id' => 0,
			),
			$atts,
			'co360_ssa_enrollment'
		);

		$token = get_query_var( CO360_SSA_TOKEN_QUERY );
		$context = $this->auth->get_context_by_token( $token );
		$user = wp_get_current_user();

		$errors = array();
		if ( ! $context ) {
			$errors[] = __( 'No se pudo validar el contexto de inscripción.', CO360_SSA_TEXT_DOMAIN );
		}
		if ( ! $user || ! $user->ID ) {
			$errors[] = __( 'Debes estar autenticado para inscribirte.', CO360_SSA_TEXT_DOMAIN );
		}
		if ( $context && $user && $user->ID ) {
			if ( Utils::normalize_email( $user->user_email ) !== Utils::normalize_email( $context['email'] ) ) {
				$errors[] = __( 'El email del usuario no coincide con el acceso.', CO360_SSA_TEXT_DOMAIN );
			}
		}

		$study_id = absint( $atts['study_id'] );
		if ( ! $study_id && $context ) {
			$study_id = absint( $context['study_id'] );
		}
		if ( $context && $study_id && absint( $context['study_id'] ) !== $study_id ) {
			$errors[] = __( 'El estudio no coincide con el contexto.', CO360_SSA_TEXT_DOMAIN );
		}

		$form_id = absint( $atts['form_id'] );
		if ( ! $form_id && $study_id ) {
			$form_id = absint( get_post_meta( $study_id, '_co360_ssa_enroll_form_id', true ) );
		}

		ob_start();
		wp_enqueue_style( 'co360-ssa-front' );
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-enrollment.php';
		return ob_get_clean();
	}

	public function shortcode_stats( $atts ) {
		$atts = shortcode_atts(
			array(
				'study_id' => 0,
				'days' => 30,
				'chart' => 'line',
				'show_totals' => 1,
			),
			$atts,
			'co360_ssa_stats'
		);

		$study_id = absint( $atts['study_id'] );
		$days = max( 1, absint( $atts['days'] ) );
		$chart = in_array( $atts['chart'], array( 'line', 'bar', 'none' ), true ) ? $atts['chart'] : 'line';

		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_TABLE );
		$start = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );

		$sql = $wpdb->prepare(
			"SELECT DATE(created_at) AS day, COUNT(*) AS total FROM {$table} WHERE estudio_id = %d AND created_at >= %s GROUP BY day ORDER BY day ASC",
			$study_id,
			$start . ' 00:00:00'
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$series = array();
		$total = 0;
		foreach ( $rows as $row ) {
			$series[] = array( 'day' => $row['day'], 'total' => (int) $row['total'] );
			$total += (int) $row['total'];
		}

		if ( 'none' !== $chart ) {
			wp_enqueue_script( 'co360-ssa-stats' );
		}
		wp_enqueue_style( 'co360-ssa-front' );

		ob_start();
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-stats.php';
		return ob_get_clean();
	}

	public function shortcode_login( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Iniciar sesión', CO360_SSA_TEXT_DOMAIN ),
				'show_labels' => 1,
				'show_remember' => 1,
			),
			$atts,
			'co360_ssa_login'
		);

		wp_enqueue_script( 'co360-ssa-login' );
		wp_localize_script(
			'co360-ssa-login',
			'co360SSA',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'co360_ssa_login' ),
			)
		);
		wp_enqueue_style( 'co360-ssa-front' );

		ob_start();
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-login.php';
		return ob_get_clean();
	}

	public function ajax_check_login() {
		check_ajax_referer( 'co360_ssa_login', 'nonce' );

		$creds = array(
			'user_login' => Utils::sanitize_text( $_POST['username'] ?? '' ),
			'user_password' => Utils::sanitize_text( $_POST['password'] ?? '' ),
			'remember' => ! empty( $_POST['remember'] ),
		);

		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Credenciales inválidas.', CO360_SSA_TEXT_DOMAIN ) ) );
		}

		$redirect = ! empty( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url();
		wp_send_json_success( array( 'redirect' => $redirect ) );
	}
}
