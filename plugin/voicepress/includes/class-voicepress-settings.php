<?php
/**
 * VoicePress settings — a single option array `voicepress_settings`.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read/write access to the plugin settings, merged with defaults.
 */
class VoicePress_Settings {

	const OPTION = 'voicepress_settings';

	/**
	 * Keys that must never be empty — an empty stored value falls back to the default.
	 *
	 * @var string[]
	 */
	private static $never_empty = array( 'store_name', 'agent_persona', 'default_country_code', 'telephony_provider', 'delivery_eta', 'support_hours', 'widget_label' );

	/**
	 * Integer settings and their allowed range.
	 *
	 * @var array
	 */
	private static $int_ranges = array(
		'return_window_days'    => array( 0, 365 ),
		'calling_hours_start'   => array( 0, 23 ),
		'calling_hours_end'     => array( 1, 24 ),
		'max_attempts'          => array( 1, 5 ),
		'retry_after'           => array( 1, 1440 ),
		'cart_discount_percent' => array( 0, 100 ),
	);

	/**
	 * On/off settings stored as 0/1.
	 *
	 * @var string[]
	 */
	private static $flags = array( 'respect_calling_hours', 'widget_enabled' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Self-heal when the plugin files were updated/copied without running the activation hook.
		if ( ! is_array( get_option( self::OPTION ) ) ) {
			self::ensure_defaults();
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$store_name = function_exists( 'get_bloginfo' ) ? trim( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) ) : '';

		return array(
			'api_key'               => '',
			'from_number'           => '',
			'phone_number_id'       => '',
			'telephony_provider'    => 'twilio',
			'public_url'            => '',
			'secret'                => '', // Generated once and stored by ensure_defaults()/secret().
			'store_name'            => '' !== $store_name ? $store_name : 'our store',
			'agent_persona'         => 'Riya',
			'default_country_code'  => '91',
			'return_window_days'    => 7,
			'delivery_eta'          => '3 to 5 working days',
			'support_hours'         => '9 AM to 9 PM, all days',
			'store_policies'        => 'Free delivery above 499 rupees. Cash on delivery available. Easy 7 day returns on unused items.',
			'agents'                => array(
				'cod_confirmation' => '',
				'delivery'         => '',
				'cart_recovery'    => '',
				'feedback'         => '',
				'support'          => '',
			),
			'automations'           => array(
				'cod_confirmation'  => array(
					'enabled' => 1,
					'delay'   => 0,
				),
				'delivery_reminder' => array(
					'enabled' => 1,
					'delay'   => 0,
				),
				'ndr_resolution'    => array(
					'enabled' => 1,
					'delay'   => 0,
				),
				'feedback'          => array(
					'enabled' => 1,
					'delay'   => 2, // Minutes (demo default; real stores use ~2880).
				),
				'cart_recovery'     => array(
					'enabled' => 1,
					'delay'   => 15,
				),
			),
			'respect_calling_hours' => 0,
			'calling_hours_start'   => 9,
			'calling_hours_end'     => 21,
			'max_attempts'          => 2,
			'retry_after'           => 30, // Minutes.
			'widget_enabled'        => 1,
			'widget_label'          => 'Talk to us',
			'cart_discount_percent' => 10,
		);
	}

	/**
	 * Get one setting, or the full merged settings array when $key is null.
	 *
	 * @param string|null $key     Setting key.
	 * @param mixed       $default Returned when the key does not exist.
	 * @return mixed
	 */
	public static function get( $key = null, $default = null ) {
		$all = self::all();
		if ( null === $key ) {
			return $all;
		}
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			return $default;
		}
		return ( array_key_exists( $key, $all ) && null !== $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Merge values into the stored settings and save. Known keys are sanitized.
	 * Note: an empty 'api_key' clears the key — omit it to keep the current one.
	 *
	 * @param array $values Values to save.
	 * @return void
	 */
	public static function update( array $values ): void {
		$stored = self::stored();
		$merged = array_replace_recursive( $stored, self::sanitize( $values ) );
		if ( ! isset( $merged['secret'] ) || ! is_string( $merged['secret'] ) || '' === $merged['secret'] ) {
			$merged['secret'] = ! empty( $stored['secret'] ) && is_string( $stored['secret'] ) ? $stored['secret'] : self::generate_secret();
		}
		update_option( self::OPTION, $merged );
	}

	/**
	 * Create the option (with a freshly generated secret) when missing; add the secret when absent.
	 *
	 * @return void
	 */
	public static function ensure_defaults(): void {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			$values           = self::defaults();
			$values['secret'] = self::generate_secret();
			if ( false === $stored ) {
				add_option( self::OPTION, $values );
			} else {
				update_option( self::OPTION, $values );
			}
			return;
		}
		if ( empty( $stored['secret'] ) || ! is_string( $stored['secret'] ) ) {
			$stored['secret'] = self::generate_secret();
			update_option( self::OPTION, $stored );
		}
	}

