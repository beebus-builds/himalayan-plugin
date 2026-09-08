<?php
/**
 * AI Vision fallback for descriptive alt text.
 *
 * Cloud OpenAI support is optional. The primary free AI path is browser-local
 * inference with Transformers.js + ONNX Florence-2, so no API key is required.
 */
class ATF_AI_Vision {

	const OPTION        = 'atf_ai_settings';
	const DEFAULT_MODEL = 'onnx-community/Florence-2-base';
	const NONCE         = 'atf_ai_vision_nonce';

	public static function init() {
		add_filter( 'atf_generate_alt', array( __CLASS__, 'generate' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_atf_ai_next', array( __CLASS__, 'ajax_next' ) );
		add_action( 'wp_ajax_atf_ai_image', array( __CLASS__, 'ajax_image' ) );
		add_action( 'wp_ajax_atf_ai_save', array( __CLASS__, 'ajax_save' ) );
	}

	public static function register_settings() {
		register_setting( 'atf_settings_group', self::OPTION, array( __CLASS__, 'sanitize_settings' ) );

		add_settings_section( 'atf_ai_vision', esc_html__( 'AI Vision Alt Text', 'alt-text-fixer' ), array( __CLASS__, 'section_description' ), 'alt-text-fixer' );
		add_settings_field( 'atf_ai_browser_enabled', esc_html__( 'Free Browser AI', 'alt-text-fixer' ), array( __CLASS__, 'field_browser_enabled' ), 'alt-text-fixer', 'atf_ai_vision' );
		add_settings_field( 'atf_ai_model', esc_html__( 'Vision model', 'alt-text-fixer' ), array( __CLASS__, 'field_model' ), 'alt-text-fixer', 'atf_ai_vision' );
		add_settings_field( 'atf_ai_api_key', esc_html__( 'OpenAI API key (optional)', 'alt-text-fixer' ), array( __CLASS__, 'field_api_key' ), 'alt-text-fixer', 'atf_ai_vision' );
	}

	public static function section_description() {
		echo '<p>' . esc_html__( 'The free Browser AI runs Florence-2 locally in the administrator browser through Transformers.js. Images are processed locally and no AI API key is required. OpenAI remains an optional server-side fallback.', 'alt-text-fixer' ) . '</p>';
	}

	public static function field_browser_enabled() {
		$settings = self::settings();
		printf(
			'<label><input type="checkbox" name="%1$s[browser_enabled]" value="yes" %2$s> %3$s</label><p class="description">%4$s</p><p><button type="button" class="button button-primary" id="atf-ai-start">%5$s</button> <span id="atf-ai-status" class="description"></span></p><div id="atf-ai-progress" style="max-width:620px;display:none"><div style="height:8px;background:#eee;border-radius:4px;overflow:hidden"><div id="atf-ai-progress-fill" style="width:0;height:100%;background:#2271b1"></div></div><p id="atf-ai-progress-text" class="description"></p></div>',
			esc_attr( self::OPTION ),
			checked( 'yes', $settings['browser_enabled'], false ),
			esc_html__( 'Use local Florence-2 in the browser to generate descriptive ALT text without an API key.', 'alt-text-fixer' ),
			esc_html__( 'The first run downloads the model from Hugging Face and the browser can cache it for later runs. Processing is done one image at a time to avoid freezing the page.', 'alt-text-fixer' ),
			esc_html__( 'Start AI Alt Text Fix', 'alt-text-fixer' )
		);
	}

	public static function field_model() {
		printf(
			'<input type="text" class="regular-text" value="%s" readonly><p class="description">%s</p>',
			esc_attr( self::DEFAULT_MODEL ),
			esc_html__( 'Browser AI model. It is ONNX-compatible with Transformers.js and does not require a server API.', 'alt-text-fixer' )
		);
	}

	public static function field_api_key() {
		$settings = self::settings();
		$defined  = defined( 'ATF_OPENAI_API_KEY' ) && ATF_OPENAI_API_KEY;
		printf( '<input type="password" class="regular-text" name="%1$s[api_key]" value="%2$s" autocomplete="new-password" placeholder="%3$s">', esc_attr( self::OPTION ), $defined ? '' : esc_attr( $settings['api_key'] ), $defined ? esc_attr__( 'Using ATF_OPENAI_API_KEY from wp-config.php', 'alt-text-fixer' ) : 'sk-...' );
	}

	public static function sanitize_settings( $input ) {
		$current = self::settings();
		$key     = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		return array(
			'browser_enabled' => ! empty( $input['browser_enabled'] ) ? 'yes' : 'no',
			'api_key'        => '' !== $key ? sanitize_text_field( $key ) : $current['api_key'],
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array(
			'browser_enabled' => isset( $saved['browser_enabled'] ) ? $saved['browser_enabled'] : 'yes',
			'api_key'         => isset( $saved['api_key'] ) ? $saved['api_key'] : '',
		);
	}

	private static function api_key() {
		if ( defined( 'ATF_OPENAI_API_KEY' ) && ATF_OPENAI_API_KEY ) {
			return trim( (string) ATF_OPENAI_API_KEY );
		}
		$settings = self::settings();
		return trim( (string) $settings['api_key'] );
	}

	/**
	 * Optional server-side OpenAI fallback for installations that configure a key.
	 */
	public static function generate( $alt, $attachment_id, $name ) {
		if ( ! self::is_candidate_weak( $alt ) ) {
			return $alt;
		}

		$key = self::api_key();
		if ( '' === $key || ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return $alt;
		}

		$cached = get_post_meta( $attachment_id, '_atf_ai_alt_text', true );
		if ( is_string( $cached ) && '' !== trim( $cached ) ) {
			return trim( $cached );
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return $alt;
		}

		$prompt = 'Write one concise, accurate, accessibility-friendly alt text for this image. Describe the meaningful visible subject, action, object, or scene. Do not start with image of or picture of. Do not invent details. Return only the alt text. Keep it under 125 characters.';
		$body   = array(
			'model' => 'gpt-4.1-mini',
			'input' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array( 'type' => 'input_text', 'text' => $prompt ),
						array( 'type' => 'input_image', 'image_url' => $url, 'detail' => 'low' ),
					),
				),
			),
		);

		$response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return $alt;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = self::clean_alt( self::extract_output_text( $data ) );
		if ( '' === $text || self::is_candidate_weak( $text ) ) {
			return $alt;
		}

		update_post_meta( $attachment_id, '_atf_ai_alt_text', $text );
		return $text;
	}

	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'alt-text-fixer' ) ) {
			return;
		}
		wp_enqueue_script( 'atf-ai-vision', ATF_URL . 'assets/ai-vision.js', array(), ATF_VERSION, true );
		wp_add_inline_script(
			'atf-ai-vision',
			'window.ATF_AI=' . wp_json_encode(
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE ),
					'model'   => self::DEFAULT_MODEL,
				)
			) . ';',
			'before'
		);
		add_filter( 'script_loader_tag', array( __CLASS__, 'module_script' ), 10, 3 );
	}

	public static function module_script( $tag, $handle, $src ) {
		if ( 'atf-ai-vision' !== $handle ) {
			return $tag;
		}
		return '<script type="module" src="' . esc_url( $src ) . '"></script>';
	}

	public static function ajax_next() {
		self::check_ajax_access();
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment'
			 AND p.post_mime_type LIKE 'image/%'
			 AND (m.meta_value IS NULL OR m.meta_value = '')
			 ORDER BY p.ID ASC LIMIT 1"
		);

		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'finished' => true ) );
		}

		$id = (int) $ids[0];
		wp_send_json_success(
			array(
				'finished' => false,
				'id'       => $id,
				'name'     => get_the_title( $id ),
			)
		);
	}

	public static function ajax_image() {
		self::check_ajax_access();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! wp_attachment_is_image( $id ) || Alt_Text_Fixer::has_alt( $id ) ) {
			wp_send_json_error( 'invalid_image' );
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! is_readable( $file ) ) {
			wp_send_json_error( 'file_unavailable' );
		}
		$size = filesize( $file );
		if ( false === $size || $size > 10 * MB_IN_BYTES ) {
			wp_send_json_error( 'image_too_large' );
		}
		$mime = get_post_mime_type( $id );
		$data = file_get_contents( $file );
		if ( false === $data ) {
			wp_send_json_error( 'read_failed' );
		}
		wp_send_json_success(
			array(
				'id'   => $id,
				'name' => get_the_title( $id ),
				'url'  => 'data:' . esc_attr( $mime ) . ';base64,' . base64_encode( $data ),
			)
		);
	}

	public static function ajax_save() {
		self::check_ajax_access();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$alt = self::clean_alt( $alt );
		if ( ! $id || '' === $alt || self::is_candidate_weak( $alt ) || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( 'invalid_alt' );
		}
		if ( Alt_Text_Fixer::has_alt( $id ) ) {
			wp_send_json_success( array( 'skipped' => true ) );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $id, '_atf_ai_alt_text', $alt );
		wp_send_json_success( array( 'alt' => $alt ) );
	}

	private static function check_ajax_access() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'permission', 403 );
		}
	}

	private static function is_candidate_weak( $value ) {
		$value   = trim( (string) $value );
		$generic = array( 'image', 'img', 'photo', 'picture', 'pic', 'graphic', 'figure', 'untitled' );
		if ( '' === $value || mb_strlen( $value ) <= 1 ) {
			return true;
		}
		return in_array( mb_strtolower( $value ), $generic, true );
	}

	private static function extract_output_text( $data ) {
		if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			return $data['output_text'];
		}
		if ( empty( $data['output'] ) || ! is_array( $data['output'] ) ) {
			return '';
		}
		foreach ( $data['output'] as $item ) {
			if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}
			foreach ( $item['content'] as $content ) {
				if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
					return $content['text'];
				}
			}
		}
		return '';
	}

	public static function clean_alt( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/[\r\n\t]+/', ' ', $text );
		$text = trim( $text, " \t\n\r\0\x0B\"'`" );
		$text = preg_replace( '/^(?:alt\s*text\s*:\s*)/i', '', $text );
		$text = preg_replace( '/^(?:the\s+image\s+(?:shows|depicts)\s+)/i', '', $text );
		$text = trim( $text );
		if ( mb_strlen( $text ) > 125 ) {
			$text = mb_substr( $text, 0, 122 );
			$text = preg_replace( '/\s+\S*$/u', '', $text );
			$text .= '...';
		}
		return $text;
	}
}

ATF_AI_Vision::init();
