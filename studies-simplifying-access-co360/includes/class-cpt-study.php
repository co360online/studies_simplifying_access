<?php
namespace CO360\SSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPT_Study {
	public function register() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'save_post_' . CO360_SSA_CT_STUDY, array( $this, 'save_metabox' ) );
	}

	public function register_post_type() {
		$labels = array(
			'name' => __( 'Estudios', CO360_SSA_TEXT_DOMAIN ),
			'singular_name' => __( 'Estudio', CO360_SSA_TEXT_DOMAIN ),
			'add_new_item' => __( 'Añadir estudio', CO360_SSA_TEXT_DOMAIN ),
			'edit_item' => __( 'Editar estudio', CO360_SSA_TEXT_DOMAIN ),
			'menu_name' => __( 'Estudios', CO360_SSA_TEXT_DOMAIN ),
		);

		$args = array(
			'labels' => $labels,
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'supports' => array( 'title', 'editor', 'thumbnail' ),
			'has_archive' => false,
			'publicly_queryable' => false,
			'show_in_rest' => false,
		);

		register_post_type( CO360_SSA_CT_STUDY, $args );
	}

	public function add_metabox() {
		add_meta_box(
			'co360_ssa_study_meta',
			__( 'Parámetros del estudio (CO360)', CO360_SSA_TEXT_DOMAIN ),
			array( $this, 'render_metabox' ),
			CO360_SSA_CT_STUDY,
			'normal',
			'default'
		);
	}

	public function render_metabox( $post ) {
		wp_nonce_field( 'co360_ssa_study_meta', 'co360_ssa_study_meta_nonce' );
		$meta = Utils::get_study_meta( $post->ID );
		if ( empty( $meta['activo'] ) ) {
			$meta['activo'] = '1';
		}
		?>
		<p>
			<strong><?php esc_html_e( 'Opción 1 – Prefijo del código', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<input type="text" name="co360_ssa_prefijo" value="<?php echo esc_attr( $meta['prefijo'] ); ?>" class="regular-text">
		</p>
		<p>
			<strong><?php esc_html_e( 'Opción 2 – Regex del código', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<input type="text" name="co360_ssa_regex" value="<?php echo esc_attr( $meta['regex'] ); ?>" class="regular-text">
		</p>
		<p>
			<strong><?php esc_html_e( 'URL del CRD', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<input type="url" name="co360_ssa_crd_url" value="<?php echo esc_attr( $meta['crd_url'] ); ?>" class="regular-text" placeholder="https://...">
		</p>
		<p>
			<strong><?php esc_html_e( 'Formulario de inscripción (Formidable) — opcional', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<input type="number" name="co360_ssa_enroll_form_id" value="<?php echo esc_attr( $meta['enroll_form_id'] ); ?>" class="small-text">
		</p>
		<p>
			<strong><?php esc_html_e( 'ID del campo centro (select)', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<input type="number" name="co360_ssa_center_select_field_id" value="<?php echo esc_attr( $meta['center_select_field_id'] ); ?>" class="small-text">
		</p>
		<p>
			<strong><?php esc_html_e( 'ID del campo otro centro (texto)', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<input type="number" name="co360_ssa_center_other_field_id" value="<?php echo esc_attr( $meta['center_other_field_id'] ); ?>" class="small-text">
		</p>
		<p>
			<strong><?php esc_html_e( 'Centros del estudio (seed)', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<textarea name="co360_ssa_centers_seed" class="large-text" rows="6"><?php echo esc_textarea( $meta['centers_seed'] ); ?></textarea>
		</p>
		<p>
			<strong><?php esc_html_e( 'Página de inscripción', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<?php
			wp_dropdown_pages(
				array(
					'name' => 'co360_ssa_enroll_page_id',
					'show_option_none' => __( '-- Seleccionar --', CO360_SSA_TEXT_DOMAIN ),
					'option_none_value' => '0',
					'selected' => $meta['enroll_page_id'],
				)
			);
			?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Modo de código', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<select name="co360_ssa_code_mode">
				<option value="single" <?php selected( $meta['code_mode'], 'single' ); ?>><?php esc_html_e( 'Único por estudio (prefijo/regex)', CO360_SSA_TEXT_DOMAIN ); ?></option>
				<option value="list" <?php selected( $meta['code_mode'], 'list' ); ?>><?php esc_html_e( 'Listado individual por investigador', CO360_SSA_TEXT_DOMAIN ); ?></option>
			</select>
		</p>
		<p>
			<label>
				<input type="checkbox" name="co360_ssa_lock_email" value="1" <?php checked( $meta['lock_email'], '1' ); ?>>
				<?php esc_html_e( 'Bloquear código al primer email que lo use', CO360_SSA_TEXT_DOMAIN ); ?>
			</label>
		</p>

		<p>
			<strong><?php esc_html_e( 'Página del estudio (landing)', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<?php
			wp_dropdown_pages(
				array(
					'name' => 'co360_ssa_study_page_id',
					'show_option_none' => __( '-- Seleccionar --', CO360_SSA_TEXT_DOMAIN ),
					'option_none_value' => '0',
					'selected' => $meta['study_page_id'],
				)
			);
			?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Páginas protegidas', CO360_SSA_TEXT_DOMAIN ); ?></strong><br>
			<?php
			$pages = get_pages();
			$protected = is_array( $meta['protected_pages'] ) ? array_map( 'absint', $meta['protected_pages'] ) : array();
			foreach ( $pages as $page ) :
				?>
				<label style="display:block; margin:4px 0;">
					<input type="checkbox" name="co360_ssa_protected_pages[]" value="<?php echo esc_attr( $page->ID ); ?>" <?php checked( in_array( $page->ID, $protected, true ) ); ?>>
					<?php echo esc_html( $page->post_title ); ?>
				</label>
			<?php endforeach; ?>
		</p>
		<p>
			<label>
				<input type="checkbox" name="co360_ssa_activo" value="1" <?php checked( $meta['activo'], '1' ); ?>>
				<?php esc_html_e( 'Estudio activo', CO360_SSA_TEXT_DOMAIN ); ?>
			</label>
		</p>
		<?php
	}

	public function save_metabox( $post_id ) {
		if ( ! isset( $_POST['co360_ssa_study_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['co360_ssa_study_meta_nonce'] ) ), 'co360_ssa_study_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_co360_ssa_prefijo', Utils::sanitize_text( $_POST['co360_ssa_prefijo'] ?? '' ) );
		update_post_meta( $post_id, '_co360_ssa_regex', Utils::sanitize_text( $_POST['co360_ssa_regex'] ?? '' ) );
		update_post_meta( $post_id, '_co360_ssa_crd_url', Utils::sanitize_url( $_POST['co360_ssa_crd_url'] ?? '' ) );
		update_post_meta( $post_id, '_co360_ssa_enroll_form_id', absint( $_POST['co360_ssa_enroll_form_id'] ?? 0 ) );
		update_post_meta( $post_id, '_co360_ssa_center_select_field_id', absint( $_POST['co360_ssa_center_select_field_id'] ?? 0 ) );
		update_post_meta( $post_id, '_co360_ssa_center_other_field_id', absint( $_POST['co360_ssa_center_other_field_id'] ?? 0 ) );
		$centers_seed = sanitize_textarea_field( wp_unslash( $_POST['co360_ssa_centers_seed'] ?? '' ) );
		update_post_meta( $post_id, '_co360_ssa_centers_seed', $centers_seed );
		update_post_meta( $post_id, '_co360_ssa_enroll_page_id', absint( $_POST['co360_ssa_enroll_page_id'] ?? 0 ) );
		update_post_meta( $post_id, '_co360_ssa_code_mode', ( isset( $_POST['co360_ssa_code_mode'] ) && 'list' === $_POST['co360_ssa_code_mode'] ) ? 'list' : 'single' );
		update_post_meta( $post_id, '_co360_ssa_lock_email', isset( $_POST['co360_ssa_lock_email'] ) ? '1' : '0' );
		update_post_meta( $post_id, '_co360_ssa_activo', isset( $_POST['co360_ssa_activo'] ) ? '1' : '0' );
		update_post_meta( $post_id, '_co360_ssa_study_page_id', absint( $_POST['co360_ssa_study_page_id'] ?? 0 ) );
		$protected_pages = array();
		if ( isset( $_POST['co360_ssa_protected_pages'] ) && is_array( $_POST['co360_ssa_protected_pages'] ) ) {
			$protected_pages = array_map( 'absint', wp_unslash( $_POST['co360_ssa_protected_pages'] ) );
		}
		update_post_meta( $post_id, '_co360_ssa_protected_pages', $protected_pages );

		$this->sync_centers_seed( $post_id, $centers_seed );
	}

	private function sync_centers_seed( $study_id, $raw_seed ) {
		$centers = Utils::parse_centers_seed( $raw_seed );
		if ( empty( $centers ) ) {
			return;
		}

		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_CENTERS );

		foreach ( $centers as $center ) {
			$name = Utils::normalize_center_name( $center['name'] );
			if ( '' === $name ) {
				continue;
			}
			$slug = Utils::center_slug( $name );
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, center_name FROM {$table} WHERE estudio_id = %d AND center_slug = %s",
					$study_id,
					$slug
				),
				ARRAY_A
			);
			if ( $existing ) {
				if ( $existing['center_name'] !== $name ) {
					$wpdb->update(
						$table,
						array( 'center_name' => $name ),
						array( 'id' => $existing['id'] ),
						array( '%s' ),
						array( '%d' )
					);
				}
				continue;
			}

			$raw_code = sanitize_text_field( $center['code'] );
			$code = Utils::format_center_code( $raw_code );
			if ( '' !== $raw_code && '' === $code ) {
				Utils::log( 'Código de centro inválido en seed para estudio ' . (int) $study_id . ': ' . $raw_code );
				continue;
			}
			if ( $code && ! Utils::is_center_code_valid( $code, $study_id ) ) {
				Utils::log( 'Código de centro no permitido en seed para estudio ' . (int) $study_id . ': ' . $code );
				continue;
			}
			if ( $code ) {
				$code_exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE estudio_id = %d AND center_code = %s",
						$study_id,
						$code
					)
				);
				if ( $code_exists ) {
					continue;
				}
			} else {
				$code = $this->next_center_code( $study_id );
			}

			if ( ! $code ) {
				Utils::log( 'No se pudo asignar center_code en seed para estudio ' . (int) $study_id );
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'estudio_id' => $study_id,
					'center_code' => $code,
					'center_name' => $name,
					'center_slug' => $slug,
					'source' => 'seed',
					'created_by' => get_current_user_id(),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	private function next_center_code( $study_id ) {
		global $wpdb;
		$table = DB::table_name( CO360_SSA_DB_STUDY_SEQ );
		for ( $attempts = 0; $attempts < 5; $attempts++ ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (estudio_id, last_center_num, updated_at)
					VALUES (%d, 0, NOW())
					ON DUPLICATE KEY UPDATE last_center_num = LAST_INSERT_ID(last_center_num + 1), updated_at = NOW()",
					$study_id
				)
			);
			$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
			$code = Utils::format_center_code( (string) $seq );
			if ( '' !== $code && Utils::is_center_code_valid( $code, $study_id ) ) {
				return $code;
			}
		}
		Utils::log( 'No se pudo generar center_code válido para estudio ' . (int) $study_id );
		return '';
	}
}