	/**
	 * Bolna API client, or null when no API key is configured.
	 *
	 * @return VoicePress_Bolna_Client|null
	 */
	public static function client(): ?VoicePress_Bolna_Client {
		$key = self::get( 'api_key', '' );
		$key = is_scalar( $key ) ? trim( (string) $key ) : '';
		if ( '' === $key || ! class_exists( 'VoicePress_Bolna_Client' ) ) {
			return null;
		}
		/**
		 * Filters the Bolna API base URL (useful for mocks in tests).
		 *
		 * @param string $base_url Base URL.
		 */
		$base_url = (string) apply_filters( 'voicepress_bolna_base_url', VoicePress_Bolna_Client::DEFAULT_BASE_URL );
		return new VoicePress_Bolna_Client( $key, $base_url );
	}

	/**
	 * Bolna agent id saved for an agent key ('' when not created yet).
	 *
	 * @param string $agent_key cod_confirmation|delivery|cart_recovery|feedback|support.
	 * @return string
	 */
	public static function agent_id( $agent_key ): string {
		$agent_key = is_scalar( $agent_key ) ? (string) $agent_key : '';
		$agents    = self::get( 'agents', array() );
		return ( is_array( $agents ) && isset( $agents[ $agent_key ] ) && is_scalar( $agents[ $agent_key ] ) ) ? trim( (string) $agents[ $agent_key ] ) : '';
	}

	/**
	 * Save the Bolna agent id for an agent key.
	 *
	 * @param string $agent_key Agent key.
	 * @param string $id        Bolna agent id ('' to forget it).
	 * @return void
	 */
	public static function set_agent_id( $agent_key, $id ): void {
		$agent_key = is_scalar( $agent_key ) ? sanitize_key( (string) $agent_key ) : '';
		if ( '' === $agent_key ) {
			return;
		}
		self::update( array( 'agents' => array( $agent_key => is_scalar( $id ) ? (string) $id : '' ) ) );
	}

	/**
	 * Shared secret for tool/webhook URLs (generated once, then stable).
	 *
	 * @return string
	 */
	public static function secret(): string {
		$stored = self::stored();
		if ( ! empty( $stored['secret'] ) && is_string( $stored['secret'] ) ) {
			return $stored['secret'];
		}
		self::ensure_defaults();
		$stored = self::stored();
		return ( ! empty( $stored['secret'] ) && is_string( $stored['secret'] ) ) ? $stored['secret'] : '';
	}

	/**
	 * Public URL (e.g. a Cloudflare tunnel) that Bolna uses to reach this site:
	 * trimmed, without trailing slash, '' when unset.
	 *
	 * @return string
	 */
	public static function public_url(): string {
		$url = self::get( 'public_url', '' );
		$url = is_scalar( $url ) ? trim( (string) $url ) : '';
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		return untrailingslashit( $url );
	}

	/**
	 * Is a Bolna API key configured?
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$key = self::get( 'api_key', '' );
		return is_scalar( $key ) && '' !== trim( (string) $key );
	}

	/**
	 * Automation settings for a use case.
	 *
	 * @param string $use_case cod_confirmation|delivery_reminder|ndr_resolution|feedback|cart_recovery.
	 * @return array ['enabled' => bool, 'delay' => int minutes]
	 */
	public static function automation( $use_case ): array {
		$use_case    = is_scalar( $use_case ) ? (string) $use_case : '';
		$automations = self::get( 'automations', array() );
		$row         = ( is_array( $automations ) && isset( $automations[ $use_case ] ) && is_array( $automations[ $use_case ] ) ) ? $automations[ $use_case ] : array();
		return array(
			'enabled' => ! empty( $row['enabled'] ),
			'delay'   => isset( $row['delay'] ) ? max( 0, (int) $row['delay'] ) : 0,
		);
	}

