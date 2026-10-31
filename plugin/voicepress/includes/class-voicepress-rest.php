<?php
/**
 * VoicePress REST API (namespace voicepress/v1).
 *
 * - Tool endpoints that Bolna agents call live during a conversation (token protected).
 * - The Bolna execution webhook (token protected).
 * - Storefront endpoints: in-browser voice session, checkout cart capture, call-back requests.
 * - Admin endpoints used by the VoicePress screens (manage_woocommerce).
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves every VoicePress REST route.
 */
class VoicePress_REST {

	/**
	 * Custom functions the agents may call. Names match VoicePress_Agents::build_tools().
	 *
	 * @var string[]
	 */
	const TOOLS = array(
		'identify_customer',
		'get_order_details',
		'confirm_order',
		'cancel_order',
		'reschedule_delivery',
		'update_address',
		'request_return',
		'record_feedback',
		'search_products',
		'create_ticket',
	);

	/**
	 * Wrong "last 4 digits" answers allowed per order per hour before phone verification is locked.
	 */
	const MAX_LAST4_FAILURES = 5;

	/**
	 * Use cases an admin can trigger for a single order.
	 *
	 * @var string[]
	 */
	const ORDER_USE_CASES = array( 'cod_confirmation', 'delivery_reminder', 'ndr_resolution', 'feedback', 'support_callback' );

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * REST namespace.
	 *
	 * @return string
	 */
	public static function ns() {
		return defined( 'VOICEPRESS_REST_NS' ) ? VOICEPRESS_REST_NS : 'voicepress/v1';
	}

	/* ---------------------------------------------------------------------
	 * URL builders (used by VoicePress_Agents when building tools/webhooks).
	 * ------------------------------------------------------------------- */

	/**
	 * Public base URL Bolna can reach (tunnel URL when set, otherwise the site URL).
	 *
	 * @return string No trailing slash.
	 */
	public static function public_base() {
		$base = class_exists( 'VoicePress_Settings' ) ? (string) VoicePress_Settings::public_url() : '';
		if ( '' === $base ) {
			$base = untrailingslashit( home_url() );
		}
		return $base;
	}

	/**
	 * Build a permalink-independent REST URL: {public_base}/?rest_route=/voicepress/v1/{route}&k=v.
	 *
	 * @param string $route Route without namespace, e.g. "tools/cancel_order".
	 * @param array  $query Extra query args.
	 * @return string
	 */
	public static function route_url( $route, array $query = array() ) {
		$url = self::public_base() . '/?rest_route=/' . self::ns() . '/' . ltrim( (string) $route, '/' );
		foreach ( $query as $key => $value ) {
			$url .= '&' . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return $url;
	}

	/**
	 * URL Bolna calls for a tool.
	 *
	 * @param string $tool Tool name.
	 * @return string
	 */
	public static function tool_url( $tool ) {
		return self::route_url( 'tools/' . $tool, array( 'vp_token' => VoicePress_Settings::secret() ) );
	}

	/**
	 * URL Bolna posts execution updates to.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return self::route_url( 'webhook', array( 'vp_token' => VoicePress_Settings::secret() ) );
	}

	/* ---------------------------------------------------------------------
	 * Input helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * Normalise a value Bolna sent for a tool parameter.
	 *
	 * Returns '' for null, "None", "null", "undefined", "N/A", unfilled placeholders such as
	 * "%(phone_last4)s" and unresolved prompt variables such as "{{from_number}}".
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public static function clean_param( $v ) {
		if ( null === $v || is_array( $v ) || is_object( $v ) ) {
			return '';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'true' : '';
		}
		$v = trim( (string) $v );
		// LLMs sometimes wrap a value in quotes.
		$len = strlen( $v );
		if ( $len >= 2 && ( ( '"' === $v[0] && '"' === $v[ $len - 1 ] ) || ( "'" === $v[0] && "'" === $v[ $len - 1 ] ) ) ) {
			$v = trim( substr( $v, 1, -1 ) );
		}
		if ( in_array( strtolower( $v ), array( 'none', 'null', 'undefined', 'n/a' ), true ) ) {
			return '';
		}
		if ( preg_match( '/^%\(.+\)[sif]$/', $v ) ) {
			return '';
		}
		if ( preg_match( '/^\{\{?\s*[A-Za-z0-9_.]+\s*\}\}?$/', $v ) ) {
			return '';
		}
		return $v;
	}

	/**
	 * The "language" param of a request (query, form or JSON body) when it is an enabled language, else ''.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	public static function requested_language( WP_REST_Request $request ) {
		if ( ! class_exists( 'VoicePress_Languages' ) ) {
			return '';
		}
		$raw = $request->get_param( 'language' );
		if ( null === $raw ) {
			$json = $request->get_json_params();
			$raw  = is_array( $json ) && isset( $json['language'] ) ? $json['language'] : null;
		}
		$code = VoicePress_Languages::sanitize_code( is_scalar( $raw ) ? (string) $raw : '' );
		return ( '' !== $code && VoicePress_Languages::is_enabled( $code ) ) ? $code : '';
	}

	/**
	 * Is this request signed with the VoicePress secret (Bearer header or vp_token query arg)?
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function check_token( WP_REST_Request $request ) {
		$secret = class_exists( 'VoicePress_Settings' ) ? (string) VoicePress_Settings::secret() : '';
		if ( '' === $secret ) {
			return false;
		}

		$candidates = array();
		$auth       = trim( (string) $request->get_header( 'authorization' ) );
		if ( '' !== $auth && preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
			$candidates[] = trim( $m[1] );
		}
		$query = $request->get_query_params();
		if ( isset( $query['vp_token'] ) && is_string( $query['vp_token'] ) ) {
			$candidates[] = trim( $query['vp_token'] );
		}

		foreach ( $candidates as $token ) {
			if ( '' !== $token && hash_equals( $secret, $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Permission callback for Bolna-facing routes (tools + webhook).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_token( WP_REST_Request $request ) {
		if ( self::check_token( $request ) ) {
			return true;
		}
		return new WP_Error( 'voicepress_unauthorized', __( 'Invalid or missing VoicePress token.', 'voicepress' ), array( 'status' => 401 ) );
	}

	/**
	 * Permission callback for admin routes.
	 *
	 * @return bool
	 */
	public static function permission_admin() {
		return current_user_can( 'manage_woocommerce' );
	}

	/* ---------------------------------------------------------------------
	 * Route registration.
	 * ------------------------------------------------------------------- */

	/**
	 * Register all routes.
	 */
	public static function register_routes() {
		$token  = array( __CLASS__, 'permission_token' );
		$admin  = array( __CLASS__, 'permission_admin' );
		$public = '__return_true';
		$post   = WP_REST_Server::CREATABLE;
		$get    = WP_REST_Server::READABLE;

		// path, methods, callback, permission, required params.
		$routes = array(
			// Called by Bolna.
			array( '/tools/(?P<tool>[a-z_]+)', $post, 'route_tool', $token, array() ),
			array( '/webhook', $post, 'route_webhook', $token, array() ),
			// Called by the storefront widget.
			array( '/web-session', $post, 'route_web_session', $public, array() ),
			array( '/cart-capture', $post, 'route_cart_capture', $public, array() ),
			array( '/callback-request', $post, 'route_callback_request', $public, array() ),
			// Called by the VoicePress admin screens.
			array( '/admin/test-call', $post, 'route_admin_test_call', $admin, array( 'phone', 'use_case' ) ),
			array( '/admin/call-order', $post, 'route_admin_call_order', $admin, array( 'order_id', 'use_case' ) ),
			array( '/admin/tick', $post, 'route_admin_tick', $admin, array() ),
			array( '/admin/sync-call', $post, 'route_admin_sync_call', $admin, array( 'id' ) ),
			array( '/admin/call/(?P<id>\d+)', $get, 'route_admin_call', $admin, array() ),
			array( '/admin/order-calls/(?P<order_id>\d+)', $get, 'route_admin_order_calls', $admin, array() ),
			array( '/admin/recording/(?P<id>\d+)', $get, 'route_admin_recording', $admin, array() ),
			array( '/admin/provision', $post, 'route_admin_provision', $admin, array() ),
			array( '/admin/sync-agents', $post, 'route_admin_sync_agents', $admin, array() ),
			array( '/admin/link-inbound', $post, 'route_admin_link_inbound', $admin, array( 'phone_number_id' ) ),
			array( '/admin/bolna-status', $get, 'route_admin_bolna_status', $admin, array() ),
			array( '/admin/simulate-cart', $post, 'route_admin_simulate_cart', $admin, array( 'phone' ) ),
		);

		foreach ( $routes as $route ) {
			$args = array();
			foreach ( $route[4] as $required ) {
				$args[ $required ] = array( 'required' => true );
			}
			register_rest_route(
				self::ns(),
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => self::safe( $route[2] ),
					'permission_callback' => $route[3],
					'args'                => $args,
				)
			);
		}
	}

	/**
	 * Wrap a route callback so an unexpected error never becomes a fatal / HTML error page.
	 *
	 * @param string $method Static method name on this class.
	 * @return Closure
	 */
	private static function safe( $method ) {
		return static function ( $request ) use ( $method ) {
			try {
				return call_user_func( array( __CLASS__, $method ), $request );
			} catch ( Throwable $e ) {
				self::log( 'REST ' . $method . ' failed: ' . $e->getMessage() );
				return new WP_Error( 'voicepress_internal', __( 'Something went wrong. Please try again.', 'voicepress' ), array( 'status' => 500 ) );
			}
		};
	}

	/* ---------------------------------------------------------------------
	 * Bolna: tools.
	 * ------------------------------------------------------------------- */

	/**
	 * POST tools/{tool} — always HTTP 200 with { ok, message, ...data }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_tool( WP_REST_Request $request ) {
		$url_params = $request->get_url_params();
		$tool       = isset( $url_params['tool'] ) ? sanitize_key( $url_params['tool'] ) : '';
		$params     = array();
		$result     = null;

		try {
			$params = self::tool_params( $request );
			$method = 'tool_' . $tool;
			if ( in_array( $tool, self::TOOLS, true ) && method_exists( __CLASS__, $method ) ) {
				$result = call_user_func( array( __CLASS__, $method ), $params );
			} else {
				$result = array(
					'ok'      => false,
					'message' => __( 'Sorry, that is not something I can do on this call.', 'voicepress' ),
				);
			}
		} catch ( Throwable $e ) {
			self::log( 'Tool ' . $tool . ' failed: ' . $e->getMessage() );
			$result = null;
		}

		if ( ! is_array( $result ) ) {
			$result = array(
				'ok'      => false,
				'message' => __( 'Sorry, I could not complete that just now. Offer to raise a ticket so our team calls the customer back.', 'voicepress' ),
			);
		}
		// Put ok + message first so they are the first thing the LLM reads.
		$result = array_merge(
			array(
				'ok'      => ! empty( $result['ok'] ),
				'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
			),
			$result
		);
		$result['ok'] = (bool) $result['ok'];

		self::log( 'Tool ' . $tool . ' -> ' . ( $result['ok'] ? 'ok' : 'not ok' ) . ': ' . $result['message'] );

		/**
		 * Fires after a Bolna agent called a VoicePress tool.
		 *
		 * @param string $tool   Tool name.
		 * @param array  $params Cleaned parameters.
		 * @param array  $result Response sent back to the agent.
		 */
		do_action( 'voicepress_tool_called', $tool, $params, $result );

		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Collect tool parameters from JSON body, form body or query string, cleaned to strings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,string>
	 */
	private static function tool_params( WP_REST_Request $request ) {
		$raw = $request->get_params();

		// JSON sent without a JSON content type is not parsed by WordPress.
		$json = $request->get_json_params();
		if ( empty( $json ) ) {
			$decoded = json_decode( (string) $request->get_body(), true );
			if ( is_array( $decoded ) ) {
				$raw = array_merge( $raw, $decoded );
			}
		}

		// Tolerate the arguments being nested one level deep.
		foreach ( array( 'param', 'params', 'parameters', 'arguments', 'args' ) as $nest ) {
			if ( ! isset( $raw[ $nest ] ) ) {
				continue;
			}
			$inner = $raw[ $nest ];
			if ( is_string( $inner ) ) {
				$inner = json_decode( $inner, true );
			}
			if ( is_array( $inner ) ) {
				$raw = array_merge( $raw, $inner );
			}
		}

		unset( $raw['vp_token'], $raw['tool'], $raw['rest_route'], $raw['_wpnonce'] );

		$out = array();
		foreach ( $raw as $key => $value ) {
			if ( ! is_string( $key ) || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$out[ sanitize_key( $key ) ] = self::clean_param( $value );
		}
		return $out;
	}

	/**
	 * Tool: identify the caller from the phone numbers on the call.
	 *
	 * @param array $p Params: from_number, to_number, call_sid.
	 * @return array
	 */
	public static function tool_identify_customer( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}

		foreach ( self::caller_numbers( $p ) as $number ) {
			$orders = VoicePress_Helpers::find_orders_by_phone( $number, 5 );
			$orders = array_values(
				array_filter(
					(array) $orders,
					static function ( $o ) {
						return $o instanceof WC_Order;
					}
				)
			);
			if ( empty( $orders ) ) {
				continue;
			}

			$name   = trim( (string) $orders[0]->get_billing_first_name() );
			$list   = array();
			$spoken = array();
			foreach ( $orders as $order ) {
				$c      = VoicePress_Helpers::order_context( $order );
				$list[] = array(
					'order_id' => $c['order_id'],
					'status'   => $c['order_status'],
					'items'    => $c['items'],
					'total'    => $c['order_total'],
					'date'     => $c['order_date'],
				);
				if ( count( $spoken ) < 3 ) {
					/* translators: 1: order number, 2: items summary, 3: order status. */
					$spoken[] = sprintf( __( 'order %1$s for %2$s, %3$s', 'voicepress' ), $c['order_id'], $c['items'], $c['order_status'] );
				}
			}

			$count = count( $list );
			$who   = '' !== $name ? $name : __( 'a customer', 'voicepress' );
			/* translators: 1: customer first name, 2: number of orders, 3: list of orders. */
			$message = sprintf( _n( 'Found %1$s with %2$d recent order: %3$s.', 'Found %1$s with %2$d recent orders: %3$s.', $count, 'voicepress' ), $who, $count, implode( '; ', $spoken ) );

			return array(
				'ok'            => true,
				'found'         => true,
				'customer_name' => $name,
				'orders'        => $list,
				'message'       => $message,
			);
		}

		return array(
			'ok'            => true,
			'found'         => false,
			'customer_name' => '',
			'orders'        => array(),
			'message'       => __( 'No account found for this number; ask for the order number.', 'voicepress' ),
		);
	}

