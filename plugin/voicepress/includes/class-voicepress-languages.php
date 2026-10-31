<?php
/**
 * VoicePress languages: English plus the 10 Indian languages Bolna speaks end to end through Sarvam
 * (Hindi, Tamil, Telugu, Kannada, Malayalam, Marathi, Bengali, Gujarati, Punjabi and Odia).
 *
 * - The catalog (names, native names, scripts), greetings, hand-off lines and the prompt directive.
 * - Which languages are enabled, and which language a call starts in
 *   (order preference → customer's state → default language).
 * - Bookkeeping of the languages each Bolna agent was last provisioned with, so a call only asks
 *   Bolna to start in a language the agent really has (Bolna answers 400 otherwise).
 * - The "Preferred language for order calls" field on the block and classic checkout.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language catalog and per-call language selection (static).
 */
class VoicePress_Languages {

	/**
	 * Language codes, English first. Odia is "od" everywhere in Bolna.
	 */
	const CODES = array( 'en', 'hi', 'ta', 'te', 'kn', 'ml', 'mr', 'bn', 'gu', 'pa', 'od' );

	/**
	 * Code => [name (English), native, script].
	 */
	const CATALOG = array(
		'en' => array(
			'name'   => 'English',
			'native' => 'English',
			'script' => 'Latin',
		),
		'hi' => array(
			'name'   => 'Hindi',
			'native' => 'हिन्दी',
			'script' => 'Devanagari',
		),
		'ta' => array(
			'name'   => 'Tamil',
			'native' => 'தமிழ்',
			'script' => 'Tamil',
		),
		'te' => array(
			'name'   => 'Telugu',
			'native' => 'తెలుగు',
			'script' => 'Telugu',
		),
		'kn' => array(
			'name'   => 'Kannada',
			'native' => 'ಕನ್ನಡ',
			'script' => 'Kannada',
		),
		'ml' => array(
			'name'   => 'Malayalam',
			'native' => 'മലയാളം',
			'script' => 'Malayalam',
		),
		'mr' => array(
			'name'   => 'Marathi',
			'native' => 'मराठी',
			'script' => 'Devanagari',
		),
		'bn' => array(
			'name'   => 'Bengali',
			'native' => 'বাংলা',
			'script' => 'Bengali',
		),
		'gu' => array(
			'name'   => 'Gujarati',
			'native' => 'ગુજરાતી',
			'script' => 'Gujarati',
		),
		'pa' => array(
			'name'   => 'Punjabi',
			'native' => 'ਪੰਜਾਬੀ',
			'script' => 'Gurmukhi',
		),
		'od' => array(
			'name'   => 'Odia',
			'native' => 'ଓଡ଼ିଆ',
			'script' => 'Odia',
		),
	);

	/**
	 * Greeting for outbound calls (COD confirmation, delivery, cart recovery, feedback).
	 * {store} and {agent} are filled in at provisioning time; {{customer_name}} stays a Bolna variable.
	 */
	const OUTBOUND = array(
		'en' => 'Hi, am I speaking with {{customer_name}}? This is {agent} calling from {store}.',
		'hi' => 'नमस्ते! क्या मेरी बात {{customer_name}} जी से हो रही है? मैं {store} से {agent} बोल रही हूँ।',
		'ta' => 'வணக்கம்! நான் {{customer_name}} அவர்களுடன் பேசுகிறேனா? நான் {store}-இலிருந்து {agent} பேசுகிறேன்.',
		'te' => 'నమస్కారం! నేను {{customer_name}} గారితో మాట్లాడుతున్నానా? నేను {store} నుండి {agent} మాట్లాడుతున్నాను.',
		'kn' => 'ನಮಸ್ಕಾರ! ನಾನು {{customer_name}} ಅವರೊಂದಿಗೆ ಮಾತನಾಡುತ್ತಿದ್ದೇನೆಯೇ? ನಾನು {store} ಇಂದ {agent} ಮಾತನಾಡುತ್ತಿದ್ದೇನೆ.',
		'ml' => 'നമസ്കാരം! {{customer_name}} ആണോ സംസാരിക്കുന്നത്? ഞാൻ {store}-ൽ നിന്ന് {agent} ആണ്.',
		'mr' => 'नमस्कार! मी {{customer_name}} यांच्याशी बोलत आहे का? मी {store} मधून {agent} बोलत आहे.',
		'bn' => 'নমস্কার! আমি কি {{customer_name}}-এর সাথে কথা বলছি? আমি {store} থেকে {agent} বলছি।',
		'gu' => 'નમસ્તે! શું હું {{customer_name}} સાથે વાત કરી રહી છું? હું {store} તરફથી {agent} બોલું છું.',
		'pa' => 'ਸਤ ਸ੍ਰੀ ਅਕਾਲ! ਕੀ ਮੇਰੀ ਗੱਲ {{customer_name}} ਜੀ ਨਾਲ ਹੋ ਰਹੀ ਹੈ? ਮੈਂ {store} ਤੋਂ {agent} ਬੋਲ ਰਹੀ ਹਾਂ।',
		'od' => 'ନମସ୍କାର! ମୁଁ କ\'ଣ {{customer_name}}ଙ୍କ ସହ କଥା ହେଉଛି? ମୁଁ {store} ରୁ {agent} କହୁଛି।',
	);