	/**
	 * Mask an API key for display: 'bn-…a46eb'.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask_key( $key ): string {
		$key = is_scalar( $key ) ? trim( (string) $key ) : '';
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) < 12 ) {
			return '…' . substr( $key, -2 );
		}
		return substr( $key, 0, 3 ) . '…' . substr( $key, -5 );
	}

	/**
	 * Stored option merged with defaults.
	 *
	 * @return array
	 */
	private static function all() {
		$defaults = self::defaults();
		$stored   = self::stored();
		foreach ( array( 'agents', 'automations' ) as $group ) {
			if ( isset( $stored[ $group ] ) && ! is_array( $stored[ $group ] ) ) {
				unset( $stored[ $group ] );
			}
		}
		if ( isset( $stored['automations'] ) ) {
			foreach ( $stored['automations'] as $use_case => $row ) {
				if ( ! is_array( $row ) ) {
					unset( $stored['automations'][ $use_case ] );
				}
			}
		}

		$all = array_replace_recursive( $defaults, $stored );

		foreach ( self::$never_empty as $key ) {
			if ( ! isset( $all[ $key ] ) || ! is_scalar( $all[ $key ] ) || '' === trim( (string) $all[ $key ] ) ) {
				$all[ $key ] = $defaults[ $key ];
			}
		}
		foreach ( $all['automations'] as $use_case => $row ) {
			$all['automations'][ $use_case ] = array_merge(
				$row,
				array(
					'enabled' => empty( $row['enabled'] ) ? 0 : 1,
					'delay'   => isset( $row['delay'] ) ? max( 0, (int) $row['delay'] ) : 0,
				)
			);
		}
		foreach ( self::$int_ranges as $key => $range ) {
			$all[ $key ] = is_numeric( $all[ $key ] ) ? (int) $all[ $key ] : (int) $defaults[ $key ];
		}
		foreach ( self::$flags as $key ) {
			$all[ $key ] = self::to_flag( $all[ $key ] );
		}

		// Optional: keep the API key out of the database with define( 'VOICEPRESS_BOLNA_API_KEY', 'bn-…' ) in wp-config.php.
		if ( defined( 'VOICEPRESS_BOLNA_API_KEY' ) && is_string( VOICEPRESS_BOLNA_API_KEY ) && '' !== trim( VOICEPRESS_BOLNA_API_KEY ) ) {
			$all['api_key'] = trim( VOICEPRESS_BOLNA_API_KEY );
		}

		if ( ! is_string( $all['secret'] ) || '' === $all['secret'] ) {
			$all['secret'] = self::secret();
		}
		return $all;
	}

	/**
	 * Raw stored option (always an array).
	 *
	 * @return array
	 */
	private static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Sanitize known keys; unknown keys pass through untouched.
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private static function sanitize( array $values ) {
		$clean = array();
		foreach ( $values as $key => $value ) {
			switch ( $key ) {
				case 'agents':
					if ( is_array( $value ) ) {
						$clean['agents'] = array();
						foreach ( $value as $agent_key => $agent_id ) {
							$clean['agents'][ sanitize_key( $agent_key ) ] = is_scalar( $agent_id ) ? sanitize_text_field( (string) $agent_id ) : '';
						}
					}
					break;

				case 'automations':
					if ( is_array( $value ) ) {
						$clean['automations'] = array();
						foreach ( $value as $use_case => $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							$use_case = sanitize_key( $use_case );
							$entry    = array();
							if ( array_key_exists( 'enabled', $row ) ) {
								$entry['enabled'] = self::to_flag( $row['enabled'] );
							}
							if ( array_key_exists( 'delay', $row ) ) {
								$entry['delay'] = min( 525600, absint( $row['delay'] ) );
							}
							$clean['automations'][ $use_case ] = $entry;
						}
					}
					break;

				case 'api_key':
				case 'phone_number_id':
				case 'secret':
					$clean[ $key ] = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
					break;

				case 'from_number':
					$clean[ $key ] = is_scalar( $value ) ? preg_replace( '/[^\d+]/', '', (string) $value ) : '';
					break;

				case 'telephony_provider':
					$clean[ $key ] = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
					break;

				case 'public_url':
					$url = is_scalar( $value ) ? trim( (string) $value ) : '';
					if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
						$url = 'https://' . ltrim( $url, '/' ); // Tunnels are https; plain http would turn POSTs into GET redirects.
					}
					$clean[ $key ] = '' === $url ? '' : untrailingslashit( esc_url_raw( $url, array( 'http', 'https' ) ) );
					break;

				case 'default_country_code':
					$clean[ $key ] = is_scalar( $value ) ? substr( preg_replace( '/\D+/', '', (string) $value ), 0, 4 ) : '';
					break;

				case 'store_policies':
					$clean[ $key ] = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
					break;

				case 'store_name':
				case 'agent_persona':
				case 'delivery_eta':
				case 'support_hours':
				case 'widget_label':
					$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
					break;

				default:
					if ( isset( self::$int_ranges[ $key ] ) ) {
						$range         = self::$int_ranges[ $key ];
						$clean[ $key ] = max( $range[0], min( $range[1], (int) ( is_numeric( $value ) ? $value : $range[0] ) ) );
					} elseif ( in_array( $key, self::$flags, true ) ) {
						$clean[ $key ] = self::to_flag( $value );
					} else {
						$clean[ $key ] = $value;
					}
			}
		}
		return $clean;
	}

	/**
	 * Truthy form values ('1', 'on', 'yes', true, 1) → 1, everything else → 0.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function to_flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0 ? 1 : 0;
		}
		return ( is_string( $value ) && in_array( strtolower( trim( $value ) ), array( 'on', 'yes', 'true' ), true ) ) ? 1 : 0;
	}

	/**
	 * A new random secret.
	 *
	 * @return string
	 */
	private static function generate_secret() {
		return wp_generate_password( 32, false, false );
	}
}