	/**
	 * Tool: order status and details (full details only for verified callers).
	 *
	 * @param array $p Params: order_id, from_number, to_number, phone_last4.
	 * @return array
	 */
	public static function tool_get_order_details( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}
		$raw   = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		$order = self::find_order( $raw );
		if ( ! $order ) {
			return self::order_missing( $raw );
		}

		$c        = VoicePress_Helpers::order_context( $order );
		$status   = self::status_sentence( $order, $c );
		$expected = self::expected_delivery( $order, $c );
		$failure  = '';
		$verified = '' !== self::verify_caller( $order, $p, $failure );

		if ( ! $verified ) {
			$result = array(
				'ok'                => true,
				'verified'          => false,
				'order_id'          => $c['order_id'],
				'status'            => $c['order_status'],
				'expected_delivery' => $expected,
			);
			if ( 'mismatch' === $failure || 'locked' === $failure || 'no_phone' === $failure ) {
				$unverified = self::unverified( $failure );
				$result['needs_verification'] = $unverified['needs_verification'];
				$result['message']            = $status . ' ' . $unverified['message'];
			} else {
				$result['message'] = $status . ' ' . __( 'To share more details or make changes, ask for the last 4 digits of the phone number used on the order.', 'voicepress' );
			}
			return $result;
		}

		$is_cod = 'yes' === $c['is_cod'];
		if ( $is_cod ) {
			/* translators: %s: order total. */
			$payment = sprintf( __( 'Total %s, to be paid in cash on delivery.', 'voicepress' ), $c['order_total'] );
		} else {
			/* translators: 1: order total, 2: payment method. */
			$payment = sprintf( __( 'Total %1$s, paid by %2$s.', 'voicepress' ), $c['order_total'], $c['payment_method'] );
		}
		/* translators: 1: status sentence, 2: items summary, 3: payment sentence. */
		$message = sprintf( __( '%1$s It contains %2$s. %3$s', 'voicepress' ), $status, $c['items'], $payment );

		$actions = array();
		foreach ( (array) VoicePress_Helpers::allowed_actions( $order ) as $key => $value ) {
			$actions[ $key ] = self::truthy( $value );
		}