	/**
	 * Greeting for the support agent (inbound phone calls, web calls and call-backs).
	 */
	const INBOUND = array(
		'en' => 'Hi, thank you for calling {store}. This is {agent}. How can I help you today?',
		'hi' => '{store} को कॉल करने के लिए धन्यवाद। मैं {agent} बोल रही हूँ, बताइए मैं आपकी क्या मदद कर सकती हूँ?',
		'ta' => '{store}-க்கு அழைத்ததற்கு நன்றி. நான் {agent} பேசுகிறேன், உங்களுக்கு எப்படி உதவலாம்?',
		'te' => '{store}కి కాల్ చేసినందుకు ధన్యవాదాలు. నేను {agent}, మీకు ఎలా సహాయం చేయగలను?',
		'kn' => '{store}ಗೆ ಕರೆ ಮಾಡಿದ್ದಕ್ಕೆ ಧನ್ಯವಾದಗಳು. ನಾನು {agent}, ನಿಮಗೆ ಹೇಗೆ ಸಹಾಯ ಮಾಡಲಿ?',
		'ml' => '{store}-ലേക്ക് വിളിച്ചതിന് നന്ദി. ഞാൻ {agent} ആണ്, എങ്ങനെ സഹായിക്കാം?',
		'mr' => '{store} ला कॉल केल्याबद्दल धन्यवाद. मी {agent} बोलत आहे, मी तुमची काय मदत करू शकते?',
		'bn' => '{store}-এ কল করার জন্য ধন্যবাদ। আমি {agent} বলছি, আপনাকে কীভাবে সাহায্য করতে পারি?',
		'gu' => '{store} ને કૉલ કરવા બદલ આભાર. હું {agent} બોલું છું, હું તમારી શું મદદ કરી શકું?',
		'pa' => '{store} ਨੂੰ ਕਾਲ ਕਰਨ ਲਈ ਧੰਨਵਾਦ। ਮੈਂ {agent} ਬੋਲ ਰਹੀ ਹਾਂ, ਮੈਂ ਤੁਹਾਡੀ ਕੀ ਮਦਦ ਕਰ ਸਕਦੀ ਹਾਂ?',
		'od' => '{store} କୁ କଲ୍ କରିଥିବାରୁ ଧନ୍ୟବାଦ। ମୁଁ {agent} କହୁଛି, ମୁଁ ଆପଣଙ୍କୁ କିପରି ସାହାଯ୍ୟ କରିପାରିବି?',
	);

