<?php
/**
 * Automations: custom order statuses, order hooks (new order, status changes), the cron tick,
 * abandoned-cart calls, and the Voice AI column / bulk actions / meta box on the WooCommerce
 * order screens (legacy posts screen and HPOS screen).
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static automation hooks.
 */
class VoicePress_Automations {

	/**
	 * Order status (without "wc-") → use case of the call it triggers.
	 */
	const STATUS_USE_CASES = array(
		'out-for-delivery' => 'delivery_reminder',
		'delivery-failed'  => 'ndr_resolution',
		'completed'        => 'feedback',
	);

	/**
	 * Bulk action → use case.
	 */
	const BULK_ACTIONS = array(
		'voicepress_call_cod'      => 'cod_confirmation',
		'voicepress_call_delivery' => 'delivery_reminder',
		'voicepress_call_feedback' => 'feedback',
	);

	/**
	 * Screen ids of the order list (legacy + HPOS).
	 */
	const LIST_SCREENS = array( 'edit-shop_order', 'woocommerce_page_wc-orders', 'admin_page_wc-orders' );

	/**
	 * Screen ids of every order screen (list + edit, legacy + HPOS).
	 */
	const ORDER_SCREENS = array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders', 'admin_page_wc-orders' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_order_statuses' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The tick has to run every minute.
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 20 );
		add_action( 'voicepress_tick', array( 'VoicePress_Calls', 'run_tick' ) );

		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );

		if ( is_admin() ) {
			// Voice AI column (legacy, then HPOS).
			add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_column' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_legacy_column' ), 20, 2 );
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_column' ), 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_hpos_column' ), 20, 2 );

			// Bulk actions (legacy, then HPOS).
			add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_actions' ), 20 );
			add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_actions' ), 20 );
			add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_actions' ), 20, 3 );
			add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_actions' ), 20, 3 );
			add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
			add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );

			add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 30, 0 );
			add_action( 'admin_head', array( __CLASS__, 'print_admin_styles' ) );
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Custom order statuses
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * VoicePress order statuses: key (with "wc-") => label.
	 *
	 * @return array
	 */
	public static function order_statuses() {
		return array(
			'wc-out-for-delivery' => _x( 'Out for delivery', 'Order status', 'voicepress' ),
			'wc-delivery-failed'  => _x( 'Delivery failed', 'Order status', 'voicepress' ),
			'wc-return-requested' => _x( 'Return requested', 'Order status', 'voicepress' ),
		);
	}

	/**
	 * Register the statuses as post statuses (used by both order list screens for labels and counts).
	 */
	public static function register_statuses() {
		$counts = array(
			/* translators: %s: number of orders. */
			'wc-out-for-delivery' => _n_noop( 'Out for delivery <span class="count">(%s)</span>', 'Out for delivery <span class="count">(%s)</span>', 'voicepress' ),
			/* translators: %s: number of orders. */
			'wc-delivery-failed'  => _n_noop( 'Delivery failed <span class="count">(%s)</span>', 'Delivery failed <span class="count">(%s)</span>', 'voicepress' ),
			/* translators: %s: number of orders. */
			'wc-return-requested' => _n_noop( 'Return requested <span class="count">(%s)</span>', 'Return requested <span class="count">(%s)</span>', 'voicepress' ),
		);
		foreach ( self::order_statuses() as $status => $label ) {
			if ( get_post_status_object( $status ) ) {
				continue;
			}
			register_post_status(
				$status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $counts[ $status ],
				)
			);
		}
	}

	/**
	 * Add the statuses to WooCommerce, right after "Processing".
	 *
	 * @param array $statuses Order statuses.
	 * @return array
	 */
	public static function add_order_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		$custom   = self::order_statuses();
		$out      = array();
		$inserted = false;
		foreach ( $statuses as $key => $label ) {
			if ( isset( $custom[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out      = array_merge( $out, $custom );
				$inserted = true;
			}
		}
		return $inserted ? $out : array_merge( $out, $custom );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Add the "every minute" schedule.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules['voicepress_minute'] ) ) {
			$schedules['voicepress_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => did_action( 'init' ) ? __( 'Every minute', 'voicepress' ) : 'Every minute',
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the voicepress_tick event when it is missing. Safe to call on activation (before init()).
	 */
	public static function schedule_cron() {
		if ( false === has_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The tick has to run every minute.
		}
		if ( ! wp_next_scheduled( 'voicepress_tick' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'voicepress_minute', 'voicepress_tick' );
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Order hooks
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * New order from the classic or block checkout: recover open carts, then queue the COD confirmation call.
	 * The call is dialled when the checkout request ends (after payment), so checkout stays fast.
	 *
	 * @param WC_Order|int $order Order or order id.
	 */
	public static function on_new_order( $order ) {
		try {
			$order = self::get_order( $order );
			if ( ! $order ) {
				return;
			}

			self::mark_carts_recovered( $order );

			if ( 'cod' !== $order->get_payment_method() || $order->get_meta( '_voicepress_new_order_handled' ) ) {
				return;
			}
			$order->update_meta_data( '_voicepress_new_order_handled', current_time( 'mysql', true ) );
			$order->save();

			$automation = VoicePress_Settings::automation( 'cod_confirmation' );
			if ( empty( $automation['enabled'] ) || ! self::can_call( 'cod_confirmation' ) ) {
				return;
			}

			$result = VoicePress_Calls::place_call(
				'cod_confirmation',
				array(
					'order_id' => $order->get_id(),
					'delay'    => max( 0, (int) $automation['delay'] ),
					'defer'    => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				/* translators: %s: error message. */
				$order->add_order_note( sprintf( __( 'VoicePress: couldn\'t schedule the COD confirmation call. %s', 'voicepress' ), $result->get_error_message() ) );
				return;
			}
			$order->update_meta_data( '_voicepress_cod_status', 'pending' );
			$order->save();
		} catch ( \Throwable $e ) {
			VoicePress_Helpers::log( 'New order automation failed', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Status changes: Out for delivery → delivery reminder, Delivery failed → NDR call, Completed → feedback call.
	 *
	 * @param int           $order_id Order id.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_status_changed( $order_id, $from = '', $to = '', $order = null ) {
		try {
			$map = self::STATUS_USE_CASES;
			$to  = is_string( $to ) ? $to : '';
			if ( ! isset( $map[ $to ] ) ) {
				return;
			}
			$use_case   = $map[ $to ];
			$automation = VoicePress_Settings::automation( $use_case );
			if ( empty( $automation['enabled'] ) || ! self::can_call( $use_case ) ) {
				return;
			}
			$order = self::get_order( $order instanceof WC_Order ? $order : $order_id );
			if ( ! $order ) {
				return;
			}
			if ( VoicePress_Calls::has_open_call( $order->get_id(), $use_case ) ) {
				return;
			}
			if ( 'feedback' === $use_case && self::feedback_done( $order ) ) {
				return;
			}

			$result = VoicePress_Calls::place_call(
				$use_case,
				array(
					'order_id' => $order->get_id(),
					'delay'    => max( 0, (int) $automation['delay'] ),
					'defer'    => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				/* translators: 1: call type, e.g. "Delivery reminder", 2: error message. */
				$order->add_order_note( sprintf( __( 'VoicePress: couldn\'t schedule the %1$s call. %2$s', 'voicepress' ), VoicePress_Calls::use_case_label( $use_case ), $result->get_error_message() ) );
			}
		} catch ( \Throwable $e ) {
			VoicePress_Helpers::log( 'Status automation failed', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Call abandoned carts whose wait time is over.
	 *
	 * @return int Calls placed.
	 */
	public static function process_abandoned_carts() {
		$automation = VoicePress_Settings::automation( 'cart_recovery' );
		if ( empty( $automation['enabled'] ) || ! self::can_call( 'cart_recovery' ) ) {
			return 0;
		}

		$placed = 0;
		foreach ( (array) VoicePress_DB::due_carts( max( 0, (int) $automation['delay'] ), 3 ) as $cart ) {
			$cart_id = (int) $cart['id'];
			if ( empty( $cart['cart'] ) ) {
				VoicePress_DB::update_cart( $cart_id, array( 'status' => 'ignored' ) );
				continue;
			}

			$result = VoicePress_Calls::place_call(
				'cart_recovery',
				array(
					'cart_id'       => $cart_id,
					'phone'         => (string) $cart['phone'],
					'customer_name' => (string) $cart['customer_name'],
				)
			);

			if ( is_wp_error( $result ) ) {
				// Never retry the same cart every minute: park it as failed (the call log keeps the reason).
				$data = $result->get_error_data();
				VoicePress_DB::update_cart(
					$cart_id,
					array(
						'status'  => 'failed',
						'call_id' => ( is_array( $data ) && ! empty( $data['call']['id'] ) ) ? (int) $data['call']['id'] : null,
					)
				);
				continue;
			}

			VoicePress_DB::update_cart(
				$cart_id,
				array(
					'status'  => 'calling',
					'call_id' => (int) $result['id'],
				)
			);
			++$placed;
		}
		return $placed;
	}

	/**
	 * Mark this shopper's open carts as recovered by the order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function mark_carts_recovered( $order ) {
		$session_key = '';
		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$session_key = (string) WC()->session->get_customer_id();
		}
		$raw_phone = (string) $order->get_billing_phone();
		$phone     = '' !== $raw_phone ? VoicePress_Helpers::normalize_phone( $raw_phone ) : '';
		$phone     = '' !== $phone ? $phone : $raw_phone;
		$email     = (string) $order->get_billing_email();
		if ( '' === $session_key && '' === $phone && '' === $email ) {
			return;
		}

		$after_call = false;
		foreach ( (array) VoicePress_DB::find_open_carts_for( $session_key, $phone, $email ) as $cart ) {
			VoicePress_DB::update_cart(
				(int) $cart['id'],
				array(
					'status'   => 'recovered',
					'order_id' => $order->get_id(),
				)
			);
			if ( ! empty( $cart['call_id'] ) ) {
				$after_call = true;
			}
		}
		if ( $after_call ) {
			$order->add_order_note( __( 'VoicePress: abandoned cart recovered. The customer ordered after our call.', 'voicepress' ) );
		}
	}

	/**
	 * Has this order already given feedback (rating saved or a feedback call completed)?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function feedback_done( $order ) {
		if ( '' !== (string) $order->get_meta( '_voicepress_rating' ) ) {
			return true;
		}
		$calls = VoicePress_DB::list_calls(
			array(
				'order_id' => $order->get_id(),
				'use_case' => 'feedback',
				'status'   => 'completed',
				'limit'    => 1,
			)
		);
		return ! empty( $calls );
	}

	/**
	 * Is Bolna connected and the agent for this use case created?
	 *
	 * @param string $use_case Use case key.
	 * @return bool
	 */
	private static function can_call( $use_case ) {
		return VoicePress_Settings::is_configured() && '' !== (string) VoicePress_Agents::agent_for_use_case( $use_case );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Orders list: Voice AI column
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Add the "Voice AI" column after the status column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_order_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$label = __( 'Voice AI', 'voicepress' );
		$out   = array();
		foreach ( $columns as $key => $title ) {
			$out[ $key ] = $title;
			if ( 'order_status' === $key ) {
				$out['voicepress'] = $label;
			}
		}
		if ( ! isset( $out['voicepress'] ) ) {
			$out['voicepress'] = $label;
		}
		return $out;
	}

	/**
	 * Column cell on the legacy (posts) orders screen.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order id.
	 */
	public static function render_legacy_column( $column, $post_id ) {
		if ( 'voicepress' === $column ) {
			self::render_column_cell( self::get_order( $post_id ) );
		}
	}

	/**
	 * Column cell on the HPOS orders screen.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order.
	 */
	public static function render_hpos_column( $column, $order ) {
		if ( 'voicepress' === $column ) {
			self::render_column_cell( self::get_order( $order ) );
		}
	}

	/**
	 * Badge + one line of context for an order.
	 *
	 * @param WC_Order|null $order Order.
	 */
	private static function render_column_cell( $order ) {
		$badge = $order ? self::order_badge( $order ) : null;
		if ( ! $badge ) {
			echo '<span class="vp-muted" aria-hidden="true">&ndash;</span>';
			return;
		}
		printf(
			'<span class="vp-badge vp-badge--%1$s" title="%3$s">%2$s</span>',
			esc_attr( $badge['tone'] ),
			esc_html( $badge['label'] ),
			esc_attr( $badge['title'] )
		);
		if ( '' !== $badge['sub'] ) {
			printf( '<br><small class="vp-badge-sub">%s</small>', esc_html( $badge['sub'] ) );
		}
	}

	/**
	 * Badge for an order: latest call first, else the COD status.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null ['label', 'tone', 'title', 'sub']
	 */
	public static function order_badge( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$call       = VoicePress_DB::latest_call_for_order( $order->get_id() );
		$cod_status = (string) $order->get_meta( '_voicepress_cod_status' );
		if ( $call && 'skipped' === $call['status'] && self::cod_badge( $cod_status ) ) {
			$call = null; // "Confirmed ✓" says more than an old skipped attempt.
		}

		if ( $call ) {
			list( $label, $tone ) = self::call_badge( $call );
			$sub                  = VoicePress_Calls::use_case_label( $call['use_case'] ) . ' · ' . self::call_when( $call );
			if ( 'cod_confirmation' !== $call['use_case'] ) {
				$cod = self::cod_badge( $cod_status );
				if ( $cod && 'pending' !== $cod_status ) {
					/* translators: %s: COD status, e.g. "Confirmed". */
					$sub .= ' · ' . sprintf( __( 'COD: %s', 'voicepress' ), $cod[2] );
				}
			}
			$title = ! empty( $call['summary'] ) ? (string) $call['summary'] : ( ! empty( $call['error'] ) ? (string) $call['error'] : '' );
			return array(
				'label' => $label,
				'tone'  => $tone,
				'title' => self::short( $title, 220 ),
				'sub'   => $sub,
			);
		}

		$cod = self::cod_badge( $cod_status );
		if ( ! $cod ) {
			return null;
		}
		return array(
			'label' => $cod[0],
			'tone'  => $cod[1],
			'title' => '',
			'sub'   => __( 'COD confirmation', 'voicepress' ),
		);
	}

	/**
	 * Label + tone for a call row.
	 *
	 * @param array $call Call row.
	 * @return array [label, tone]
	 */
	public static function call_badge( array $call ) {
		$status  = isset( $call['status'] ) ? (string) $call['status'] : '';
		$outcome = isset( $call['outcome'] ) ? (string) $call['outcome'] : '';
		$rating  = isset( $call['rating'] ) ? (int) $call['rating'] : 0;

		if ( 'scheduled' === $status ) {
			return array( __( 'Scheduled', 'voicepress' ), 'neutral' );
		}
		if ( in_array( $status, VoicePress_Calls::ACTIVE_STATUSES, true ) ) {
			return array( __( 'Calling…', 'voicepress' ), 'live' );
		}
		if ( $rating >= 1 && $rating <= 5 ) {
			/* translators: %d: star rating 1-5. */
			return array( sprintf( __( 'Rated %d★', 'voicepress' ), $rating ), $rating >= 4 ? 'success' : ( 3 === $rating ? 'warning' : 'danger' ) );
		}

		switch ( $outcome ) {
			case 'confirmed':
				return array( __( 'Confirmed ✓', 'voicepress' ), 'success' );
			case 'available':
				return array( __( 'Available ✓', 'voicepress' ), 'success' );
			case 'will_purchase':
				return array( __( 'Will buy ✓', 'voicepress' ), 'success' );
			case 'positive':
				return array( __( 'Happy ✓', 'voicepress' ), 'success' );
			case 'cancelled':
				return array( __( 'Cancelled', 'voicepress' ), 'danger' );
			case 'cancel':
				return array( __( 'Wants to cancel', 'voicepress' ), 'danger' );
			case 'negative':
				return array( __( 'Unhappy', 'voicepress' ), 'danger' );
			case 'unreachable':
				return array( __( 'Unreachable', 'voicepress' ), 'warning' );
			case 'no_decision':
				return array( __( 'No decision', 'voicepress' ), 'warning' );
			case 'not_interested':
				return array( __( 'Not interested', 'voicepress' ), 'neutral' );
			case '':
				break;
			default:
				return array( VoicePress_Calls::outcome_label( $outcome ), 'info' );
		}

		if ( 'skipped' === $status ) {
			return array( __( 'Skipped', 'voicepress' ), 'neutral' );
		}
		if ( in_array( $status, array( 'failed', 'error', 'balance-low' ), true ) ) {
			return array( __( 'Call failed', 'voicepress' ), 'danger' );
		}
		if ( 'completed' === $status ) {
			return array( __( 'Completed', 'voicepress' ), 'success' );
		}
		return array( VoicePress_Calls::status_label( $status ), 'neutral' );
	}

	/**
	 * Badge for the COD status meta.
	 *
	 * @param string $cod_status _voicepress_cod_status value.
	 * @return array|null [label, tone, plain name]
	 */
	private static function cod_badge( $cod_status ) {
		switch ( (string) $cod_status ) {
			case 'pending':
				return array( __( 'COD · to confirm', 'voicepress' ), 'neutral', __( 'to confirm', 'voicepress' ) );
			case 'calling':
				return array( __( 'Calling…', 'voicepress' ), 'live', __( 'calling', 'voicepress' ) );
			case 'confirmed':
				return array( __( 'Confirmed ✓', 'voicepress' ), 'success', __( 'confirmed', 'voicepress' ) );
			case 'cancelled':
				return array( __( 'Cancelled', 'voicepress' ), 'danger', __( 'cancelled', 'voicepress' ) );
			case 'unreachable':
				return array( __( 'Unreachable', 'voicepress' ), 'warning', __( 'unreachable', 'voicepress' ) );
			case 'wants_prepaid':
				return array( __( 'Wants to pay online', 'voicepress' ), 'info', __( 'wants to pay online', 'voicepress' ) );
		}
		return null;
	}

	/**
	 * "5 min ago" / "in 12 min" for a call row.
	 *
	 * @param array $call Call row.
	 * @return string
	 */
	private static function call_when( array $call ) {
		if ( 'scheduled' === $call['status'] && ! empty( $call['scheduled_at'] ) ) {
			$at = strtotime( $call['scheduled_at'] . ' UTC' );
			if ( $at && $at > time() ) {
				/* translators: %s: human time difference, e.g. "12 mins". */
				return sprintf( __( 'in %s', 'voicepress' ), human_time_diff( time(), $at ) );
			}
			return __( 'due now', 'voicepress' );
		}
		$time = ! empty( $call['updated_at'] ) ? $call['updated_at'] : ( isset( $call['created_at'] ) ? $call['created_at'] : '' );
		return '' !== (string) $time ? VoicePress_Helpers::human_time( $time ) : '';
	}

	/* ---------------------------------------------------------------------------------------------
	 * Orders list: bulk actions
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Status changes for the VoicePress statuses + Voice AI call actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function add_bulk_actions( $actions ) {
		if ( ! is_array( $actions ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		$actions['mark_out-for-delivery']    = __( 'Change status to out for delivery', 'voicepress' );
		$actions['mark_delivery-failed']     = __( 'Change status to delivery failed', 'voicepress' );
		$actions['mark_return-requested']    = __( 'Change status to return requested', 'voicepress' );
		$actions['voicepress_call_cod']      = __( 'Voice AI: COD confirmation call', 'voicepress' );
		$actions['voicepress_call_delivery'] = __( 'Voice AI: Delivery reminder call', 'voicepress' );
		$actions['voicepress_call_feedback'] = __( 'Voice AI: Feedback call', 'voicepress' );
		return $actions;
	}

	/**
	 * Queue Voice AI calls for the selected orders (dialled at the end of the request / by cron).
	 * WooCommerce / WordPress verify the bulk-action nonce before this filter runs.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param array  $ids      Order ids.
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect, $action, $ids ) {
		$map = self::BULK_ACTIONS;
		if ( ! is_string( $action ) || ! isset( $map[ $action ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}
		$use_case = $map[ $action ];
		$called   = 0;
		$skipped  = 0;
		$error    = '';

		if ( ! VoicePress_Settings::is_configured() ) {
			$error = 'voicepress_not_configured';
		} elseif ( '' === (string) VoicePress_Agents::agent_for_use_case( $use_case ) ) {
			$error = 'voicepress_no_agent';
		} else {
			$ids = array_slice( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ), 0, 100 );
			foreach ( $ids as $order_id ) {
				try {
					$order = self::get_order( $order_id );
					$ready = $order
						&& ! VoicePress_Calls::has_open_call( $order_id, $use_case )
						&& '' === VoicePress_Calls::skip_reason(
							array(
								'use_case' => $use_case,
								'order_id' => $order_id,
							),
							$order
						);
					if ( ! $ready ) {
						++$skipped;
						continue;
					}
					$result = VoicePress_Calls::place_call(
						$use_case,
						array(
							'order_id' => $order_id,
							'defer'    => true,
						)
					);
					if ( is_wp_error( $result ) ) {
						++$skipped;
						continue;
					}
					++$called;
				} catch ( \Throwable $e ) {
					++$skipped;
					VoicePress_Helpers::log( 'Bulk call failed', array( 'error' => $e->getMessage() ) );
				}
			}
		}

		$args = array( 'vp_called' => $called );
		if ( $skipped ) {
			$args['vp_skipped'] = $skipped;
		}
		if ( '' !== $error ) {
			$args['vp_error'] = $error;
		}
		return add_query_arg( $args, remove_query_arg( array( 'vp_called', 'vp_skipped', 'vp_error' ), (string) $redirect ) );
	}

	/**
	 * Result notice after a Voice AI bulk action.
	 */
	public static function bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice after a nonce-checked bulk action.
		if ( ! isset( $_GET['vp_called'] ) || ! self::is_screen( self::LIST_SCREENS ) ) {
			return;
		}
		$called  = absint( wp_unslash( $_GET['vp_called'] ) );
		$skipped = isset( $_GET['vp_skipped'] ) ? absint( wp_unslash( $_GET['vp_skipped'] ) ) : 0;
		$error   = isset( $_GET['vp_error'] ) ? sanitize_key( wp_unslash( $_GET['vp_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$class = 'notice-success';
		$link  = '';
		if ( 'voicepress_not_configured' === $error ) {
			$class   = 'notice-error';
			$message = __( 'VoicePress: connect your Bolna account before placing calls.', 'voicepress' );
			$link    = sprintf( ' <a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=voicepress-settings' ) ), esc_html__( 'Open settings', 'voicepress' ) );
		} elseif ( 'voicepress_no_agent' === $error ) {
			$class   = 'notice-error';
			$message = __( 'VoicePress: create the voice agents before placing calls.', 'voicepress' );
			$link    = sprintf( ' <a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=voicepress-agents' ) ), esc_html__( 'Create agents', 'voicepress' ) );
		} else {
			$parts = array();
			if ( $called ) {
				/* translators: %s: number of calls. */
				$parts[] = sprintf( _n( '📞 %s VoicePress call queued. The customer will be called in a moment.', '📞 %s VoicePress calls queued. Customers will be called over the next few minutes.', $called, 'voicepress' ), number_format_i18n( $called ) );
			}
			if ( $skipped ) {
				/* translators: %s: number of orders. */
				$parts[] = sprintf( _n( '%s order skipped (already handled, a call is already lined up, or no valid phone).', '%s orders skipped (already handled, a call is already lined up, or no valid phone).', $skipped, 'voicepress' ), number_format_i18n( $skipped ) );
			}
			if ( ! $called ) {
				$class = 'notice-warning';
			}
			$message = $parts ? implode( ' ', $parts ) : __( 'VoicePress: no calls were queued.', 'voicepress' );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s%3$s</p></div>',
			esc_attr( $class ),
			esc_html( $message ),
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		);
	}

	/**
	 * Strip the notice args from the URL after display.
	 *
	 * @param array $args Removable query args.
	 * @return array
	 */
	public static function removable_query_args( $args ) {
		$args   = is_array( $args ) ? $args : array();
		$args[] = 'vp_called';
		$args[] = 'vp_skipped';
		$args[] = 'vp_error';
		return $args;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Order edit screen: meta box
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Register the "VoicePress · Voice AI" box on the legacy and HPOS order edit screens.
	 */
	public static function add_meta_boxes() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			try {
				$screens[] = (string) wc_get_page_screen_id( 'shop-order' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box( 'voicepress-voice-ai', __( 'VoicePress · Voice AI', 'voicepress' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
		}
	}

	/**
	 * Meta box: COD status and facts, call buttons, and the call list (filled live by admin.js).
	 *
	 * @param WP_Post|WC_Order $object Post (legacy screen) or order (HPOS screen).
	 */
	public static function render_meta_box( $object ) {
		$order = $object instanceof WP_Post ? self::get_order( $object->ID ) : self::get_order( $object );
		if ( ! $order ) {
			echo '<p class="vp-muted">' . esc_html__( 'Save the order to enable voice calls.', 'voicepress' ) . '</p>';
			return;
		}
		$order_id   = $order->get_id();
		$configured = VoicePress_Settings::is_configured();
		$has_agents = $configured && '' !== (string) VoicePress_Agents::agent_for_use_case( 'cod_confirmation' );

		echo '<div class="vp-metabox" data-order="' . esc_attr( $order_id ) . '">';

		if ( ! $configured ) {
			printf(
				'<p class="vp-metabox__hint">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Connect Bolna to start calling.', 'voicepress' ),
				esc_url( admin_url( 'admin.php?page=voicepress-settings' ) ),
				esc_html__( 'Settings', 'voicepress' )
			);
		} elseif ( ! $has_agents ) {
			printf(
				'<p class="vp-metabox__hint">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Create the voice agents to start calling.', 'voicepress' ),
				esc_url( admin_url( 'admin.php?page=voicepress-agents' ) ),
				esc_html__( 'Agents', 'voicepress' )
			);
		}

		self::render_order_facts( $order );

		$buttons = array(
			'cod_confirmation'  => __( '📞 Confirm order', 'voicepress' ),
			'delivery_reminder' => __( '🚚 Delivery reminder', 'voicepress' ),
			'ndr_resolution'    => __( '📦 Failed delivery', 'voicepress' ),
			'feedback'          => __( '⭐ Ask for feedback', 'voicepress' ),
		);
		echo '<div class="vp-metabox__actions">';
		foreach ( $buttons as $use_case => $label ) {
			printf(
				'<button type="button" class="button vp-call-order" data-order="%1$s" data-use-case="%2$s"%4$s>%3$s</button>',
				esc_attr( $order_id ),
				esc_attr( $use_case ),
				esc_html( $label ),
				$configured ? '' : ' disabled="disabled"'
			);
		}
		echo '</div>';

		echo '<div class="vp-order-calls" data-order="' . esc_attr( $order_id ) . '">';
		self::render_order_calls( $order_id );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * COD status, rating, delivery preference, address change and escalation for an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_order_facts( $order ) {
		$facts      = array();
		$cod_status = (string) $order->get_meta( '_voicepress_cod_status' );
		if ( 'cod' === $order->get_payment_method() || '' !== $cod_status ) {
			$cod     = self::cod_badge( $cod_status );
			$facts[] = array(
				__( 'COD status', 'voicepress' ),
				$cod
					? sprintf( '<span class="vp-badge vp-badge--%1$s">%2$s</span>', esc_attr( $cod[1] ), esc_html( $cod[0] ) )
					: '<span class="vp-badge vp-badge--neutral">' . esc_html__( 'Not called yet', 'voicepress' ) . '</span>',
			);
		}

		// The language order calls will start in (customer preference → delivery state → default).
		if ( class_exists( 'VoicePress_Languages' ) && VoicePress_Languages::is_multilingual() ) {
			$code    = VoicePress_Languages::for_order( $order );
			$title   = '' !== VoicePress_Languages::order_preference( $order ) ? __( 'Chosen by the customer', 'voicepress' ) : ( 'auto_state' === VoicePress_Languages::mode() ? __( 'From the delivery state, or your default language', 'voicepress' ) : __( 'Your default language', 'voicepress' ) );
			$facts[] = array(
				__( 'Call language', 'voicepress' ),
				'<span class="vp-badge vp-badge--brand" lang="' . esc_attr( VoicePress_Languages::html_lang( $code ) ) . '" title="' . esc_attr( $title ) . '">' . esc_html( VoicePress_Languages::label( $code ) ) . '</span>',
			);
		}

		$rating = (int) $order->get_meta( '_voicepress_rating' );
		if ( $rating >= 1 && $rating <= 5 ) {
			$facts[] = array(
				__( 'Rating', 'voicepress' ),
				'<span class="vp-stars" title="' . esc_attr( $rating . '/5' ) . '">' . esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ) . '</span>',
			);
		}
		$text_facts = array(
			'_voicepress_delivery_pref'  => __( 'Delivery preference', 'voicepress' ),
			'_voicepress_address_change' => __( 'Address change', 'voicepress' ),
			'_voicepress_escalation'     => __( 'Needs follow-up', 'voicepress' ),
		);
		foreach ( $text_facts as $key => $label ) {
			$value = $order->get_meta( $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$facts[] = array( $label, esc_html( self::short( (string) $value, 160 ) ) );
			}
		}

		if ( ! $facts ) {
			return;
		}
		echo '<ul class="vp-metabox__facts">';
		foreach ( $facts as $fact ) {
			// $fact[1] is built from escaped parts above.
			echo '<li><span class="vp-metabox__label">' . esc_html( $fact[0] ) . '</span> ' . $fact[1] . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ul>';
	}

	/**
	 * Server-rendered call list (admin.js replaces it with the live list from admin/order-calls/{id}).
	 *
	 * @param int $order_id Order id.
	 */
	private static function render_order_calls( $order_id ) {
		$calls = VoicePress_DB::list_calls(
			array(
				'order_id' => (int) $order_id,
				'limit'    => 5,
			)
		);
		if ( empty( $calls ) ) {
			echo '<p class="vp-muted">' . esc_html__( 'No calls yet.', 'voicepress' ) . '</p>';
			return;
		}
		echo '<ul class="vp-order-calls__list">';
		foreach ( $calls as $call ) {
			list( $label, $tone ) = self::call_badge( $call );
			$details              = array( self::call_when( $call ) );
			if ( ! empty( $call['duration'] ) ) {
				$details[] = VoicePress_Helpers::format_duration( (int) $call['duration'] );
			}
			printf(
				'<li><a href="%1$s">%2$s</a> <span class="vp-badge vp-badge--%3$s">%4$s</span><br><small class="vp-muted">%5$s</small></li>',
				esc_url( admin_url( 'admin.php?page=voicepress-calls&call=' . (int) $call['id'] ) ),
				esc_html( VoicePress_Calls::use_case_label( $call['use_case'] ) ),
				esc_attr( $tone ),
				esc_html( $label ),
				esc_html( implode( ' · ', array_filter( $details ) ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Small fallback styles for the column, the meta box and the VoicePress status pills. Zero
	 * specificity (:where) for the VoicePress classes, so admin.css always wins.
	 */
	public static function print_admin_styles() {
		if ( ! self::is_screen( self::ORDER_SCREENS ) ) {
			return;
		}
		?>
		<style id="voicepress-order-screens">
			:where(.vp-badge){display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;line-height:18px;font-weight:600;white-space:nowrap;background:#f0f0f1;color:#50575e}
			:where(.vp-badge--success){background:#e6f6ec;color:#16723a}
			:where(.vp-badge--danger){background:#fde8e8;color:#b42318}
			:where(.vp-badge--warning){background:#fff4e0;color:#8a5300}
			:where(.vp-badge--info){background:#eef0ff;color:#3b2bb8}
			:where(.vp-badge--live){background:#e5f8ef;color:#0f7a4a}
			:where(.vp-badge--brand){background:#ece8ff;color:#4b2fd6}
			:where(.vp-badge-sub,.vp-muted){color:#646970}
			:where(.vp-metabox__hint){margin:0 0 10px;padding:8px 10px;border-radius:6px;background:#fff8e5}
			:where(.vp-metabox__facts){margin:0 0 10px}
			:where(.vp-metabox__facts li){display:flex;justify-content:space-between;gap:8px;align-items:center;margin:0 0 6px}
			:where(.vp-metabox__label){color:#646970}
			:where(.vp-metabox__actions){display:grid;grid-template-columns:1fr 1fr;gap:6px;margin:0 0 12px}
			:where(.vp-metabox__actions .button){white-space:normal;line-height:1.3;min-height:32px;text-align:center}
			:where(.vp-order-calls__list li){margin:0 0 8px}
			:where(.vp-stars){color:#f0b400;letter-spacing:1px}
			.wp-list-table .column-voicepress{width:9.5em}
			.order-status.status-out-for-delivery{background:#e6e1ff;color:#3b23b8}
			.order-status.status-delivery-failed{background:#fbe2e2;color:#8a1f1f}
			.order-status.status-return-requested{background:#fff1d6;color:#7a4d00}
		</style>
		<?php
	}

	/* ---------------------------------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Order from an order object or id (null for refunds, unknown ids or without WooCommerce).
	 *
	 * @param WC_Order|int $order Order or id.
	 * @return WC_Order|null
	 */
	private static function get_order( $order ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		if ( $order instanceof WC_Order ) {
			return $order;
		}
		$order_id = is_numeric( $order ) ? absint( $order ) : 0;
		if ( ! $order_id ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Is the current admin screen one of these?
	 *
	 * @param string[] $ids Screen ids.
	 * @return bool
	 */
	private static function is_screen( array $ids ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && in_array( $screen->id, $ids, true );
	}

	/**
	 * Shorten text.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function short( $text, $max ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1 ) : substr( $text, 0, $max - 1 );
		return rtrim( $cut ) . '…';
	}
}