		return array(
			'ok'                => true,
			'verified'          => true,
			'order_id'          => $c['order_id'],
			'customer_name'     => $c['customer_name'],
			'status'            => $c['order_status'],
			'items'             => $c['items'],
			'total'             => $c['order_total'],
			'payment_method'    => $c['payment_method'],
			'is_cod'            => $c['is_cod'],
			'order_date'        => $c['order_date'],
			'delivery_address'  => $c['delivery_address'],
			'expected_delivery' => $expected,
			'delivered_on'      => $c['delivered_on'],
			'actions'           => $actions,
			'notes'             => self::latest_customer_note( $order ),
			'message'           => $message,
		);
	}

	/**
	 * Tool: customer confirms a (COD) order.
	 *
	 * @param array $p Params: order_id, from_number, to_number.
	 * @return array
	 */
	public static function tool_confirm_order( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}
		$raw   = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		$order = self::find_order( $raw );
		if ( ! $order ) {
			return self::order_missing( $raw );
		}
		$c   = VoicePress_Helpers::order_context( $order );
		$num = $c['order_id'];

		if ( ! self::can_confirm( $order, $p ) ) {
			return array(
				'ok'       => false,
				'order_id' => $num,
				/* translators: %s: order number. */
				'message'  => sprintf( __( 'I could not match this call to order %s, so I cannot confirm it here. Tell the customer our team will confirm it with them.', 'voicepress' ), $num ),
			);
		}

		$status = $order->get_status();
		$cod    = (string) $order->get_meta( '_voicepress_cod_status' );

		if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) || 'cancelled' === $cod ) {
			return array(
				'ok'       => false,
				'order_id' => $num,
				/* translators: %s: order number. */
				'message'  => sprintf( __( 'Order %s is already cancelled, so it cannot be confirmed. The customer can place a fresh order on our website.', 'voicepress' ), $num ),
			);
		}
		if ( ! in_array( $status, array( 'pending', 'on-hold', 'processing' ), true ) ) {
			return array(
				'ok'       => true,
				'order_id' => $num,
				'status'   => $c['order_status'],
				/* translators: 1: order number, 2: order status. */
				'message'  => sprintf( __( 'Order %1$s is already %2$s, so no confirmation is needed.', 'voicepress' ), $num, strtolower( $c['order_status'] ) ),
			);
		}
		if ( 'confirmed' === $cod ) {
			return array(
				'ok'       => true,
				'order_id' => $num,
				'status'   => 'confirmed',
				/* translators: %s: order number. */
				'message'  => sprintf( __( 'Order %s was already confirmed, so nothing more is needed.', 'voicepress' ), $num ),
			);
		}

		$order->update_meta_data( '_voicepress_cod_status', 'confirmed' );
		$order->save();
		$order->add_order_note( __( 'Customer confirmed the order on a VoicePress call.', 'voicepress' ) );

		/* translators: 1: order number, 2: delivery estimate such as "3 to 5 working days". */
		$message = sprintf( __( 'Order %1$s is confirmed. It will be dispatched soon and usually arrives within %2$s.', 'voicepress' ), $num, self::eta( $c ) );
		if ( 'yes' === $c['is_cod'] ) {
			/* translators: %s: order total. */
			$message .= ' ' . sprintf( __( 'Please keep %s ready for cash on delivery.', 'voicepress' ), $c['order_total'] );
		}

		return array(
			'ok'       => true,
			'order_id' => $num,
			'status'   => 'confirmed',
			'message'  => $message,
		);
	}

	/**
	 * Tool: cancel an order that has not shipped.
	 *
	 * @param array $p Params: order_id, reason, from_number, to_number, phone_last4.
	 * @return array
	 */
	public static function tool_cancel_order( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}
		$raw   = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		$order = self::find_order( $raw );
		if ( ! $order ) {
			return self::order_missing( $raw );
		}
		$failure = '';
		if ( '' === self::verify_caller( $order, $p, $failure ) ) {
			return self::unverified( $failure );
		}

		$c      = VoicePress_Helpers::order_context( $order );
		$num    = $c['order_id'];
		$status = $order->get_status();

		if ( 'cancelled' === $status ) {
			return array(
				'ok'       => true,
				'order_id' => $num,
				/* translators: %s: order number. */
				'message'  => sprintf( __( 'Order %s is already cancelled.', 'voicepress' ), $num ),
			);
		}

		$actions = (array) VoicePress_Helpers::allowed_actions( $order );
		if ( empty( $actions['can_cancel'] ) || ! self::truthy( $actions['can_cancel'] ) ) {
			return array(
				'ok'       => false,
				'order_id' => $num,
				'status'   => $c['order_status'],
				'message'  => self::cannot_cancel_message( $order, $c ),
			);
		}

		$reason = self::text( self::first( $p, array( 'reason', 'cancel_reason' ) ), 300 );
		if ( '' === $reason ) {
			$reason = __( 'not given', 'voicepress' );
		}
		$is_cod = 'cod' === $order->get_payment_method();
		if ( $is_cod ) {
			$order->update_meta_data( '_voicepress_cod_status', 'cancelled' );
		}
		/* translators: %s: cancellation reason. */
		$done = $order->update_status( 'cancelled', sprintf( __( 'Cancelled by customer on VoicePress call. Reason: %s', 'voicepress' ), $reason ) );
		if ( ! $done ) {
			return array(
				'ok'       => false,
				'order_id' => $num,
				/* translators: %s: order number. */
				'message'  => sprintf( __( 'I could not cancel order %s right now. Offer to raise a ticket so our team cancels it and calls the customer back.', 'voicepress' ), $num ),
			);
		}

		/* translators: %s: order number. */
		$message  = sprintf( __( 'Order %s is now cancelled.', 'voicepress' ), $num );
		$message .= ' ' . ( $is_cod
			? __( 'Since it was cash on delivery, there is nothing to pay.', 'voicepress' )
			: __( 'Our team will process the refund for the online payment.', 'voicepress' ) );

		return array(
			'ok'       => true,
			'order_id' => $num,
			'status'   => wc_get_order_status_name( 'cancelled' ),
			'message'  => $message,
		);
	}

	/**
	 * Tool: note a preferred delivery date / slot.
	 *
	 * @param array $p Params: order_id, preferred_date, time_slot, from_number, to_number, phone_last4.
	 * @return array
	 */
	public static function tool_reschedule_delivery( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}
		$raw   = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		$order = self::find_order( $raw );
		if ( ! $order ) {
			return self::order_missing( $raw );
		}
		$failure = '';
		if ( '' === self::verify_caller( $order, $p, $failure ) ) {
			return self::unverified( $failure );
		}

		$c       = VoicePress_Helpers::order_context( $order );
		$num     = $c['order_id'];
		$actions = (array) VoicePress_Helpers::allowed_actions( $order );
		if ( empty( $actions['can_reschedule'] ) || ! self::truthy( $actions['can_reschedule'] ) ) {
			if ( 'completed' === $order->get_status() ) {
				/* translators: %s: order number. */
				$message = sprintf( __( 'Order %s has already been delivered, so the delivery cannot be rescheduled.', 'voicepress' ), $num );
			} else {
				/* translators: 1: order number, 2: order status. */
				$message = sprintf( __( 'Order %1$s is %2$s, so there is no delivery to reschedule.', 'voicepress' ), $num, strtolower( $c['order_status'] ) );
			}
			return array(
				'ok'       => false,
				'order_id' => $num,
				'status'   => $c['order_status'],
				'message'  => $message,
			);
		}

		$date_raw = self::text( self::first( $p, array( 'preferred_date', 'date', 'new_date' ) ), 120 );
		$slot     = self::text( self::first( $p, array( 'time_slot', 'preferred_slot', 'slot' ) ), 60 );
		if ( '' === $date_raw ) {
			return array(
				'ok'       => false,
				'order_id' => $num,
				'message'  => __( 'Please ask the customer which day suits them for the delivery.', 'voicepress' ),
			);
		}
		if ( '' === $slot ) {
			$slot = self::slot_from_text( $date_raw );
		}
		$slot = self::normalize_slot( $slot );
		$date = self::resolve_date( $date_raw );
		$pref = '' !== $slot ? $date . ' / ' . $slot : $date;

		$order->update_meta_data( '_voicepress_delivery_pref', $pref );
		$order->save();
		/* translators: %s: preferred date and slot. */
		$order->add_order_note( sprintf( __( 'Customer asked to reschedule delivery to %s on a VoicePress call.', 'voicepress' ), $pref ) );

		$when = '' !== $slot ? $date . ', ' . $slot : $date;
		/* translators: 1: order number, 2: preferred date and slot. */
		$message = sprintf( __( 'Done. Delivery of order %1$s is noted for %2$s, and our delivery team has been informed.', 'voicepress' ), $num, $when );

		return array(
			'ok'             => true,
			'order_id'       => $num,
			'preferred_date' => $date,
			'time_slot'      => $slot,
			'message'        => $message,
		);
	}

	/**
	 * Tool: record a new delivery address for the team to apply.
	 *
	 * @param array $p Params: order_id, new_address, from_number, to_number, phone_last4.
	 * @return array
	 */
	public static function tool_update_address( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}
		$raw   = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		$order = self::find_order( $raw );
		if ( ! $order ) {
			return self::order_missing( $raw );
		}
		$failure = '';
		if ( '' === self::verify_caller( $order, $p, $failure ) ) {
			return self::unverified( $failure );
		}

		$c       = VoicePress_Helpers::order_context( $order );
		$num     = $c['order_id'];
		$status  = $order->get_status();
		$actions = (array) VoicePress_Helpers::allowed_actions( $order );
		$before  = ! empty( $actions['can_update_address'] ) && self::truthy( $actions['can_update_address'] );
		// Already shipped: the courier can still use a corrected address on the next attempt.
		$shipped = in_array( $status, array( 'out-for-delivery', 'delivery-failed' ), true );

		if ( ! $before && ! $shipped ) {
			if ( 'completed' === $status ) {
				/* translators: %s: order number. */
				$message = sprintf( __( 'Order %s has already been delivered, so the address cannot be changed.', 'voicepress' ), $num );
			} else {
				/* translators: 1: order number, 2: order status. */
				$message = sprintf( __( 'Order %1$s is %2$s, so the address cannot be changed.', 'voicepress' ), $num, strtolower( $c['order_status'] ) );
			}
			return array(
				'ok'       => false,
				'order_id' => $num,
				'status'   => $c['order_status'],
				'message'  => $message,
			);
		}

		$address = self::text( self::first( $p, array( 'new_address', 'address' ) ), 400 );
		if ( '' === $address ) {
			return array(
				'ok'       => false,
				'order_id' => $num,
				'message'  => __( 'Please ask the customer for the full new address, including the area, city and pincode.', 'voicepress' ),
			);
		}

		$order->update_meta_data( '_voicepress_address_change', $address );
		$order->save();
		/* translators: %s: new address. */
		$order->add_order_note( sprintf( __( 'Address change requested: %s', 'voicepress' ), $address ) );

		if ( $shipped ) {
			/* translators: 1: order number, 2: new address. */
			$message = sprintf( __( 'I have noted the updated address for order %1$s: %2$s. The order has already been shipped, so our team will pass it to the delivery partner for the next attempt.', 'voicepress' ), $num, $address );
		} else {
			/* translators: 1: order number, 2: new address. */
			$message = sprintf( __( 'I have noted the new delivery address for order %1$s: %2$s. Our team will update it before the order is dispatched.', 'voicepress' ), $num, $address );
		}

		return array(
			'ok'          => true,
			'order_id'    => $num,
			'new_address' => $address,
			'message'     => $message,
		);
	}

	/**
	 * Tool: start a return for a delivered order inside the return window.
	 *
	 * @param array $p Params: order_id, reason, reason_category, from_number, to_number, phone_last4.
	 * @return array
	 */
	public static function tool_request_return( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}
		$raw   = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		$order = self::find_order( $raw );
		if ( ! $order ) {
			return self::order_missing( $raw );
		}
		$failure = '';
		if ( '' === self::verify_caller( $order, $p, $failure ) ) {
			return self::unverified( $failure );
		}

		$c      = VoicePress_Helpers::order_context( $order );
		$num    = $c['order_id'];
		$status = $order->get_status();
		$window = max( 0, (int) VoicePress_Settings::get( 'return_window_days', 7 ) );

		if ( 'return-requested' === $status ) {
			return array(
				'ok'       => true,
				'order_id' => $num,
				/* translators: %s: order number. */
				'message'  => sprintf( __( 'A return for order %s is already registered; our team will contact the customer about the pickup.', 'voicepress' ), $num ),
			);
		}

		$actions = (array) VoicePress_Helpers::allowed_actions( $order );
		if ( empty( $actions['can_return'] ) || ! self::truthy( $actions['can_return'] ) ) {
			if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				/* translators: 1: order number, 2: order status. */
				$message = sprintf( __( 'Order %1$s is %2$s, so there is nothing to return.', 'voicepress' ), $num, strtolower( $c['order_status'] ) );
			} elseif ( 'completed' !== $status ) {
				/* translators: %s: order number. */
				$message = sprintf( __( 'Order %s has not been delivered yet, so a return cannot be started.', 'voicepress' ), $num );
				if ( ! empty( $actions['can_cancel'] ) && self::truthy( $actions['can_cancel'] ) ) {
					$message .= ' ' . __( 'It can still be cancelled instead, if the customer wants.', 'voicepress' );
				} else {
					/* translators: %d: return window in days. */
					$message .= ' ' . sprintf( __( 'Once it is delivered, a return can be requested within %d days.', 'voicepress' ), $window );
				}
			} else {
				$delivered = '' !== $c['delivered_on'] ? $c['delivered_on'] : __( 'an earlier date', 'voicepress' );
				/* translators: 1: order number, 2: delivery date, 3: return window in days. */
				$message = sprintf( __( 'Order %1$s was delivered on %2$s, which is outside our %3$d day return window, so a return is not possible. Offer to raise a ticket if there is a problem with the product.', 'voicepress' ), $num, $delivered, $window );
			}
			return array(
				'ok'       => false,
				'order_id' => $num,
				'status'   => $c['order_status'],
				'message'  => $message,
			);
		}

		$categories = array( 'damaged', 'wrong_item', 'size_issue', 'quality', 'changed_mind', 'other' );
		$category   = sanitize_key( self::first( $p, array( 'reason_category', 'category' ) ) );
		if ( ! in_array( $category, $categories, true ) ) {
			$category = 'other';
		}
		$reason = self::text( self::first( $p, array( 'reason', 'return_reason' ) ), 300 );
		if ( '' === $reason ) {
			$reason = __( 'not given', 'voicepress' );
		}
		/* translators: 1: reason category, 2: reason in the customer's words. */
		$note = sprintf( __( 'Return requested by customer on VoicePress call. Category: %1$s. Reason: %2$s', 'voicepress' ), str_replace( '_', ' ', $category ), $reason );

		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		if ( isset( $statuses['wc-return-requested'] ) ) {
			$done = $order->update_status( 'return-requested', $note );
		} else {
			// Custom status not registered: never let WooCommerce fall back to "pending".
			$order->add_order_note( $note );
			$done = true;
		}
		if ( ! $done ) {
			return array(
				'ok'       => false,
				'order_id' => $num,
				/* translators: %s: order number. */
				'message'  => sprintf( __( 'I could not register the return for order %s right now. Offer to raise a ticket so our team calls the customer back.', 'voicepress' ), $num ),
			);
		}

		return array(
			'ok'              => true,
			'order_id'        => $num,
			'reason_category' => $category,
			/* translators: %s: order number. */
			'message'         => sprintf( __( 'I have registered a return request for order %s. Our team will contact the customer to arrange the pickup.', 'voicepress' ), $num ),
		);
	}

	/**
	 * Tool: save a 1-5 rating from a feedback call.
	 *
	 * @param array $p Params: order_id, rating, comment.
	 * @return array
	 */
	public static function tool_record_feedback( array $p ): array {
		if ( ! self::woo_ready() ) {
			return self::woo_unavailable();
		}
		$rating = self::parse_rating( self::first( $p, array( 'rating', 'stars', 'score' ) ) );
		if ( $rating < 1 ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please ask the customer for a rating from 1 to 5 stars.', 'voicepress' ),
			);
		}
		$raw   = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		$order = self::find_order( $raw );
		if ( ! $order ) {
			return array(
				'ok'      => false,
				'rating'  => $rating,
				'message' => __( 'I could not find that order to save the rating, but thank the customer warmly for the feedback.', 'voicepress' ),
			);
		}

		$comment = self::text( self::first( $p, array( 'comment', 'feedback', 'note' ) ), 500 );
		$order->update_meta_data( '_voicepress_rating', $rating );
		if ( $rating <= 2 ) {
			$order->update_meta_data(
				'_voicepress_escalation',
				'' !== $comment
					/* translators: 1: rating, 2: customer comment. */
					? sprintf( __( 'Rated %1$d/5 on feedback call: %2$s', 'voicepress' ), $rating, $comment )
					/* translators: %d: rating. */
					: sprintf( __( 'Rated %d/5 on feedback call.', 'voicepress' ), $rating )
			);
		}
		$order->save();
		$order->add_order_note(
			'' !== $comment
				/* translators: 1: rating, 2: customer comment. */
				? sprintf( __( 'Customer rated %1$d/5 on feedback call: %2$s', 'voicepress' ), $rating, $comment )
				/* translators: %d: rating. */
				: sprintf( __( 'Customer rated %d/5 on feedback call.', 'voicepress' ), $rating )
		);

		if ( $rating >= 4 ) {
			/* translators: %d: rating. */
			$message = sprintf( __( 'Thank you so much! I have recorded your %d star rating.', 'voicepress' ), $rating );
		} elseif ( 3 === $rating ) {
			$message = __( 'Thank you, I have recorded your 3 star rating and shared your comments with the team.', 'voicepress' );
		} else {
			$message = __( 'I am sorry about that. I have recorded your rating and flagged it so our team can follow up with you.', 'voicepress' );
		}

		return array(
			'ok'        => true,
			'order_id'  => VoicePress_Helpers::order_context( $order )['order_id'],
			'rating'    => $rating,
			'escalated' => $rating <= 2,
			'message'   => $message,
		);
	}

	/**
	 * Tool: search the catalogue.
	 *
	 * @param array $p Params: query.
	 * @return array
	 */
	public static function tool_search_products( array $p ): array {
		if ( ! self::woo_ready() || ! function_exists( 'wc_get_products' ) ) {
			return self::woo_unavailable();
		}
		$query = self::text( self::first( $p, array( 'query', 'product', 'search', 'q' ) ), 100 );
		if ( '' === $query ) {
			return array(
				'ok'       => false,
				'products' => array(),
				'message'  => __( 'Please ask the customer which product they are looking for.', 'voicepress' ),
			);
		}

		$products = array();
		$spoken   = array();
		foreach ( self::find_products( $query, 3 ) as $product ) {
			$price = (string) $product->get_price();
			$price = '' !== $price ? VoicePress_Helpers::speak_amount( (float) $price ) : '';
			if ( '' !== $price && $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) && $product->get_variation_price( 'min' ) !== $product->get_variation_price( 'max' ) ) {
				/* translators: %s: lowest price. */
				$price = sprintf( __( 'from %s', 'voicepress' ), $price );
			}
			$in_stock    = $product->is_in_stock();
			$description = $product->get_short_description();
			if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
				$description = $product->get_description();
			}

			$products[] = array(
				'id'                => $product->get_id(),
				'name'              => $product->get_name(),
				'price'             => $price,
				'in_stock'          => $in_stock,
				'short_description' => self::short_text( $description, 160 ),
			);

			$stock    = $in_stock ? __( 'in stock', 'voicepress' ) : __( 'currently out of stock', 'voicepress' );
			$spoken[] = '' !== $price
				/* translators: 1: product name, 2: price, 3: stock status. */
				? sprintf( __( '%1$s for %2$s, %3$s', 'voicepress' ), $product->get_name(), $price, $stock )
				/* translators: 1: product name, 2: stock status. */
				: sprintf( __( '%1$s, %2$s', 'voicepress' ), $product->get_name(), $stock );
		}

		if ( empty( $products ) ) {
			return array(
				'ok'       => true,
				'products' => array(),
				/* translators: %s: search words. */
				'message'  => sprintf( __( 'I could not find any products matching %s. Ask the customer to describe it differently.', 'voicepress' ), $query ),
			);
		}

		$count = count( $products );
		return array(
			'ok'       => true,
			'products' => $products,
			/* translators: 1: number of products, 2: list of products. */
			'message'  => sprintf( _n( 'I found %1$d product: %2$s.', 'I found %1$d products: %2$s.', $count, 'voicepress' ), $count, implode( '; ', $spoken ) ),
		);
	}

	/**
	 * Tool: raise a ticket for the human team.
	 *
	 * @param array $p Params: summary, priority, order_id, from_number, to_number.
	 * @return array
	 */
	public static function tool_create_ticket( array $p ): array {
		$summary = self::text( self::first( $p, array( 'summary', 'issue', 'description' ) ), 500 );
		if ( '' === $summary ) {
			$summary = __( 'Customer asked for help from the team (no summary given).', 'voicepress' );
		}
		$priority = strtolower( self::first( $p, array( 'priority' ) ) );
		$map      = array(
			'low'    => 'low',
			'normal' => 'normal',
			'medium' => 'normal',
			'high'   => 'high',
			'urgent' => 'high',
		);
		$priority = isset( $map[ $priority ] ) ? $map[ $priority ] : 'normal';

		$numbers = self::caller_numbers( $p );
		$phone   = ! empty( $numbers ) ? VoicePress_Helpers::normalize_phone( $numbers[0] ) : '';

		$order    = null;
		$order_id = '';
		$name     = '';
		$raw      = self::first( $p, array( 'order_id', 'order_number', 'order' ) );
		if ( '' !== $raw && self::woo_ready() ) {
			$order = self::find_order( $raw );
			if ( $order ) {
				$order_id = (string) $order->get_id();
				$name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				/* translators: 1: priority, 2: ticket summary. */
				$order->add_order_note( sprintf( __( 'VoicePress support ticket raised on call (%1$s priority): %2$s', 'voicepress' ), $priority, $summary ) );
			}
		}

		$ticket = array(
			'time'          => current_time( 'mysql', true ),
			'summary'       => $summary,
			'priority'      => $priority,
			'order_id'      => $order_id,
			'phone'         => $phone,
			'source'        => 'voice_agent',
			'customer_name' => $name,
		);

		$tickets = get_option( 'voicepress_tickets', array() );
		if ( ! is_array( $tickets ) ) {
			$tickets = array();
		}
		array_unshift( $tickets, $ticket );
		update_option( 'voicepress_tickets', array_slice( $tickets, 0, 50 ), false );

		/**
		 * Fires when an agent raises a support ticket.
		 *
		 * @param array         $ticket Ticket data.
		 * @param WC_Order|null $order  Related order, if any.
		 */
		do_action( 'voicepress_ticket_created', $ticket, $order );

		return array(
			'ok'       => true,
			'priority' => $priority,
			'message'  => __( "I've raised a ticket; our team will call you back within 24 hours.", 'voicepress' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Bolna: webhook.
	 * ------------------------------------------------------------------- */

	/**
	 * POST webhook — Bolna execution payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_webhook( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			$payload = json_decode( (string) $request->get_body(), true );
		}
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		if ( ! is_array( $payload ) || empty( $payload['id'] ) || ! is_scalar( $payload['id'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Missing execution id.',
				),
				400
			);
		}
		$payload['id'] = (string) $payload['id'];

		try {
			$row = VoicePress_Calls::ingest_execution( $payload );
		} catch ( Throwable $e ) {
			// Answer 200 anyway: the cron sync picks the call up later and Bolna does not retry forever.
			self::log( 'Webhook ingest failed for ' . $payload['id'] . ': ' . $e->getMessage() );
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'call_id' => is_array( $row ) && isset( $row['id'] ) ? (int) $row['id'] : null,
			),
			200
		);
	}

	/* ---------------------------------------------------------------------
	 * Storefront (public) routes.
	 * ------------------------------------------------------------------- */

	/**
	 * POST web-session — mint a Bolna web-call session for the storefront widget.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_web_session( WP_REST_Request $request ) {
		$unavailable = new WP_Error( 'voicepress_unavailable', __( 'Voice calls are not available right now.', 'voicepress' ), array( 'status' => 403 ) );

		if ( ! self::truthy( VoicePress_Settings::get( 'widget_enabled' ) ) || ! VoicePress_Settings::is_configured() ) {
			return $unavailable;
		}
		$agent_id = (string) VoicePress_Agents::agent_for_use_case( 'web_call' );
		$client   = VoicePress_Settings::client();
		if ( '' === $agent_id || ! $client ) {
			return $unavailable;
		}
		if ( ! self::rate_limit( 'web', 20, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'voicepress_rate_limited', __( 'Too many calls from your network. Please try again a little later.', 'voicepress' ), array( 'status' => 429 ) );
		}

		$user_data = array();
		try {
			$user_data = VoicePress_Frontend::web_user_data( $request );
		} catch ( Throwable $e ) {
			self::log( 'web_user_data failed: ' . $e->getMessage() );
		}
		if ( ! is_array( $user_data ) || empty( $user_data ) ) {
			$user_data = array_merge(
				VoicePress_Helpers::store_context(),
				array(
					'customer_name'    => 'there',
					'customer_context' => '',
					'channel'          => 'web',
				)
			);
		}
		foreach ( $user_data as $key => $value ) {
			if ( null === $value || is_scalar( $value ) ) {
				$user_data[ $key ] = (string) $value;
			}
		}

		// Language picked in the widget (validated against the enabled languages), else the default language.
		$language   = self::requested_language( $request );
		$language   = '' !== $language ? $language : ( class_exists( 'VoicePress_Languages' ) ? VoicePress_Languages::default_language() : 'en' );
		$agent_data = class_exists( 'VoicePress_Languages' ) ? VoicePress_Languages::agent_data( $agent_id, $language ) : array();
		// Without agent_data the call starts in the agent's default language, English.
		$user_data['call_language'] = empty( $agent_data ) ? 'en' : $language;

		$session = $client->mint_web_session( $agent_id, $user_data, $agent_data );
		if ( ! empty( $agent_data ) && VoicePress_Calls::is_language_rejection( $session ) ) {
			VoicePress_Languages::forget_agent( $agent_id );
			$user_data['call_language'] = 'en';
			$session                    = $client->mint_web_session( $agent_id, $user_data );
		}
		if ( is_wp_error( $session ) || ! is_array( $session ) ) {
			$detail = is_wp_error( $session ) ? $session->get_error_message() : 'Unexpected response';
			self::log( 'Web session mint failed: ' . $detail );
			$message = current_user_can( 'manage_woocommerce' ) ? $detail : __( 'We could not start the voice call right now. Please try again in a moment.', 'voicepress' );
			return new WP_REST_Response( array( 'message' => $message ), 502 );
		}

		if ( ! empty( $session['run_id'] ) && is_scalar( $session['run_id'] ) ) {
			try {
				$name = isset( $user_data['customer_name'] ) ? trim( (string) $user_data['customer_name'] ) : '';
				if ( '' === $name || 'there' === strtolower( $name ) ) {
					$name = __( 'Web visitor', 'voicepress' );
				}
				VoicePress_DB::insert_call(
					array(
						'execution_id'  => sanitize_text_field( (string) $session['run_id'] ),
						'use_case'      => 'web_call',
						'direction'     => 'web',
						'agent_id'      => $agent_id,
						'customer_name' => $name,
						'phone'         => self::current_user_phone(),
						'status'        => 'initiated',
						'user_data'     => $user_data,
					)
				);
			} catch ( Throwable $e ) {
				// Logging the call must never block the conversation.
				self::log( 'Could not log web call: ' . $e->getMessage() );
			}
		}

		// The Web Call SDK expects the mint JSON unchanged.
		$response = new WP_REST_Response( $session, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST cart-capture — remember the checkout contact + cart for abandoned-cart calls.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_cart_capture( WP_REST_Request $request ) {
		$phone = VoicePress_Helpers::normalize_phone( sanitize_text_field( (string) $request->get_param( 'phone' ) ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			$email = '';
		}
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( '' === $phone && '' === $email ) {
			return self::public_response( false, __( 'A phone number or email is required.', 'voicepress' ), 400 );
		}
		if ( ! self::rate_limit( 'cart', 120, HOUR_IN_SECONDS ) ) {
			return self::public_response( false, __( 'Too many requests. Please try again later.', 'voicepress' ), 429 );
		}
		if ( ! self::woo_ready() || ! function_exists( 'WC' ) ) {
			return self::public_response( false, __( 'The store is not available right now.', 'voicepress' ) );
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			WC()->frontend_includes();
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart ) {
			return self::public_response( false, __( 'The cart is empty.', 'voicepress' ) );
		}

		$items     = array();
		$line_sum  = 0.0;
		$cart_rows = (array) $cart->get_cart();
		foreach ( $cart_rows as $row ) {
			$product = isset( $row['data'] ) ? $row['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$qty     = isset( $row['quantity'] ) ? max( 1, (int) $row['quantity'] ) : 1;
			$items[] = array(
				'name'       => $product->get_name(),
				'qty'        => $qty,
				'price'      => (float) $product->get_price(),
				'product_id' => $product->get_id(),
			);
			$line_sum += ( isset( $row['line_total'] ) ? (float) $row['line_total'] : 0.0 ) + ( isset( $row['line_tax'] ) ? (float) $row['line_tax'] : 0.0 );
		}
		if ( empty( $items ) ) {
			return self::public_response( false, __( 'The cart is empty.', 'voicepress' ) );
		}

		$total = (float) $cart->get_total( 'edit' );
		if ( $total <= 0 ) {
			$total = $line_sum;
		}
		if ( $total <= 0 ) {
			foreach ( $items as $item ) {
				$total += $item['price'] * $item['qty'];
			}
		}

		// Fill gaps from the logged-in customer's profile.
		$user_id = get_current_user_id();
		if ( $user_id && '' === $name ) {
			$name = (string) get_user_meta( $user_id, 'billing_first_name', true );
			if ( '' === $name ) {
				$user = wp_get_current_user();
				$name = $user ? (string) $user->first_name : '';
			}
		}

		$session_key = '';
		if ( WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$session_key = (string) WC()->session->get_customer_id();
		}
		if ( '' === $session_key ) {
			$agent       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$session_key = md5( self::client_ip() . '|' . $agent );
		}

		$data = array(
			'session_key' => $session_key,
			'cart'        => wp_json_encode( $items ),
			'cart_total'  => round( $total, 2 ),
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'status'      => 'captured',
		);
		foreach ( array(
			'customer_name' => $name,
			'phone'         => $phone,
			'email'         => $email,
		) as $key => $value ) {
			if ( '' !== $value ) {
				$data[ $key ] = $value;
			}
		}

		$existing = VoicePress_DB::get_cart_by_session( $session_key );
		if ( $existing && ! empty( $existing['id'] ) ) {
			$cart_id = (int) $existing['id'];
			VoicePress_DB::update_cart( $cart_id, $data );
		} else {
			$cart_id = (int) VoicePress_DB::insert_cart( $data );
		}

		return new WP_REST_Response(
			array(
				'ok'      => $cart_id > 0,
				'cart_id' => $cart_id,
			),
			200
		);
	}

	/**
	 * POST callback-request — the shopper asks the support agent to call them now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_callback_request( WP_REST_Request $request ) {
		$phone = VoicePress_Helpers::normalize_phone( sanitize_text_field( (string) $request->get_param( 'phone' ) ) );
		if ( '' === $phone ) {
			return self::public_response( false, __( 'Please enter a valid phone number.', 'voicepress' ), 400 );
		}
		$name     = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$order_id = absint( self::digits( (string) $request->get_param( 'order_id' ) ) );

		if ( ! self::rate_limit( 'callback', 5, HOUR_IN_SECONDS ) || ! self::rate_limit( 'callback_phone', 3, DAY_IN_SECONDS, $phone ) ) {
			return self::public_response( false, __( 'We have already received several call-back requests. Please try again later.', 'voicepress' ), 429 );
		}
		if ( ! VoicePress_Settings::is_configured() || '' === (string) VoicePress_Agents::agent_for_use_case( 'support_callback' ) ) {
			return self::public_response( false, __( 'Call-backs are not available right now.', 'voicepress' ), 503 );
		}

		$args = array( 'phone' => $phone );

		// Only attach an order the requester really owns (never trust client-sent order data).
		if ( $order_id && self::woo_ready() ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$user_id = get_current_user_id();
				$owns    = VoicePress_Helpers::phones_match( self::order_phone( $order ), $phone )
					|| ( $user_id && (int) $order->get_customer_id() === (int) $user_id );
				if ( $owns ) {
					$args['order_id'] = $order->get_id();
					if ( '' === $name ) {
						$name = (string) $order->get_billing_first_name();
					}
				}
			}
		}
		if ( '' === $name && self::woo_ready() ) {
			$orders = VoicePress_Helpers::find_orders_by_phone( $phone, 1 );
			if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
				$name = (string) $orders[0]->get_billing_first_name();
			}
		}
		// Unknown name stays empty in the call log; build_user_data() already greets 'there'.
		$args['customer_name'] = $name;
		$args['extra']         = array(
			'customer_context' => (string) VoicePress_Frontend::customer_context_for_phone( $phone, $name ),
			'channel'          => 'phone',
		);
		// Language picked in the widget ('' → the order's language or the default language).
		$args['language'] = self::requested_language( $request );

		$call = VoicePress_Calls::place_call( 'support_callback', $args );
		if ( is_wp_error( $call ) ) {
			self::log( 'Call-back request failed: ' . $call->get_error_message() );
			$message = current_user_can( 'manage_woocommerce' ) ? $call->get_error_message() : __( 'We could not place the call right now. Please try again in a moment.', 'voicepress' );
			return self::public_response( false, $message, 502 );
		}
		if ( is_array( $call ) && isset( $call['status'] ) && in_array( $call['status'], array( 'failed', 'error', 'skipped' ), true ) ) {
			self::log( 'Call-back request not placed: ' . ( isset( $call['error'] ) ? $call['error'] : $call['status'] ) );
			return self::public_response( false, __( 'We could not place the call right now. Please try again in a moment.', 'voicepress' ), 502 );
		}

		$message = is_array( $call ) && isset( $call['status'] ) && 'scheduled' === $call['status']
			? __( 'Thanks! We will call you back shortly.', 'voicepress' )
			: __( 'Calling you now. Please keep your phone nearby.', 'voicepress' );

		return self::public_response( true, $message, 200, array( 'call_id' => is_array( $call ) && isset( $call['id'] ) ? (int) $call['id'] : 0 ) );
	}

	/* ---------------------------------------------------------------------
	 * Admin routes.
	 * ------------------------------------------------------------------- */

	/**
	 * POST admin/test-call {phone, use_case, order_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function route_admin_test_call( WP_REST_Request $request ) {
		$phone = VoicePress_Helpers::normalize_phone( sanitize_text_field( (string) $request->get_param( 'phone' ) ) );
		if ( '' === $phone ) {
			return new WP_Error( 'voicepress_invalid_phone', __( 'Enter a valid phone number (add the country code if it is not your default country).', 'voicepress' ), array( 'status' => 400 ) );
		}
		$use_case = sanitize_key( (string) $request->get_param( 'use_case' ) );
		$labels   = (array) VoicePress_Agents::use_case_labels();
		if ( ! isset( $labels[ $use_case ] ) || in_array( $use_case, array( 'web_call', 'inbound' ), true ) ) {
			return new WP_Error( 'voicepress_invalid_use_case', __( 'Choose a valid call type.', 'voicepress' ), array( 'status' => 400 ) );
		}
		$order_id = absint( $request->get_param( 'order_id' ) );

		// Optional language ('' or 'auto' = the order's language or the default language).
		$raw_language = $request->get_param( 'language' );
		$language     = self::requested_language( $request );
		if ( '' === $language && null !== $raw_language && ! in_array( strtolower( trim( is_scalar( $raw_language ) ? (string) $raw_language : 'invalid' ) ), array( '', 'auto' ), true ) ) {
			return new WP_Error( 'voicepress_invalid_language', __( 'This language is not turned on. Enable it in VoicePress → Settings → Languages.', 'voicepress' ), array( 'status' => 400 ) );
		}

		return self::call_result( VoicePress_Calls::test_call( $use_case, $phone, $order_id, $language ) );
	}

	/**
	 * POST admin/call-order {order_id, use_case}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function route_admin_call_order( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$use_case = sanitize_key( (string) $request->get_param( 'use_case' ) );
		if ( ! in_array( $use_case, self::ORDER_USE_CASES, true ) ) {
			return new WP_Error( 'voicepress_invalid_use_case', __( 'Choose a valid call type.', 'voicepress' ), array( 'status' => 400 ) );
		}
		if ( ! $order_id || ! self::woo_ready() || ! ( wc_get_order( $order_id ) instanceof WC_Order ) ) {
			return new WP_Error( 'voicepress_order_not_found', __( 'Order not found.', 'voicepress' ), array( 'status' => 404 ) );
		}

		return self::call_result( VoicePress_Calls::place_call( $use_case, array( 'order_id' => $order_id ) ) );
	}

	/**
	 * POST admin/tick — run the scheduler now and return fresh stats.
	 *
	 * @return array
	 */
	public static function route_admin_tick() {
		return array(
			'ok'    => true,
			'tick'  => VoicePress_Calls::run_tick(),
			'stats' => VoicePress_DB::stats(),
		);
	}

	/**
	 * POST admin/sync-call {id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function route_admin_sync_call( WP_REST_Request $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = $id ? VoicePress_DB::get_call( $id ) : null;
		if ( ! $row ) {
			return new WP_Error( 'voicepress_call_not_found', __( 'Call not found.', 'voicepress' ), array( 'status' => 404 ) );
		}
		if ( empty( $row['execution_id'] ) ) {
			return new WP_Error( 'voicepress_not_placed', __( 'This call has not been placed with Bolna yet, so there is nothing to sync.', 'voicepress' ), array( 'status' => 400 ) );
		}
		// sync_call() silently returns the row unchanged without a client: do not report that as a successful sync.
		if ( ! VoicePress_Settings::client() ) {
			return new WP_Error( 'voicepress_not_configured', __( 'Add your Bolna API key first.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$fresh = VoicePress_Calls::sync_call( $row );
		if ( is_wp_error( $fresh ) ) {
			return self::rest_error( $fresh, 502 );
		}
		if ( ! is_array( $fresh ) || empty( $fresh ) ) {
			$fresh = VoicePress_DB::get_call( $id );
		}

		return array(
			'ok'      => true,
			'message' => __( 'Call synced from Bolna.', 'voicepress' ),
			'call'    => self::prepare_call( $fresh ),
		);
	}

	/**
	 * GET admin/call/{id} — call row + labels.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function route_admin_call( WP_REST_Request $request ) {
		$row = VoicePress_DB::get_call( absint( $request['id'] ) );
		if ( ! $row ) {
			return new WP_Error( 'voicepress_call_not_found', __( 'Call not found.', 'voicepress' ), array( 'status' => 404 ) );
		}
		$call = self::prepare_call( $row );
		// Row fields at the top level (spec) and under "call" for convenience.
		return array_merge( array( 'ok' => true ), $call, array( 'call' => $call ) );
	}

	/**
	 * GET admin/order-calls/{order_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function route_admin_order_calls( WP_REST_Request $request ) {
		$order_id = absint( $request['order_id'] );
		$rows     = (array) VoicePress_DB::list_calls(
			array(
				'order_id' => $order_id,
				'limit'    => 20,
			)
		);

		$cod_status = '';
		if ( $order_id && self::woo_ready() ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$cod_status = (string) $order->get_meta( '_voicepress_cod_status' );
			}
		}

		return array(
			'ok'         => true,
			'order_id'   => $order_id,
			'cod_status' => $cod_status,
			'calls'      => array_values( array_map( array( __CLASS__, 'prepare_call' ), $rows ) ),
		);
	}

	/**
	 * GET admin/recording/{id} — stream the call recording (audio bytes, not JSON).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error Only on failure; success streams audio and exits.
	 */
	public static function route_admin_recording( WP_REST_Request $request ) {
		$id  = absint( $request['id'] );
		$row = VoicePress_DB::get_call( $id );
		if ( ! $row || empty( $row['recording_url'] ) ) {
			return new WP_Error( 'voicepress_no_recording', __( 'No recording is available for this call yet.', 'voicepress' ), array( 'status' => 404 ) );
		}

		$audio = self::load_recording( $id, (string) $row['recording_url'] );
		if ( is_wp_error( $audio ) ) {
			return self::rest_error( $audio, 502 );
		}

		self::stream_audio( $audio['body'], $audio['content_type'], $id );
		exit;
	}

	/**
	 * POST admin/provision {agent_key?} — create/update one agent or all of them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function route_admin_provision( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'agent_key' ) );
		if ( '' === $key || 'all' === $key ) {
			return self::agents_bulk_response( VoicePress_Agents::provision_all(), 'provision' );
		}
		if ( ! in_array( $key, (array) VoicePress_Agents::AGENT_KEYS, true ) ) {
			return new WP_Error( 'voicepress_unknown_agent', __( 'Unknown agent.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$result = VoicePress_Agents::provision( $key );
		if ( is_wp_error( $result ) ) {
			return self::rest_error( $result );
		}

		$label = self::agent_label( $key );
		if ( is_array( $result ) && isset( $result['action'] ) && 'updated' === $result['action'] ) {
			/* translators: %s: agent label. */
			$message = sprintf( __( '%s agent updated.', 'voicepress' ), $label );
		} else {
			/* translators: %s: agent label. */
			$message = sprintf( __( '%s agent created.', 'voicepress' ), $label );
		}
		if ( is_array( $result ) && ! empty( $result['extractions'] ) && 'ok' !== $result['extractions'] ) {
			/* translators: %s: error message. */
			$message .= ' ' . sprintf( __( 'Call analysis setup needs attention: %s', 'voicepress' ), (string) $result['extractions'] );
		}

		return array(
			'ok'        => true,
			'agent_key' => $key,
			'result'    => $result,
			'agents'    => VoicePress_Settings::get( 'agents', array() ),
			'message'   => $message,
		);
	}

	/**
	 * POST admin/sync-agents — push current settings/prompts to every existing agent.
	 *
	 * @return array
	 */
	public static function route_admin_sync_agents() {
		return self::agents_bulk_response( VoicePress_Agents::sync_all(), 'sync' );
	}

	/**
	 * POST admin/link-inbound {phone_number_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function route_admin_link_inbound( WP_REST_Request $request ) {
		$phone_number_id = sanitize_text_field( (string) $request->get_param( 'phone_number_id' ) );
		if ( '' === $phone_number_id ) {
			return new WP_Error( 'voicepress_missing_number', __( 'Choose a phone number first.', 'voicepress' ), array( 'status' => 400 ) );
		}
		$result = VoicePress_Agents::link_inbound( $phone_number_id );
		if ( is_wp_error( $result ) ) {
			return self::rest_error( $result );
		}
		return array(
			'ok'      => true,
			'result'  => $result,
			'message' => __( 'Support agent linked. Calls to this number are now answered by your AI agent.', 'voicepress' ),
		);
	}

	/**
	 * GET admin/bolna-status — account, wallet, numbers and agents.
	 *
	 * @return array|WP_Error
	 */
	public static function route_admin_bolna_status() {
		$client = VoicePress_Settings::client();
		if ( ! $client ) {
			return new WP_Error( 'voicepress_not_configured', __( 'Add your Bolna API key first.', 'voicepress' ), array( 'status' => 400 ) );
		}
		$me = $client->me();
		if ( is_wp_error( $me ) ) {
			return self::rest_error( $me );
		}
		$me = is_array( $me ) ? $me : array();

		$warnings      = array();
		$phone_numbers = array();
		$numbers       = $client->list_phone_numbers();
		if ( is_wp_error( $numbers ) ) {
			$warnings[] = $numbers->get_error_message();
		} else {
			foreach ( self::list_of( $numbers ) as $n ) {
				$phone_numbers[] = array(
					'id'                 => isset( $n['id'] ) ? (string) $n['id'] : '',
					'phone_number'       => isset( $n['phone_number'] ) ? (string) $n['phone_number'] : '',
					'telephony_provider' => isset( $n['telephony_provider'] ) ? (string) $n['telephony_provider'] : '',
					'agent_id'           => isset( $n['agent_id'] ) ? (string) $n['agent_id'] : '',
				);
			}
		}

		$agents = array();
		$list   = $client->list_agents();
		if ( is_wp_error( $list ) ) {
			$warnings[] = $list->get_error_message();
		} else {
			foreach ( self::list_of( $list ) as $a ) {
				$agents[] = array(
					'id'         => isset( $a['id'] ) ? (string) $a['id'] : '',
					'agent_name' => isset( $a['agent_name'] ) ? (string) $a['agent_name'] : '',
				);
			}
		}

		return array(
			'ok'            => true,
			'wallet'        => isset( $me['wallet'] ) && is_numeric( $me['wallet'] ) ? round( (float) $me['wallet'] / 100, 2 ) : null,
			'concurrency'   => isset( $me['concurrency'] ) ? $me['concurrency'] : null,
			'account'       => array(
				'name'  => isset( $me['name'] ) ? (string) $me['name'] : '',
				'email' => isset( $me['email'] ) ? (string) $me['email'] : '',
			),
			'phone_numbers' => $phone_numbers,
			'agents'        => $agents,
			'warnings'      => $warnings,
		);
	}

	/**
	 * POST admin/simulate-cart {phone, name} — demo cart from 2 random products + immediate recovery call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function route_admin_simulate_cart( WP_REST_Request $request ) {
		$phone = VoicePress_Helpers::normalize_phone( sanitize_text_field( (string) $request->get_param( 'phone' ) ) );
		if ( '' === $phone ) {
			return new WP_Error( 'voicepress_invalid_phone', __( 'Enter a valid phone number (add the country code if it is not your default country).', 'voicepress' ), array( 'status' => 400 ) );
		}
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( ! self::woo_ready() || ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'voicepress_no_woocommerce', __( 'WooCommerce must be active to simulate a cart.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$ids = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 30,
				'return' => 'ids',
			)
		);
		$ids = array_map( 'absint', (array) $ids );
		shuffle( $ids );

		$items = array();
		$total = 0.0;
		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || '' === (string) $product->get_price() || (float) $product->get_price() <= 0 ) {
				continue;
			}
			$items[] = array(
				'name'       => $product->get_name(),
				'qty'        => 1,
				'price'      => (float) $product->get_price(),
				'product_id' => $product->get_id(),
			);
			$total  += (float) $product->get_price();
			if ( count( $items ) >= 2 ) {
				break;
			}
		}
		if ( empty( $items ) ) {
			return new WP_Error( 'voicepress_no_products', __( 'Publish at least one product with a price first.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$cart_id = (int) VoicePress_DB::insert_cart(
			array(
				'session_key'   => 'demo-' . wp_generate_password( 12, false, false ),
				'customer_name' => $name,
				'phone'         => $phone,
				'email'         => '',
				'cart'          => wp_json_encode( $items ),
				'cart_total'    => round( $total, 2 ),
				'currency'      => get_woocommerce_currency(),
				'status'        => 'captured',
			)
		);
		if ( ! $cart_id ) {
			return new WP_Error( 'voicepress_cart_failed', __( 'Could not save the demo cart.', 'voicepress' ), array( 'status' => 500 ) );
		}

		$call = VoicePress_Calls::place_call(
			'cart_recovery',
			array(
				'cart_id'       => $cart_id,
				'phone'         => $phone,
				'customer_name' => $name, // '' → the agent says "Hi there" (build_user_data), the log shows the phone.
			)
		);
		if ( is_wp_error( $call ) ) {
			// Keep the cron from calling this demo cart later.
			VoicePress_DB::update_cart( $cart_id, array( 'status' => 'ignored' ) );
			return self::rest_error( $call );
		}

		$result = self::call_result( $call );
		if ( is_wp_error( $result ) ) {
			VoicePress_DB::update_cart( $cart_id, array( 'status' => 'ignored' ) );
			return $result;
		}
		VoicePress_DB::update_cart(
			$cart_id,
			$result['ok']
				? array(
					'status'  => 'calling',
					'call_id' => (int) $result['call_id'],
				)
				: array(
					'status'  => 'ignored',
					'call_id' => (int) $result['call_id'],
				)
		);

		$result['cart_id'] = $cart_id;
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Admin helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * Add display labels to a call row for the admin UI.
	 *
	 * @param array|null $row Call row from VoicePress_DB.
	 * @return array
	 */
	public static function prepare_call( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		$status   = isset( $row['status'] ) ? (string) $row['status'] : '';
		$outcome  = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
		$use_case = isset( $row['use_case'] ) ? (string) $row['use_case'] : '';
		$labels   = (array) VoicePress_Agents::use_case_labels();

		$row['status_label']   = VoicePress_Calls::status_label( $status );
		$row['outcome_label']  = '' !== $outcome ? VoicePress_Calls::outcome_label( $outcome ) : '';
		$row['use_case_label'] = isset( $labels[ $use_case ] ) ? $labels[ $use_case ] : $use_case;
		$row['duration_label'] = ! empty( $row['duration'] ) ? VoicePress_Helpers::format_duration( (int) $row['duration'] ) : '';
		$row['time_ago']       = ! empty( $row['created_at'] ) ? VoicePress_Helpers::human_time( (string) $row['created_at'] ) : '';
		$row['has_recording']  = ! empty( $row['recording_url'] );
		$row['is_terminal']    = in_array( $status, (array) VoicePress_Calls::TERMINAL, true );

		// Language the call started in (user_data.call_language) for the language chip.
		$user_data = isset( $row['user_data'] ) ? $row['user_data'] : array();
		if ( is_string( $user_data ) ) {
			$user_data = json_decode( $user_data, true );
		}
		$language              = ( is_array( $user_data ) && isset( $user_data['call_language'] ) && class_exists( 'VoicePress_Languages' ) ) ? VoicePress_Languages::sanitize_code( $user_data['call_language'] ) : '';
		$row['call_language']  = $language;
		$row['language_label'] = '' !== $language ? VoicePress_Languages::label( $language ) : '';
		$row['language_name']  = '' !== $language ? VoicePress_Languages::name( $language ) : '';
		$row['language_short'] = '' !== $language ? VoicePress_Languages::native( $language ) : '';
		$row['language_html']  = '' !== $language ? VoicePress_Languages::html_lang( $language ) : '';
		return $row;
	}

	/**
	 * Shape the result of place_call()/test_call() for the admin UI.
	 *
	 * @param array|WP_Error $res Call row or error.
	 * @return array|WP_Error
	 */
	private static function call_result( $res ) {
		if ( is_wp_error( $res ) ) {
			return self::rest_error( $res );
		}
		if ( ! is_array( $res ) || empty( $res['id'] ) ) {
			return new WP_Error( 'voicepress_call_failed', __( 'The call could not be created.', 'voicepress' ), array( 'status' => 500 ) );
		}

		$call   = self::prepare_call( $res );
		$status = isset( $call['status'] ) ? (string) $call['status'] : '';
		$ok     = ! in_array( $status, array( 'failed', 'error', 'skipped', 'balance-low' ), true );

		if ( ! $ok ) {
			$message = ! empty( $call['error'] ) ? (string) $call['error'] : ( 'skipped' === $status ? __( 'The call was skipped.', 'voicepress' ) : __( 'The call could not be placed.', 'voicepress' ) );
		} elseif ( 'scheduled' === $status ) {
			$when = ! empty( $call['scheduled_at'] ) ? get_date_from_gmt( (string) $call['scheduled_at'], get_option( 'time_format', 'g:i a' ) ) : '';
			/* translators: %s: time. */
			$message = '' !== $when ? sprintf( __( 'Call scheduled for %s.', 'voicepress' ), $when ) : __( 'Call scheduled.', 'voicepress' );
		} else {
			$who = ! empty( $call['phone'] ) ? (string) $call['phone'] : ( ! empty( $call['customer_name'] ) ? (string) $call['customer_name'] : __( 'the customer', 'voicepress' ) );
			/* translators: %s: phone number or name. */
			$message = sprintf( __( 'Calling %s now…', 'voicepress' ), $who );
		}

		return array(
			'ok'      => $ok,
			'message' => $message,
			'call_id' => (int) $call['id'],
			'call'    => $call,
		);
	}

	/**
	 * Summarise provision_all()/sync_all() results.
	 *
	 * @param array  $results agent_key => result array | error message.
	 * @param string $mode    'provision' or 'sync'.
	 * @return array
	 */
	private static function agents_bulk_response( $results, $mode ) {
		$clean  = array();
		$errors = array();
		foreach ( (array) $results as $key => $result ) {
			if ( is_wp_error( $result ) ) {
				$result = $result->get_error_message();
			}
			$clean[ $key ] = $result;
			if ( ! is_array( $result ) ) {
				/* translators: 1: agent label, 2: error message. */
				$errors[ $key ] = sprintf( __( '%1$s: %2$s', 'voicepress' ), self::agent_label( $key ), (string) $result );
			}
		}

		$total = count( $clean );
		$done  = $total - count( $errors );
		if ( 0 === $total ) {
			$ok      = false;
			$message = 'sync' === $mode ? __( 'No agents to update yet. Create the agents first.', 'voicepress' ) : __( 'No agents were created.', 'voicepress' );
		} elseif ( empty( $errors ) ) {
			$ok = true;
			/* translators: %d: number of agents. */
			$message = 'sync' === $mode ? sprintf( _n( '%d agent updated with your latest settings.', '%d agents updated with your latest settings.', $total, 'voicepress' ), $total ) : sprintf( _n( '%d agent is ready.', 'All %d agents are ready.', $total, 'voicepress' ), $total );
		} else {
			$ok = false;
			/* translators: 1: agents done, 2: total agents, 3: error list. */
			$message = sprintf( __( '%1$d of %2$d agents are ready. %3$s', 'voicepress' ), $done, $total, implode( ' ', $errors ) );
		}

		return array(
			'ok'      => $ok,
			'message' => $message,
			'results' => $clean,
			'errors'  => $errors,
			'agents'  => VoicePress_Settings::get( 'agents', array() ),
		);
	}

	/**
	 * Human label for an agent key.
	 *
	 * @param string $key Agent key.
	 * @return string
	 */
	private static function agent_label( $key ) {
		$labels = (array) VoicePress_Agents::labels();
		if ( isset( $labels[ $key ]['label'] ) ) {
			return (string) $labels[ $key ]['label'];
		}
		return ucwords( str_replace( '_', ' ', (string) $key ) );
	}

	/**
	 * Convert an error from another module into a REST error with a sensible HTTP status.
	 *
	 * Bolna API errors carry Bolna's own status (e.g. 401 for a bad key); answering the browser
	 * with that status would look like an expired WordPress login, so they become 502.
	 *
	 * @param WP_Error $error          Error.
	 * @param int      $default_status Status when the error has none.
	 * @return WP_Error
	 */
	private static function rest_error( $error, $default_status = 400 ) {
		if ( ! is_wp_error( $error ) ) {
			return new WP_Error( 'voicepress_error', __( 'Something went wrong.', 'voicepress' ), array( 'status' => $default_status ) );
		}
		$code   = $error->get_error_code() ? $error->get_error_code() : 'voicepress_error';
		$data   = $error->get_error_data();
		$status = $default_status;
		$extra  = array();
		if ( 'voicepress_bolna' === $code ) {
			$status = 502;
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$extra['bolna_status'] = (int) $data['status'];
			}
		} elseif ( is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] >= 400 && (int) $data['status'] < 600 ) {
			$status = (int) $data['status'];
		}
		$message = $error->get_error_message();
		if ( '' === $message ) {
			$message = __( 'Something went wrong.', 'voicepress' );
		}
		// Keep the call row VoicePress_Calls attaches to skipped / failed dials: admin.js reads data.call
		// to link the row ("View call →") and to list it under Recent calls.
		if ( is_array( $data ) && ! empty( $data['call'] ) && is_array( $data['call'] ) ) {
			$extra['call'] = self::prepare_call( $data['call'] );
		}
		return new WP_Error( $code, $message, array_merge( $extra, array( 'status' => $status ) ) );
	}

	/**
	 * Accept a bare list or a { data: [...] } wrapper from Bolna.
	 *
	 * @param mixed $value API response.
	 * @return array[]
	 */
	private static function list_of( $value ) {
		if ( is_array( $value ) && isset( $value['data'] ) && is_array( $value['data'] ) ) {
			$value = $value['data'];
		}
		return array_values( array_filter( (array) $value, 'is_array' ) );
	}

	/* ---------------------------------------------------------------------
	 * Recording proxy.
	 * ------------------------------------------------------------------- */

	/**
	 * Get recording bytes, using a short-lived private temp-file cache so seeking (Range requests) stays fast.
	 *
	 * @param int    $call_id Call row id.
	 * @param string $url     Recording URL.
	 * @return array|WP_Error ['body' => string, 'content_type' => string]
	 */
	private static function load_recording( $call_id, $url ) {
		$file = '';
		$dir  = wp_normalize_path( (string) get_temp_dir() );
		// get_temp_dir() can fall back to wp-content; never cache recordings inside the web root.
		foreach ( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) as $web_root ) {
			if ( '' !== $web_root && 0 === strpos( trailingslashit( $dir ), trailingslashit( wp_normalize_path( $web_root ) ) ) ) {
				$dir = '';
			}
		}
		if ( '' !== $dir && wp_is_writable( $dir ) ) {
			$file = trailingslashit( $dir ) . 'voicepress-rec-' . md5( $call_id . '|' . $url . '|' . VoicePress_Settings::secret() ) . '.bin';
			if ( is_readable( $file ) && ( time() - (int) filemtime( $file ) ) < 6 * HOUR_IN_SECONDS ) {
				$body = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private temp cache.
				if ( is_string( $body ) && '' !== $body ) {
					return array(
						'body'         => $body,
						'content_type' => self::audio_type( '', $body ),
					);
				}
			}
		}

		$audio = self::fetch_recording( $url );
		if ( ! is_wp_error( $audio ) && '' !== $file && strlen( $audio['body'] ) <= 50 * MB_IN_BYTES ) {
			file_put_contents( $file, $audio['body'], LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private temp cache.
		}
		return $audio;
	}

	/**
	 * Download a recording through the Bolna client.
	 *
	 * @param string $url Recording URL.
	 * @return array|WP_Error ['body' => string, 'content_type' => string]
	 */
	private static function fetch_recording( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		// Refuse local/private targets (the URL comes from call data, so treat it as untrusted).
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'voicepress_recording_url', __( 'The recording link is not valid.', 'voicepress' ), array( 'status' => 404 ) );
		}
		$client = VoicePress_Settings::client();
		if ( ! $client ) {
			return new WP_Error( 'voicepress_not_configured', __( 'Add your Bolna API key to play recordings.', 'voicepress' ), array( 'status' => 400 ) );
		}

		// The client only sends the API key to Bolna hosts and follows storage redirects itself.
		$res = $client->fetch_recording( $url );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$body  = is_array( $res ) && isset( $res['body'] ) ? (string) $res['body'] : '';
		$parts = explode( ';', is_array( $res ) && isset( $res['content_type'] ) ? (string) $res['content_type'] : '' );
		$type  = strtolower( trim( $parts[0] ) );
		if ( '' === $body || 0 === strpos( $type, 'text/' ) || false !== strpos( $type, 'json' ) ) {
			return new WP_Error( 'voicepress_recording_pending', __( 'The recording is not ready yet.', 'voicepress' ), array( 'status' => 404 ) );
		}

		return array(
			'body'         => $body,
			'content_type' => self::audio_type( $type, $body ),
		);
	}

	/**
	 * Pick an audio MIME type from the header or the file signature.
	 *
	 * @param string $type Content-Type reported by the server (lowercase, no params).
	 * @param string $body File bytes.
	 * @return string
	 */
	private static function audio_type( $type, $body ) {
		if ( 0 === strpos( $type, 'audio/' ) ) {
			return $type;
		}
		$head = substr( $body, 0, 12 );
		if ( 0 === strpos( $head, 'RIFF' ) ) {
			return 'audio/wav';
		}
		if ( 0 === strpos( $head, 'OggS' ) ) {
			return 'audio/ogg';
		}
		if ( 0 === strpos( $head, 'fLaC' ) ) {
			return 'audio/flac';
		}
		if ( 'ftyp' === substr( $head, 4, 4 ) ) {
			return 'audio/mp4';
		}
		if ( "\x1A\x45\xDF\xA3" === substr( $head, 0, 4 ) ) {
			return 'audio/webm';
		}
		// ID3-tagged or raw MPEG frames, and the default for telephony recordings.
		return 'audio/mpeg';
	}

	/**
	 * Send audio bytes (with single-range support for seeking) and stop.
	 *
	 * @param string $body    Audio bytes.
	 * @param string $type    MIME type.
	 * @param int    $call_id Call id (file name).
	 */
	private static function stream_audio( $body, $type, $call_id ) {
		$size   = strlen( $body );
		$start  = 0;
		$end    = $size - 1;
		$status = 200;

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $size > 0 && preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range ), $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				if ( '' !== $m[2] ) {
					$end = min( (int) $m[2], $size - 1 );
				}
			}
			if ( $start > $end || $start >= $size ) {
				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$status = 206;
		}

		$extensions = array(
			'audio/wav'  => 'wav',
			'audio/ogg'  => 'ogg',
			'audio/flac' => 'flac',
			'audio/mp4'  => 'm4a',
			'audio/webm' => 'webm',
		);
		$ext = isset( $extensions[ $type ] ) ? $extensions[ $type ] : 'mp3';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( $status );
		header( 'Content-Type: ' . $type );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 206 === $status ) {
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}
		header( 'Content-Disposition: inline; filename="voicepress-call-' . absint( $call_id ) . '.' . $ext . '"' );
		header( 'Cache-Control: private, max-age=600' );
		header( 'X-Content-Type-Options: nosniff' );

		echo substr( $body, $start, $end - $start + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary audio stream.
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Tool helpers: orders, verification, wording.
	 * ------------------------------------------------------------------- */

	/**
	 * Is WooCommerce loaded?
	 *
	 * @return bool
	 */
	private static function woo_ready() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Response when WooCommerce is not available.
	 *
	 * @return array
	 */
	private static function woo_unavailable() {
		return array(
			'ok'      => false,
			'message' => __( 'Sorry, I cannot access orders right now. Offer to raise a ticket so our team can call the customer back.', 'voicepress' ),
		);
	}

	/**
	 * First non-empty param among several possible names.
	 *
	 * @param array    $p    Cleaned params.
	 * @param string[] $keys Candidate names.
	 * @return string
	 */
	private static function first( array $p, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $p[ $key ] ) ) {
				$value = self::clean_param( $p[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Single-line sanitized text, capped in length.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters.
	 * @return string
	 */
	private static function text( $value, $max = 300 ) {
		$value = sanitize_textarea_field( self::clean_param( $value ) );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		if ( mb_strlen( $value ) > $max ) {
			$value = rtrim( mb_substr( $value, 0, $max - 1 ) ) . '…';
		}
		return $value;
	}

	/**
	 * Plain-text excerpt.
	 *
	 * @param string $html Text or HTML.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private static function short_text( $html, $max ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $html ) ) ) );
		if ( mb_strlen( $text ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}

	/**
	 * Digits only; spoken digits ("four two one zero") are converted when no numerals are present.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function digits( $value ) {
		$value = strtolower( trim( (string) $value ) );
		// A number formatted as a float ("101.0") must not become "1010".
		if ( preg_match( '/^(\d+)\.0+$/', $value, $m ) ) {
			return $m[1];
		}
		if ( ! preg_match( '/\d/', $value ) ) {
			$map   = array(
				'zero'  => '0',
				'oh'    => '0',
				'one'   => '1',
				'two'   => '2',
				'three' => '3',
				'four'  => '4',
				'five'  => '5',
				'six'   => '6',
				'seven' => '7',
				'eight' => '8',
				'nine'  => '9',
			);
			$value = (string) preg_replace_callback(
				'/\b(zero|oh|one|two|three|four|five|six|seven|eight|nine)\b/',
				static function ( $m ) use ( $map ) {
					return $map[ $m[1] ];
				},
				$value
			);
		}
		return (string) preg_replace( '/\D+/', '', $value );
	}

	/**
	 * Loose boolean (true, 1, "1", "yes", "true", "on").
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Find an order from what the agent passed ("1234", "#1234", "ORD-1234", "one two three four").
	 *
	 * @param string $raw Raw order reference.
	 * @return WC_Order|null
	 */
	private static function find_order( $raw ) {
		if ( ! self::woo_ready() ) {
			return null;
		}
		$raw = ltrim( self::clean_param( $raw ), '#' );
		if ( '' === $raw ) {
			return null;
		}
		$digits     = self::digits( $raw );
		$candidates = array();
		foreach ( array_unique( array_filter( array( $raw, $digits ), 'strlen' ) ) as $ref ) {
			// Sequential order number plugins map order numbers to IDs through this core filter.
			$candidates[] = apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $ref );
		}
		$candidates[] = $digits;

		foreach ( array_unique( array_map( 'strval', $candidates ) ) as $candidate ) {
			if ( ! preg_match( '/^\d+$/', $candidate ) || (int) $candidate <= 0 ) {
				continue;
			}
			$order = wc_get_order( (int) $candidate );
			if ( $order instanceof WC_Order && ! in_array( $order->get_status(), array( 'trash', 'checkout-draft', 'auto-draft', 'draft' ), true ) ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Response when the order reference is missing or unknown.
	 *
	 * @param string $raw What the agent passed.
	 * @return array
	 */
	private static function order_missing( $raw ) {
		$raw = self::clean_param( $raw );
		if ( '' === $raw ) {
			return array(
				'ok'      => false,
				'found'   => false,
				'message' => __( 'Please ask the customer for their order number.', 'voicepress' ),
			);
		}
		$ref = self::digits( $raw );
		return array(
			'ok'      => false,
			'found'   => false,
			/* translators: %s: order number as given. */
			'message' => sprintf( __( 'I could not find order %s. Please ask the customer to check the order number and read it out digit by digit.', 'voicepress' ), '' !== $ref ? $ref : $raw ),
		);
	}

	/**
	 * Customer phone numbers from the call context (from/to), excluding the store's own number.
	 *
	 * @param array $p Cleaned params.
	 * @return string[]
	 */
	private static function caller_numbers( array $p ) {
		$store = (string) VoicePress_Settings::get( 'from_number', '' );
		$out   = array();
		foreach ( array( 'from_number', 'to_number' ) as $key ) {
			$number = isset( $p[ $key ] ) ? self::clean_param( $p[ $key ] ) : '';
			if ( strlen( self::digits( $number ) ) < 7 ) {
				continue;
			}
			if ( '' !== trim( $store ) && VoicePress_Helpers::phones_match( $number, $store ) ) {
				continue;
			}
			$out[] = $number;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Is the caller allowed to act on this order?
	 *
	 * Verified when a call number matches the billing phone, when VoicePress itself placed a call
	 * about this order to that number (covers admin test calls to another phone), or when
	 * phone_last4 matches the billing phone.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $p       Cleaned params.
	 * @param string   $failure Set to missing|mismatch|locked|no_phone when not verified.
	 * @return string Verification method (phone|call|last4) or '' when not verified.
	 */
	private static function verify_caller( WC_Order $order, array $p, &$failure = '' ) {
		$failure = '';
		$billing = self::order_phone( $order );
		$numbers = self::caller_numbers( $p );

		if ( '' !== trim( $billing ) ) {
			foreach ( $numbers as $number ) {
				if ( VoicePress_Helpers::phones_match( $billing, $number ) ) {
					return 'phone';
				}
			}
		}
		if ( ! empty( $numbers ) && self::called_about_order( $order->get_id(), $numbers ) ) {
			return 'call';
		}

		$billing_digits = self::digits( $billing );
		if ( strlen( $billing_digits ) < 4 ) {
			$failure = 'no_phone';
			return '';
		}
		$last4 = self::digits( isset( $p['phone_last4'] ) ? $p['phone_last4'] : '' );
		if ( '' === $last4 ) {
			$failure = 'missing';
			return '';
		}
		$lock_key = 'voicepress_l4_' . $order->get_id();
		$attempts = (int) get_transient( $lock_key );
		if ( $attempts >= self::MAX_LAST4_FAILURES ) {
			$failure = 'locked';
			return '';
		}
		if ( strlen( $last4 ) >= 4 && substr( $last4, -4 ) === substr( $billing_digits, -4 ) ) {
			return 'last4';
		}
		set_transient( $lock_key, $attempts + 1, HOUR_IN_SECONDS );
		$failure = 'mismatch';
		return '';
	}

	/**
	 * Did VoicePress place an outbound call about this order to one of these numbers?
	 *
	 * @param int      $order_id Order id.
	 * @param string[] $numbers  Caller numbers.
	 * @return bool
	 */
	private static function called_about_order( $order_id, array $numbers ) {
		$rows = (array) VoicePress_DB::list_calls(
			array(
				'order_id' => (int) $order_id,
				'limit'    => 25,
			)
		);
		foreach ( $rows as $row ) {
			if ( empty( $row['phone'] ) || ( isset( $row['direction'] ) && 'outbound' !== $row['direction'] ) ) {
				continue;
			}
			foreach ( $numbers as $number ) {
				if ( VoicePress_Helpers::phones_match( (string) $row['phone'], $number ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Was a call of one of these use cases placed for the order within the last N hours?
	 *
	 * @param int      $order_id  Order id.
	 * @param string[] $use_cases Use cases.
	 * @param int      $hours     Window.
	 * @return bool
	 */
	private static function recent_call_for_order( $order_id, array $use_cases, $hours ) {
		$rows   = (array) VoicePress_DB::list_calls(
			array(
				'order_id' => (int) $order_id,
				'limit'    => 10,
			)
		);
		$cutoff = time() - ( (int) $hours * HOUR_IN_SECONDS );
		foreach ( $rows as $row ) {
			if ( ! isset( $row['use_case'] ) || ! in_array( $row['use_case'], $use_cases, true ) || empty( $row['created_at'] ) ) {
				continue;
			}
			$created = strtotime( $row['created_at'] . ' UTC' );
			if ( $created && $created >= $cutoff ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * confirm_order has no phone_last4 param and its agent only runs on calls VoicePress placed,
	 * so a recent COD confirmation call for the order also counts as verification.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $p     Cleaned params.
	 * @return bool
	 */
	private static function can_confirm( WC_Order $order, array $p ) {
		$check = $p;
		unset( $check['phone_last4'] );
		$failure = '';
		if ( '' !== self::verify_caller( $order, $check, $failure ) ) {
			return true;
		}
		return self::recent_call_for_order( $order->get_id(), array( 'cod_confirmation', 'test' ), 3 );
	}

	/**
	 * Response for an unverified write action.
	 *
	 * @param string $failure Failure reason from verify_caller().
	 * @return array
	 */
	private static function unverified( $failure ) {
		switch ( $failure ) {
			case 'mismatch':
				return array(
					'ok'                 => false,
					'needs_verification' => true,
					'message'            => __( 'Those digits do not match the phone number on this order. Please ask the customer to check the last 4 digits of the phone number used on the order.', 'voicepress' ),
				);
			case 'locked':
				return array(
					'ok'                 => false,
					'needs_verification' => false,
					'message'            => __( 'I could not verify the customer after several attempts, so I cannot change this order on this call. Offer to raise a ticket so our team can call back.', 'voicepress' ),
				);
			case 'no_phone':
				return array(
					'ok'                 => false,
					'needs_verification' => false,
					'message'            => __( 'This order has no phone number on file, so I cannot verify it on this call. Offer to raise a ticket so our team can help.', 'voicepress' ),
				);
			default:
				return array(
					'ok'                 => false,
					'needs_verification' => true,
					'message'            => __( 'Please ask the customer for the last 4 digits of the phone number used on the order.', 'voicepress' ),
				);
		}
	}

	/**
	 * One speakable sentence describing where the order is.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $c     VoicePress_Helpers::order_context().
	 * @return string
	 */
	private static function status_sentence( WC_Order $order, array $c ) {
		$num    = $c['order_id'];
		$status = $order->get_status();
		if ( 'pending' === $status && 'yes' !== $c['is_cod'] ) {
			/* translators: %s: order number. */
			return sprintf( __( 'Order %s is placed and waiting for payment confirmation.', 'voicepress' ), $num );
		}
		switch ( $status ) {
			case 'pending':
			case 'on-hold':
			case 'processing':
				$pref = (string) $order->get_meta( '_voicepress_delivery_pref' );
				if ( '' !== $pref ) {
					/* translators: 1: order number, 2: preferred delivery date. */
					return sprintf( __( 'Order %1$s is being prepared for dispatch, and delivery is planned for %2$s as the customer asked.', 'voicepress' ), $num, str_replace( ' / ', ', ', $pref ) );
				}
				if ( '' === $c['order_date'] ) {
					/* translators: 1: order number, 2: delivery estimate. */
					return sprintf( __( 'Order %1$s is being prepared for dispatch and usually arrives within %2$s.', 'voicepress' ), $num, self::eta( $c ) );
				}
				/* translators: 1: order number, 2: order date, 3: delivery estimate. */
				return sprintf( __( 'Order %1$s was placed on %2$s, is being prepared for dispatch and usually arrives within %3$s.', 'voicepress' ), $num, $c['order_date'], self::eta( $c ) );
			case 'out-for-delivery':
				/* translators: %s: order number. */
				return sprintf( __( 'Order %s is out for delivery and should arrive today.', 'voicepress' ), $num );
			case 'delivery-failed':
				/* translators: %s: order number. */
				return sprintf( __( 'Our delivery partner could not deliver order %s; the delivery can be rescheduled.', 'voicepress' ), $num );
			case 'completed':
				if ( '' !== $c['delivered_on'] ) {
					/* translators: 1: order number, 2: delivery date. */
					return sprintf( __( 'Order %1$s was delivered on %2$s.', 'voicepress' ), $num, $c['delivered_on'] );
				}
				/* translators: %s: order number. */
				return sprintf( __( 'Order %s has been delivered.', 'voicepress' ), $num );
			case 'cancelled':
				/* translators: %s: order number. */
				return sprintf( __( 'Order %s has been cancelled.', 'voicepress' ), $num );
			case 'refunded':
				/* translators: %s: order number. */
				return sprintf( __( 'Order %s has been refunded.', 'voicepress' ), $num );
			case 'failed':
				/* translators: %s: order number. */
				return sprintf( __( 'The payment for order %s did not go through, so the order was not placed.', 'voicepress' ), $num );
			case 'return-requested':
				/* translators: %s: order number. */
				return sprintf( __( 'A return is in progress for order %s; our team will arrange the pickup.', 'voicepress' ), $num );
			default:
				/* translators: 1: order number, 2: order status. */
				return sprintf( __( 'Order %1$s is %2$s.', 'voicepress' ), $num, strtolower( $c['order_status'] ) );
		}
	}

	/**
	 * Expected delivery text for an order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $c     Order context.
	 * @return string
	 */
	private static function expected_delivery( WC_Order $order, array $c ) {
		switch ( $order->get_status() ) {
			case 'completed':
			case 'return-requested':
				/* translators: %s: delivery date. */
				return '' !== $c['delivered_on'] ? sprintf( __( 'Delivered on %s', 'voicepress' ), $c['delivered_on'] ) : __( 'Delivered', 'voicepress' );
			case 'cancelled':
			case 'refunded':
			case 'failed':
				return __( 'Not applicable', 'voicepress' );
			case 'out-for-delivery':
				return __( 'Today', 'voicepress' );
		}
		$pref = (string) $order->get_meta( '_voicepress_delivery_pref' );
		if ( '' !== $pref ) {
			/* translators: %s: preferred date and slot. */
			return sprintf( __( 'Rescheduled to %s', 'voicepress' ), str_replace( ' / ', ', ', $pref ) );
		}
		/* translators: %s: delivery estimate such as "3 to 5 working days". */
		return sprintf( __( 'Within %s of the order date', 'voicepress' ), self::eta( $c ) );
	}

	/**
	 * Delivery estimate from settings, with a safe default when it is blank.
	 *
	 * @param array $c Order context.
	 * @return string
	 */
	private static function eta( array $c ) {
		$eta = isset( $c['expected_delivery'] ) ? trim( (string) $c['expected_delivery'] ) : '';
		return '' !== $eta ? $eta : __( 'a few working days', 'voicepress' );
	}

	/**
	 * Phone on the order: billing phone, falling back to the shipping phone.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function order_phone( WC_Order $order ) {
		$phone = (string) $order->get_billing_phone();
		if ( '' === trim( $phone ) && is_callable( array( $order, 'get_shipping_phone' ) ) ) {
			$phone = (string) $order->get_shipping_phone();
		}
		return $phone;
	}

	/**
	 * Explain why an order cannot be cancelled.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $c     Order context.
	 * @return string
	 */
	private static function cannot_cancel_message( WC_Order $order, array $c ) {
		$num    = $c['order_id'];
		$window = max( 0, (int) VoicePress_Settings::get( 'return_window_days', 7 ) );
		switch ( $order->get_status() ) {
			case 'out-for-delivery':
			case 'delivery-failed':
				/* translators: 1: order number, 2: return window in days. */
				return sprintf( __( 'Order %1$s has already been shipped, so it cannot be cancelled now. Once it is delivered, the customer can request a return within %2$d days.', 'voicepress' ), $num, $window );
			case 'completed':
				/* translators: 1: order number, 2: return window in days. */
				return sprintf( __( 'Order %1$s has already been delivered, so it cannot be cancelled. I can help with a return instead if it is within the %2$d day return window.', 'voicepress' ), $num, $window );
			case 'refunded':
				/* translators: %s: order number. */
				return sprintf( __( 'Order %s has already been refunded, so there is nothing to cancel.', 'voicepress' ), $num );
			case 'failed':
				/* translators: %s: order number. */
				return sprintf( __( 'The payment for order %s did not go through, so there is nothing to cancel.', 'voicepress' ), $num );
			case 'return-requested':
				/* translators: %s: order number. */
				return sprintf( __( 'A return is already in progress for order %s, so it cannot be cancelled.', 'voicepress' ), $num );
			default:
				/* translators: 1: order number, 2: order status. */
				return sprintf( __( 'Order %1$s is %2$s and cannot be cancelled at this stage. Offer to raise a ticket for our team.', 'voicepress' ), $num, strtolower( $c['order_status'] ) );
		}
	}

	/**
	 * Latest note the customer can see on the order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function latest_customer_note( WC_Order $order ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return '';
		}
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'customer',
				'limit'    => 1,
			)
		);
		if ( empty( $notes ) ) {
			return '';
		}
		$note = reset( $notes );
		return isset( $note->content ) ? self::short_text( $note->content, 200 ) : '';
	}

	/**
	 * Turn a spoken day ("tomorrow", "Friday", "5th November") into "Friday, 6 November".
	 * Vague phrases ("next week", "after Diwali") are kept exactly as said.
	 *
	 * @param string $text What the customer said.
	 * @return string
	 */
	private static function resolve_date( $text ) {
		$text  = trim( (string) $text );
		$lower = strtolower( $text );
		if ( '' === $text || ! preg_match( '/\b(today|tomorrow|tmrw|monday|tuesday|wednesday|thursday|friday|saturday|sunday|jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)\b|\d{4}-\d{2}-\d{2}/', $lower ) ) {
			return $text;
		}

		$clean = str_replace( array( 'day after tomorrow', 'tmrw' ), array( '+2 days', 'tomorrow' ), $lower );
		$clean = (string) preg_replace( '/(\d+)(st|nd|rd|th)\b/', '$1', $clean );
		$clean = (string) preg_replace( '/\b(on|the|of|by|coming|upcoming|please|morning|afternoon|evening|night|in)\b/', ' ', $clean );
		$clean = (string) preg_replace( '/[^a-z0-9+\- ]/', ' ', $clean );
		$clean = trim( (string) preg_replace( '/\s+/', ' ', $clean ) );
		if ( '' === $clean ) {
			return $text;
		}

		try {
			$tz    = wp_timezone();
			$today = new DateTimeImmutable( 'today', $tz );
			$date  = new DateTimeImmutable( $clean, $tz );
			$date  = $date->setTime( 0, 0 );
			// "5 November" said in December means next year.
			if ( $date < $today && ! preg_match( '/\d{4}/', $clean ) ) {
				$date = $date->modify( '+1 year' );
			}
			$days = (int) $today->diff( $date )->format( '%r%a' );
		} catch ( Exception $e ) {
			return $text;
		}
		if ( $days < 0 || $days > 90 ) {
			return $text;
		}

		$label = $date->format( 'l, j F' );
		if ( 0 === $days ) {
			$label .= ' (' . __( 'today', 'voicepress' ) . ')';
		} elseif ( 1 === $days ) {
			$label .= ' (' . __( 'tomorrow', 'voicepress' ) . ')';
		}
		return $label;
	}

	/**
	 * Pick a slot word out of free text ("tomorrow morning" -> "morning").
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function slot_from_text( $text ) {
		if ( preg_match( '/\b(morning|afternoon|evening)\b/i', (string) $text, $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
	}

	/**
	 * Normalise a delivery slot.
	 *
	 * @param string $slot Slot as given.
	 * @return string
	 */
	private static function normalize_slot( $slot ) {
		$lower = strtolower( (string) $slot );
		foreach ( array( 'morning', 'afternoon', 'evening' ) as $known ) {
			if ( false !== strpos( $lower, $known ) ) {
				return $known;
			}
		}
		return (string) $slot;
	}

	/**
	 * Parse "4", "4 stars", "4/5", "four" into 1-5 (0 when invalid).
	 *
	 * @param string $raw Rating as sent.
	 * @return int
	 */
	private static function parse_rating( $raw ) {
		$raw = strtolower( self::clean_param( $raw ) );
		if ( '' === $raw ) {
			return 0;
		}
		$words = array(
			'one'   => 1,
			'two'   => 2,
			'three' => 3,
			'four'  => 4,
			'five'  => 5,
		);
		if ( preg_match( '/\d+(?:\.\d+)?/', $raw, $m ) ) {
			$rating = (int) round( (float) $m[0] );
		} elseif ( preg_match( '/\b(one|two|three|four|five)\b/', $raw, $m ) ) {
			$rating = $words[ $m[1] ];
		} else {
			return 0;
		}
		return ( $rating >= 1 && $rating <= 5 ) ? $rating : 0;
	}

	/**
	 * Search visible products, falling back to individual words of the query.
	 *
	 * @param string $query Search words.
	 * @param int    $limit Max products.
	 * @return WC_Product[]
	 */
	private static function find_products( $query, $limit ) {
		$terms = array( $query );
		$stop  = array( 'the', 'and', 'for', 'with', 'any', 'some', 'you', 'have', 'want', 'need', 'looking', 'buy', 'show', 'please', 'something', 'from', 'your' );
		$words = preg_split( '/[^a-z0-9]+/', strtolower( $query ) );
		$words = array_filter(
			(array) $words,
			static function ( $w ) use ( $stop ) {
				return strlen( $w ) >= 3 && ! in_array( $w, $stop, true );
			}
		);
		usort(
			$words,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $words as $word ) {
			$terms[] = $word;
			if ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) ) {
				$terms[] = substr( $word, 0, -1 );
			}
		}
		$terms = array_slice( array_values( array_unique( $terms ) ), 0, 5 );

		$found = array();
		foreach ( $terms as $term ) {
			$results = wc_get_products(
				array(
					's'      => $term,
					'limit'  => $limit,
					'status' => 'publish',
				)
			);
			foreach ( (array) $results as $product ) {
				if ( ! $product instanceof WC_Product || isset( $found[ $product->get_id() ] ) || ! $product->is_visible() ) {
					continue;
				}
				$found[ $product->get_id() ] = $product;
				if ( count( $found ) >= $limit ) {
					break 2;
				}
			}
		}
		return array_values( $found );
	}

	/* ---------------------------------------------------------------------
	 * Public-route helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * JSON { ok, message, ... } response for the storefront.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Extra fields.
	 * @return WP_REST_Response
	 */
	private static function public_response( $ok, $message, $status = 200, array $extra = array() ) {
		return new WP_REST_Response(
			array_merge(
				array(
					'ok'      => (bool) $ok,
					'message' => (string) $message,
				),
				$extra
			),
			$status
		);
	}

	/**
	 * Simple fixed-window rate limit stored in a transient. Store managers are never limited.
	 *
	 * @param string      $bucket  Bucket name.
	 * @param int         $max     Allowed hits per window.
	 * @param int         $window  Window in seconds.
	 * @param string|null $subject Who is limited (defaults to the client IP).
	 * @return bool True when the request may proceed.
	 */
	private static function rate_limit( $bucket, $max, $window, $subject = null ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		/**
		 * Filters a VoicePress rate limit (hits per window).
		 *
		 * @param int    $max    Allowed hits.
		 * @param string $bucket web | cart | callback | callback_phone.
		 */
		$max = (int) apply_filters( 'voicepress_rate_limit', $max, $bucket );
		if ( $max <= 0 ) {
			return true;
		}
		$subject = null === $subject ? self::client_ip() : (string) $subject;
		$key     = 'voicepress_rl_' . $bucket . '_' . md5( $subject );
		$now     = time();
		$data    = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['reset'] ) || (int) $data['reset'] <= $now ) {
			$data = array(
				'count' => 0,
				'reset' => $now + (int) $window,
			);
		}
		if ( (int) $data['count'] >= $max ) {
			return false;
		}
		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, max( 1, (int) $data['reset'] - $now ) );
		return true;
	}

	/**
	 * Visitor IP. Forwarded headers are only trusted when the direct peer is a local/private
	 * proxy (e.g. a cloudflared tunnel), so they cannot be spoofed from the internet.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
		$public = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( ! $public ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$list      = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$candidate = trim( $list[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
		return $ip;
	}

	/**
	 * Billing phone of the logged-in shopper (E.164) or ''.
	 *
	 * @return string
	 */
	private static function current_user_phone() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}
		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		return '' !== trim( $phone ) ? (string) VoicePress_Helpers::normalize_phone( $phone ) : '';
	}

	/**
	 * Debug log (only when WP_DEBUG is on). Never logs secrets.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( class_exists( 'VoicePress_Helpers' ) && method_exists( 'VoicePress_Helpers', 'log' ) ) {
			VoicePress_Helpers::log( $message );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[VoicePress] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