	/**
	 * Played when the agent switches INTO the language.
	 */
	const HANDOFF = array(
		'en' => 'Sure, I\'ll continue in English.',
		'hi' => 'ज़रूर, अब मैं हिंदी में बात करती हूँ।',
		'ta' => 'சரி, இனி நான் தமிழில் பேசுகிறேன்.',
		'te' => 'సరే, ఇకపై నేను తెలుగులో మాట్లాడతాను.',
		'kn' => 'ಸರಿ, ಇನ್ನು ಮುಂದೆ ನಾನು ಕನ್ನಡದಲ್ಲಿ ಮಾತನಾಡುತ್ತೇನೆ.',
		'ml' => 'ശരി, ഇനി ഞാൻ മലയാളത്തിൽ സംസാരിക്കാം.',
		'mr' => 'नक्की, आता मी मराठीत बोलते.',
		'bn' => 'ঠিক আছে, এখন থেকে আমি বাংলায় কথা বলছি।',
		'gu' => 'ચોક્કસ, હવે હું ગુજરાતીમાં વાત કરું છું.',
		'pa' => 'ਠੀਕ ਹੈ, ਹੁਣ ਮੈਂ ਪੰਜਾਬੀ ਵਿੱਚ ਗੱਲ ਕਰਦੀ ਹਾਂ।',
		'od' => 'ଠିକ୍ ଅଛି, ଏବେ ମୁଁ ଓଡ଼ିଆରେ କଥା ହେବି।',
	);

	/**
	 * Appended to every non-English system prompt.
	 */
	const DIRECTIVE = '## Language' . "\n" . 'You are speaking {Name} ({native}). Reply ONLY in {Name}, written in {script} script — never transliterate {Name} into English letters. Keep product names, brand names, the store name and order numbers exactly as they are. Speak simple, everyday spoken {Name}, the way people talk on the phone; common English words such as order, delivery, cash on delivery, refund and OTP are fine. Say amounts as the number followed by the {Name} word for rupees. If the caller clearly switches to another language, follow them.';

	/**
	 * Sarvam bulbul:v3 voices available on the account: voice id => gender.
	 */
	const VOICES = array(
		'ritu'   => 'female',
		'pooja'  => 'female',
		'simran' => 'female',
		'shreya' => 'female',
		'ishita' => 'female',
		'amit'   => 'male',
		'manan'  => 'male',
		'shubh'  => 'male',
		'sumit'  => 'male',
	);

	/**
	 * Default Sarvam voice.
	 */
	const DEFAULT_VOICE = 'ritu';

	/**
	 * Bolna language_switch_trigger values.
	 */
	const SWITCH_TRIGGERS = array( 'requested_or_auto_detected', 'caller_requested' );

	/**
	 * How the starting language of an order call is chosen.
	 */
	const MODES = array( 'auto_state', 'default' );

	/**
	 * Indian state / union territory code (WooCommerce) => language.
	 */
	const STATE_LANGUAGES = array(
		'hi' => array( 'BR', 'CH', 'CT', 'CG', 'DL', 'HR', 'HP', 'JH', 'JK', 'LA', 'MP', 'RJ', 'UK', 'UT', 'UP', 'AN' ),
		'ta' => array( 'TN', 'PY' ),
		'te' => array( 'AP', 'TS', 'TG' ),
		'kn' => array( 'KA' ),
		'ml' => array( 'KL', 'LD' ),
		'mr' => array( 'MH' ),
		'bn' => array( 'WB', 'TR' ),
		'gu' => array( 'GJ', 'DN', 'DD', 'DH' ),
		'pa' => array( 'PB' ),
		'od' => array( 'OR', 'OD' ),
	);

	/**
	 * Order meta with the customer's preferred call language (classic checkout, or set by code).
	 */
	const ORDER_META = '_voicepress_language';

	/**
	 * Block checkout additional field id (stored by WooCommerce as _wc_other/voicepress/language).
	 */
	const CHECKOUT_FIELD_ID = 'voicepress/language';

	/**
	 * Classic checkout field name.
	 */
	const CLASSIC_FIELD = 'voicepress_language';

	/**
	 * Option: agent_key => [agent_id, languages, voice, switch, synced_at] as last sent to Bolna.
	 */
	const AGENTS_OPTION = 'voicepress_agent_languages';

	/**
	 * Sarvam models.
	 */
	const SARVAM_STT_MODEL = 'saaras:v4';
	const SARVAM_TTS_MODEL = 'bulbul:v3';

	/**
	 * Register the checkout field hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( did_action( 'woocommerce_init' ) ) {
			self::register_block_checkout_field();
		} else {
			add_action( 'woocommerce_init', array( __CLASS__, 'register_block_checkout_field' ) );
		}
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_classic_checkout_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic_checkout_field' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Catalog
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Every language VoicePress knows: code => [name, native, script].
	 *
	 * @return array
	 */
	public static function all(): array {
		return self::CATALOG;
	}

