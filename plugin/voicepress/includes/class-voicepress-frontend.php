<?php
/**
 * VoicePress storefront integration.
 *
 * - Floating voice widget: an in-browser call with the Support agent (Bolna Web Call SDK).
 * - [voicepress_call_button] shortcode: an inline "Talk to us" button that opens the widget.
 * - Checkout contact capture used by abandoned-cart recovery calls.
 * - Trusted, server-side caller context (customer_context) for web calls and requested call-backs.
 *
 * The Bolna API key never reaches the browser: web call sessions are minted server-side by
 * VoicePress_REST (POST voicepress/v1/web-session), which asks this class for the user_data.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront features (static-method class).
 */
class VoicePress_Frontend {

	/**
	 * Maximum length (characters) of the customer_context variable.
	 */
	const CONTEXT_MAX_CHARS = 3500;

	/**
	 * Allowed values of context.page.
	 */
	const PAGE_TYPES = array( 'product', 'checkout', 'cart', 'order', 'account', 'other' );

	/**
	 * Version of the bundled Bolna Web Call SDK.
	 */
	const SDK_VERSION = '3.0.1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_root' ), 5 );
		add_shortcode( 'voicepress_call_button', array( __CLASS__, 'shortcode_call_button' ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Availability
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Whether the voice widget should load on this request: enabled in settings, Bolna configured
	 * and a Support agent exists (the agent used for web calls).
	 *
	 * @return bool
	 */
	public static function widget_active() {
		if ( ! class_exists( 'VoicePress_Settings' ) || ! class_exists( 'VoicePress_Agents' ) ) {
			return false;
		}
		$active = ! empty( VoicePress_Settings::get( 'widget_enabled' ) )
			&& VoicePress_Settings::is_configured()
			&& '' !== (string) VoicePress_Agents::agent_for_use_case( 'web_call' );

		if ( ! $active ) {
			return false;
		}

		/**
		 * Filters whether the storefront voice widget renders on the current request.
		 * Return false to hide it on specific pages. It cannot enable an unconfigured widget.
		 *
		 * @param bool $show Default true.
		 */
		return (bool) apply_filters( 'voicepress_widget_enabled', true );
	}

	/**
	 * Whether checkout contact capture should run on this request: on the checkout form (not the
	 * thank-you / pay pages) while the cart recovery automation is enabled and usable.
	 *
	 * @return bool
	 */
	public static function capture_active() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'is_checkout' ) || ! class_exists( 'VoicePress_Settings' ) || ! class_exists( 'VoicePress_Agents' ) ) {
			return false;
		}
		if ( ! is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return false;
		}
		if ( ! VoicePress_Settings::is_configured() ) {
			return false;
		}
		$automation = VoicePress_Settings::automation( 'cart_recovery' );
		if ( empty( $automation['enabled'] ) ) {
			return false;
		}
		if ( '' === (string) VoicePress_Agents::agent_for_use_case( 'cart_recovery' ) ) {
			return false;
		}

		/**
		 * Filters whether VoicePress captures checkout phone / email / first name for cart recovery.
		 *
		 * @param bool $capture Default true.
		 */
		return (bool) apply_filters( 'voicepress_checkout_capture_enabled', true );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Assets & markup
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Enqueue the widget (SDK + widget.js + widget.css) and/or the checkout capture (widget.js only).
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		$widget  = self::widget_active();
		$capture = self::capture_active();
		if ( ! $widget && ! $capture ) {
			return;
		}

		$deps = array();
		if ( $widget ) {
			wp_enqueue_style( 'voicepress-widget', self::asset_url( 'widget.css' ), array(), self::asset_version( 'widget.css' ) );
			wp_enqueue_script( 'bolna-web-call', self::asset_url( 'vendor/bolna-web-call.min.js' ), array(), self::SDK_VERSION, true );
			$deps[] = 'bolna-web-call';
		}

		// Without the widget (capture only) widget.js must not depend on the SDK.
		wp_enqueue_script( 'voicepress-widget', self::asset_url( 'widget.js' ), $deps, self::asset_version( 'widget.js' ), true );
		wp_localize_script( 'voicepress-widget', 'VoicePressWidget', self::script_data( $widget, $capture ) );
	}

	/**
	 * Print the widget root in the footer. widget.js builds the UI inside it.
	 *
	 * @return void
	 */
	public static function render_root() {
		if ( is_admin() || ! self::widget_active() ) {
			return;
		}
		echo '<div id="voicepress-widget" class="vpw-root" data-nosnippet></div>' . "\n";
	}

	/**
	 * [voicepress_call_button label="Talk to us" start="no" class=""] — inline button that opens
	 * the voice widget (start="yes" also starts the call on click).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_call_button( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'label' => '',
				'start' => 'no',
				'class' => '',
			),
			$atts,
			'voicepress_call_button'
		);

		if ( ! self::widget_active() ) {
			if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
				return '<span class="vpw-inline-note" style="display:inline-block;padding:6px 10px;border:1px dashed #b3a6f7;border-radius:8px;color:#4327d6;font-size:13px;line-height:1.4;">'
					. esc_html__( 'VoicePress call button is hidden: turn on the voice widget in VoicePress → Settings and create the Support agent. Only admins see this note.', 'voicepress' )
					. '</span>';
			}
			return '';
		}

		$label = self::plain( $atts['label'] );
		if ( '' === $label ) {
			$label = self::widget_label();
		}
		$mode  = in_array( strtolower( trim( (string) $atts['start'] ) ), array( 'yes', '1', 'true', 'on' ), true ) ? 'start' : 'open';
		$class = 'vpw-inline-btn';
		$extra = preg_split( '/\s+/', trim( (string) $atts['class'] ) );
		foreach ( (array) $extra as $name ) {
			$name = sanitize_html_class( $name );
			if ( '' !== $name ) {
				$class .= ' ' . $name;
			}
		}

		return sprintf(
			'<button type="button" class="%1$s" data-vpw-open="%2$s" aria-haspopup="dialog" aria-controls="vpw-panel"><span class="vpw-inline-btn__icon" aria-hidden="true">%3$s</span><span class="vpw-inline-btn__label">%4$s</span></button>',
			esc_attr( $class ),
			esc_attr( $mode ),
			self::mic_svg(), // Static markup.
			esc_html( $label )
		);
	}

	/**
	 * Data for window.VoicePressWidget.
	 *
	 * @param bool $widget  Widget active.
	 * @param bool $capture Checkout capture active.
	 * @return array
	 */
	private static function script_data( $widget, $capture ) {
		$store = self::store_name();
		$agent = self::agent_name();
		$ns    = defined( 'VOICEPRESS_REST_NS' ) ? VOICEPRESS_REST_NS : 'voicepress/v1';

		return array(
			'rest'        => esc_url_raw( rest_url( $ns . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'label'       => self::widget_label(),
			'storeName'   => $store,
			'agentName'   => $agent,
			'context'     => self::page_context(),
			'isCheckout'  => function_exists( 'is_checkout' ) && is_checkout(),
			'widget'      => (bool) $widget,
			'capture'     => (bool) $capture,
			'captureOnly' => $capture && ! $widget,
			'prefill'     => $widget ? self::prefill() : array(
				'name'  => '',
				'phone' => '',
			),
			'i18n'        => $widget ? self::strings( $store, $agent ) : array(),
		);
	}

	/**
	 * Where the visitor is. Only ids are sent to the browser, and they are re-validated
	 * server-side by web_user_data(). order_id is set only for the signed-in owner of the order.
	 *
	 * @return array{page:string,product_id:int,order_id:int}
	 */
	public static function page_context() {
		$context = array(
			'page'       => 'other',
			'product_id' => 0,
			'order_id'   => 0,
		);
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $context;
		}
		try {
			$order_id = self::current_order_endpoint_id();
			if ( null !== $order_id ) {
				$context['page'] = 'order';
				if ( $order_id > 0 && self::owned_order( $order_id ) ) {
					$context['order_id'] = $order_id;
				}
			} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
				$context['page'] = 'checkout';
			} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
				$context['page'] = 'cart';
			} elseif ( function_exists( 'is_product' ) && is_product() ) {
				$context['page']       = 'product';
				$context['product_id'] = absint( get_queried_object_id() );
			} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
				$context['page'] = 'account';
			}
		} catch ( \Throwable $e ) {
			self::log( 'page_context failed: ' . $e->getMessage() );
		}
		return $context;
	}

	/**
	 * Order id from the view-order / order-received endpoint; null when not on those endpoints.
	 *
	 * @return int|null
	 */
	private static function current_order_endpoint_id() {
		global $wp;
		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			return null;
		}
		foreach ( array( 'view-order', 'order-received' ) as $endpoint ) {
			if ( is_wc_endpoint_url( $endpoint ) ) {
				return ( is_object( $wp ) && isset( $wp->query_vars[ $endpoint ] ) ) ? absint( $wp->query_vars[ $endpoint ] ) : 0;
			}
		}
		return null;
	}

	/**
	 * Name / phone to pre-fill the call-back form for signed-in customers.
	 *
	 * @return array{name:string,phone:string}
	 */
	private static function prefill() {
		$prefill = array(
			'name'  => '',
			'phone' => '',
		);
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $prefill;
		}
		$profile          = self::customer_profile( $user_id );
		$prefill['name']  = $profile['first_name'];
		$prefill['phone'] = $profile['phone'];
		return $prefill;
	}

	/**
	 * Translatable UI strings for widget.js (store / assistant names already filled in).
	 *
	 * @param string $store Store name.
	 * @param string $agent Assistant name.
	 * @return array
	 */
	private static function strings( $store, $agent ) {
		return array(
			'label'               => self::widget_label(),
			/* translators: %s: store name. */
			'title'               => sprintf( __( 'Talk to %s', 'voicepress' ), $store ),
			/* translators: %s: voice assistant name, e.g. Riya. */
			'subtitle'            => sprintf( __( '%s · AI voice assistant · English', 'voicepress' ), $agent ),
			'minimize'            => __( 'Minimize', 'voicepress' ),
			'liveCall'            => __( 'Voice call in progress. Open the call panel', 'voicepress' ),
			'live'                => __( 'Live', 'voicepress' ),
			'start'               => __( 'Start voice call', 'voicepress' ),
			'idleTitle'           => __( 'Start voice call', 'voicepress' ),
			/* translators: %s: voice assistant name. */
			'idleText'            => sprintf( __( 'Ask about your orders, delivery, returns or products. %s answers instantly, any time.', 'voicepress' ), $agent ),
			'privacy'             => __( 'Uses your microphone. Calls may be recorded to help us serve you better.', 'voicepress' ),
			'connectingTitle'     => __( 'Connecting…', 'voicepress' ),
			'connectingText'      => __( 'Allow microphone access if your browser asks.', 'voicepress' ),
			/* translators: %s: voice assistant name. */
			'connectingMic'       => sprintf( __( 'Microphone ready. Connecting you to %s…', 'voicepress' ), $agent ),
			'ringingTitle'        => __( 'Ringing…', 'voicepress' ),
			/* translators: %s: voice assistant name. */
			'ringingText'         => sprintf( __( '%s will pick up in a moment.', 'voicepress' ), $agent ),
			/* translators: %s: voice assistant name. */
			'liveTitle'           => sprintf( __( 'You’re talking to %s', 'voicepress' ), $agent ),
			'liveText'            => __( 'Speak naturally. Ask anything about your order or our products.', 'voicepress' ),
			'mutedText'           => __( 'You’re muted. Unmute to keep talking.', 'voicepress' ),
			/* translators: %s: voice assistant name. */
			'speaking'            => sprintf( __( '%s is speaking', 'voicepress' ), $agent ),
			'listening'           => __( 'Listening…', 'voicepress' ),
			'muted'               => __( 'Muted', 'voicepress' ),
			'stayHint'            => __( 'Keep this page open during the call.', 'voicepress' ),
			'mute'                => __( 'Mute', 'voicepress' ),
			'unmute'              => __( 'Unmute', 'voicepress' ),
			'end'                 => __( 'End call', 'voicepress' ),
			'cancel'              => __( 'Cancel', 'voicepress' ),
			/* translators: %s: voice assistant name. */
			'enableSound'         => sprintf( __( 'Tap to hear %s', 'voicepress' ), $agent ),
			/* translators: %s: store name. */
			'endedTitle'          => sprintf( __( 'Thanks for calling %s!', 'voicepress' ), $store ),
			/* translators: %s: voice assistant name. */
			'endedText'           => sprintf( __( 'Hope %s could help. Need anything else? Start another call or ask us to call you.', 'voicepress' ), $agent ),
			/* translators: %s: voice assistant name. */
			'endedRemote'         => sprintf( __( '%s ended the call. Need anything else? Start another call or ask us to call you.', 'voicepress' ), $agent ),
			'callLength'          => __( 'Call length', 'voicepress' ),
			'callAgain'           => __( 'Call again', 'voicepress' ),
			'tryAgain'            => __( 'Try again', 'voicepress' ),
			'callMeInstead'       => __( 'Call my phone instead', 'voicepress' ),
			'errMicTitle'         => __( 'Microphone is blocked', 'voicepress' ),
			'errMicText'          => __( 'Allow microphone access from your browser’s address bar and try again, or let us call your phone.', 'voicepress' ),
			'errBusyTitle'        => __( 'All lines are busy', 'voicepress' ),
			'errBusyText'         => __( 'Please try again in a minute, or let us call you back.', 'voicepress' ),
			'errLimitTitle'       => __( 'Too many attempts', 'voicepress' ),
			'errLimitText'        => __( 'Please wait a little before starting another call, or let us call your phone.', 'voicepress' ),
			'errUnavailableTitle' => __( 'Voice calls are unavailable', 'voicepress' ),
			'errUnavailableText'  => __( 'Our voice assistant can’t take website calls right now. Leave your number and we’ll call you.', 'voicepress' ),
			'errUnsupportedTitle' => __( 'Voice calls aren’t supported here', 'voicepress' ),
			'errUnsupportedText'  => __( 'This browser can’t make voice calls on this page. Leave your number and we’ll call you instead.', 'voicepress' ),
			'errConnectTitle'     => __( 'Connection problem', 'voicepress' ),
			'errConnectText'      => __( 'We couldn’t reach the voice server. Check your internet connection and try again.', 'voicepress' ),
			'errRejectedTitle'    => __( 'The call didn’t go through', 'voicepress' ),
			'errRejectedText'     => __( 'Please try again in a moment.', 'voicepress' ),
			'errDroppedTitle'     => __( 'The call dropped', 'voicepress' ),
			'errDroppedText'      => __( 'Sorry about that. Please try again.', 'voicepress' ),
			'errMintTitle'        => __( 'Couldn’t start the call', 'voicepress' ),
			'errMintText'         => __( 'Please try again in a moment, or let us call your phone.', 'voicepress' ),
			'preferPhone'         => __( 'Prefer a phone call?', 'voicepress' ),
			'backToVoice'         => __( 'Back to voice call', 'voicepress' ),
			'cbTitle'             => __( 'We’ll call you', 'voicepress' ),
			/* translators: %s: voice assistant name. */
			'cbText'              => sprintf( __( 'Leave your number and %s will call you right away.', 'voicepress' ), $agent ),
			'cbName'              => __( 'Your name', 'voicepress' ),
			'cbPhone'             => __( 'Phone number', 'voicepress' ),
			'cbPhoneHint'         => __( 'Your mobile number', 'voicepress' ),
			'cbOrder'             => __( 'Order number (optional)', 'voicepress' ),
			'cbSubmit'            => __( 'Call me now', 'voicepress' ),
			'cbSending'           => __( 'Requesting…', 'voicepress' ),
			'cbInvalid'           => __( 'Please enter a valid phone number (at least 10 digits).', 'voicepress' ),
			'cbError'             => __( 'We couldn’t place the call right now. Please try again shortly.', 'voicepress' ),
			'cbDoneTitle'         => __( 'Calling you now…', 'voicepress' ),
			/* translators: %s: store name. */
			'cbDoneText'          => sprintf( __( 'Pick up the call from %s in a few seconds.', 'voicepress' ), $store ),
			'done'                => __( 'Done', 'voicepress' ),
			'poweredBy'           => __( 'Voice AI by VoicePress', 'voicepress' ),
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Trusted caller context (user_data)
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * user_data for a website voice call (Support agent, use case web_call). Built server-side:
	 * the browser only sends page/product/order ids, which are re-validated here (an order is used
	 * only when it belongs to the signed-in user). ALL values are strings.
	 *
	 * Keys: store_context() keys + customer_name ('there' for guests), channel ('web'),
	 * call_reason_text, customer_context (multi-line plain text).
	 *
	 * @param WP_REST_Request|null $request REST request ({ context: { page, product_id, order_id } }).
	 * @return array
	 */
	public static function web_user_data( $request = null ) {
		$data                     = self::store_context();
		$data['customer_name']    = 'there';
		$data['channel']          = 'web';
		$data['call_reason_text'] = 'you started a voice call from our website';
		$data['customer_context'] = '';

		try {
			$context = self::request_context( $request );
			$user_id = get_current_user_id();
			$lines   = array( 'Website visitor, ' . self::page_label( $context['page'] ) . '.' );

			if ( $user_id ) {
				$profile = self::customer_profile( $user_id );
				if ( '' !== $profile['first_name'] ) {
					$data['customer_name'] = $profile['first_name'];
				}
				$lines = array_merge( $lines, self::account_lines( $user_id, $profile ) );
			} else {
				$lines[] = 'Guest (not signed in): identity not verified.';
			}

			if ( $context['product_id'] ) {
				$product_line = self::product_line( $context['product_id'] );
				if ( '' !== $product_line ) {
					$lines[] = 'Product on screen: ' . $product_line;
				}
			}

			if ( $context['order_id'] && $user_id ) {
				$order = self::owned_order( $context['order_id'] );
				if ( $order ) {
					$lines[] = 'Order on screen: ' . self::order_detail_line( $order );
				}
			}

			$cart_line = self::cart_line();
			if ( '' !== $cart_line ) {
				$lines[] = 'Cart: ' . $cart_line;
			}

			$data['customer_context'] = self::truncate( implode( "\n", $lines ), self::CONTEXT_MAX_CHARS );
		} catch ( \Throwable $e ) {
			self::log( 'web_user_data failed: ' . $e->getMessage() );
		}

		return self::stringify( $data );
	}

	/**
	 * customer_context for a call to a phone number (call-back requests, support test calls):
	 * the name typed on the form plus the last 3 orders placed with this number.
	 *
	 * @param string $phone Phone number in any format.
	 * @param string $name  Name typed by the visitor (unverified).
	 * @return string Multi-line plain text, '' when nothing is known.
	 */
	public static function customer_context_for_phone( $phone, $name = '' ) {
		$lines = array();
		try {
			$name   = self::truncate( self::plain( sanitize_text_field( is_scalar( $name ) ? (string) $name : '' ) ), 60 );
			$e164   = self::normalize_phone( is_scalar( $phone ) ? (string) $phone : '' );
			$orders = ( '' !== $e164 && class_exists( 'WooCommerce' ) ) ? self::orders_for_phone( $e164, 3 ) : array();

			if ( '' !== $name ) {
				$lines[] = 'Caller name (as given, not verified): ' . $name . '.';
			}
			if ( $orders ) {
				$first = self::plain( $orders[0]->get_billing_first_name() );
				if ( '' !== $first && 0 !== strcasecmp( $first, $name ) ) {
					$lines[] = 'Name on their latest order: ' . $first . '.';
				}
				$lines[] = 'Recent orders placed with this phone number:';
				foreach ( $orders as $order ) {
					$lines[] = '- ' . self::order_line( $order );
				}
			} elseif ( '' !== $e164 ) {
				$lines[] = 'No orders found for this phone number.';
			}
		} catch ( \Throwable $e ) {
			self::log( 'customer_context_for_phone failed: ' . $e->getMessage() );
		}
		return self::truncate( implode( "\n", $lines ), self::CONTEXT_MAX_CHARS );
	}

	/**
	 * Sanitised { page, product_id, order_id } from the REST request body.
	 *
	 * @param mixed $request REST request.
	 * @return array{page:string,product_id:int,order_id:int}
	 */
	private static function request_context( $request ) {
		$raw = null;
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$raw = $request->get_param( 'context' );
			if ( null === $raw && method_exists( $request, 'get_json_params' ) ) {
				$json = $request->get_json_params();
				$raw  = ( is_array( $json ) && isset( $json['context'] ) ) ? $json['context'] : null;
			}
		}
		if ( is_string( $raw ) ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$page = ( isset( $raw['page'] ) && is_scalar( $raw['page'] ) ) ? sanitize_key( (string) $raw['page'] ) : 'other';
		if ( ! in_array( $page, self::PAGE_TYPES, true ) ) {
			$page = 'other';
		}

		return array(
			'page'       => $page,
			'product_id' => ( isset( $raw['product_id'] ) && is_scalar( $raw['product_id'] ) ) ? absint( $raw['product_id'] ) : 0,
			'order_id'   => ( isset( $raw['order_id'] ) && is_scalar( $raw['order_id'] ) ) ? absint( $raw['order_id'] ) : 0,
		);
	}

	/**
	 * Human description of the page type for the agent.
	 *
	 * @param string $page Page type.
	 * @return string
	 */
	private static function page_label( $page ) {
		$labels = array(
			'product'  => 'looking at a product page',
			'checkout' => 'on the checkout page',
			'cart'     => 'on the cart page',
			'order'    => 'looking at one of their orders',
			'account'  => 'in their account area',
			'other'    => 'browsing the store',
		);
		return isset( $labels[ $page ] ) ? $labels[ $page ] : $labels['other'];
	}

	/**
	 * Lines describing a signed-in customer and their recent orders.
	 *
	 * @param int   $user_id User id.
	 * @param array $profile customer_profile() result.
	 * @return string[]
	 */
	private static function account_lines( $user_id, array $profile ) {
		$details = array();
		if ( '' !== $profile['full_name'] ) {
			$details[] = $profile['full_name'];
		}
		if ( '' !== $profile['email'] ) {
			$details[] = 'email ' . $profile['email'];
		}
		if ( '' !== $profile['phone'] ) {
			$details[] = 'phone ' . $profile['phone'];
		}
		$lines = array( 'Signed-in customer (verified by website login): ' . ( $details ? implode( ', ', $details ) : 'no name on the account' ) . '.' );

		$orders = self::customer_orders( $user_id, 3 );
		if ( $orders ) {
			$lines[] = 'Their recent orders (already verified by the login; if an action asks for phone_last4, use the phone ending shown for that order):';
			foreach ( $orders as $order ) {
				$lines[] = '- ' . self::order_line( $order, true );
			}
		} else {
			$lines[] = 'No orders on this account yet.';
		}
		return $lines;
	}

	/**
	 * Name, email and phone of a customer account (billing details first, then the WP profile).
	 *
	 * @param int $user_id User id.
	 * @return array{first_name:string,full_name:string,email:string,phone:string}
	 */
	private static function customer_profile( $user_id ) {
		$profile = array(
			'first_name' => '',
			'full_name'  => '',
			'email'      => '',
			'phone'      => '',
		);
		$user    = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return $profile;
		}

		$first = '';
		$last  = '';
		$email = '';
		$phone = '';
		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new WC_Customer( (int) $user_id );
				$first    = (string) $customer->get_billing_first_name();
				$last     = (string) $customer->get_billing_last_name();
				$email    = (string) $customer->get_billing_email();
				$phone    = (string) $customer->get_billing_phone();
				if ( '' === trim( $first ) ) {
					$first = (string) $customer->get_first_name();
					$last  = (string) $customer->get_last_name();
				}
			} catch ( \Exception $e ) {
				// Fall back to the WordPress profile below.
				$first = '';
			}
		}
		if ( '' === trim( $first ) ) {
			$first = (string) $user->first_name;
			$last  = (string) $user->last_name;
		}
		if ( '' === trim( $first ) && false === strpos( (string) $user->display_name, '@' ) ) {
			$first = (string) $user->display_name;
		}

		$profile['first_name'] = self::plain( $first );
		$profile['full_name']  = self::plain( $first . ' ' . $last );
		$profile['email']      = sanitize_email( '' !== $email ? $email : (string) $user->user_email );
		$profile['phone']      = self::plain( $phone );
		return $profile;
	}

	/**
	 * Latest orders of a customer account (no drafts / refunds).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max orders.
	 * @return WC_Order[]
	 */
	private static function customer_orders( $user_id, $limit = 3 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'type'        => 'shop_order',
				'limit'       => (int) $limit + 2,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$out    = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order || 'checkout-draft' === $order->get_status() ) {
				continue;
			}
			$out[] = $order;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The order when it exists and belongs to the signed-in user, else null.
	 *
	 * @param int $order_id Order id.
	 * @return WC_Order|null
	 */
	private static function owned_order( $order_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		return ( (int) $order->get_customer_id() === (int) $user_id ) ? $order : null;
	}

	/**
	 * "Order 123 · Processing · Cotton Kurta Set (quantity 1) · 899 rupees · placed 30 Oct".
	 *
	 * @param WC_Order $order           Order.
	 * @param bool     $with_phone_hint Append "phone ending 1234" (signed-in owner only).
	 * @return string
	 */
	private static function order_line( $order, $with_phone_hint = false ) {
		$parts   = array( 'Order ' . $order->get_order_number() );
		$parts[] = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status();
		$items   = self::items_summary( $order, 3 );
		if ( '' !== $items ) {
			$parts[] = $items;
		}
		$parts[] = self::amount( $order->get_total(), $order->get_currency() );
		$created = $order->get_date_created();
		if ( $created ) {
			$parts[] = 'placed ' . $created->date_i18n( 'j M' );
		}
		if ( 'cod' === $order->get_payment_method() ) {
			$parts[] = 'cash on delivery';
		}
		if ( $with_phone_hint ) {
			$digits = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
			if ( strlen( (string) $digits ) >= 4 ) {
				$parts[] = 'phone ending ' . substr( $digits, -4 );
			}
		}
		return self::plain( implode( ' · ', $parts ) );
	}

	/**
	 * order_line() plus payment, delivery address and delivery timing.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function order_detail_line( $order ) {
		$extra   = array();
		$context = self::helper( 'order_context', array( $order ) );
		if ( is_array( $context ) ) {
			if ( ! empty( $context['payment_method'] ) && 'cod' !== $order->get_payment_method() ) {
				$extra[] = 'paid by ' . $context['payment_method']; // COD is already in order_line().
			}
			if ( ! empty( $context['delivery_address'] ) ) {
				$extra[] = 'delivering to ' . $context['delivery_address'];
			}
			if ( ! empty( $context['delivered_on'] ) ) {
				$extra[] = 'delivered on ' . $context['delivered_on'];
			} elseif ( ! empty( $context['expected_delivery'] ) && in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing', 'out-for-delivery' ), true ) ) {
				$extra[] = 'expected delivery ' . $context['expected_delivery'];
			}
		}
		$line = self::order_line( $order, true );
		return $extra ? self::plain( $line . ' · ' . implode( ' · ', $extra ) ) : $line;
	}

	/**
	 * "Cotton Kurta Set · price 899 rupees · in stock · options: Size S, M, L · about: ...".
	 *
	 * @param int $product_id Product id.
	 * @return string '' when the product is not public.
	 */
	private static function product_line( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || '' !== (string) $product->get_post_password() ) {
			return '';
		}

		$parts = array( self::plain( $product->get_name() ) );

		$price = $product->get_price();
		if ( '' !== (string) $price ) {
			$parts[] = ( $product->is_type( array( 'variable', 'grouped' ) ) ? 'price from ' : 'price ' ) . self::amount( $price );
			if ( $product->is_type( 'simple' ) && $product->is_on_sale() && '' !== (string) $product->get_regular_price() ) {
				$parts[] = 'on sale, regular price ' . self::amount( $product->get_regular_price() );
			}
		}

		if ( ! $product->is_in_stock() ) {
			$parts[] = 'out of stock';
		} elseif ( $product->is_on_backorder() ) {
			$parts[] = 'available on backorder';
		} else {
			$qty     = $product->managing_stock() ? $product->get_stock_quantity() : null;
			$parts[] = ( null !== $qty && $qty > 0 && $qty <= 10 ) ? sprintf( 'in stock, only %d left', (int) $qty ) : 'in stock';
		}

		$options = self::product_options( $product );
		if ( '' !== $options ) {
			$parts[] = 'options: ' . $options;
		}

		$about = (string) $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( $about ) ) ) {
			$about = (string) $product->get_description();
		}
		$about = self::plain( strip_shortcodes( $about ) );
		if ( '' !== $about ) {
			$parts[] = 'about: ' . self::truncate( $about, 220 );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * "Size S, M, L; Colour Red, Blue" (visible / variation attributes, max 3).
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function product_options( $product ) {
		$bits = array();
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ( ! $attribute->get_visible() && ! $attribute->get_variation() ) ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			if ( is_wp_error( $values ) ) {
				continue;
			}
			$clean = array();
			foreach ( (array) $values as $value ) {
				$value = self::plain( $value );
				if ( '' !== $value ) {
					$clean[] = $value;
				}
				if ( count( $clean ) >= 8 ) {
					break;
				}
			}
			if ( $clean ) {
				$bits[] = self::plain( wc_attribute_label( $attribute->get_name(), $product ) ) . ' ' . implode( ', ', $clean );
			}
			if ( count( $bits ) >= 3 ) {
				break;
			}
		}
		return implode( '; ', $bits );
	}

	/**
	 * "Cotton Kurta Set (quantity 1), Silver Jhumkas (quantity 2) · cart total 2198 rupees".
	 * REST requests do not load the cart, so it is loaded from the visitor's session here.
	 *
	 * @return string '' when the cart is empty or unavailable.
	 */
	private static function cart_line() {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) ) {
			return '';
		}
		$wc = WC();
		if ( ! is_object( $wc ) ) {
			return '';
		}
		if ( null === $wc->cart ) {
			wc_load_cart();
		}
		$cart = $wc->cart;
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) || $cart->is_empty() ) {
			return '';
		}

		$items = array();
		$more  = 0;
		$sum   = 0.0;
		foreach ( (array) $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$qty  = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$sum += (float) $product->get_price() * $qty;
			if ( count( $items ) >= 5 ) {
				++$more;
				continue;
			}
			$items[] = sprintf( '%s (quantity %d)', self::plain( $product->get_name() ), $qty );
		}
		if ( ! $items ) {
			return '';
		}

		$line = implode( ', ', $items );
		if ( $more ) {
			$line .= sprintf( ' and %d more %s', $more, 1 === $more ? 'item' : 'items' );
		}
		$total = (float) $cart->get_total( 'edit' );
		if ( $total <= 0 ) {
			$total = $sum;
		}
		if ( $total > 0 ) {
			$line .= ' · cart total ' . self::amount( $total );
		}
		return $line;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Call a VoicePress_Helpers method when available.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed|null Null when the helper is unavailable.
	 */
	private static function helper( $method, array $args = array() ) {
		if ( class_exists( 'VoicePress_Helpers' ) && is_callable( array( 'VoicePress_Helpers', $method ) ) ) {
			return call_user_func_array( array( 'VoicePress_Helpers', $method ), $args );
		}
		return null;
	}

	/**
	 * Store facts for user_data (store_name, agent_name, return_window_days, delivery_eta,
	 * support_hours, store_policies).
	 *
	 * @return array
	 */
	private static function store_context() {
		$context  = self::helper( 'store_context' );
		$defaults = array(
			'store_name'         => self::store_name(),
			'agent_name'         => self::agent_name(),
			'return_window_days' => (string) self::setting( 'return_window_days', '7' ),
			'delivery_eta'       => (string) self::setting( 'delivery_eta', '' ),
			'support_hours'      => (string) self::setting( 'support_hours', '' ),
			'store_policies'     => (string) self::setting( 'store_policies', '' ),
		);
		$merged   = $defaults;
		foreach ( (array) $context as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$merged[ $key ] = self::plain( $value );
			}
		}
		return $merged;
	}

	/**
	 * Orders placed with a phone number (newest first).
	 *
	 * @param string $phone E.164 phone.
	 * @param int    $limit Max orders.
	 * @return WC_Order[]
	 */
	private static function orders_for_phone( $phone, $limit ) {
		$out = array();
		foreach ( (array) self::helper( 'find_orders_by_phone', array( $phone, $limit ) ) as $order ) {
			if ( $order instanceof WC_Order ) {
				$out[] = $order;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * E.164 phone or ''.
	 *
	 * @param string $raw Raw phone.
	 * @return string
	 */
	private static function normalize_phone( $raw ) {
		$value = self::helper( 'normalize_phone', array( (string) $raw ) );
		if ( null !== $value ) {
			return (string) $value;
		}
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		return strlen( (string) $digits ) >= 10 ? '+' . $digits : '';
	}

	/**
	 * Order items in words.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $max   Max items named.
	 * @return string
	 */
	private static function items_summary( $order, $max = 3 ) {
		$summary = self::helper( 'order_items_summary', array( $order, $max ) );
		if ( null !== $summary ) {
			return self::plain( $summary );
		}
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = sprintf( '%s (quantity %d)', self::plain( $item->get_name() ), (int) $item->get_quantity() );
			if ( count( $names ) >= $max ) {
				break;
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Amount in speakable words ("1299 rupees").
	 *
	 * @param float|string $amount   Amount.
	 * @param string|null  $currency Currency code (store currency when null).
	 * @return string
	 */
	private static function amount( $amount, $currency = null ) {
		$spoken = self::helper( 'speak_amount', array( $amount, $currency ) );
		if ( null !== $spoken ) {
			return self::plain( $spoken );
		}
		if ( ! $currency && function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
		}
		return trim( number_format( (float) $amount, 2, '.', '' ) . ' ' . (string) $currency );
	}

	/**
	 * A setting value (default when missing / empty).
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private static function setting( $key, $default = null ) {
		if ( ! class_exists( 'VoicePress_Settings' ) ) {
			return $default;
		}
		$value = VoicePress_Settings::get( $key, $default );
		return ( null === $value || '' === $value ) ? $default : $value;
	}

	/**
	 * Launcher / button label.
	 *
	 * @return string
	 */
	private static function widget_label() {
		$label = self::plain( self::setting( 'widget_label', '' ) );
		return '' !== $label ? $label : __( 'Talk to us', 'voicepress' );
	}

	/**
	 * Store name for the UI.
	 *
	 * @return string
	 */
	private static function store_name() {
		$name = self::plain( self::setting( 'store_name', '' ) );
		return '' !== $name ? $name : self::plain( get_bloginfo( 'name' ) );
	}

	/**
	 * Voice assistant name for the UI.
	 *
	 * @return string
	 */
	private static function agent_name() {
		$name = self::plain( self::setting( 'agent_persona', '' ) );
		return '' !== $name ? $name : 'Riya';
	}

	/**
	 * Plain single-line text: no tags, entities decoded, whitespace collapsed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function plain( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Truncate to $max characters (multibyte safe).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private static function truncate( $text, $max ) {
		$text = (string) $text;
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, max( 1, $max - 1 ), 'UTF-8' ) ) . '…';
	}

	/**
	 * Cast every value to a string (Bolna user_data contract).
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function stringify( array $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$out[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $out;
	}

	/**
	 * Inline microphone icon for the shortcode button.
	 *
	 * @return string
	 */
	private static function mic_svg() {
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><rect x="9" y="3" width="6" height="11.5" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7"/></svg>';
	}

	/**
	 * Public URL of a file in assets/.
	 *
	 * @param string $file Path relative to assets/.
	 * @return string
	 */
	private static function asset_url( $file ) {
		$base = defined( 'VOICEPRESS_URL' ) ? VOICEPRESS_URL : plugin_dir_url( dirname( __FILE__ ) );
		return trailingslashit( $base ) . 'assets/' . ltrim( $file, '/' );
	}

	/**
	 * Cache-busting version for a file in assets/ (plugin version + file mtime).
	 *
	 * @param string $file Path relative to assets/.
	 * @return string
	 */
	private static function asset_version( $file ) {
		$version = defined( 'VOICEPRESS_VERSION' ) ? VOICEPRESS_VERSION : '0.1.0';
		$dir     = defined( 'VOICEPRESS_DIR' ) ? VOICEPRESS_DIR : plugin_dir_path( dirname( __FILE__ ) );
		$path    = trailingslashit( $dir ) . 'assets/' . ltrim( $file, '/' );
		$mtime   = file_exists( $path ) ? (int) filemtime( $path ) : 0;
		return $mtime ? $version . '.' . $mtime : $version;
	}

	/**
	 * Debug log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function log( $message ) {
		if ( class_exists( 'VoicePress_Helpers' ) && is_callable( array( 'VoicePress_Helpers', 'log' ) ) ) {
			VoicePress_Helpers::log( '[frontend] ' . $message );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[VoicePress] [frontend] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
