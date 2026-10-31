<?php
/**
 * Call orchestration: places outbound calls through Bolna, keeps call rows in sync with
 * Bolna executions (webhook + polling) and turns call outcomes into WooCommerce order updates.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static call orchestration.
 */
class VoicePress_Calls {

	/**
	 * Statuses after which a call row does not change any more.
	 */
	const TERMINAL = array( 'completed', 'no-answer', 'busy', 'failed', 'canceled', 'stopped', 'error', 'balance-low', 'skipped' );

	/**
	 * Terminal statuses that mean the customer was not reached.
	 */
	const UNREACHABLE_STATUSES = array( 'no-answer', 'busy', 'failed', 'canceled', 'error', 'balance-low' );

	/**
	 * Statuses of a call that is on its way or live. 'dispatching' is VoicePress' own short-lived
	 * "being sent to Bolna" state.
	 */
	const ACTIVE_STATUSES = array( 'dispatching', 'prepared', 'queued', 'rescheduled', 'initiated', 'ringing', 'in-progress', 'call-disconnected' );

	/**
	 * Use cases that always belong to a WooCommerce order.
	 */
	const ORDER_USE_CASES = array( 'cod_confirmation', 'delivery_reminder', 'ndr_resolution', 'feedback' );

	/**
	 * Decision disposition per use case (looked up in every extraction category).
	 */
	const DECISIONS = array(
		'cod_confirmation'  => 'Order Decision',
		'delivery_reminder' => 'Delivery Decision',
		'ndr_resolution'    => 'Delivery Decision',
		'cart_recovery'     => 'Cart Outcome',
		'feedback'          => 'Sentiment',
		'support_callback'  => 'Call Reason',
		'web_call'          => 'Call Reason',
		'inbound'           => 'Call Reason',
	);

	/**
	 * Transient used as the tick lock.
	 */
	const TICK_LOCK = 'voicepress_tick_lock';

	/**
	 * Transient that limits the support-agent execution import to once every 5 minutes.
	 */
	const IMPORT_THROTTLE = 'voicepress_import_throttle';

	/**
	 * Call ids to dial when the current request ends.
	 *
	 * @var int[]
	 */
	private static $deferred = array();

	/**
	 * Calls are driven by Automations, REST and cron, so there is nothing to hook here.
	 */
	public static function init() {
	}

	/* ---------------------------------------------------------------------------------------------
	 * Placing calls
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Place (or schedule) an outbound call.
	 *
	 * @param string $use_case Use case key (cod_confirmation, delivery_reminder, ndr_resolution, cart_recovery, feedback, support_callback).
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type int    $order_id      Order the call is about.
	 *     @type int    $cart_id       Abandoned cart row the call is about.
	 *     @type string $phone         Number to call. Overrides the order / cart phone.
	 *     @type string $customer_name Name for the call log (and for the agent when there is no order).
	 *     @type array  $extra         Extra user_data, merged over the generated variables.
	 *     @type int    $delay         Minutes to wait before calling. 0 = now.
	 *     @type int    $attempt       Attempt number (retries).
	 *     @type string $agent_key     Use this agent instead of the use case's agent.
	 *     @type bool   $force         Ignore skip conditions and calling hours (test calls).
	 *     @type bool   $defer         With delay 0: save the call and dial it when the current request
	 *                                 ends, so checkout and order saves stay fast. Cron is the safety net.
	 * }
	 * @return array|WP_Error Call row.
	 */
	public static function place_call( $use_case, array $args = array() ) {
		$use_case = sanitize_key( (string) $use_case );
		$args     = wp_parse_args(
			$args,
			array(
				'order_id'      => 0,
				'cart_id'       => 0,
				'phone'         => '',
				'customer_name' => '',
				'extra'         => array(),
				'delay'         => 0,
				'attempt'       => 1,
				'agent_key'     => '',
				'force'         => false,
				'defer'         => false,
			)
		);

		if ( '' === $use_case || in_array( $use_case, array( 'inbound', 'web_call' ), true ) ) {
			return self::error( 'voicepress_bad_use_case', __( 'This kind of call cannot be dialled from here.', 'voicepress' ), 400 );
		}
		if ( ! VoicePress_Settings::is_configured() ) {
			return self::error( 'voicepress_not_configured', __( 'Add your Bolna API key first (VoicePress → Settings).', 'voicepress' ), 400 );
		}

		$agent_key = sanitize_key( (string) $args['agent_key'] );
		$agent_id  = '' !== $agent_key ? VoicePress_Settings::agent_id( $agent_key ) : VoicePress_Agents::agent_for_use_case( $use_case );
		if ( '' === (string) $agent_id ) {
			return self::error( 'voicepress_no_agent', __( 'Create the VoicePress agents first (VoicePress → Agents)', 'voicepress' ), 400 );
		}

		$order    = null;
		$order_id = absint( $args['order_id'] );
		if ( $order_id ) {
			$order = self::get_order( $order_id );
			if ( ! $order ) {
				return self::error( 'voicepress_no_order', __( 'Order not found.', 'voicepress' ), 404 );
			}
		} elseif ( in_array( $use_case, self::ORDER_USE_CASES, true ) ) {
			return self::error( 'voicepress_no_order', __( 'This call needs an order.', 'voicepress' ), 400 );
		}

		$cart    = null;
		$cart_id = absint( $args['cart_id'] );
		if ( $cart_id ) {
			$cart = VoicePress_DB::get_cart( $cart_id );
			if ( ! $cart ) {
				return self::error( 'voicepress_no_cart', __( 'Cart not found.', 'voicepress' ), 404 );
			}
		}

		$phone = self::resolve_phone( $args['phone'], $order, $cart );
		if ( '' === $phone ) {
			return self::error( 'voicepress_no_phone', __( 'No valid phone number for this call.', 'voicepress' ), 400 );
		}

		$name = sanitize_text_field( is_scalar( $args['customer_name'] ) ? (string) $args['customer_name'] : '' );
		if ( '' === $name && $order ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		if ( '' === $name && $cart ) {
			$name = sanitize_text_field( (string) $cart['customer_name'] );
		}

		$delay = max( 0, (int) $args['delay'] );
		$data  = array(
			'use_case'      => $use_case,
			'direction'     => 'outbound',
			'agent_id'      => (string) $agent_id,
			'order_id'      => $order_id ? $order_id : null,
			'cart_id'       => $cart_id ? $cart_id : null,
			'customer_name' => $name,
			'phone'         => $phone,
			'attempt'       => max( 1, (int) $args['attempt'] ),
			// Until the call is dialled, user_data holds only the caller's extras; dispatch() builds the rest.
			'user_data'     => self::stringify( is_array( $args['extra'] ) ? $args['extra'] : array() ),
		);

		if ( $delay > 0 || ! empty( $args['defer'] ) ) {
			$data['status']       = 'scheduled';
			$data['scheduled_at'] = gmdate( 'Y-m-d H:i:s', time() + $delay * MINUTE_IN_SECONDS );
			$id                   = (int) VoicePress_DB::insert_call( $data );
			if ( ! $id ) {
				return self::error( 'voicepress_db', __( 'Could not save the call.', 'voicepress' ), 500 );
			}
			if ( 0 === $delay ) {
				self::dispatch_at_shutdown( $id );
			}
			$row = VoicePress_DB::get_call( $id );
			return $row ? $row : array_merge( $data, array( 'id' => $id ) );
		}

		$data['status'] = 'queued';
		$id             = (int) VoicePress_DB::insert_call( $data );
		if ( ! $id ) {
			return self::error( 'voicepress_db', __( 'Could not save the call.', 'voicepress' ), 500 );
		}
		return self::dispatch( $id, ! empty( $args['force'] ) );
	}

	/**
	 * Dial a saved call through Bolna.
	 *
	 * Returns the updated row on success (or when calling hours moved it to the next window),
	 * a WP_Error with data ['status' => int, 'call' => row] when the call was skipped or failed.
	 *
	 * @param int  $id    Call row id.
	 * @param bool $force Ignore skip conditions and calling hours (test calls).
	 * @return array|WP_Error
	 */
	public static function dispatch( $id, $force = false ) {
		$id  = absint( $id );
		$row = $id ? VoicePress_DB::get_call( $id ) : null;
		if ( ! $row ) {
			return self::error( 'voicepress_call_not_found', __( 'Call not found.', 'voicepress' ), 404 );
		}
		// Already dialled (or finished): nothing to do.
		if ( ! empty( $row['execution_id'] ) || ! in_array( (string) $row['status'], array( 'scheduled', 'queued' ), true ) ) {
			return $row;
		}
		// Another request is dialling this row right now.
		if ( ! self::claim( $row ) ) {
			$fresh = VoicePress_DB::get_call( $id );
			return $fresh ? $fresh : $row;
		}

		$use_case = (string) $row['use_case'];
		$order    = null;
		$cart     = null;

		try {
			$order = ! empty( $row['order_id'] ) ? self::get_order( $row['order_id'] ) : null;
			$cart  = ! empty( $row['cart_id'] ) ? VoicePress_DB::get_cart( (int) $row['cart_id'] ) : null;

			if ( ! $force ) {
				$reason = self::skip_reason( $row, $order, $cart );
				if ( '' !== $reason ) {
					VoicePress_DB::update_call(
						$id,
						array(
							'status' => 'skipped',
							'error'  => $reason,
						)
					);
					return self::error( 'voicepress_call_skipped', $reason, 409, array( 'call' => VoicePress_DB::get_call( $id ) ) );
				}

				$next_window = self::next_calling_window();
				if ( '' !== $next_window ) {
					VoicePress_DB::update_call(
						$id,
						array(
							'status'       => 'scheduled',
							'scheduled_at' => $next_window,
							'error'        => __( 'Outside calling hours. The call goes out when calling hours start.', 'voicepress' ),
						)
					);
					$fresh = VoicePress_DB::get_call( $id );
					return $fresh ? $fresh : $row;
				}
			}

			$client   = VoicePress_Settings::client();
			$agent_id = (string) $row['agent_id'];
			if ( '' === $agent_id ) {
				$agent_id = (string) VoicePress_Agents::agent_for_use_case( $use_case );
			}
			$phone = (string) $row['phone'];
			if ( ! $client ) {
				return self::fail_dispatch( $row, $order, $cart, __( 'Add your Bolna API key first (VoicePress → Settings).', 'voicepress' ) );
			}
			if ( '' === $agent_id ) {
				return self::fail_dispatch( $row, $order, $cart, __( 'Create the VoicePress agents first (VoicePress → Agents)', 'voicepress' ) );
			}
			if ( '' === $phone ) {
				return self::fail_dispatch( $row, $order, $cart, __( 'No valid phone number for this call.', 'voicepress' ) );
			}

			$user_data = self::build_user_data(
				$use_case,
				array(
					'order_id'      => (int) $row['order_id'],
					'cart_id'       => (int) $row['cart_id'],
					'phone'         => $phone,
					'customer_name' => (string) $row['customer_name'],
					'extra'         => is_array( $row['user_data'] ) ? $row['user_data'] : array(),
				)
			);

			$response     = $client->make_call( $agent_id, $phone, $user_data, (string) VoicePress_Settings::get( 'from_number', '' ) );
			$execution_id = ( is_array( $response ) && ! empty( $response['execution_id'] ) ) ? sanitize_text_field( (string) $response['execution_id'] ) : '';
			if ( is_wp_error( $response ) || '' === $execution_id ) {
				$message = is_wp_error( $response ) ? $response->get_error_message() : __( 'Bolna did not return a call id.', 'voicepress' );
				return self::fail_dispatch( $row, $order, $cart, $message, $user_data, $agent_id );
			}

			// A webhook may already have attached itself to this row while we waited: never move its status back.
			$current = VoicePress_DB::get_call( $id );
			$update  = array(
				'execution_id' => $execution_id,
				'agent_id'     => $agent_id,
				'user_data'    => $user_data,
				'error'        => null,
			);
			if ( ! $current || in_array( (string) $current['status'], array( 'dispatching', 'scheduled', 'queued' ), true ) ) {
				$status           = isset( $response['status'] ) ? sanitize_key( (string) $response['status'] ) : '';
				$update['status'] = '' !== $status ? $status : 'queued';
			}
			VoicePress_DB::update_call( $id, $update );
			$finished = $current && in_array( (string) $current['status'], self::TERMINAL, true );

			if ( $order ) {
				$order->update_meta_data( '_voicepress_last_call_id', $id );
				if ( 'cod_confirmation' === $use_case && ! $finished && ! in_array( (string) $order->get_meta( '_voicepress_cod_status' ), array( 'confirmed', 'cancelled' ), true ) ) {
					$order->update_meta_data( '_voicepress_cod_status', 'calling' );
				}
				$order->save();
				$label = self::use_case_label( $use_case );
				$order->add_order_note(
					$force
						/* translators: %s: use case label, e.g. "COD confirmation". */
						? sprintf( __( 'VoicePress: %s test call started.', 'voicepress' ), $label )
						/* translators: 1: use case label, e.g. "COD confirmation", 2: attempt number. */
						: sprintf( __( 'VoicePress: %1$s call started (attempt %2$d).', 'voicepress' ), $label, max( 1, (int) $row['attempt'] ) )
				);
			}
			if ( $cart && 'cart_recovery' === $use_case && 'recovered' !== (string) $cart['status'] ) {
				VoicePress_DB::update_cart(
					(int) $cart['id'],
					array(
						'status'  => 'calling',
						'call_id' => $id,
					)
				);
			}

			$fresh = VoicePress_DB::get_call( $id );
			return $fresh ? $fresh : $row;
		} catch ( \Throwable $e ) {
			VoicePress_Helpers::log(
				'Dispatch failed',
				array(
					'call'  => $id,
					'error' => $e->getMessage(),
				)
			);
			return self::fail_dispatch( $row, null, null, $e->getMessage() );
		}
	}

	/**
	 * Why a saved call should not go out any more ('' = call it).
	 *
	 * @param array         $row   Call row (needs use_case, order_id, cart_id).
	 * @param WC_Order|null $order Order (loaded when null).
	 * @param array|null    $cart  Cart row (loaded when null).
	 * @return string
	 */
	public static function skip_reason( array $row, $order = null, $cart = null ) {
		$use_case = isset( $row['use_case'] ) ? (string) $row['use_case'] : '';

		if ( ! empty( $row['order_id'] ) && ! $order ) {
			$order = self::get_order( $row['order_id'] );
			if ( ! $order ) {
				return __( 'The order no longer exists.', 'voicepress' );
			}
		}
		if ( ! empty( $row['cart_id'] ) && ! $cart ) {
			$cart = VoicePress_DB::get_cart( (int) $row['cart_id'] );
			if ( ! $cart ) {
				return __( 'The cart no longer exists.', 'voicepress' );
			}
		}

		if ( 'cod_confirmation' === $use_case && $order ) {
			$cod_status = (string) $order->get_meta( '_voicepress_cod_status' );
			if ( 'confirmed' === $cod_status ) {
				return __( 'The customer already confirmed this order.', 'voicepress' );
			}
			if ( 'cancelled' === $cod_status ) {
				return __( 'The customer already cancelled this order.', 'voicepress' );
			}
			if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing' ), true ) ) {
				/* translators: %s: order status name. */
				return sprintf( __( 'Order status is %s, so no confirmation call is needed.', 'voicepress' ), wc_get_order_status_name( $order->get_status() ) );
			}
		}

		if ( 'cart_recovery' === $use_case && $cart && 'recovered' === (string) $cart['status'] ) {
			return __( 'The customer already completed the purchase.', 'voicepress' );
		}

		return '';
	}