	/**
	 * A known language code from user input ('' otherwise). Accepts "TA", "ta-IN" and the ISO code "or" for Odia.
	 *
	 * @param mixed $code Code.
	 * @return string
	 */
	public static function sanitize_code( $code ): string {
		if ( ! is_scalar( $code ) ) {
			return '';
		}
		$code = strtolower( trim( str_replace( '_', '-', (string) $code ) ) );
		$dash = strpos( $code, '-' );
		if ( false !== $dash ) {
			$code = substr( $code, 0, $dash );
		}
		if ( 'or' === $code || 'ori' === $code ) {
			$code = 'od';
		}
		return in_array( $code, self::CODES, true ) ? $code : '';
	}

	/**
	 * A clean list of language codes: known codes only, no duplicates, English always first, catalog order.
	 *
	 * @param mixed $codes Codes (array or comma-separated string).
	 * @return string[]
	 */
	public static function normalize_list( $codes ): array {
		if ( is_string( $codes ) ) {
			$codes = explode( ',', $codes );
		}
		$wanted = array( 'en' );
		foreach ( (array) $codes as $code ) {
			$code = self::sanitize_code( $code );
			if ( '' !== $code ) {
				$wanted[] = $code;
			}
		}
		return array_values( array_intersect( self::CODES, $wanted ) );
	}

