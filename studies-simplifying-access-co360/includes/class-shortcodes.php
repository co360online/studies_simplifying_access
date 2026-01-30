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
		add_shortcode( 'co360_ssa_registration_gate', array( $this, 'shortcode_registration_gate' ) );
		add_shortcode( 'co360_ssa_form_context', array( $this, 'shortcode_form_context' ) );
		add_shortcode( 'co360_ssa_enrollment', array( $this, 'shortcode_enrollment' ) );
		add_shortcode( 'co360_ssa_stats', array( $this, 'shortcode_stats' ) );
		add_shortcode( 'co360_ssa_login', array( $this, 'shortcode_login' ) );
		add_shortcode( 'co360_ssa_my_studies', array( $this, 'shortcode_my_studies' ) );

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
				'my_studies_url' => '',
			),
			$atts,
			'acceso_estudio'
		);

		$study_id = absint( $atts['study_id'] );
		$study = null;
		$meta = array();

		if ( $study_id ) {
			$study = get_post( $study_id );
			if ( ! $study || CO360_SSA_CT_STUDY !== $study->post_type ) {
				return '<div class="co360-ssa-error">' . esc_html__( 'Estudio inválido.', CO360_SSA_TEXT_DOMAIN ) . '</div>';
			}
			$meta = Utils::get_study_meta( $study_id );
			if ( '1' !== (string) $meta['activo'] ) {
				return '<div class="co360-ssa-error">' . esc_html__( 'Estudio inactivo.', CO360_SSA_TEXT_DOMAIN ) . '</div>';
			}
		}

		$notices = array();

		if ( isset( $_POST['co360_ssa_access_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['co360_ssa_access_nonce'] ) ), 'co360_ssa_access' ) ) {
			$email = Utils::sanitize_email( $_POST['co360_ssa_email'] ?? '' );
			$code = Utils::sanitize_text( $_POST['co360_ssa_code'] ?? '' );

			if ( ! is_email( $email ) ) {
				$notices[] = __( 'Email inválido.', CO360_SSA_TEXT_DOMAIN );
			}
			if ( $atts['require_code'] && empty( $code ) ) {
				$notices[] = __( 'Debes ingresar un código.', CO360_SSA_TEXT_DOMAIN );
			}

			if ( empty( $notices ) && ( $atts['require_code'] || $code ) ) {
				if ( ! $study_id ) {
					$study_lookup = $this->auth->find_study_for_code( $email, $code );
					if ( is_wp_error( $study_lookup ) ) {
						$notices[] = $study_lookup->get_error_message();
					} else {
						$study_id = absint( $study_lookup );
						$study = get_post( $study_id );
						$meta = Utils::get_study_meta( $study_id );
						if ( '1' !== (string) $meta['activo'] ) {
							$notices[] = __( 'Estudio inactivo.', CO360_SSA_TEXT_DOMAIN );
						}
					}
				} else {
					$validation = $this->auth->validate_code_for_study( $study_id, $email, $code );
					if ( is_wp_error( $validation ) ) {
						$notices[] = $validation->get_error_message();
					}
				}
			}

			if ( empty( $notices ) && ! $study_id ) {
				$notices[] = __( 'El código no corresponde a ningún estudio.', CO360_SSA_TEXT_DOMAIN );
			}

			if ( empty( $notices ) ) {
				$token = $this->auth->set_context_token( $email, $study_id, $code );
				$user = get_user_by( 'email', $email );
				if ( ! $user ) {
					$options = Utils::get_options();
					$registration_url = ! empty( $options['registration_page_url'] ) ? $options['registration_page_url'] : home_url( '/' );
					$target = Utils::add_query_arg_token( $registration_url, $token );
					if ( 2 === Utils::get_debug_level() ) {
						return $this->render_debug_panel( $target, $token );
					}
					$this->redirect->safe_redirect( $target );
				}

				wp_set_current_user( $user->ID );
				wp_set_auth_cookie( $user->ID, true );

				$after_url = add_query_arg(
					array(
						CO360_SSA_REDIRECT_FLAG => 'after_login',
						CO360_SSA_TOKEN_QUERY => $token,
					),
					home_url( '/' )
				);

				if ( 2 === Utils::get_debug_level() ) {
					return $this->render_debug_panel( $after_url, $token );
				}

				// Always redirect access flow through after_login to enforce authentication before enrollment page.
				$this->redirect->safe_redirect( $after_url );
			}
		}

		$user = wp_get_current_user();
		$options = Utils::get_options();
		$my_studies_url = '';
		if ( ! empty( $atts['my_studies_url'] ) ) {
			$my_studies_url = esc_url_raw( $atts['my_studies_url'] );
		} elseif ( ! empty( $options['my_studies_page_url'] ?? '' ) ) {
			$my_studies_url = $options['my_studies_page_url'];
		}

		ob_start();
		wp_enqueue_style( 'co360-ssa-front' );
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-access.php';
		return ob_get_clean();
	}

	public function shortcode_registration_gate( $atts ) {
		$atts = shortcode_atts(
			array(
				'strict' => 1,
				'message_ok' => __( 'Completa tu registro para acceder al estudio.', CO360_SSA_TEXT_DOMAIN ),
				'message_fail' => __( 'El acceso al registro es solo por invitación. Ingresa desde el formulario de acceso.', CO360_SSA_TEXT_DOMAIN ),
			),
			$atts,
			'co360_ssa_registration_gate'
		);

		$token = get_query_var( CO360_SSA_TOKEN_QUERY );
		$context = $this->auth->get_context_by_token( $token );
		$strict = absint( $atts['strict'] );
		$is_valid = (bool) $context;

		wp_enqueue_style( 'co360-ssa-front' );
		ob_start();
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-registration-gate.php';
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

		$form_output = '';
		if ( empty( $errors ) && $form_id ) {
			$form_output = $this->render_formidable_form( $form_id, $token, $study_id );
		}

		ob_start();
		wp_enqueue_style( 'co360-ssa-front' );
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-enrollment.php';
		return ob_get_clean();
	}

	private function render_formidable_form( $form_id, $token, $study_id ) {
		if ( ! class_exists( '\FrmFormsController' ) ) {
			return do_shortcode( '[formidable id="' . absint( $form_id ) . '"]' );
		}

		$token = sanitize_text_field( $token );
		$hidden = '<input type="hidden" name="' . esc_attr( CO360_SSA_TOKEN_QUERY ) . '" value="' . esc_attr( $token ) . '">';
		$center_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_select_field_id', true ) );
		if ( ! $center_field_id ) {
			$center_field_id = absint( get_post_meta( $study_id, '_co360_ssa_center_field_id', true ) );
		}
		if ( $study_id ) {
			Utils::ensure_centers_have_codes( $study_id );
		}
		$centers = $study_id ? Utils::get_centers_for_study( $study_id ) : array();

		add_filter(
			'frm_form_content',
			array( $this, 'inject_formidable_token' ),
			10,
			2
		);
		add_filter(
			'frm_setup_new_fields_vars',
			array( $this, 'inject_formidable_centers' ),
			10,
			2
		);
		add_filter(
			'frm_setup_edit_fields_vars',
			array( $this, 'inject_formidable_centers' ),
			10,
			2
		);
		$this->formidable_token_data = array(
			'form_id' => (int) $form_id,
			'hidden' => $hidden,
		);
		$this->formidable_center_data = array(
			'form_id' => (int) $form_id,
			'field_id' => $center_field_id,
			'centers' => $centers,
		);

		$form_html = \FrmFormsController::show_form( $form_id, '', true, false );

		remove_filter( 'frm_form_content', array( $this, 'inject_formidable_token' ), 10 );
		remove_filter( 'frm_setup_new_fields_vars', array( $this, 'inject_formidable_centers' ), 10 );
		remove_filter( 'frm_setup_edit_fields_vars', array( $this, 'inject_formidable_centers' ), 10 );
		$this->formidable_token_data = null;
		$this->formidable_center_data = null;

		return $form_html;
	}

	private $formidable_token_data;
	private $formidable_center_data;

	public function inject_formidable_token( $content, $form ) {
		if ( empty( $this->formidable_token_data ) ) {
			return $content;
		}
		if ( (int) $form->id !== (int) $this->formidable_token_data['form_id'] ) {
			return $content;
		}
		$hidden = $this->formidable_token_data['hidden'];
		if ( false === strpos( $content, $hidden ) ) {
			$content = preg_replace( '/(<form[^>]*>)/', '$1' . $hidden, $content, 1 );
		}
		return $content;
	}

	public function inject_formidable_centers( $values, $field ) {
		if ( empty( $this->formidable_center_data ) ) {
			return $values;
		}
		if ( (int) $field->form_id !== (int) $this->formidable_center_data['form_id'] ) {
			return $values;
		}
		if ( (int) $field->id !== (int) $this->formidable_center_data['field_id'] ) {
			return $values;
		}
		if ( empty( $field->type ) || ! in_array( $field->type, array( 'select', 'dropdown' ), true ) ) {
			return $values;
		}
		// Ejemplo HTML esperado: <option value="001">Hospital Clínico San Carlos (001)</option>
		$options = array(
			'' => __( 'Selecciona un centro', CO360_SSA_TEXT_DOMAIN ),
		);
		foreach ( $this->formidable_center_data['centers'] as $center ) {
			$label = $center['center_name'] . ' (' . $center['center_code'] . ')';
			$options[ (string) $center['center_code'] ] = $label;
		}
		$options['other'] = __( 'Mi centro no está en la lista', CO360_SSA_TEXT_DOMAIN );
		$values['options'] = $options;
		$values['use_key'] = true;
		if ( isset( $field->options ) && is_array( $field->options ) ) {
			$field->options = $options;
		} elseif ( isset( $field->field_options ) && is_array( $field->field_options ) ) {
			$field->field_options['options'] = $options;
			$field->field_options['use_key'] = true;
		}
		return $values;
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
		$redirect_to_raw = wp_unslash( $_REQUEST['redirect_to'] ?? '' );
		$token = sanitize_text_field( wp_unslash( $_REQUEST['co360_ssa_token'] ?? '' ) );
		if ( $token && false !== strpos( $redirect_to_raw, 'co360_ssa=after_login' ) && false === strpos( $redirect_to_raw, 'co360_ssa_token=' ) ) {
			$redirect_to_raw = add_query_arg( 'co360_ssa_token', $token, $redirect_to_raw );
		}
		$redirect_to = wp_validate_redirect( esc_url_raw( $redirect_to_raw ), home_url( '/' ) );

		$mode = sanitize_text_field( wp_unslash( $_GET['mode'] ?? '' ) );
		$mode = $mode ? $mode : 'login';
		$mode = in_array( $mode, array( 'login', 'lost', 'reset' ), true ) ? $mode : 'login';

		$notices = array();
		$errors = array();
		if ( 'lost' === $mode && isset( $_POST['co360_ssa_lostpass_submit'] ) ) {
			$lost_result = $this->handle_lost_password( $redirect_to );
			if ( is_wp_error( $lost_result ) ) {
				$errors[] = $lost_result->get_error_message();
			} else {
				$notices[] = __( 'Si el email existe, te hemos enviado un enlace para restablecer la contraseña.', CO360_SSA_TEXT_DOMAIN );
			}
		}

		if ( 'reset' === $mode && isset( $_POST['co360_ssa_resetpass_submit'] ) ) {
			$reset_result = $this->handle_reset_password( $redirect_to );
			if ( is_wp_error( $reset_result ) ) {
				$errors[] = $reset_result->get_error_message();
			} else {
				wp_safe_redirect( $reset_result );
				exit;
			}
		}

		if ( 'reset' === $mode && ! isset( $_POST['co360_ssa_resetpass_submit'] ) ) {
			$login = sanitize_text_field( wp_unslash( $_GET['login'] ?? '' ) );
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
			if ( $login && $key ) {
				$reset_check = check_password_reset_key( $key, $login );
				if ( is_wp_error( $reset_check ) ) {
					$errors[] = $reset_check->get_error_message();
				}
			} else {
				$errors[] = __( 'El enlace de restablecimiento no es válido.', CO360_SSA_TEXT_DOMAIN );
			}
		}

		if ( 'login' === $mode && isset( $_POST['co360_ssa_login_submit'] ) && isset( $_POST['co360_ssa_login_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['co360_ssa_login_nonce'] ) ), 'co360_ssa_login_form' ) ) {
				$username = Utils::sanitize_text( $_POST['username'] ?? '' );
				if ( false !== strpos( $username, '@' ) ) {
					$user = get_user_by( 'email', $username );
					if ( $user ) {
						$username = $user->user_login;
					}
				}

				$creds = array(
					'user_login' => $username,
					'user_password' => Utils::sanitize_text( $_POST['password'] ?? '' ),
					'remember' => ! empty( $_POST['remember'] ),
				);

				$user = wp_signon( $creds, is_ssl() );
				if ( ! is_wp_error( $user ) ) {
					// Respect redirect_to to continue after_login flow.
					wp_safe_redirect( $redirect_to );
					exit;
				}
			}
		}

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
		if ( 'lost' === $mode ) {
			include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-lost-password.php';
		} elseif ( 'reset' === $mode ) {
			include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-reset-password.php';
		} else {
			include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-login.php';
		}
		return ob_get_clean();
	}

	private function handle_lost_password( $redirect_to ) {
		if ( ! isset( $_POST['co360_ssa_lostpass_nonce'] ) ) {
			return new \WP_Error( 'invalid_nonce', __( 'No se pudo validar la solicitud.', CO360_SSA_TEXT_DOMAIN ) );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['co360_ssa_lostpass_nonce'] ) ), 'co360_ssa_lostpass_form' ) ) {
			return new \WP_Error( 'invalid_nonce', __( 'No se pudo validar la solicitud.', CO360_SSA_TEXT_DOMAIN ) );
		}

		$identifier = Utils::sanitize_text( $_POST['co360_ssa_user'] ?? '' );
		if ( empty( $identifier ) ) {
			return new \WP_Error( 'missing_user', __( 'Debes ingresar tu email o usuario.', CO360_SSA_TEXT_DOMAIN ) );
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$rate_key = 'co360_ssa_lostpass_' . md5( $ip . '|' . $identifier );
		if ( get_transient( $rate_key ) ) {
			return true;
		}

		$user = null;
		if ( false !== strpos( $identifier, '@' ) ) {
			$user = get_user_by( 'email', sanitize_email( $identifier ) );
		} else {
			$user = get_user_by( 'login', $identifier );
		}

		if ( $user && ! is_wp_error( $user ) ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$reset_url = add_query_arg(
					array(
						'mode' => 'reset',
						'login' => rawurlencode( $user->user_login ),
						'key' => rawurlencode( $key ),
						'redirect_to' => $redirect_to,
					),
					get_permalink( get_queried_object_id() )
				);

				$subject = __( 'Restablecer contraseña', CO360_SSA_TEXT_DOMAIN );
				$message = sprintf(
					"%s\n\n%s\n",
					__( 'Para restablecer tu contraseña, haz clic en el siguiente enlace:', CO360_SSA_TEXT_DOMAIN ),
					$reset_url
				);
				wp_mail( $user->user_email, $subject, $message );
			}
		}

		set_transient( $rate_key, 1, 60 );
		return true;
	}

	private function handle_reset_password( $redirect_to ) {
		if ( ! isset( $_POST['co360_ssa_resetpass_nonce'] ) ) {
			return new \WP_Error( 'invalid_nonce', __( 'No se pudo validar la solicitud.', CO360_SSA_TEXT_DOMAIN ) );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['co360_ssa_resetpass_nonce'] ) ), 'co360_ssa_resetpass_form' ) ) {
			return new \WP_Error( 'invalid_nonce', __( 'No se pudo validar la solicitud.', CO360_SSA_TEXT_DOMAIN ) );
		}

		$login = sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) );
		$key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$password_1 = Utils::sanitize_text( $_POST['password_1'] ?? '' );
		$password_2 = Utils::sanitize_text( $_POST['password_2'] ?? '' );

		if ( empty( $login ) || empty( $key ) ) {
			return new \WP_Error( 'invalid_link', __( 'El enlace de restablecimiento no es válido.', CO360_SSA_TEXT_DOMAIN ) );
		}

		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( $password_1 !== $password_2 ) {
			return new \WP_Error( 'password_mismatch', __( 'Las contraseñas no coinciden.', CO360_SSA_TEXT_DOMAIN ) );
		}
		if ( strlen( $password_1 ) < 8 ) {
			return new \WP_Error( 'password_short', __( 'La contraseña debe tener al menos 8 caracteres.', CO360_SSA_TEXT_DOMAIN ) );
		}

		reset_password( $user, $password_1 );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		return $redirect_to ? $redirect_to : home_url( '/' );
	}

	public function shortcode_my_studies( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Mis estudios', CO360_SSA_TEXT_DOMAIN ),
				'show_inactive' => 0,
				'show_dates' => 1,
				'layout' => 'cards',
			),
			$atts,
			'co360_ssa_my_studies'
		);

		$layout = in_array( $atts['layout'], array( 'cards', 'list' ), true ) ? $atts['layout'] : 'cards';
		$show_inactive = absint( $atts['show_inactive'] );
		$show_dates = absint( $atts['show_dates'] );
		$user = wp_get_current_user();
		$entries = array();
		$login_url = '';

		if ( ! $user || ! $user->ID ) {
			$options = Utils::get_options();
			$current_url = get_permalink( get_queried_object_id() );
			if ( ! empty( $options['login_page_url'] ) ) {
				$login_url = add_query_arg( 'redirect_to', $current_url, $options['login_page_url'] );
			} else {
				$login_url = wp_login_url( $current_url );
			}
		} else {
			global $wpdb;
			$table = DB::table_name( CO360_SSA_DB_TABLE );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT estudio_id, MAX(created_at) AS last_enrolled FROM {$table} WHERE user_id = %d GROUP BY estudio_id ORDER BY last_enrolled DESC",
					$user->ID
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$study_id = absint( $row['estudio_id'] );
				if ( ! $study_id ) {
					continue;
				}
				$study = get_post( $study_id );
				if ( ! $study || CO360_SSA_CT_STUDY !== $study->post_type ) {
					continue;
				}
				$is_active = '1' === (string) get_post_meta( $study_id, '_co360_ssa_activo', true );
				if ( ! $is_active && ! $show_inactive ) {
					continue;
				}

				$study_page_id = absint( get_post_meta( $study_id, '_co360_ssa_study_page_id', true ) );
				$crd_url = (string) get_post_meta( $study_id, '_co360_ssa_crd_url', true );
				$study_url = '';
				if ( $study_page_id > 0 ) {
					$study_url = get_permalink( $study_page_id );
				} elseif ( ! empty( $crd_url ) ) {
					$study_url = $crd_url;
				} else {
					$post_type = get_post_type_object( $study->post_type );
					if ( $post_type && $post_type->publicly_queryable ) {
						$study_url = get_permalink( $study_id );
					}
				}

				$date_display = '';
				if ( $show_dates && ! empty( $row['last_enrolled'] ) ) {
					$date_display = date_i18n( get_option( 'date_format' ), strtotime( $row['last_enrolled'] ) );
				}

				$entries[] = array(
					'title' => $study->post_title,
					'url' => $study_url,
					'date' => $date_display,
					'is_active' => $is_active,
				);
			}
		}

		wp_enqueue_style( 'co360-ssa-front' );
		ob_start();
		include CO360_SSA_PLUGIN_PATH . 'templates/shortcode-my-studies.php';
		return ob_get_clean();
	}

	public function ajax_check_login() {
		check_ajax_referer( 'co360_ssa_login', 'nonce' );

		$username = Utils::sanitize_text( $_POST['username'] ?? '' );
		if ( false !== strpos( $username, '@' ) ) {
			$user = get_user_by( 'email', $username );
			if ( $user ) {
				$username = $user->user_login;
			}
		}

		$creds = array(
			'user_login' => $username,
			'user_password' => Utils::sanitize_text( $_POST['password'] ?? '' ),
			'remember' => ! empty( $_POST['remember'] ),
		);

		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Credenciales inválidas.', CO360_SSA_TEXT_DOMAIN ) ) );
		}

		$redirect_to = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) ), home_url( '/' ) );
		// Respect redirect_to to continue after_login flow.
		wp_send_json_success( array( 'redirect' => $redirect_to ) );
	}
}
