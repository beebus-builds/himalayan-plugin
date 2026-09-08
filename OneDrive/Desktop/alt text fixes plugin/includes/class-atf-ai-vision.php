<?php
/**
 * Optional AI Vision fallback for generating descriptive alt text.
 *
 * The provider is intentionally a fallback: the plugin first tries its normal
 * title/file-name/context logic. AI is only called when that result is empty
 * or too generic, which keeps API usage and cost under control.
 */
class ATF_AI_Vision {

	const OPTION = 'atf_ai_settings';

	/**
	 * Register the provider and its settings.
	 */
	public static function init() {
		add_filter( 'atf_generate_alt', array( __CLASS__, 'generate' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register AI settings on the existing Alt Text Fixer settings page.
	 */
	public static function register_settings() {
		register_setting(
			'atf_settings_group',
			self::OPTION,
			array( __CLASS__, 'sanitize_settings' )
		);

		add_settings_section(
			'atf_ai_vision',
			esc_html__( 'AI Vision Alt Text', 'alt-text-fixer' ),
			array( __CLASS__, 'section_description' ),
			'alt-text-fixer'
		);

		add_settings_field(
			'atf_ai_enabled',
			esc_html__( 'Enable AI Vision fallback', 'alt-text-fixer' ),
			array( __CLASS__, 'field_enabled' ),
			'alt-text-fixer',
			'atf_ai_vision'
		);

		add_settings_field(
			'atf_ai_model',
			esc_html__( 'Vision model', 'alt-text-fixer' ),
			array( __CLASS__, 'field_model' ),
			'alt-text-fixer',
			'atf_ai_vision'
		);

		add_settings_field(
			'atf_ai_api_key',
			esc_html__( 'OpenAI API key', 'alt-text-fixer' ),
			array( __CLASS__, 'field_api_key' ),
			'alt-text-fixer',
			'atf_ai_vision'
		);
	}

	/**
	 * Settings section description.
	 */
	public static function section_description() {
		echo '<p>' . esc_html__( 'Used only when the normal title/file-name/context fallback produces an empty or generic alt value. The API key may also be defined as ATF_OPENAI_API_KEY in wp-config.php.', 'alt-text-fixer' ) . '</p>';
	}

	/**
	 * Render enable field.
	 */
	public static function field_enabled() {
		$settings = self::settings();
		printf(
			'<label><input type="checkbox" name="%1$s[enabled]" value="yes" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( 'yes', $settings['enabled'], false ),
			esc_html__( 'Generate image descriptions with AI when normal rules cannot produce a useful alt text.', 'alt-text-fixer' )
		);
	}

	/**
	 * Render model field.
	 */
	public static function field_model() {
		$settings = self::settings();
		printf(
			'<input type="text" class="regular-text" name="%1$s[model]" value="%2$s" placeholder="gpt-5.6-luna">',
			esc_attr( self::OPTION ),
			esc_attr( $settings['model'] )
		);
	}

	/**
	 * Render API key field.
	 */
	public static function field_api_key() {
		$settings = self::settings();
		$defined  = defined( 'ATF_OPENAI_API_KEY' ) && ATF_OPENAI_API_KEY;
		printf(
			'<input type="password" class="regular-text" name="%1$s[api_key]" value="%2$s" autocomplete="new-password" placeholder="%3$s">',
			esc_attr( self::OPTION ),
			$defined ? '' : esc_attr( $settings['api_key'] ),
			$defined ? esc_attr__( 'Using ATF_OPENAI_API_KEY from wp-config.php', 'alt-text-fixer' ) : 'sk-...'
		);
		if ( $defined ) {
			echo '<p class="description">' . esc_html__( 'A wp-config.php constant is active, so the stored key is ignored.', 'alt-text-fixer' ) . '</p>';
		}
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$current = self::settings();
		$key     = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';

		return array(
			'enabled' => ! empty( $input['enabled'] ) ? 'yes' : 'no',
			'model'   => ! empty( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'gpt-5.6-luna',
			'api_key' => '' !== $key ? sanitize_text_field( $key ) : $current['api_key'],
		);
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array(
			'enabled' => isset( $saved['enabled'] ) ? $saved['enabled'] : 'no',
			'model'   => isset( $saved['model'] ) && $saved['model'] ? $saved['model'] : 'gpt-5.6-luna',
			'api_key' => isset( $saved['api_key'] ) ? $saved['api_key'] : '',
		);
	}

	/**
	 * Get the configured API key.
	 *
	 * @return string
	 */
	private static function api_key() {
		if ( defined( 'ATF_OPENAI_API_KEY' ) && ATF_OPENAI_API_KEY ) {
			return trim( (string) ATF_OPENAI_API_KEY );
		}
		$settings = self::settings();
		return trim( (string) $settings['api_key'] );
	}

	/**
	 * Generate an AI description only when the normal candidate is not useful.
	 *
	 * @param string $alt Current candidate.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $name Raw title/file name.
	 * @return string
	 */
	public static function generate( $alt, $attachment_id, $name ) {
		$settings = self::settings();

		if ( 'yes' !== $settings['enabled'] || ! $attachment_id || ! self::is_candidate_weak( $alt ) ) {
			return $alt;
		}

		$key = self::api_key();
		if ( '' === $key ) {
			return $alt;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url || ! wp_attachment_is_image( $attachment_id ) ) {
			return $alt;
		}

		// Cache successful results so the same image never triggers a request on
		// every bulk pass or page load.
		$cache_key = '_atf_ai_alt_text';
		$cached    = get_post_meta( $attachment_id, $cache_key, true );
		if ( is_string( $cached ) && '' !== trim( $cached ) ) {
			return trim( $cached );
		}

		$prompt = 'Write one concise, accurate, accessibility-friendly alt text for this image. '
			. 'Describe the meaningful visible subject, action, object, or scene. '
			. 'Do not start with "image of" or "picture of". Do not invent details. '
			. 'Do not mention colors unless they help identify the subject. '
			. 'Return only the alt text, with no quotes, bullets, or explanation. '
			. 'Keep it under 125 characters.';

		$body = array(
			'model' => $settings['model'],
			'input' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $prompt,
						),
						array(
							'type'      => 'input_image',
							'image_url' => $url,
							'detail'    => 'low',
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
			'timeout' => 20,
			'headers' => array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
		);

		if ( is_wp_error( $response ) ) {
			return $alt;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $alt;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = self::extract_output_text( $data );
		$text = self::clean_alt( $text );

		if ( '' === $text || self::is_candidate_weak( $text ) ) {
			return $alt;
		}

		update_post_meta( $attachment_id, $cache_key, $text );
		return $text;
	}

	/**
	 * Decide whether a candidate deserves AI assistance.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_candidate_weak( $value ) {
		$value = trim( (string) $value );
		$generic = array( 'image', 'img', 'photo', 'picture', 'pic', 'graphic', 'figure', 'untitled' );
		if ( '' === $value || mb_strlen( $value ) <= 1 ) {
			return true;
		}
		return in_array( mb_strtolower( $value ), $generic, true );
	}

	/**
	 * Extract text from a Responses API response.
	 *
	 * @param array $data Decoded response.
	 * @return string
	 */
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

	/**
	 * Normalize the model response before storing it.
	 *
	 * @param string $text Raw response.
	 * @return string
	 */
	private static function clean_alt( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/[\r\n\t]+/', ' ', $text );
		$text = trim( $text, " \t\n\r\0\x0B\"'`" );
		$text = preg_replace( '/^(?:alt\s*text\s*:\s*)/i', '', $text );
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