	/**
	 * English name ("Tamil"), '' for unknown codes.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function name( $code ): string {
		return self::field( $code, 'name' );
	}

	/**
	 * Native name ("தமிழ்"), '' for unknown codes.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function native( $code ): string {
		return self::field( $code, 'native' );
	}

	/**
	 * Script name ("Tamil"), '' for unknown codes.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function script( $code ): string {
		return self::field( $code, 'script' );
	}

	/**
	 * Display label: "தமிழ் · Tamil" ("English" for English), '' for unknown codes.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function label( $code ): string {
		$name   = self::name( $code );
		$native = self::native( $code );
		if ( '' === $name ) {
			return '';
		}
		return $native === $name ? $name : $native . ' · ' . $name;
	}

	/**
	 * HTML lang attribute for a code (Odia is "or" in HTML, "od" in Bolna).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function html_lang( $code ): string {
		$code = self::sanitize_code( $code );
		return 'od' === $code ? 'or' : $code;
	}

	/**
	 * Sarvam bulbul:v3 voices: voice id => label ("Ritu · female").
	 *
	 * @return array
	 */
	public static function voices(): array {
		$voices = array();
		foreach ( self::VOICES as $id => $gender ) {
			$voices[ $id ] = ucfirst( $id ) . ' · ' . ( 'male' === $gender ? __( 'male', 'voicepress' ) : __( 'female', 'voicepress' ) );
		}
		return $voices;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Enabled language codes (Settings "languages"): known codes only, English always first.
	 *
	 * @return string[]
	 */
	public static function enabled(): array {
		return self::normalize_list( self::setting( 'languages', self::CODES ) );
	}

	/**
	 * Whether a language is enabled.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function is_enabled( $code ): bool {
		$code = self::sanitize_code( $code );
		return '' !== $code && in_array( $code, self::enabled(), true );
	}

	/**
	 * Whether the agents speak more than one language.
	 *
	 * @return bool
	 */
	public static function is_multilingual(): bool {
		return count( self::enabled() ) >= 2;
	}

	/**
	 * Default call language (Settings "default_language" when enabled, else English).
	 *
	 * @return string
	 */
	public static function default_language(): string {
		$code = self::sanitize_code( self::setting( 'default_language', 'en' ) );
		return ( '' !== $code && in_array( $code, self::enabled(), true ) ) ? $code : 'en';
	}

	/**
	 * "auto_state" (start order calls in the language of the customer's state) or "default".
	 *
	 * @return string
	 */
	public static function mode(): string {
		$mode = self::setting( 'language_mode', 'auto_state' );
		return ( is_string( $mode ) && in_array( $mode, self::MODES, true ) ) ? $mode : 'auto_state';
	}

	/**
	 * Sarvam voice id for the Indian languages.
	 *
	 * @return string
	 */
	public static function indic_voice(): string {
		$voice = self::setting( 'indic_voice', self::DEFAULT_VOICE );
		$voice = is_string( $voice ) ? strtolower( trim( $voice ) ) : '';
		return array_key_exists( $voice, self::VOICES ) ? $voice : self::DEFAULT_VOICE;
	}

	/**
	 * Bolna language_switch_trigger.
	 *
	 * @return string
	 */
	public static function switch_trigger(): string {
		$trigger = self::setting( 'language_switch', 'requested_or_auto_detected' );
		return ( is_string( $trigger ) && in_array( $trigger, self::SWITCH_TRIGGERS, true ) ) ? $trigger : 'requested_or_auto_detected';
	}

	/* ---------------------------------------------------------------------------------------------
	 * Greetings, hand-offs and the prompt directive
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Outbound greeting in a language, with {store} / {agent} filled in.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function outbound_greeting( $code ): string {
		return self::template( self::OUTBOUND, $code );
	}

	/**
	 * Support (inbound / web / call-back) greeting in a language, with {store} / {agent} filled in.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function inbound_greeting( $code ): string {
		return self::template( self::INBOUND, $code );
	}

	/**
	 * Hand-off line played when the agent switches into a language.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function handoff( $code ): string {
		return self::template( self::HANDOFF, $code );
	}

	/**
	 * "## Language" block appended to the system prompt of a non-English language ('' for English).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function directive( $code ): string {
		$code = self::sanitize_code( $code );
		if ( '' === $code || 'en' === $code ) {
			return '';
		}
		return strtr(
			self::DIRECTIVE,
			array(
				'{Name}'   => self::name( $code ),
				'{native}' => self::native( $code ),
				'{script}' => self::script( $code ),
			)
		);
	}

	/**
	 * Sarvam transcriber override for a language (merged onto the base transcriber by Bolna).
	 *
	 * @param string $code Code.
	 * @return array
	 */
	public static function sarvam_transcriber( $code ): array {
		return array(
			'provider' => 'sarvam',
			'model'    => self::SARVAM_STT_MODEL,
			'language' => self::sanitize_code( $code ),
		);
	}

	/**
	 * Full Sarvam synthesizer for a language (a different provider replaces the base synthesizer entirely).
	 *
	 * @param string $code Code.
	 * @return array
	 */
	public static function sarvam_synthesizer( $code ): array {
		$voice = self::indic_voice();
		return array(
			'provider'        => 'sarvam',
			'provider_config' => array(
				'voice'    => ucfirst( $voice ),
				'voice_id' => $voice,
				'model'    => self::SARVAM_TTS_MODEL,
				'language' => self::sanitize_code( $code ),
			),
			'stream'          => true,
			'buffer_size'     => 250,
			'audio_format'    => 'wav',
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Choosing the language of a call
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Language of an Indian state or union territory ('' for anything else).
	 *
	 * @param string $state_code WooCommerce state code, e.g. "TN".
	 * @return string
	 */
	public static function state_language( $state_code ): string {
		$state = is_scalar( $state_code ) ? strtoupper( trim( (string) $state_code ) ) : '';
		if ( '' === $state ) {
			return '';
		}
		foreach ( self::STATE_LANGUAGES as $code => $states ) {
			if ( in_array( $state, $states, true ) ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Language an order call starts in: 1) the customer's preference on the order (classic or block checkout),
	 * 2) in "auto_state" mode, the language of the delivery (else billing) state for Indian addresses,
	 * 3) the default language. Always an enabled code ('en' at worst).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function for_order( $order ): string {
		if ( class_exists( 'WC_Order' ) && $order instanceof WC_Order ) {
			try {
				$preference = self::order_preference( $order );
				if ( '' !== $preference && self::is_enabled( $preference ) ) {
					return $preference;
				}
				if ( 'auto_state' === self::mode() ) {
					$code = self::order_state_language( $order );
					if ( '' !== $code && self::is_enabled( $code ) ) {
						return $code;
					}
				}
			} catch ( \Throwable $e ) {
				self::log( 'for_order failed: ' . $e->getMessage() );
			}
		}
		return self::default_language();
	}

	/**
	 * The customer's preferred call language saved on an order ('' when none).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_preference( $order ): string {
		if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
			return '';
		}
		foreach ( array( self::ORDER_META, '_wc_other/' . self::CHECKOUT_FIELD_ID, '_wc_additional/' . self::CHECKOUT_FIELD_ID ) as $key ) {
			$code = self::sanitize_code( $order->get_meta( $key ) );
			if ( '' !== $code ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Language for a call without an order (carts, call-backs): the state's language in "auto_state" mode,
	 * else the default language.
	 *
	 * @param string $state State code, when known.
	 * @return string
	 */
	public static function for_phone_context( $state = '' ): string {
		if ( 'auto_state' === self::mode() ) {
			$code = self::state_language( $state );
			if ( '' !== $code && self::is_enabled( $code ) ) {
				return $code;
			}
		}
		return self::default_language();
	}

	/* ---------------------------------------------------------------------------------------------
	 * What the Bolna agents can do (provisioning bookkeeping)
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Remember the languages an agent was just created / updated with in Bolna.
	 *
	 * @param string   $agent_key Agent key.
	 * @param string   $agent_id  Bolna agent id.
	 * @param string[] $codes     Keys of multilingual_config.languages ([] = single-language agent).
	 * @return void
	 */
	public static function record_agent_languages( $agent_key, $agent_id, array $codes ) {
		$agent_key = is_scalar( $agent_key ) ? sanitize_key( (string) $agent_key ) : '';
		$agent_id  = is_scalar( $agent_id ) ? trim( (string) $agent_id ) : '';
		if ( '' === $agent_key || '' === $agent_id ) {
			return;
		}
		$clean = array();
		foreach ( $codes as $code ) {
			$code = self::sanitize_code( $code );
			if ( '' !== $code ) {
				$clean[] = $code;
			}
		}
		$map               = self::agents_map();
		$map[ $agent_key ] = array(
			'agent_id'  => $agent_id,
			'languages' => array_values( array_intersect( self::CODES, $clean ) ),
			'voice'     => self::indic_voice(),
			'switch'    => self::switch_trigger(),
			'synced_at' => time(),
		);
		update_option( self::AGENTS_OPTION, $map, false );
	}

	/**
	 * Languages a Bolna agent was last provisioned with ([] when unknown or single-language).
	 *
	 * @param string $agent_id Bolna agent id.
	 * @return string[]
	 */
	public static function agent_languages( $agent_id ): array {
		$agent_id = is_scalar( $agent_id ) ? trim( (string) $agent_id ) : '';
		if ( '' === $agent_id ) {
			return array();
		}
		foreach ( self::agents_map() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['agent_id'] ) && (string) $entry['agent_id'] === $agent_id ) {
				return isset( $entry['languages'] ) && is_array( $entry['languages'] ) ? array_values( $entry['languages'] ) : array();
			}
		}
		return array();
	}

	/**
	 * Forget what we know about an agent (e.g. Bolna rejected a language it should have had).
	 *
	 * @param string $agent_id Bolna agent id.
	 * @return void
	 */
	public static function forget_agent( $agent_id ) {
		$agent_id = is_scalar( $agent_id ) ? trim( (string) $agent_id ) : '';
		$map      = self::agents_map();
		$changed  = false;
		foreach ( $map as $key => $entry ) {
			if ( is_array( $entry ) && isset( $entry['agent_id'] ) && (string) $entry['agent_id'] === $agent_id ) {
				unset( $map[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::AGENTS_OPTION, $map, false );
		}
	}

	/**
	 * Bolna agent_data that starts a call in a language: ['language' => code] only when the store is
	 * multilingual, the language is enabled and the agent was provisioned with it; [] otherwise
	 * (the call then starts in the agent's default language, English).
	 *
	 * @param string $agent_id Bolna agent id.
	 * @param string $code     Language code.
	 * @return array
	 */
	public static function agent_data( $agent_id, $code ): array {
		$code = self::sanitize_code( $code );
		if ( '' === $code || ! self::is_multilingual() || ! self::is_enabled( $code ) ) {
			return array();
		}
		$speaks = self::agent_languages( $agent_id );
		if ( count( $speaks ) < 2 || ! in_array( $code, $speaks, true ) ) {
			return array();
		}
		return array( 'language' => $code );
	}

	/**
	 * Whether a saved agent must be re-synced to speak the current language settings.
	 *
	 * @param string $agent_key Agent key.
	 * @return bool
	 */
	public static function agent_needs_sync( $agent_key ): bool {
		$agent_id = class_exists( 'VoicePress_Settings' ) ? (string) VoicePress_Settings::agent_id( $agent_key ) : '';
		if ( '' === $agent_id ) {
			return false;
		}
		$multilingual = self::is_multilingual();
		$map          = self::agents_map();
		$entry        = isset( $map[ $agent_key ] ) && is_array( $map[ $agent_key ] ) ? $map[ $agent_key ] : null;
		if ( ! $entry || ! isset( $entry['agent_id'] ) || (string) $entry['agent_id'] !== $agent_id ) {
			return $multilingual; // Created before languages were added: single-language.
		}
		$synced = isset( $entry['languages'] ) && is_array( $entry['languages'] ) ? array_values( $entry['languages'] ) : array();
		if ( ! $multilingual ) {
			return count( $synced ) >= 2;
		}
		if ( $synced !== self::enabled() ) {
			return true;
		}
		$voice  = isset( $entry['voice'] ) ? (string) $entry['voice'] : '';
		$switch = isset( $entry['switch'] ) ? (string) $entry['switch'] : '';
		return self::indic_voice() !== $voice || self::switch_trigger() !== $switch;
	}

	/**
	 * Whether any saved agent must be re-synced for the current language settings.
	 *
	 * @return bool
	 */
	public static function needs_resync(): bool {
		$keys = class_exists( 'VoicePress_Agents' ) ? VoicePress_Agents::AGENT_KEYS : array( 'cod_confirmation', 'delivery', 'cart_recovery', 'feedback', 'support' );
		foreach ( $keys as $agent_key ) {
			if ( self::agent_needs_sync( $agent_key ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Checkout field: "Preferred language for order calls"
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Whether the checkout asks for a preferred call language.
	 *
	 * @return bool
	 */
	public static function checkout_field_active(): bool {
		return ! empty( self::setting( 'checkout_language_field', 1 ) ) && self::is_multilingual();
	}

	/**
	 * Enabled languages as select options: [ ['value' => 'ta', 'label' => 'தமிழ் · Tamil'], … ].
	 *
	 * @return array[]
	 */
	public static function checkout_options(): array {
		$options = array();
		foreach ( self::enabled() as $code ) {
			$options[] = array(
				'value' => $code,
				'label' => self::label( $code ),
			);
		}
		return $options;
	}

	/**
	 * Label of the "no preference" choice.
	 *
	 * @return string
	 */
	public static function auto_label(): string {
		if ( 'auto_state' === self::mode() ) {
			return __( 'Automatic (based on your address)', 'voicepress' );
		}
		/* translators: %s: language name, e.g. "English". */
		return sprintf( __( 'Automatic (%s)', 'voicepress' ), self::name( self::default_language() ) );
	}

	/**
	 * Block checkout: register the select as an additional order field (on woocommerce_init).
	 *
	 * @return void
	 */
	public static function register_block_checkout_field() {
		static $done = false;
		if ( $done || ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! self::checkout_field_active() ) {
			return;
		}
		$done = true;
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'          => self::CHECKOUT_FIELD_ID,
					'label'       => __( 'Preferred language for order calls', 'voicepress' ),
					'location'    => 'order',
					'type'        => 'select',
					'required'    => false,
					'placeholder' => self::auto_label(),
					'options'     => self::checkout_options(),
				)
			);
		} catch ( \Throwable $e ) {
			self::log( 'Could not register the checkout language field: ' . $e->getMessage() );
		}
	}

	/**
	 * Classic checkout: print the select after the order notes.
	 *
	 * @param WC_Checkout|null $checkout Checkout.
	 * @return void
	 */
	public static function render_classic_checkout_field( $checkout = null ) {
		if ( ! function_exists( 'woocommerce_form_field' ) || ! self::checkout_field_active() ) {
			return;
		}
		$options = array( '' => self::auto_label() );
		foreach ( self::checkout_options() as $option ) {
			$options[ $option['value'] ] = $option['label'];
		}
		$value = '';
		if ( is_object( $checkout ) && method_exists( $checkout, 'get_value' ) ) {
			$value = self::sanitize_code( $checkout->get_value( self::CLASSIC_FIELD ) );
		}
		echo '<div class="voicepress-checkout-language">';
		woocommerce_form_field(
			self::CLASSIC_FIELD,
			array(
				'type'     => 'select',
				'class'    => array( 'form-row-wide' ),
				'label'    => __( 'Preferred language for order calls', 'voicepress' ),
				'required' => false,
				'options'  => $options,
			),
			isset( $options[ $value ] ) ? $value : ''
		);
		echo '</div>';
	}

	/**
	 * Classic checkout: save the chosen language on the order (before WooCommerce saves it).
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 * @return void
	 */
	public static function save_classic_checkout_field( $order, $data = array() ) {
		unset( $data );
		if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order || ! self::checkout_field_active() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		$raw  = isset( $_POST[ self::CLASSIC_FIELD ] ) && is_scalar( $_POST[ self::CLASSIC_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CLASSIC_FIELD ] ) ) : '';
		$code = self::sanitize_code( $raw );
		if ( '' !== $code && self::is_enabled( $code ) ) {
			$order->update_meta_data( self::ORDER_META, $code );
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * One catalog field for a code.
	 *
	 * @param string $code  Code.
	 * @param string $field name|native|script.
	 * @return string
	 */
	private static function field( $code, $field ) {
		$code    = self::sanitize_code( $code );
		$catalog = self::CATALOG;
		return ( '' !== $code && isset( $catalog[ $code ][ $field ] ) ) ? (string) $catalog[ $code ][ $field ] : '';
	}

	/**
	 * A template in a language with {store} / {agent} filled in ('' for unknown codes).
	 *
	 * @param array  $templates code => template.
	 * @param string $code      Code.
	 * @return string
	 */
	private static function template( array $templates, $code ) {
		$code = self::sanitize_code( $code );
		if ( '' === $code || ! isset( $templates[ $code ] ) ) {
			return '';
		}
		return strtr(
			$templates[ $code ],
			array(
				'{store}' => self::clean( self::setting( 'store_name', '' ), 'our store' ),
				'{agent}' => self::clean( self::setting( 'agent_persona', '' ), 'Riya' ),
			)
		);
	}

	/**
	 * Language of the order's delivery (else billing) state, for Indian addresses.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function order_state_language( $order ) {
		$state   = trim( (string) $order->get_shipping_state() );
		$country = trim( (string) $order->get_shipping_country() );
		if ( '' === $state ) {
			$state   = trim( (string) $order->get_billing_state() );
			$country = trim( (string) $order->get_billing_country() );
		}
		if ( '' === $state ) {
			return '';
		}
		if ( '' === $country && function_exists( 'WC' ) && WC()->countries ) {
			$country = (string) WC()->countries->get_base_country();
		}
		return 'IN' === strtoupper( $country ) ? self::state_language( $state ) : '';
	}

	/**
	 * Saved agent languages map.
	 *
	 * @return array
	 */
	private static function agents_map() {
		$map = get_option( self::AGENTS_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Store name / persona for a spoken template: plain text, no {braces} (Bolna variables).
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Used when empty.
	 * @return string
	 */
	private static function clean( $value, $fallback ) {
		$text = is_scalar( $value ) ? (string) $value : '';
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = str_replace( array( '{', '}' ), array( '(', ')' ), $text );
		$flat = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( null === $flat ? $text : $flat );
		return '' !== $text ? $text : $fallback;
	}

	/**
	 * Setting with a fallback.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	private static function setting( $key, $fallback = null ) {
		if ( ! class_exists( 'VoicePress_Settings' ) ) {
			return $fallback;
		}
		$value = VoicePress_Settings::get( $key, $fallback );
		return null === $value ? $fallback : $value;
	}

	/**
	 * Debug log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function log( $message ) {
		if ( class_exists( 'VoicePress_Helpers' ) && method_exists( 'VoicePress_Helpers', 'log' ) ) {
			VoicePress_Helpers::log( $message );
		}
	}
}