	/**
	 * Whether an order already has a call for this use case that is scheduled or under way.
	 * Rows stuck in a non-final state for more than 6 hours do not count.
	 *
	 * @param int    $order_id Order id.
	 * @param string $use_case Use case key.
	 * @return bool
	 */
	public static function has_open_call( $order_id, $use_case ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return false;
		}
		$rows  = VoicePress_DB::list_calls(
			array(
				'order_id' => $order_id,
				'use_case' => (string) $use_case,
				'limit'    => 20,
			)
		);
		$stale = time() - 6 * HOUR_IN_SECONDS;
		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];
			if ( in_array( $status, self::TERMINAL, true ) ) {
				continue;
			}
			if ( 'scheduled' === $status || self::gmt_timestamp( $row['updated_at'] ) >= $stale ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Dial the deferred calls of this request (hooked on shutdown). At most 3 here; cron dials the rest.
	 */
	public static function dispatch_deferred() {
		$ids            = array_slice( array_unique( array_map( 'intval', self::$deferred ) ), 0, 3 );
		self::$deferred = array();
		if ( ! $ids ) {
			return;
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		foreach ( $ids as $id ) {
			try {
				$row = VoicePress_DB::get_call( $id );
				if ( $row && 'scheduled' === $row['status'] && empty( $row['execution_id'] ) ) {
					self::dispatch( $id );
				}
			} catch ( \Throwable $e ) {
				VoicePress_Helpers::log(
					'Deferred dispatch failed',
					array(
						'call'  => $id,
						'error' => $e->getMessage(),
					)
				);
			}
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Per-call variables
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * The user_data variables for a call. ALL values are strings.
	 *
	 * @param string $use_case Use case key.
	 * @param array  $args     order_id, cart_id, phone, customer_name, extra (merged last).
	 * @return array
	 */
	public static function build_user_data( $use_case, array $args = array() ) {
		$use_case = sanitize_key( (string) $use_case );
		$args     = wp_parse_args(
			$args,
			array(
				'order_id'      => 0,
				'cart_id'       => 0,
				'phone'         => '',
				'customer_name' => '',
				'extra'         => array(),
			)
		);
		$extra    = self::stringify( is_array( $args['extra'] ) ? $args['extra'] : array() );
		$store    = VoicePress_Helpers::store_context();
		$order    = ! empty( $args['order_id'] ) ? self::get_order( $args['order_id'] ) : null;
		$ctx      = $order ? VoicePress_Helpers::order_context( $order ) : array();
		$cart     = ! empty( $args['cart_id'] ) ? VoicePress_DB::get_cart( (int) $args['cart_id'] ) : null;

		// First name from the order; otherwise the name we were given; otherwise the cart's name.
		$name = ( isset( $ctx['customer_name'] ) && 'there' !== $ctx['customer_name'] ) ? (string) $ctx['customer_name'] : '';
		if ( '' === $name && is_scalar( $args['customer_name'] ) ) {
			$name = sanitize_text_field( (string) $args['customer_name'] );
		}
		if ( '' === $name && $cart ) {
			$name = sanitize_text_field( (string) $cart['customer_name'] );
		}
		if ( '' === $name ) {
			$name = 'there';
		}

		$data = array(
			'customer_name'    => $name,
			'store_name'       => self::ctx( $store, 'store_name' ),
			'agent_name'       => self::ctx( $store, 'agent_name' ),
			'call_reason_text' => self::call_reason_text( $use_case, $ctx ),
		);

		switch ( $use_case ) {
			case 'cod_confirmation':
				$data['order_id']          = self::ctx( $ctx, 'order_id' );
				$data['items']             = self::ctx( $ctx, 'items' );
				$data['order_total']       = self::ctx( $ctx, 'order_total' );
				$data['payment_method']    = self::ctx( $ctx, 'payment_method' );
				$data['delivery_address']  = self::ctx( $ctx, 'delivery_address' );
				$data['expected_delivery'] = self::ctx( $ctx, 'expected_delivery', self::ctx( $store, 'delivery_eta' ) );
				break;

			case 'delivery_reminder':
			case 'ndr_resolution':
				$data['order_id']         = self::ctx( $ctx, 'order_id' );
				$data['items']            = self::ctx( $ctx, 'items' );
				$data['order_total']      = self::ctx( $ctx, 'order_total' );
				$data['payment_method']   = self::ctx( $ctx, 'payment_method' );
				$data['is_cod']           = self::ctx( $ctx, 'is_cod', 'no' );
				$data['delivery_address'] = self::ctx( $ctx, 'delivery_address' );
				$data['call_reason']      = 'ndr_resolution' === $use_case ? 'delivery_failed' : 'out_for_delivery';
				break;

			case 'cart_recovery':
				$data = array_merge( $data, self::cart_variables( $cart, $extra ) );
				// The discount was handled above (reused or freshly created): never let stale extras override it.
				unset( $extra['discount_code'], $extra['discount_code_spoken'], $extra['discount_percent'] );
				break;

			case 'feedback':
				$data['order_id']     = self::ctx( $ctx, 'order_id' );
				$data['items']        = self::ctx( $ctx, 'items' );
				$data['delivered_on'] = self::ctx( $ctx, 'delivered_on' );
				break;

			case 'support_callback':
			case 'web_call':
				$data['customer_context'] = array_key_exists( 'customer_context', $extra )
					? $extra['customer_context']
					: self::context_for_phone( (string) $args['phone'], 'there' !== $name ? $name : '' );
				$data['channel']          = 'web_call' === $use_case ? 'web' : 'phone';
				break;
		}

		foreach ( $extra as $key => $value ) {
			$data[ $key ] = $value;
		}

		/**
		 * Filter the variables sent to the Bolna agent for a call.
		 *
		 * @param array  $data     user_data (string values).
		 * @param string $use_case Use case key.
		 * @param array  $args     Arguments passed to build_user_data().
		 */
		$data = apply_filters( 'voicepress_user_data', $data, $use_case, $args );

		return self::stringify( is_array( $data ) ? $data : array() );
	}

	/**
	 * Cart recovery variables: items, total and a one-time discount code.
	 *
	 * @param array|null $cart  Cart row.
	 * @param array      $extra Extras (may carry the discount code of a previous attempt).
	 * @return array
	 */
	private static function cart_variables( $cart, array $extra ) {
		$items = array();
		if ( $cart && isset( $cart['cart'] ) ) {
			$items = is_array( $cart['cart'] ) ? $cart['cart'] : VoicePress_Helpers::json_decode_array( (string) $cart['cart'] );
		}
		$currency = ( $cart && ! empty( $cart['currency'] ) ) ? (string) $cart['currency'] : null;
		$total    = ( $cart && is_numeric( $cart['cart_total'] ) ) ? VoicePress_Helpers::speak_amount( (float) $cart['cart_total'], $currency ) : '';

		$code    = isset( $extra['discount_code'] ) ? strtoupper( trim( (string) $extra['discount_code'] ) ) : '';
		$percent = '' !== $code ? self::usable_coupon_percent( $code ) : 0;
		if ( $percent <= 0 ) {
			$code    = '';
			$percent = max( 0, min( 100, (int) VoicePress_Settings::get( 'cart_discount_percent', 10 ) ) );
			if ( $percent > 0 ) {
				$code = self::create_cart_coupon( $percent, $cart );
			}
		}

		return array(
			'cart_items'           => self::cart_items_summary( $items ),
			'cart_total'           => $total,
			'discount_code'        => $code,
			'discount_code_spoken' => '' !== $code ? VoicePress_Helpers::spell_out( $code ) : '',
			'discount_percent'     => '' !== $code ? (string) $percent : '',
		);
	}

	/**
	 * "Cotton Kurta Set (quantity 1), Silver Jhumkas (quantity 2) and 2 more items" for cart rows.
	 *
	 * @param array $items Cart items [{name, qty, price}].
	 * @param int   $max   Items to name.
	 * @return string
	 */
	private static function cart_items_summary( array $items, $max = 4 ) {
		$parts = array();
		$count = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) || ! is_scalar( $item['name'] ) ) {
				continue;
			}
			++$count;
			if ( count( $parts ) < $max ) {
				$qty = isset( $item['qty'] ) ? (int) $item['qty'] : ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
				/* translators: 1: product name, 2: quantity. */
				$parts[] = sprintf( __( '%1$s (quantity %2$s)', 'voicepress' ), wp_strip_all_tags( html_entity_decode( (string) $item['name'], ENT_QUOTES, 'UTF-8' ) ), max( 1, $qty ) );
			}
		}
		$summary = implode( ', ', $parts );
		$more    = $count - count( $parts );
		if ( $more > 0 ) {
			/* translators: %d: number of further items. */
			$summary .= ' ' . sprintf( _n( 'and %d more item', 'and %d more items', $more, 'voicepress' ), $more );
		}
		return $summary;
	}

	/**
	 * Create a one-time percent coupon "COMEBACK" + 4 characters, valid for 3 days.
	 *
	 * @param int        $percent Discount percent.
	 * @param array|null $cart    Cart row.
	 * @return string Coupon code, '' on failure.
	 */
	private static function create_cart_coupon( $percent, $cart ) {
		if ( ! class_exists( 'WC_Coupon' ) || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return '';
		}
		try {
			$code = '';
			for ( $i = 0; $i < 5 && '' === $code; $i++ ) {
				$candidate = 'COMEBACK' . self::random_code( 4 );
				if ( ! wc_get_coupon_id_by_code( $candidate ) ) {
					$code = $candidate;
				}
			}
			if ( '' === $code ) {
				return '';
			}
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( (int) $percent );
			$coupon->set_usage_limit( 1 );
			$coupon->set_individual_use( true );
			$coupon->set_date_expires( time() + 3 * DAY_IN_SECONDS );
			$coupon->set_description( $cart ? sprintf( 'VoicePress cart recovery (cart #%d)', (int) $cart['id'] ) : 'VoicePress cart recovery' );
			if ( $cart ) {
				$coupon->update_meta_data( '_voicepress_cart_id', (int) $cart['id'] );
			}
			$coupon->save();
			return $coupon->get_id() ? $code : '';
		} catch ( \Throwable $e ) {
			VoicePress_Helpers::log( 'Coupon creation failed', array( 'error' => $e->getMessage() ) );
			return '';
		}
	}

	/**
	 * Percent of an existing coupon that can still be used for a while (0 = not usable).
	 *
	 * @param string $code Coupon code.
	 * @return int
	 */
	private static function usable_coupon_percent( $code ) {
		if ( ! class_exists( 'WC_Coupon' ) || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return 0;
		}
		try {
			$id = wc_get_coupon_id_by_code( $code );
			if ( ! $id ) {
				return 0;
			}
			$coupon  = new WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			if ( $expires && $expires->getTimestamp() < time() + HOUR_IN_SECONDS ) {
				return 0;
			}
			if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
				return 0;
			}
			return 'percent' === $coupon->get_discount_type() ? (int) round( (float) $coupon->get_amount() ) : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Random characters that are easy to say and hear (no 0/O, 1/I).
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private static function random_code( $length ) {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code     = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$code .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $code;
	}

	/**
	 * Plain-English reason for the call (agent-facing, so not translated).
	 *
	 * @param string $use_case Use case key.
	 * @param array  $ctx      Order context.
	 * @return string
	 */
	private static function call_reason_text( $use_case, array $ctx ) {
		switch ( $use_case ) {
			case 'cod_confirmation':
				return ( isset( $ctx['is_cod'] ) && 'no' === $ctx['is_cod'] )
					? 'to confirm your order before we ship it'
					: 'to confirm your cash on delivery order before we ship it';
			case 'delivery_reminder':
				return 'your order is out for delivery today';
			case 'ndr_resolution':
				return 'our delivery partner could not deliver your order';
			case 'cart_recovery':
				return 'you left items in your cart';
			case 'feedback':
				return 'to hear how you liked your order';
			case 'support_callback':
				return 'you requested a call back';
			case 'web_call':
				return 'you started a voice chat on our website';
		}
		return 'to help you with your order';
	}

	/**
	 * Customer context for support calls (recent orders for a phone number).
	 *
	 * @param string $phone Phone number.
	 * @param string $name  Customer name.
	 * @return string
	 */
	private static function context_for_phone( $phone, $name = '' ) {
		if ( '' === (string) $phone || ! class_exists( 'VoicePress_Frontend' ) || ! method_exists( 'VoicePress_Frontend', 'customer_context_for_phone' ) ) {
			return '';
		}
		try {
			$context = VoicePress_Frontend::customer_context_for_phone( $phone, $name );
			return is_scalar( $context ) ? (string) $context : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Test calls
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * "Try it live": call the given phone for a use case, using a real order or a demo cart.
	 *
	 * @param string $use_case Use case key.
	 * @param string $phone    Phone to call.
	 * @param int    $order_id Order to use (0 = most recent suitable order).
	 * @return array|WP_Error Call row.
	 */
	public static function test_call( $use_case, $phone, $order_id = 0 ) {
		$use_case = sanitize_key( (string) $use_case );
		$phone    = VoicePress_Helpers::normalize_phone( is_scalar( $phone ) ? (string) $phone : '' );
		if ( '' === $phone ) {
			return self::error( 'voicepress_bad_phone', __( 'Enter a valid phone number, ideally with the country code (for example +91 98765 43210).', 'voicepress' ), 400 );
		}

		if ( in_array( $use_case, self::ORDER_USE_CASES, true ) ) {
			if ( ! self::woo_active() ) {
				return self::error( 'voicepress_no_woocommerce', __( 'WooCommerce is not active.', 'voicepress' ), 400 );
			}
			$order_id = absint( $order_id );
			$order    = $order_id ? self::get_order( $order_id ) : self::pick_test_order( $use_case );
			if ( ! $order ) {
				return $order_id
					? self::error( 'voicepress_no_order', __( 'Order not found.', 'voicepress' ), 404 )
					: self::error( 'voicepress_no_order', __( 'There are no orders yet. Place a test order first.', 'voicepress' ), 404 );
			}
			return self::place_call(
				$use_case,
				array(
					'order_id' => $order->get_id(),
					'phone'    => $phone,
					'force'    => true,
				)
			);
		}

		if ( 'cart_recovery' === $use_case ) {
			$cart_id = self::create_demo_cart( $phone, self::current_user_first_name() );
			if ( is_wp_error( $cart_id ) ) {
				return $cart_id;
			}
			$result = self::place_call(
				'cart_recovery',
				array(
					'cart_id' => $cart_id,
					'phone'   => $phone,
					'force'   => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				VoicePress_DB::update_cart( $cart_id, array( 'status' => 'failed' ) );
			}
			return $result;
		}

		if ( in_array( $use_case, array( 'support_callback', 'support', 'test' ), true ) ) {
			$name = self::current_user_first_name();
			return self::place_call(
				'support_callback',
				array(
					'phone'         => $phone,
					'customer_name' => $name,
					'extra'         => array(
						'customer_context' => self::context_for_phone( $phone, $name ),
						'channel'          => 'phone',
					),
					'force'         => true,
				)
			);
		}

		return self::error( 'voicepress_bad_use_case', __( 'This use case cannot be tested with a phone call.', 'voicepress' ), 400 );
	}

	/**
	 * Most recent order that suits a test call (falls back to the most recent order).
	 *
	 * @param string $use_case Use case key.
	 * @return WC_Order|null
	 */
	private static function pick_test_order( $use_case ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$preferences = array();
		if ( 'cod_confirmation' === $use_case ) {
			$preferences[] = array(
				'payment_method' => 'cod',
				'status'         => array( 'pending', 'on-hold', 'processing' ),
			);
		} elseif ( 'feedback' === $use_case ) {
			$preferences[] = array( 'status' => array( 'completed' ) );
		} else {
			$preferences[] = array( 'status' => array( 'out-for-delivery', 'delivery-failed', 'processing' ) );
		}
		$preferences[] = array();

		foreach ( $preferences as $preference ) {
			try {
				$orders = wc_get_orders(
					array_merge(
						array(
							'type'    => 'shop_order',
							'limit'   => 1,
							'orderby' => 'date',
							'order'   => 'DESC',
						),
						$preference
					)
				);
			} catch ( \Throwable $e ) {
				$orders = array();
			}
			if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
				return $orders[0];
			}
		}
		return null;
	}

	/**
	 * Demo abandoned cart with 2 random published products for a test call.
	 *
	 * @param string $phone Phone (E.164).
	 * @param string $name  Customer name.
	 * @return int|WP_Error Cart id.
	 */
	private static function create_demo_cart( $phone, $name ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return self::error( 'voicepress_no_woocommerce', __( 'WooCommerce is not active.', 'voicepress' ), 400 );
		}
		try {
			$products = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => 20,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
		} catch ( \Throwable $e ) {
			$products = array();
		}
		$pool = array();
		foreach ( (array) $products as $product ) {
			if ( $product instanceof WC_Product && is_numeric( $product->get_price() ) && (float) $product->get_price() > 0 ) {
				$pool[] = $product;
			}
		}
		if ( ! $pool ) {
			return self::error( 'voicepress_no_products', __( 'Publish at least one product with a price first.', 'voicepress' ), 400 );
		}
		shuffle( $pool );

		$items = array();
		$total = 0.0;
		foreach ( array_slice( $pool, 0, 2 ) as $product ) {
			$price   = (float) $product->get_price();
			$items[] = array(
				'name'  => wp_strip_all_tags( $product->get_name() ),
				'qty'   => 1,
				'price' => $price,
			);
			$total  += $price;
		}

		$id = (int) VoicePress_DB::insert_cart(
			array(
				'session_key'   => 'vp-test-' . strtolower( wp_generate_password( 12, false, false ) ),
				'customer_name' => $name,
				'phone'         => $phone,
				'email'         => '',
				'cart'          => $items,
				'cart_total'    => round( $total, 2 ),
				'currency'      => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				// Not 'captured': the abandoned-cart automation must not call this cart again.
				'status'        => 'calling',
			)
		);
		return $id ? $id : self::error( 'voicepress_db', __( 'Could not save the test cart.', 'voicepress' ), 500 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Syncing executions
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Pull the latest execution data for a call from Bolna.
	 *
	 * @param array $row Call row.
	 * @return array Fresh call row.
	 */
	public static function sync_call( $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return is_array( $row ) ? $row : array();
		}
		$client = VoicePress_Settings::client();
		if ( empty( $row['execution_id'] ) || ! $client ) {
			return $row;
		}

		$execution = $client->get_execution( (string) $row['execution_id'] );
		if ( is_wp_error( $execution ) || ! is_array( $execution ) || empty( $execution ) ) {
			if ( is_wp_error( $execution ) ) {
				self::maybe_expire_web_call( $row, $execution );
				VoicePress_Helpers::log(
					'Execution sync failed',
					array(
						'call'  => $row['id'],
						'error' => $execution->get_error_message(),
					)
				);
			}
			$fresh = VoicePress_DB::get_call( (int) $row['id'] );
			return $fresh ? $fresh : $row;
		}

		return self::apply_execution( $row, $execution );
	}

	/**
	 * Copy a Bolna execution onto a call row, then act on the outcome once the call is over.
	 *
	 * @param array $row Call row.
	 * @param array $ex  Execution payload (GET /executions/{id} or webhook).
	 * @return array Fresh call row.
	 */
	public static function apply_execution( $row, $ex ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) || ! is_array( $ex ) ) {
			return is_array( $row ) ? $row : array();
		}
		$id        = (int) $row['id'];
		$telephony = ( isset( $ex['telephony_data'] ) && is_array( $ex['telephony_data'] ) ) ? $ex['telephony_data'] : array();
		$extracted = ( isset( $ex['extracted_data'] ) && is_array( $ex['extracted_data'] ) ) ? $ex['extracted_data'] : array();
		$current   = (string) $row['status'];
		$incoming  = isset( $ex['status'] ) && is_scalar( $ex['status'] ) ? sanitize_key( (string) $ex['status'] ) : '';
		$update    = array();

		if ( '' !== $incoming && $incoming !== $current && self::can_move( $current, $incoming ) ) {
			$update['status'] = $incoming;
		}
		$status = isset( $update['status'] ) ? $update['status'] : $current;

		$transcript = self::long_text( isset( $ex['transcript'] ) ? $ex['transcript'] : '' );
		if ( '' !== $transcript ) {
			$update['transcript'] = $transcript;
		}

		$summary = self::long_text( isset( $ex['summary'] ) ? $ex['summary'] : '' );
		if ( '' === $summary ) {
			$summary = self::long_text( self::extraction_value( $extracted, 'Call Summary', 'subjective' ) );
		}
		if ( '' !== $summary ) {
			$update['summary'] = $summary;
		}

		$recording = isset( $telephony['recording_url'] ) && is_string( $telephony['recording_url'] ) ? esc_url_raw( $telephony['recording_url'] ) : '';
		if ( '' !== $recording ) {
			$update['recording_url'] = $recording;
		}

		$duration = isset( $ex['conversation_duration'] ) && is_numeric( $ex['conversation_duration'] ) ? (float) $ex['conversation_duration'] : 0.0;
		if ( $duration <= 0 && isset( $telephony['duration'] ) && is_numeric( $telephony['duration'] ) ) {
			$duration = (float) $telephony['duration'];
		}
		if ( $duration > 0 || ( in_array( $status, self::TERMINAL, true ) && ! isset( $row['duration'] ) ) ) {
			$update['duration'] = (int) round( max( 0, $duration ) );
		}

		// Bolna reports cost in cents; stored as is.
		if ( isset( $ex['total_cost'] ) && is_numeric( $ex['total_cost'] ) ) {
			$update['cost'] = round( (float) $ex['total_cost'], 4 );
		}

		if ( ! empty( $extracted ) ) {
			$update['extracted'] = $extracted;
		}

		if ( in_array( $status, self::TERMINAL, true ) && 'completed' !== $status ) {
			$error = self::text( isset( $ex['error_message'] ) ? $ex['error_message'] : '' );
			if ( '' === $error ) {
				$reason = self::text( isset( $ex['termination_reason'] ) ? $ex['termination_reason'] : '' );
				$error  = 'unknown' === strtolower( $reason ) ? '' : $reason;
			}
			if ( '' !== $error ) {
				$update['error'] = self::short( $error, 300 );
			}
		} elseif ( 'completed' === $status && ! empty( $row['error'] ) ) {
			$update['error'] = null;
		}

		if ( empty( $row['phone'] ) ) {
			$call_type = isset( $telephony['call_type'] ) ? strtolower( (string) $telephony['call_type'] ) : '';
			$raw_phone = self::text( isset( $ex['user_number'] ) ? $ex['user_number'] : '' );
			if ( '' === $raw_phone ) {
				$raw_phone = self::text( 'inbound' === $call_type ? ( isset( $telephony['from_number'] ) ? $telephony['from_number'] : '' ) : ( isset( $telephony['to_number'] ) ? $telephony['to_number'] : '' ) );
			}
			$phone = '' !== $raw_phone ? VoicePress_Helpers::normalize_phone( $raw_phone ) : '';
			if ( '' !== $phone ) {
				$update['phone'] = $phone;
			}
		}
		if ( empty( $row['agent_id'] ) && ! empty( $ex['agent_id'] ) && is_scalar( $ex['agent_id'] ) ) {
			$update['agent_id'] = sanitize_text_field( (string) $ex['agent_id'] );
		}

		if ( $update ) {
			VoicePress_DB::update_call( $id, $update );
		}
		$fresh = VoicePress_DB::get_call( $id );
		if ( ! $fresh ) {
			$fresh = array_merge( $row, $update );
		}

		if ( in_array( (string) $fresh['status'], self::TERMINAL, true ) && 'skipped' !== $fresh['status'] && empty( $fresh['outcome_applied'] ) ) {
			self::apply_outcome( $fresh );
			$after = VoicePress_DB::get_call( $id );
			if ( $after ) {
				$fresh = $after;
			}
		}
		return $fresh;
	}

	/**
	 * Store an execution that Bolna pushed (webhook) or that we pulled (import). Creates the call
	 * row for calls VoicePress did not start itself (inbound, web, callbacks from the dashboard).
	 *
	 * @param array $ex Execution payload.
	 * @return array|null Fresh call row.
	 */
	public static function ingest_execution( $ex ) {
		if ( ! is_array( $ex ) ) {
			return null;
		}
		$execution_id = '';
		if ( ! empty( $ex['id'] ) && is_scalar( $ex['id'] ) ) {
			$execution_id = sanitize_text_field( (string) $ex['id'] );
		} elseif ( ! empty( $ex['execution_id'] ) && is_scalar( $ex['execution_id'] ) ) {
			$execution_id = sanitize_text_field( (string) $ex['execution_id'] );
		}
		if ( '' === $execution_id ) {
			return null;
		}

		$row = VoicePress_DB::get_call_by_execution( $execution_id );
		if ( ! $row ) {
			$telephony = ( isset( $ex['telephony_data'] ) && is_array( $ex['telephony_data'] ) ) ? $ex['telephony_data'] : array();
			$call_type = isset( $telephony['call_type'] ) && is_scalar( $telephony['call_type'] ) ? strtolower( (string) $telephony['call_type'] ) : '';
			$provider  = strtolower( self::text( isset( $ex['provider'] ) ? $ex['provider'] : ( isset( $telephony['provider'] ) ? $telephony['provider'] : '' ) ) );
			if ( in_array( $provider, array( 'web-call', 'web_call', 'webcall', 'websocket' ), true ) ) {
				$call_type = 'web';
			}
			$agent_id  = self::text( isset( $ex['agent_id'] ) ? $ex['agent_id'] : '' );
			$raw_phone = self::text( isset( $ex['user_number'] ) ? $ex['user_number'] : '' );
			if ( '' === $raw_phone ) {
				$raw_phone = self::text( 'inbound' === $call_type ? ( isset( $telephony['from_number'] ) ? $telephony['from_number'] : '' ) : ( isset( $telephony['to_number'] ) ? $telephony['to_number'] : '' ) );
			}
			$phone = '' !== $raw_phone ? VoicePress_Helpers::normalize_phone( $raw_phone ) : '';

			// A call we are dialling right now whose first webhook beat the make_call response.
			if ( 'outbound' === $call_type ) {
				$row = self::find_inflight_row( $agent_id, $phone );
				if ( $row ) {
					VoicePress_DB::update_call( (int) $row['id'], array( 'execution_id' => $execution_id ) );
					$row = VoicePress_DB::get_call( (int) $row['id'] );
				}
			}

			if ( ! $row ) {
				if ( 'inbound' === $call_type ) {
					$direction = 'inbound';
					$use_case  = 'inbound';
				} elseif ( 'outbound' === $call_type ) {
					$direction = 'outbound';
					$use_case  = 'support_callback';
				} else {
					$direction = 'web';
					$use_case  = 'web_call';
				}

				$name = '';
				if ( '' !== $phone && self::woo_active() ) {
					$orders = VoicePress_Helpers::find_orders_by_phone( $phone, 1 );
					if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
						$name = trim( $orders[0]->get_billing_first_name() . ' ' . $orders[0]->get_billing_last_name() );
					}
				}

				$id = (int) VoicePress_DB::insert_call(
					array(
						'execution_id'  => $execution_id,
						'use_case'      => $use_case,
						'direction'     => $direction,
						'agent_id'      => $agent_id,
						'phone'         => $phone,
						'customer_name' => $name,
						'status'        => 'queued',
					)
				);
				$row = $id ? VoicePress_DB::get_call( $id ) : null;
			}
		}

		return $row ? self::apply_execution( $row, $ex ) : null;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Outcomes
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * What came out of a finished call.
	 *
	 * @param array $row Call row (with decoded `extracted`).
	 * @return array ['outcome' => string|null, 'note' => string, 'rating' => int|null]
	 */
	public static function detect_outcome( $row ) {
		$result = array(
			'outcome' => null,
			'note'    => '',
			'rating'  => null,
		);
		if ( ! is_array( $row ) ) {
			return $result;
		}

		$status    = isset( $row['status'] ) ? (string) $row['status'] : '';
		$use_case  = isset( $row['use_case'] ) ? (string) $row['use_case'] : '';
		$extracted = isset( $row['extracted'] ) ? $row['extracted'] : array();
		if ( is_string( $extracted ) ) {
			$extracted = VoicePress_Helpers::json_decode_array( $extracted );
		}
		$extracted = is_array( $extracted ) ? $extracted : array();

		$result['note'] = self::short( self::extraction_value( $extracted, 'Customer Note', 'subjective' ), 300 );

		$rating = self::extraction_value( $extracted, 'Rating', 'subjective' );
		if ( '' === $rating ) {
			$rating = self::extraction_value( $extracted, 'Rating', 'objective' );
		}
		$result['rating'] = self::parse_rating( $rating );

		if ( in_array( $status, self::UNREACHABLE_STATUSES, true ) ) {
			$result['outcome'] = 'unreachable';
			return $result;
		}
		if ( ! in_array( $status, array( 'completed', 'stopped' ), true ) ) {
			return $result;
		}

		$decisions = self::DECISIONS;
		$names     = isset( $decisions[ $use_case ] ) ? array( $decisions[ $use_case ] ) : array_unique( array_values( $decisions ) );
		foreach ( $names as $name ) {
			$objective = self::extraction_value( $extracted, $name, 'objective' );
			if ( '' !== $objective ) {
				$result['outcome'] = self::normalize_outcome( $objective );
				break;
			}
		}

		if ( null === $result['outcome'] && 'feedback' === $use_case && null !== $result['rating'] ) {
			$result['outcome'] = $result['rating'] >= 4 ? 'positive' : ( 3 === $result['rating'] ? 'neutral' : 'negative' );
		}

		if ( null === $result['outcome'] && 'completed' === $status ) {
			$result['outcome'] = self::customer_spoke( $row )
				? self::guess_outcome( $use_case, isset( $row['summary'] ) ? (string) $row['summary'] : '' )
				: 'unreachable';
		}

		return $result;
	}

	/**
	 * Act on a finished call exactly once: save the outcome and update the order / cart.
	 *
	 * @param array $row Call row.
	 */
	public static function apply_outcome( $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) || ! empty( $row['outcome_applied'] ) ) {
			return;
		}
		$id = (int) $row['id'];
		if ( ! self::claim_outcome( $id ) ) {
			return;
		}

		$detected = self::detect_outcome( $row );
		$outcome  = $detected['outcome'];
		$note     = $detected['note'];
		$rating   = $detected['rating'];

		VoicePress_DB::update_call(
			$id,
			array(
				'outcome'         => $outcome,
				'rating'          => $rating,
				'outcome_applied' => 1,
			)
		);
		$row['outcome']         = $outcome;
		$row['rating']          = $rating;
		$row['outcome_applied'] = 1;

		try {
			switch ( (string) $row['use_case'] ) {
				case 'cod_confirmation':
					self::apply_cod_outcome( $row, $outcome, $note );
					break;
				case 'delivery_reminder':
				case 'ndr_resolution':
					self::apply_delivery_outcome( $row, $outcome, $note );
					break;
				case 'cart_recovery':
					self::apply_cart_outcome( $row, $outcome );
					break;
				case 'feedback':
					self::apply_feedback_outcome( $row, $outcome, $note, $rating );
					break;
			}
		} catch ( \Throwable $e ) {
			VoicePress_Helpers::log(
				'Applying call outcome failed',
				array(
					'call'  => $id,
					'error' => $e->getMessage(),
				)
			);
		}

		/**
		 * Fires after VoicePress acted on a finished call.
		 *
		 * @param array       $row     Call row.
		 * @param string|null $outcome Outcome key.
		 * @param string      $note    Customer note.
		 */
		do_action( 'voicepress_call_outcome', $row, $outcome, $note );
	}

	/**
	 * Call the customer again later when attempts are left.
	 *
	 * @param array $row Call row.
	 * @return array|WP_Error|null New (scheduled) call row, error, or null when no attempts are left.
	 */
	public static function maybe_retry( $row ) {
		if ( ! is_array( $row ) || empty( $row['use_case'] ) ) {
			return null;
		}
		$attempt = max( 1, (int) $row['attempt'] );
		if ( $attempt >= max( 1, (int) VoicePress_Settings::get( 'max_attempts', 2 ) ) ) {
			return null;
		}
		$use_case = (string) $row['use_case'];
		if ( ! empty( $row['order_id'] ) && self::has_open_call( (int) $row['order_id'], $use_case ) ) {
			return null;
		}

		// Carry over what must stay the same between attempts (discount code, support context).
		$previous = is_array( $row['user_data'] ) ? $row['user_data'] : array();
		$extra    = array_intersect_key( $previous, array_flip( array( 'discount_code', 'customer_context', 'channel' ) ) );

		return self::place_call(
			$use_case,
			array(
				'order_id'      => (int) $row['order_id'],
				'cart_id'       => (int) $row['cart_id'],
				'phone'         => (string) $row['phone'],
				'customer_name' => (string) $row['customer_name'],
				'extra'         => $extra,
				'delay'         => max( 1, (int) VoicePress_Settings::get( 'retry_after', 30 ) ),
				'attempt'       => $attempt + 1,
			)
		);
	}

	/**
	 * COD confirmation outcome → order meta, notes, cancellation, invoice, retry.
	 *
	 * @param array       $row     Call row.
	 * @param string|null $outcome Outcome.
	 * @param string      $note    Customer note.
	 */
	private static function apply_cod_outcome( array $row, $outcome, $note ) {
		$order = self::get_order( $row['order_id'] );
		$live  = $order ? (string) $order->get_meta( '_voicepress_cod_status' ) : '';

		if ( 'unreachable' === $outcome || 'no_decision' === $outcome ) {
			// Already decided (live through the confirm_order / cancel_order tools, or by the store):
			// keep that decision, no retry call and no "didn't confirm" note.
			if ( in_array( $live, array( 'confirmed', 'cancelled' ), true ) ) {
				if ( 'no_decision' === $outcome ) {
					VoicePress_DB::update_call( (int) $row['id'], array( 'outcome' => $live ) );
				}
				return;
			}
			if ( $order ) {
				$order->update_meta_data( '_voicepress_cod_status', 'unreachable' );
				$order->save();
			}
			$retry = self::maybe_retry( $row );
			if ( $order ) {
				$what = 'unreachable' === $outcome
					? __( 'couldn\'t reach the customer for COD confirmation', 'voicepress' )
					: __( 'the customer didn\'t confirm or cancel on the call', 'voicepress' );
				$order->add_order_note( self::retry_note( $row, $retry, $what ) );
			}
			return;
		}
		if ( ! $order ) {
			return;
		}

		$cod_status = (string) $order->get_meta( '_voicepress_cod_status' );
		$with_note  = '' !== $note ? ' ' . $note : '';

		switch ( $outcome ) {
			case 'confirmed':
				if ( 'cancelled' === $order->get_status() ) {
					break;
				}
				if ( 'confirmed' !== $cod_status ) {
					$order->update_meta_data( '_voicepress_cod_status', 'confirmed' );
					$order->save();
					$order->add_order_note( trim( __( '✅ Customer confirmed this COD order on call.', 'voicepress' ) . ( '' !== $note ? ' ' . sprintf( /* translators: %s: customer note. */ __( 'Note: %s', 'voicepress' ), $note ) : '' ) ) );
				}
				break;

			case 'cancelled':
				if ( 'confirmed' === $cod_status && 'cancelled' !== $order->get_status() ) {
					// The customer confirmed live on the call (confirm_order tool): never auto-cancel over that
					// on the strength of the post-call analysis alone; flag it for a person instead.
					$order->add_order_note( trim( __( 'VoicePress: the call analysis suggests a cancellation, but the customer confirmed this order live on the call, so it was not cancelled automatically. Please review.', 'voicepress' ) . $with_note ) );
					break;
				}
				$order->update_meta_data( '_voicepress_cod_status', 'cancelled' );
				$order->save();
				if ( 'cancelled' === $order->get_status() ) {
					break; // Already cancelled live on the call.
				}
				$actions = VoicePress_Helpers::allowed_actions( $order );
				if ( ! empty( $actions['can_cancel'] ) ) {
					$order->update_status( 'cancelled', trim( __( '❌ Customer cancelled on VoicePress confirmation call.', 'voicepress' ) . $with_note ) );
				} else {
					/* translators: %s: order status name. */
					$order->add_order_note( sprintf( __( 'VoicePress: the customer asked to cancel, but the order is already %s. Please review.', 'voicepress' ), wc_get_order_status_name( $order->get_status() ) ) );
				}
				break;

			case 'reschedule':
				$preference = '' !== $note ? $note : (string) $order->get_meta( '_voicepress_delivery_pref' );
				$order->update_meta_data( '_voicepress_delivery_pref', '' !== $preference ? $preference : __( 'Asked to reschedule delivery', 'voicepress' ) );
				if ( 'cancelled' !== $cod_status ) {
					$order->update_meta_data( '_voicepress_cod_status', 'confirmed' );
				}
				$order->save();
				$order->add_order_note( trim( __( 'VoicePress: the customer wants the order but asked to reschedule delivery.', 'voicepress' ) . $with_note ) );
				break;

			case 'address_change':
				$address = '' !== $note ? $note : (string) $order->get_meta( '_voicepress_address_change' );
				$order->update_meta_data( '_voicepress_address_change', '' !== $address ? $address : __( 'Asked to change the delivery address', 'voicepress' ) );
				if ( 'cancelled' !== $cod_status ) {
					$order->update_meta_data( '_voicepress_cod_status', 'confirmed' );
				}
				$order->save();
				$order->add_order_note( trim( __( 'VoicePress: the customer wants the order but asked to change the delivery address.', 'voicepress' ) . $with_note ) );
				break;

			case 'wants_prepaid':
				$order->update_meta_data( '_voicepress_cod_status', 'wants_prepaid' );
				$order->save();
				$sent = false;
				try {
					if ( function_exists( 'WC' ) && WC()->mailer() && method_exists( WC()->mailer(), 'customer_invoice' ) ) {
						WC()->mailer()->customer_invoice( $order );
						$sent = true;
					}
				} catch ( \Throwable $e ) {
					$sent = false;
				}
				$order->add_order_note(
					$sent
						? __( 'VoicePress: the customer prefers to pay online. Invoice emailed; please share a payment link.', 'voicepress' )
						: __( 'VoicePress: the customer prefers to pay online. Please send a payment link.', 'voicepress' )
				);
				break;

			default:
				if ( 'calling' === $cod_status ) {
					$order->update_meta_data( '_voicepress_cod_status', 'pending' );
					$order->save();
				}
				break;
		}
	}

	/**
	 * Delivery reminder / failed delivery outcome → order meta, note, retry.
	 *
	 * @param array       $row     Call row.
	 * @param string|null $outcome Outcome.
	 * @param string      $note    Customer note.
	 */
	private static function apply_delivery_outcome( array $row, $outcome, $note ) {
		$order = self::get_order( $row['order_id'] );
		$ndr   = 'ndr_resolution' === $row['use_case'];

		if ( 'unreachable' === $outcome ) {
			$retry = self::maybe_retry( $row );
			if ( $order ) {
				$what = $ndr
					? __( 'couldn\'t reach the customer about the failed delivery', 'voicepress' )
					: __( 'couldn\'t reach the customer about today\'s delivery', 'voicepress' );
				$order->add_order_note( self::retry_note( $row, $retry, $what ) );
			}
			return;
		}
		if ( ! $order || null === $outcome ) {
			return;
		}

		switch ( $outcome ) {
			case 'available':
				$text = __( 'customer will be available to receive the order.', 'voicepress' );
				break;
			case 'reschedule':
				$preference = '' !== $note ? $note : (string) $order->get_meta( '_voicepress_delivery_pref' );
				$order->update_meta_data( '_voicepress_delivery_pref', '' !== $preference ? $preference : __( 'Asked to reschedule delivery', 'voicepress' ) );
				$order->save();
				$text = __( 'customer asked to reschedule delivery.', 'voicepress' );
				break;
			case 'address_change':
				$address = '' !== $note ? $note : (string) $order->get_meta( '_voicepress_address_change' );
				$order->update_meta_data( '_voicepress_address_change', '' !== $address ? $address : __( 'Asked to change the delivery address', 'voicepress' ) );
				$order->save();
				$text = __( 'customer gave a new delivery address.', 'voicepress' );
				break;
			case 'cancel':
				$text = __( 'customer wants to cancel, but the order has already shipped. Please review.', 'voicepress' );
				break;
			case 'no_decision':
				$text = __( 'no clear answer from the customer.', 'voicepress' );
				break;
			default:
				/* translators: %s: outcome label. */
				$text = sprintf( __( 'outcome: %s.', 'voicepress' ), self::outcome_label( $outcome ) );
				break;
		}

		$prefix = $ndr ? __( 'VoicePress failed-delivery call:', 'voicepress' ) : __( 'VoicePress delivery call:', 'voicepress' );
		$order->add_order_note( trim( $prefix . ' ' . $text . ( '' !== $note ? ' ' . sprintf( /* translators: %s: customer note. */ __( 'Note: %s', 'voicepress' ), $note ) : '' ) ) );
	}

	/**
	 * Cart recovery outcome → cart status, retry.
	 *
	 * @param array       $row     Call row.
	 * @param string|null $outcome Outcome.
	 */
	private static function apply_cart_outcome( array $row, $outcome ) {
		$cart = ! empty( $row['cart_id'] ) ? VoicePress_DB::get_cart( (int) $row['cart_id'] ) : null;
		if ( ! $cart || 'recovered' === (string) $cart['status'] ) {
			return; // Ordered in the meantime: keep 'recovered'.
		}
		if ( 'unreachable' === $outcome ) {
			$retry = self::maybe_retry( $row );
			VoicePress_DB::update_cart( (int) $cart['id'], array( 'status' => is_array( $retry ) ? 'calling' : 'called' ) );
			return;
		}
		VoicePress_DB::update_cart( (int) $cart['id'], array( 'status' => 'not_interested' === $outcome ? 'ignored' : 'called' ) );
	}

	/**
	 * Feedback outcome → rating meta, note, escalation.
	 *
	 * @param array       $row     Call row.
	 * @param string|null $outcome Outcome (sentiment).
	 * @param string      $note    Customer note.
	 * @param int|null    $rating  Rating 1–5.
	 */
	private static function apply_feedback_outcome( array $row, $outcome, $note, $rating ) {
		$order = self::get_order( $row['order_id'] );
		if ( ! $order || 'unreachable' === $outcome ) {
			return;
		}

		if ( null !== $rating ) {
			$previous = (int) $order->get_meta( '_voicepress_rating' );
			$order->update_meta_data( '_voicepress_rating', $rating );
			$order->save();
			if ( $previous !== $rating ) { // The record_feedback tool may already have noted it live.
				$order->add_order_note(
					'' !== $note
						/* translators: 1: rating 1-5, 2: customer comment. */
						? sprintf( __( '⭐ %1$d/5 — %2$s', 'voicepress' ), $rating, $note )
						/* translators: %d: rating 1-5. */
						: sprintf( __( '⭐ %d/5 on the feedback call.', 'voicepress' ), $rating )
				);
			}
		} elseif ( in_array( $outcome, array( 'positive', 'neutral', 'negative' ), true ) ) {
			/* translators: 1: sentiment label, 2: customer note. */
			$order->add_order_note( trim( sprintf( __( 'VoicePress feedback call: %1$s. %2$s', 'voicepress' ), self::outcome_label( $outcome ), $note ) ) );
		}

		$unhappy = ( null !== $rating && $rating <= 2 ) || 'negative' === $outcome;
		if ( $unhappy && '' === (string) $order->get_meta( '_voicepress_escalation' ) ) {
			$reason = null !== $rating
				/* translators: 1: rating 1-5, 2: customer note. */
				? sprintf( __( 'Rated %1$d/5 on the feedback call. %2$s', 'voicepress' ), $rating, $note )
				/* translators: %s: customer note. */
				: sprintf( __( 'Unhappy on the feedback call. %s', 'voicepress' ), $note );
			$order->update_meta_data( '_voicepress_escalation', trim( $reason ) );
			$order->save();
			$order->add_order_note( __( '⚠️ Unhappy customer — flagged for follow-up.', 'voicepress' ) );
		}
	}

	/**
	 * Order note for an unanswered / undecided call, with what happens next.
	 *
	 * @param array               $row   Call row.
	 * @param array|WP_Error|null $retry Result of maybe_retry().
	 * @param string              $what  What happened ("couldn't reach the customer …").
	 * @return string
	 */
	private static function retry_note( array $row, $retry, $what ) {
		$attempt = max( 1, (int) $row['attempt'] );
		if ( is_array( $retry ) ) {
			/* translators: 1: what happened, 2: attempt number, 3: minutes until the retry. */
			return sprintf( __( 'VoicePress: %1$s (attempt %2$d). Trying again in %3$d min.', 'voicepress' ), $what, $attempt, max( 1, (int) VoicePress_Settings::get( 'retry_after', 30 ) ) );
		}
		/* translators: 1: what happened, 2: attempt number. */
		return sprintf( __( 'VoicePress: %1$s (attempt %2$d). No more retries, please follow up.', 'voicepress' ), $what, $attempt );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Cron tick
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * One heartbeat: dial due calls, sync live calls, call abandoned carts, import support calls.
	 *
	 * @return array Counts: dispatched, synced, carts, imported (+ locked when another tick is running).
	 */
	public static function run_tick() {
		$counts = array(
			'dispatched' => 0,
			'synced'     => 0,
			'carts'      => 0,
			'imported'   => 0,
		);
		if ( get_transient( self::TICK_LOCK ) ) {
			$counts['locked'] = true;
			return $counts;
		}
		set_transient( self::TICK_LOCK, time(), 45 );

		try {
			if ( VoicePress_Settings::is_configured() ) {
				self::heal_stuck_calls();

				if ( self::woo_active() ) {
					foreach ( (array) VoicePress_DB::due_scheduled_calls( 5 ) as $row ) {
						$result = self::dispatch( (int) $row['id'] );
						if ( is_array( $result ) && ! empty( $result['execution_id'] ) ) {
							++$counts['dispatched'];
						}
					}
				}

				foreach ( (array) VoicePress_DB::pending_sync_calls( 10 ) as $row ) {
					self::sync_call( $row );
					++$counts['synced'];
				}

				if ( class_exists( 'VoicePress_Automations' ) ) {
					$counts['carts'] = (int) VoicePress_Automations::process_abandoned_carts();
				}

				if ( ! get_transient( self::IMPORT_THROTTLE ) ) {
					set_transient( self::IMPORT_THROTTLE, time(), 5 * MINUTE_IN_SECONDS );
					$counts['imported'] = self::import_support_executions();
				}
			}
		} catch ( \Throwable $e ) {
			VoicePress_Helpers::log( 'Tick failed', array( 'error' => $e->getMessage() ) );
		}

		delete_transient( self::TICK_LOCK );
		return $counts;
	}

	/**
	 * Import recent executions of the support agent (inbound / web calls when no webhook reached us).
	 *
	 * @return int Executions ingested.
	 */
	private static function import_support_executions() {
		$agent_id = (string) VoicePress_Settings::agent_id( 'support' );
		$client   = VoicePress_Settings::client();
		if ( '' === $agent_id || ! $client ) {
			return 0;
		}
		$response = $client->list_agent_executions( $agent_id, 1, 20 );
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return 0;
		}
		$items = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : ( wp_is_numeric_array( $response ) ? $response : array() );

		$count = 0;
		foreach ( $items as $execution ) {
			if ( ! is_array( $execution ) || empty( $execution['id'] ) || ! is_scalar( $execution['id'] ) ) {
				continue;
			}
			$existing = VoicePress_DB::get_call_by_execution( (string) $execution['id'] );
			if ( $existing && in_array( (string) $existing['status'], self::TERMINAL, true ) ) {
				continue;
			}
			// List items can be trimmed: fetch the full execution when the details are missing.
			if ( ! isset( $execution['telephony_data'] ) && ! isset( $execution['transcript'] ) ) {
				$full = $client->get_execution( (string) $execution['id'] );
				if ( is_array( $full ) && ! empty( $full ) ) {
					$execution = $full;
				}
			}
			if ( self::ingest_execution( $execution ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Fail rows that never made it to Bolna (request died mid-dispatch) so they stop counting as open.
	 */
	private static function heal_stuck_calls() {
		$rows   = VoicePress_DB::list_calls(
			array(
				'status' => array( 'dispatching', 'queued' ),
				'limit'  => 20,
			)
		);
		$cutoff = time() - 10 * MINUTE_IN_SECONDS;
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['execution_id'] ) || self::gmt_timestamp( $row['updated_at'] ) > $cutoff ) {
				continue;
			}
			VoicePress_DB::update_call(
				(int) $row['id'],
				array(
					'status' => 'failed',
					'error'  => __( 'The call could not be started (the request was interrupted).', 'voicepress' ),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Labels
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Human label for a call status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$status = is_scalar( $status ) ? (string) $status : '';
		$labels = array(
			'scheduled'         => __( 'Scheduled', 'voicepress' ),
			'dispatching'       => __( 'Starting…', 'voicepress' ),
			'prepared'          => __( 'Queued', 'voicepress' ),
			'queued'            => __( 'Queued', 'voicepress' ),
			'rescheduled'       => __( 'Rescheduled', 'voicepress' ),
			'initiated'         => __( 'Dialing…', 'voicepress' ),
			'ringing'           => __( 'Ringing…', 'voicepress' ),
			'in-progress'       => __( 'On call', 'voicepress' ),
			'call-disconnected' => __( 'Wrapping up…', 'voicepress' ),
			'completed'         => __( 'Completed', 'voicepress' ),
			'no-answer'         => __( 'No answer', 'voicepress' ),
			'busy'              => __( 'Busy', 'voicepress' ),
			'failed'            => __( 'Failed', 'voicepress' ),
			'canceled'          => __( 'Canceled', 'voicepress' ),
			'stopped'           => __( 'Stopped', 'voicepress' ),
			'error'             => __( 'Error', 'voicepress' ),
			'balance-low'       => __( 'Low balance', 'voicepress' ),
			'skipped'           => __( 'Skipped', 'voicepress' ),
		);
		if ( isset( $labels[ $status ] ) ) {
			return $labels[ $status ];
		}
		return '' === $status ? '' : ucfirst( str_replace( array( '-', '_' ), ' ', $status ) );
	}

	/**
	 * Human label for a call outcome ('' when there is none).
	 *
	 * @param string|null $outcome Outcome key.
	 * @return string
	 */
	public static function outcome_label( $outcome ) {
		$outcome = is_scalar( $outcome ) ? (string) $outcome : '';
		$labels  = array(
			'confirmed'        => __( 'Confirmed', 'voicepress' ),
			'cancelled'        => __( 'Cancelled', 'voicepress' ),
			'reschedule'       => __( 'Reschedule', 'voicepress' ),
			'address_change'   => __( 'Address change', 'voicepress' ),
			'wants_prepaid'    => __( 'Wants to pay online', 'voicepress' ),
			'no_decision'      => __( 'No decision', 'voicepress' ),
			'unreachable'      => __( 'Unreachable', 'voicepress' ),
			'available'        => __( 'Will be available', 'voicepress' ),
			'cancel'           => __( 'Wants to cancel', 'voicepress' ),
			'will_purchase'    => __( 'Will purchase', 'voicepress' ),
			'needs_help'       => __( 'Needs help', 'voicepress' ),
			'price_concern'    => __( 'Price concern', 'voicepress' ),
			'not_interested'   => __( 'Not interested', 'voicepress' ),
			'call_later'       => __( 'Call later', 'voicepress' ),
			'positive'         => __( 'Positive', 'voicepress' ),
			'neutral'          => __( 'Neutral', 'voicepress' ),
			'negative'         => __( 'Negative', 'voicepress' ),
			'order_status'     => __( 'Order status', 'voicepress' ),
			'return_refund'    => __( 'Return / refund', 'voicepress' ),
			'cancellation'     => __( 'Cancellation', 'voicepress' ),
			'delivery_change'  => __( 'Delivery change', 'voicepress' ),
			'product_question' => __( 'Product question', 'voicepress' ),
			'complaint'        => __( 'Complaint', 'voicepress' ),
			'other'            => __( 'Other', 'voicepress' ),
		);
		if ( isset( $labels[ $outcome ] ) ) {
			return $labels[ $outcome ];
		}
		return '' === $outcome ? '' : ucfirst( str_replace( array( '_', '-' ), ' ', $outcome ) );
	}

	/**
	 * Human label for a use case.
	 *
	 * @param string $use_case Use case key.
	 * @return string
	 */
	public static function use_case_label( $use_case ) {
		$use_case = is_scalar( $use_case ) ? (string) $use_case : '';
		$labels   = ( class_exists( 'VoicePress_Agents' ) && method_exists( 'VoicePress_Agents', 'use_case_labels' ) ) ? VoicePress_Agents::use_case_labels() : array();
		if ( isset( $labels[ $use_case ] ) ) {
			return (string) $labels[ $use_case ];
		}
		return ucfirst( str_replace( '_', ' ', $use_case ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Mark a row as being dialled by this request. Compare-and-set on the status with a unique token,
	 * so two requests (shutdown dispatch + cron) can never dial the same row twice.
	 *
	 * @param array $row Call row (status scheduled or queued).
	 * @return bool True when this request owns the row.
	 */
	private static function claim( array $row ) {
		global $wpdb;
		$id      = (int) $row['id'];
		$token   = 'claim:' . wp_generate_password( 12, false, false );
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			VoicePress_DB::calls_table(),
			array(
				'status'     => 'dispatching',
				'error'      => $token,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => (string) $row['status'],
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		$fresh   = VoicePress_DB::get_call( $id );
		if ( false === $updated ) {
			// Conditional update not possible: fall back to a plain check.
			return $fresh && in_array( (string) $fresh['status'], array( 'scheduled', 'queued' ), true ) && empty( $fresh['execution_id'] );
		}
		return $fresh && $token === (string) $fresh['error'];
	}

	/**
	 * Set outcome_applied = 1 only if it is still 0, so an outcome is applied once even when the
	 * webhook and the poller finish the same call at the same moment.
	 *
	 * @param int $id Call row id.
	 * @return bool True when this request should apply the outcome.
	 */
	private static function claim_outcome( $id ) {
		global $wpdb;
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			VoicePress_DB::calls_table(),
			array( 'outcome_applied' => 1 ),
			array(
				'id'              => (int) $id,
				'outcome_applied' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
		if ( false === $updated ) {
			$row = VoicePress_DB::get_call( (int) $id );
			return $row && empty( $row['outcome_applied'] );
		}
		return (int) $updated > 0;
	}

	/**
	 * Record a failed dial: row → failed, short order note, cart → failed.
	 *
	 * @param array         $row       Call row.
	 * @param WC_Order|null $order     Order.
	 * @param array|null    $cart      Cart row.
	 * @param string        $message   Error message.
	 * @param array         $user_data Variables that were sent.
	 * @param string        $agent_id  Agent id used.
	 * @return WP_Error
	 */
	private static function fail_dispatch( array $row, $order, $cart, $message, array $user_data = array(), $agent_id = '' ) {
		$id      = (int) $row['id'];
		$message = self::short( wp_strip_all_tags( (string) $message ), 300 );
		$update  = array(
			'status' => 'failed',
			'error'  => $message,
		);
		if ( $user_data ) {
			$update['user_data'] = $user_data;
		}
		if ( '' !== $agent_id ) {
			$update['agent_id'] = $agent_id;
		}
		VoicePress_DB::update_call( $id, $update );

		try {
			if ( $order ) {
				/* translators: 1: use case label, 2: error message. */
				$order->add_order_note( sprintf( __( 'VoicePress: %1$s call could not be placed. %2$s', 'voicepress' ), self::use_case_label( $row['use_case'] ), self::short( $message, 140 ) ) );
			}
			if ( $cart && 'recovered' !== (string) $cart['status'] ) {
				VoicePress_DB::update_cart(
					(int) $cart['id'],
					array(
						'status'  => 'failed',
						'call_id' => $id,
					)
				);
			}
		} catch ( \Throwable $e ) {
			VoicePress_Helpers::log( 'Recording a failed call failed', array( 'error' => $e->getMessage() ) );
		}

		return self::error( 'voicepress_call_failed', $message, 502, array( 'call' => VoicePress_DB::get_call( $id ) ) );
	}

	/**
	 * Queue a call to be dialled when the current request ends.
	 *
	 * @param int $id Call row id.
	 */
	private static function dispatch_at_shutdown( $id ) {
		self::$deferred[] = (int) $id;
		if ( false === has_action( 'shutdown', array( __CLASS__, 'dispatch_deferred' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'dispatch_deferred' ), 20 );
		}
	}

	/**
	 * UTC start of the next calling window, or '' when calling is allowed right now.
	 *
	 * @return string
	 */
	private static function next_calling_window() {
		if ( ! (int) VoicePress_Settings::get( 'respect_calling_hours', 0 ) ) {
			return '';
		}
		$start = min( 23, max( 0, (int) VoicePress_Settings::get( 'calling_hours_start', 9 ) ) );
		$end   = min( 24, max( 0, (int) VoicePress_Settings::get( 'calling_hours_end', 21 ) ) );
		if ( $start === $end ) {
			return '';
		}
		try {
			$now    = new DateTime( 'now', wp_timezone() );
			$hour   = (int) $now->format( 'G' );
			$inside = $start < $end ? ( $hour >= $start && $hour < $end ) : ( $hour >= $start || $hour < $end );
			if ( $inside ) {
				return '';
			}
			$next = clone $now;
			$next->setTime( $start, 0, 0 );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			$next->setTimezone( new DateTimeZone( 'UTC' ) );
			return $next->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Execution statuses only move forward: never back from a later state or out of a final one.
	 *
	 * @param string $from Current status.
	 * @param string $to   Incoming status.
	 * @return bool
	 */
	private static function can_move( $from, $to ) {
		if ( in_array( $from, self::TERMINAL, true ) ) {
			return 'completed' === $to && 'completed' !== $from && 'skipped' !== $from;
		}
		return self::status_rank( $to ) >= self::status_rank( $from );
	}

	/**
	 * Position of a status in the call lifecycle.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	private static function status_rank( $status ) {
		if ( in_array( $status, self::TERMINAL, true ) ) {
			return 9;
		}
		$ranks = array(
			'scheduled'         => 0,
			'dispatching'       => 0,
			'prepared'          => 1,
			'queued'            => 1,
			'rescheduled'       => 1,
			'initiated'         => 2,
			'ringing'           => 3,
			'in-progress'       => 4,
			'call-disconnected' => 5,
		);
		return isset( $ranks[ $status ] ) ? $ranks[ $status ] : 1;
	}

	/**
	 * Row being dialled right now (no execution id yet) for this agent + phone.
	 *
	 * @param string $agent_id Agent id.
	 * @param string $phone    Phone (E.164).
	 * @return array|null
	 */
	private static function find_inflight_row( $agent_id, $phone ) {
		$rows   = VoicePress_DB::list_calls(
			array(
				'status'    => array( 'dispatching', 'queued' ),
				'direction' => 'outbound',
				'limit'     => 20,
			)
		);
		$cutoff = time() - 10 * MINUTE_IN_SECONDS;
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['execution_id'] ) || self::gmt_timestamp( $row['updated_at'] ) < $cutoff ) {
				continue;
			}
			if ( '' !== $agent_id && ! empty( $row['agent_id'] ) && $row['agent_id'] !== $agent_id ) {
				continue;
			}
			if ( '' !== $phone && ! VoicePress_Helpers::phones_match( (string) $row['phone'], $phone ) ) {
				continue;
			}
			return $row;
		}
		return null;
	}

	/**
	 * A web call whose session never turned into an execution: stop polling it after 30 minutes.
	 *
	 * @param array    $row   Call row.
	 * @param WP_Error $error get_execution() error.
	 */
	private static function maybe_expire_web_call( array $row, $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 'web' !== (string) $row['direction'] || ! in_array( $status, array( 400, 404 ), true ) ) {
			return;
		}
		if ( in_array( (string) $row['status'], self::TERMINAL, true ) || self::gmt_timestamp( $row['created_at'] ) > time() - 30 * MINUTE_IN_SECONDS ) {
			return;
		}
		VoicePress_DB::update_call(
			(int) $row['id'],
			array(
				'status' => 'canceled',
				'error'  => __( 'The web call never connected.', 'voicepress' ),
			)
		);
	}

	/**
	 * Find a disposition result by name in any extraction category (also accepts the flat legacy shape).
	 *
	 * @param array  $extracted extracted_data.
	 * @param string $name      Disposition name, e.g. "Order Decision".
	 * @return array|null Result object {subjective, objective, confidence, …}.
	 */
	private static function find_extraction( $extracted, $name ) {
		if ( ! is_array( $extracted ) ) {
			return null;
		}
		$needle = strtolower( trim( $name ) );
		foreach ( $extracted as $key => $value ) {
			if ( strtolower( trim( (string) $key ) ) === $needle ) {
				if ( is_array( $value ) && ( array_key_exists( 'objective', $value ) || array_key_exists( 'subjective', $value ) ) ) {
					return $value;
				}
				if ( is_scalar( $value ) ) {
					return array(
						'objective'  => (string) $value,
						'subjective' => (string) $value,
					);
				}
			}
		}
		foreach ( $extracted as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}
			foreach ( $category as $key => $value ) {
				if ( strtolower( trim( (string) $key ) ) === $needle && is_array( $value ) ) {
					return $value;
				}
			}
		}
		return null;
	}

	/**
	 * One field of a disposition result as clean text ('' when empty or "null").
	 *
	 * @param array  $extracted extracted_data.
	 * @param string $name      Disposition name.
	 * @param string $field     'objective' or 'subjective'.
	 * @return string
	 */
	private static function extraction_value( $extracted, $name, $field ) {
		$item = self::find_extraction( $extracted, $name );
		return ( $item && isset( $item[ $field ] ) ) ? self::text( $item[ $field ] ) : '';
	}

	/**
	 * "Address Change" → "address_change".
	 *
	 * @param string $value Objective value.
	 * @return string|null
	 */
	private static function normalize_outcome( $value ) {
		$value = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $value ) ), '_' );
		return '' === $value ? null : substr( $value, 0, 64 );
	}

	/**
	 * Rating 1–5 from "4", "4 stars", "4.5" or "four"; null otherwise.
	 *
	 * @param string $raw Raw value.
	 * @return int|null
	 */
	private static function parse_rating( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( '' === $raw ) {
			return null;
		}
		$rating = 0;
		if ( preg_match( '/\d+(?:\.\d+)?/', $raw, $match ) ) {
			$rating = (int) round( (float) $match[0] );
		} else {
			$words = array(
				'one'   => 1,
				'two'   => 2,
				'three' => 3,
				'four'  => 4,
				'five'  => 5,
			);
			foreach ( $words as $word => $number ) {
				if ( preg_match( '/\b' . $word . '\b/', $raw ) ) {
					$rating = $number;
					break;
				}
			}
		}
		return ( $rating >= 1 && $rating <= 5 ) ? $rating : null;
	}

	/**
	 * Did the customer say anything? (No user turns = voicemail, dead air or instant hang-up.)
	 *
	 * @param array $row Call row.
	 * @return bool
	 */
	private static function customer_spoke( array $row ) {
		$transcript = isset( $row['transcript'] ) ? (string) $row['transcript'] : '';
		if ( '' !== trim( $transcript ) && preg_match( '/^\s*assistant\s*:/mi', $transcript ) ) {
			return (bool) preg_match( '/^\s*user\s*:\s*\S/mi', $transcript );
		}
		return '' !== trim( $transcript ) || '' !== trim( isset( $row['summary'] ) ? (string) $row['summary'] : '' );
	}

	/**
	 * Best-effort outcome from the call summary when no extraction is available.
	 *
	 * @param string $use_case Use case key.
	 * @param string $summary  Call summary.
	 * @return string
	 */
	private static function guess_outcome( $use_case, $summary ) {
		$text    = strtolower( $summary );
		$address = (bool) ( preg_match( '/\baddress\b/', $text ) && preg_match( '/chang|updat|correct|\bnew\b/', $text ) );
		$later   = (bool) preg_match( '/call (?:them |him |her )?(?:back )?later|was busy|\bbusy\b|wrong (?:person|number)/', $text );

		switch ( $use_case ) {
			case 'cod_confirmation':
				// "no need to cancel", "she can cancel any time", "did not want to cancel" are not cancellations.
				$cancel_text = preg_replace( '/\b(?:no need to|not to|not|never|cannot|can|could|option to|without|(?:didn|doesn|don|won|can)(?:\'|\xE2\x80\x99)t|did not|does not|do not|will not)\s+(?:want(?:ed|s)?\s+to\s+)?cancel\w*/', ' ', $text );
				if ( preg_match( '/\bcancel/', is_string( $cancel_text ) ? $cancel_text : $text ) ) {
					return 'cancelled';
				}
				if ( preg_match( '/reschedul|postpone|another day|later date/', $text ) ) {
					return 'reschedule';
				}
				if ( $address ) {
					return 'address_change';
				}
				if ( preg_match( '/pay online|prepaid|prepay|online payment|pay in advance/', $text ) ) {
					return 'wants_prepaid';
				}
				if ( $later ) {
					return 'no_decision';
				}
				if ( preg_match( '/\b(?:customer|caller|user|he|she|they)\s+(?:has\s+|had\s+)?(?:confirmed|agreed)|confirmed (?:the|their|his|her|this) (?:[a-z]+ ){0,3}order|\bsaid yes\b/', $text ) ) {
					return 'confirmed';
				}
				return 'no_decision';

			case 'delivery_reminder':
			case 'ndr_resolution':
				if ( preg_match( '/\bcancel|no longer want/', $text ) ) {
					return 'cancel';
				}
				if ( preg_match( '/reschedul|postpone|another day|later date|tomorrow/', $text ) ) {
					return 'reschedule';
				}
				if ( $address ) {
					return 'address_change';
				}
				if ( ! $later && preg_match( '/availab|at home|receive/', $text ) ) {
					return 'available';
				}
				return 'no_decision';

			case 'cart_recovery':
				if ( preg_match( '/not interested|no longer interested|does not want|doesn\'t want/', $text ) ) {
					return 'not_interested';
				}
				if ( $later ) {
					return 'call_later';
				}
				if ( preg_match( '/price|expensive|costly|cheaper|delivery charge/', $text ) ) {
					return 'price_concern';
				}
				if ( preg_match( '/will (?:complete|place|buy|purchase|order)|going to (?:buy|order|purchase)|complete (?:the|their|his|her) (?:purchase|order)/', $text ) ) {
					return 'will_purchase';
				}
				if ( preg_match( '/question|help|size|stock/', $text ) ) {
					return 'needs_help';
				}
				return 'no_decision';

			case 'feedback':
				if ( preg_match( '/unhappy|disappoint|damag|broken|wrong item|missing|complain|\bpoor\b|\bbad\b|terrible/', $text ) ) {
					return 'negative';
				}
				if ( preg_match( '/happy|satisf|great|good|love|excellent|liked/', $text ) ) {
					return 'positive';
				}
				return 'neutral';

			case 'support_callback':
			case 'web_call':
			case 'inbound':
				return 'other';
		}
		return 'no_decision';
	}

	/**
	 * E.164 phone for a call: explicit phone, else order billing phone (with its country code), else cart phone.
	 *
	 * @param mixed         $raw   Explicit phone.
	 * @param WC_Order|null $order Order.
	 * @param array|null    $cart  Cart row.
	 * @return string
	 */
	private static function resolve_phone( $raw, $order, $cart ) {
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' !== $raw ) {
			return VoicePress_Helpers::normalize_phone( $raw );
		}
		if ( $order ) {
			$billing = trim( (string) $order->get_billing_phone() );
			if ( '' === $billing && is_callable( array( $order, 'get_shipping_phone' ) ) ) {
				$billing = trim( (string) $order->get_shipping_phone() );
			}
			if ( '' !== $billing ) {
				return VoicePress_Helpers::normalize_phone( $billing, self::calling_code( $order->get_billing_country() ) );
			}
		}
		if ( $cart && ! empty( $cart['phone'] ) ) {
			return VoicePress_Helpers::normalize_phone( (string) $cart['phone'] );
		}
		return '';
	}

	/**
	 * Calling code digits for a country ("IN" → "91"), null when unknown.
	 *
	 * @param string $country ISO country code.
	 * @return string|null
	 */
	private static function calling_code( $country ) {
		$country = is_scalar( $country ) ? (string) $country : '';
		if ( '' === $country || ! function_exists( 'WC' ) || ! WC()->countries || ! method_exists( WC()->countries, 'get_country_calling_code' ) ) {
			return null;
		}
		$code = WC()->countries->get_country_calling_code( $country );
		if ( is_array( $code ) ) {
			$code = reset( $code );
		}
		$digits = preg_replace( '/\D+/', '', (string) $code );
		return '' !== $digits ? $digits : null;
	}

	/**
	 * Logged-in user's first name (test calls), '' when unknown.
	 *
	 * @return string
	 */
	private static function current_user_first_name() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return '';
		}
		$name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->display_name;
		return sanitize_text_field( $name );
	}

	/**
	 * Order by id (null when WooCommerce is inactive, the id is unknown or it is a refund).
	 *
	 * @param int $order_id Order id.
	 * @return WC_Order|null
	 */
	private static function get_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! self::woo_active() ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return ( $order instanceof WC_Order ) ? $order : null;
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	private static function woo_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Force every value to a string (user_data must be strings). Arrays become JSON.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function stringify( array $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( null === $value ) {
				$value = '';
			} elseif ( is_scalar( $value ) ) {
				$value = (string) $value;
			} else {
				$value = (string) wp_json_encode( $value );
			}
			$out[ $key ] = trim( wp_strip_all_tags( wp_check_invalid_utf8( $value ) ) );
		}
		return $out;
	}

	/**
	 * String value from a context array.
	 *
	 * @param array  $ctx     Context.
	 * @param string $key     Key.
	 * @param string $default Default when missing or empty.
	 * @return string
	 */
	private static function ctx( array $ctx, $key, $default = '' ) {
		return ( isset( $ctx[ $key ] ) && is_scalar( $ctx[ $key ] ) && '' !== (string) $ctx[ $key ] ) ? (string) $ctx[ $key ] : (string) $default;
	}

	/**
	 * Clean single value: trimmed string, '' for null-ish placeholders.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return in_array( strtolower( $value ), array( 'null', 'none', 'n/a', 'na', 'undefined', '-' ), true ) ? '' : $value;
	}

	/**
	 * Multi-line text from Bolna (transcript, summary): tags stripped, line breaks kept.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function long_text( $value ) {
		$value = self::text( $value );
		return '' === $value ? '' : trim( wp_strip_all_tags( wp_check_invalid_utf8( $value ) ) );
	}

	/**
	 * Shorten text to $max characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function short( $text, $max ) {
		$text = trim( (string) $text );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1 ) : substr( $text, 0, $max - 1 );
		return rtrim( $cut ) . '…';
	}

	/**
	 * Unix timestamp of a UTC MySQL datetime (0 when empty).
	 *
	 * @param string $mysql_gmt Datetime.
	 * @return int
	 */
	private static function gmt_timestamp( $mysql_gmt ) {
		if ( empty( $mysql_gmt ) || ! is_string( $mysql_gmt ) ) {
			return 0;
		}
		$timestamp = strtotime( $mysql_gmt . ' UTC' );
		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * WP_Error with an HTTP status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param array  $data    Extra error data.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400, array $data = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => (int) $status ), $data ) );
	}
}
