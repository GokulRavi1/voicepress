<?php
/**
 * Small, dependency-free helpers shared by every VoicePress module:
 * phone normalisation, speakable amounts, order/store context for the voice agents, order lookups.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helper functions.
 */
class VoicePress_Helpers {

	/**
	 * Normalise a phone number to E.164 ("+919876543210"), or '' when it cannot be a real number.
	 *
	 * @param string|int  $raw Raw phone number as typed by a customer.
	 * @param string|null $cc  Default country calling code (digits). Defaults to the VoicePress setting.
	 * @return string
	 */
	public static function normalize_phone( $raw, $cc = null ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		// Keep digits and a leading '+' only ("(+91) 98765-43210" -> "+919876543210").
		$stripped = preg_replace( '/[^\d+]/', '', trim( (string) $raw ) );
		if ( '' === $stripped ) {
			return '';
		}
		$has_plus = '+' === $stripped[0];
		$digits   = str_replace( '+', '', $stripped );
		$length   = strlen( $digits );

		if ( $has_plus ) {
			return ( $length >= 8 && $length <= 15 ) ? '+' . $digits : '';
		}

		if ( null === $cc || '' === $cc ) {
			$cc = class_exists( 'VoicePress_Settings' ) ? VoicePress_Settings::get( 'default_country_code', '91' ) : '91';
		}
		$cc = preg_replace( '/\D+/', '', is_scalar( $cc ) ? (string) $cc : '' );
		if ( '' === $cc ) {
			$cc = '91';
		}

		// International "00" dialling prefix ("0091 98765 43210").
		if ( 0 === strpos( $digits, '00' ) && $length - 2 >= 11 && $length - 2 <= 15 ) {
			return '+' . substr( $digits, 2 );
		}

		if ( $length < 10 ) {
			return '';
		}
		if ( 10 === $length ) {
			return '+' . $cc . $digits;
		}
		if ( 11 === $length && '0' === $digits[0] ) {
			return '+' . $cc . substr( $digits, 1 );
		}
		if ( 0 === strpos( $digits, $cc ) && strlen( $cc ) + 10 === $length ) {
			return '+' . $digits;
		}
		if ( $length >= 11 && $length <= 15 ) {
			return '+' . $digits;
		}
		return '';
	}

	/**
	 * Last $n digits of a phone number.
	 *
	 * @param string|int $raw Phone number in any format.
	 * @param int        $n   Number of trailing digits.
	 * @return string
	 */
	public static function phone_tail( $raw, $n = 10 ) {
		$digits = is_scalar( $raw ) ? preg_replace( '/\D+/', '', (string) $raw ) : '';
		$n      = (int) $n;
		if ( $n <= 0 || '' === $digits ) {
			return '';
		}
		return strlen( $digits ) > $n ? substr( $digits, -$n ) : $digits;
	}

	/**
	 * Do two phone numbers belong to the same line? Compares the last 10 digits
	 * (or the shorter of the two when it has 7–9 digits).
	 *
	 * @param string $a Phone number.
	 * @param string $b Phone number.
	 * @return bool
	 */
	public static function phones_match( $a, $b ) {
		$da     = is_scalar( $a ) ? preg_replace( '/\D+/', '', (string) $a ) : '';
		$db     = is_scalar( $b ) ? preg_replace( '/\D+/', '', (string) $b ) : '';
		$length = min( 10, strlen( $da ), strlen( $db ) );
		if ( $length < 7 ) {
			return false;
		}
		return substr( $da, -$length ) === substr( $db, -$length );
	}

	/**
	 * Amount as the voice agent should say it: "1299 rupees", "1299 rupees 50 paise", "12.50 dollars", "12.50 EUR".
	 *
	 * @param float|string $amount   Amount.
	 * @param string|null  $currency ISO currency code. Defaults to the store currency.
	 * @return string
	 */
	public static function speak_amount( $amount, $currency = null ) {
		if ( null === $currency || '' === $currency ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'INR';
		}
		$currency = strtoupper( trim( (string) $currency ) );

		if ( is_string( $amount ) ) {
			$amount = str_replace( array( ',', ' ' ), '', $amount );
		}
		$amount = is_numeric( $amount ) ? (float) $amount : 0.0;

		$prefix   = $amount < 0 ? 'minus ' : '';
		$cents    = (int) round( abs( $amount ) * 100 );
		$whole    = intdiv( $cents, 100 );
		$fraction = $cents % 100;

		if ( 'INR' === $currency ) {
			$spoken = $whole . ' ' . ( 1 === $whole ? 'rupee' : 'rupees' );
			if ( $fraction > 0 ) {
				$spoken .= ' ' . $fraction . ' ' . ( 1 === $fraction ? 'paisa' : 'paise' );
			}
			return $prefix . $spoken;
		}

		$number = $fraction > 0 ? $whole . '.' . str_pad( (string) $fraction, 2, '0', STR_PAD_LEFT ) : (string) $whole;
		if ( 'USD' === $currency ) {
			return $prefix . $number . ' ' . ( '1' === $number ? 'dollar' : 'dollars' );
		}
		return $prefix . $number . ' ' . $currency;
	}

	/**
	 * Spell a code character by character so the agent reads it clearly: "A1B2" -> "A 1 B 2".
	 *
	 * @param string $s Code.
	 * @return string
	 */
	public static function spell_out( $s ) {
		$s = is_scalar( $s ) ? preg_replace( '/\s+/u', '', (string) $s ) : '';
		if ( null === $s || '' === $s ) {
			return '';
		}
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			$chars = str_split( $s );
		}
		return implode( ' ', $chars );
	}

	/**
	 * "Cotton Kurta Set (quantity 1), Silver Jhumkas (quantity 2) and 2 more items".
	 *
	 * @param WC_Order $order Order.
	 * @param int      $max   Maximum number of line items to name.
	 * @return string
	 */
	public static function order_items_summary( $order, $max = 4 ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$max   = max( 1, (int) $max );
		$parts = array();
		$items = $order->get_items();
		foreach ( $items as $item ) {
			if ( count( $parts ) >= $max ) {
				break;
			}
			$name = wp_strip_all_tags( html_entity_decode( (string) $item->get_name(), ENT_QUOTES, 'UTF-8' ) );
			/* translators: 1: product name, 2: quantity. */
			$parts[] = sprintf( __( '%1$s (quantity %2$s)', 'voicepress' ), $name, wc_stock_amount( $item->get_quantity() ) );
		}
		$summary = implode( ', ', $parts );
		$more    = count( $items ) - count( $parts );
		if ( $more > 0 ) {
			/* translators: %d: number of further items in the order. */
			$summary .= ' ' . sprintf( _n( 'and %d more item', 'and %d more items', $more, 'voicepress' ), $more );
		}
		return $summary;
	}

	/**
	 * Everything the voice agents need to know about an order. ALL values are strings.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order_context( $order ) {
		$context = array_fill_keys(
			array( 'order_id', 'customer_name', 'customer_full_name', 'items', 'order_total', 'payment_method', 'is_cod', 'order_status', 'order_date', 'delivery_address', 'delivery_city', 'expected_delivery', 'delivered_on', 'phone' ),
			''
		);
		$context['customer_name'] = 'there';
		$context['is_cod']        = 'no';
		if ( ! $order instanceof WC_Order ) {
			return $context;
		}

		$first = self::plain( $order->get_billing_first_name() );
		if ( '' === $first ) {
			$first = self::plain( $order->get_shipping_first_name() );
		}
		$full = trim( self::plain( $order->get_billing_first_name() ) . ' ' . self::plain( $order->get_billing_last_name() ) );
		if ( '' === $full ) {
			$full = trim( self::plain( $order->get_shipping_first_name() ) . ' ' . self::plain( $order->get_shipping_last_name() ) );
		}

		$address_1 = self::plain( $order->get_shipping_address_1() );
		$city      = self::plain( $order->get_shipping_city() );
		if ( '' === $address_1 && '' === $city ) {
			$address_1 = self::plain( $order->get_billing_address_1() );
			$city      = self::plain( $order->get_billing_city() );
		}
		if ( '' === $city ) {
			$city = self::plain( $order->get_billing_city() );
		}

		$payment_title = self::plain( $order->get_payment_method_title() );
		if ( '' === $payment_title ) {
			$payment_title = 'cod' === $order->get_payment_method() ? __( 'Cash on delivery', 'voicepress' ) : self::plain( $order->get_payment_method() );
		}

		$phone = (string) $order->get_billing_phone();
		if ( '' === trim( $phone ) && is_callable( array( $order, 'get_shipping_phone' ) ) ) {
			$phone = (string) $order->get_shipping_phone();
		}

		$context['order_id']           = (string) $order->get_order_number();
		$context['customer_name']      = '' !== $first ? $first : 'there';
		$context['customer_full_name'] = $full;
		$context['items']              = self::order_items_summary( $order );
		$context['order_total']        = self::speak_amount( $order->get_total(), $order->get_currency() );
		$context['payment_method']     = $payment_title;
		$context['is_cod']             = 'cod' === $order->get_payment_method() ? 'yes' : 'no';
		$context['order_status']       = function_exists( 'wc_get_order_status_name' ) ? self::plain( wc_get_order_status_name( $order->get_status() ) ) : (string) $order->get_status();
		$context['order_date']         = self::format_wc_date( $order->get_date_created() );
		$context['delivery_address']   = implode( ', ', array_filter( array( $address_1, $city ), 'strlen' ) );
		$context['delivery_city']      = $city;
		$context['expected_delivery']  = class_exists( 'VoicePress_Settings' ) ? (string) VoicePress_Settings::get( 'delivery_eta', '' ) : '';
		$context['delivered_on']       = self::format_wc_date( $order->get_date_completed() );
		$context['phone']              = trim( $phone );

		return $context;
	}

	/**
	 * Store facts the agents use in every conversation. ALL values are strings.
	 *
	 * @return array
	 */
	public static function store_context() {
		$map     = array(
			'store_name'         => 'store_name',
			'agent_name'         => 'agent_persona',
			'return_window_days' => 'return_window_days',
			'delivery_eta'       => 'delivery_eta',
			'support_hours'      => 'support_hours',
			'store_policies'     => 'store_policies',
		);
		$context = array();
		foreach ( $map as $key => $setting ) {
			$value           = class_exists( 'VoicePress_Settings' ) ? VoicePress_Settings::get( $setting, '' ) : '';
			$context[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $context;
	}

	/**
	 * Find a caller's orders by phone number, newest first.
	 *
	 * @param string $phone Phone number in any format.
	 * @param int    $limit Maximum number of orders.
	 * @return WC_Order[]
	 */
	public static function find_orders_by_phone( $phone, $limit = 5 ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! is_scalar( $phone ) ) {
			return array();
		}
		$limit = max( 1, (int) $limit );
		$tail  = self::phone_tail( $phone, 10 );
		if ( strlen( $tail ) < 7 ) {
			return array();
		}
		$e164     = self::normalize_phone( $phone );
		$variants = array_unique( array_filter( array( $e164, ltrim( $e164, '+' ), $tail, '0' . $tail, trim( (string) $phone ) ), 'strlen' ) );

		$found = array();
		try {
			foreach ( $variants as $variant ) {
				$orders = wc_get_orders(
					array(
						'billing_phone' => $variant,
						'limit'         => $limit,
						'orderby'       => 'date',
						'order'         => 'DESC',
						'type'          => 'shop_order',
					)
				);
				foreach ( (array) $orders as $order ) {
					if ( self::is_real_order( $order ) ) {
						$found[ $order->get_id() ] = $order;
					}
				}
			}

			if ( empty( $found ) ) {
				// Numbers are stored in many formats ("+91 98765 43210", "098765-43210"): scan recent orders.
				$recent = wc_get_orders(
					array(
						'limit'   => 200,
						'orderby' => 'date',
						'order'   => 'DESC',
						'type'    => 'shop_order',
					)
				);
				foreach ( (array) $recent as $order ) {
					if ( ! self::is_real_order( $order ) ) {
						continue;
					}
					$shipping_phone = is_callable( array( $order, 'get_shipping_phone' ) ) ? $order->get_shipping_phone() : '';
					if ( self::phones_match( $order->get_billing_phone(), $phone ) || self::phones_match( $shipping_phone, $phone ) ) {
						$found[ $order->get_id() ] = $order;
						if ( count( $found ) >= $limit ) {
							break;
						}
					}
				}
			}
		} catch ( Throwable $e ) {
			self::log( 'find_orders_by_phone failed: ' . $e->getMessage() );
			return array();
		}

		uasort(
			$found,
			static function ( $a, $b ) {
				$ta = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
				$tb = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
				if ( $ta === $tb ) {
					return $b->get_id() - $a->get_id();
				}
				return $tb - $ta;
			}
		);
		return array_slice( array_values( $found ), 0, $limit );
	}

	/**
	 * What the customer is allowed to do with this order right now.
	 *
	 * @param WC_Order $order Order.
	 * @return array { can_cancel, can_reschedule, can_update_address, can_return } (bools)
	 */
	public static function allowed_actions( $order ) {
		$actions = array(
			'can_cancel'         => false,
			'can_reschedule'     => false,
			'can_update_address' => false,
			'can_return'         => false,
		);
		if ( ! $order instanceof WC_Order ) {
			return $actions;
		}
		$status = (string) $order->get_status();
		$open   = array( 'pending', 'on-hold', 'processing' );

		$actions['can_cancel']         = in_array( $status, $open, true );
		$actions['can_reschedule']     = ! in_array( $status, array( 'completed', 'cancelled', 'refunded', 'failed' ), true );
		$actions['can_update_address'] = in_array( $status, $open, true );

		if ( 'completed' === $status ) {
			$completed = $order->get_date_completed();
			if ( ! $completed ) {
				$completed = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
			}
			$days                  = class_exists( 'VoicePress_Settings' ) ? (int) VoicePress_Settings::get( 'return_window_days', 7 ) : 7;
			$actions['can_return'] = $completed && ( time() - $completed->getTimestamp() ) <= max( 0, $days ) * DAY_IN_SECONDS;
		}
		return $actions;
	}

	/**
	 * "1m 49s", "45s", "1h 5m"; '—' when unknown.
	 *
	 * @param int|float|null $seconds Duration in seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		if ( null === $seconds || '' === $seconds || ! is_numeric( $seconds ) ) {
			return '—';
		}
		$total = max( 0, (int) round( (float) $seconds ) );
		if ( $total < 60 ) {
			return $total . 's';
		}
		$hours   = intdiv( $total, 3600 );
		$minutes = intdiv( $total % 3600, 60 );
		$secs    = $total % 60;
		if ( $hours > 0 ) {
			return $hours . 'h ' . $minutes . 'm';
		}
		return $secs > 0 ? $minutes . 'm ' . $secs . 's' : $minutes . 'm';
	}

	/**
	 * Compact relative time for a UTC MySQL datetime: "just now", "5 min ago", "2 hrs ago", "3 days ago", "in 10 min";
	 * older than 30 days → local date ("2 Sep").
	 *
	 * @param string $mysql_gmt UTC datetime 'Y-m-d H:i:s'.
	 * @return string
	 */
	public static function human_time( $mysql_gmt ) {
		$timestamp = self::gmt_timestamp( $mysql_gmt );
		if ( ! $timestamp ) {
			return '';
		}
		$diff    = time() - $timestamp;
		$seconds = abs( $diff );
		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'just now', 'voicepress' );
		}
		if ( $seconds >= 30 * DAY_IN_SECONDS ) {
			return wp_date( 'j M', $timestamp );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$n = max( 1, (int) round( $seconds / MINUTE_IN_SECONDS ) );
			/* translators: %d: number of minutes. */
			$span = sprintf( _n( '%d min', '%d min', $n, 'voicepress' ), $n );
		} elseif ( $seconds < DAY_IN_SECONDS ) {
			$n = max( 1, (int) round( $seconds / HOUR_IN_SECONDS ) );
			/* translators: %d: number of hours. */
			$span = sprintf( _n( '%d hr', '%d hrs', $n, 'voicepress' ), $n );
		} else {
			$n = max( 1, (int) round( $seconds / DAY_IN_SECONDS ) );
			/* translators: %d: number of days. */
			$span = sprintf( _n( '%d day', '%d days', $n, 'voicepress' ), $n );
		}
		/* translators: %s: time span, e.g. "5 min". */
		return $diff >= 0 ? sprintf( __( '%s ago', 'voicepress' ), $span ) : sprintf( __( 'in %s', 'voicepress' ), $span );
	}

	/**
	 * Debug log (only when WP_DEBUG is on). Secrets in the context are redacted.
	 *
	 * @param string $msg Message.
	 * @param array  $ctx Extra context, JSON-encoded.
	 * @return void
	 */
	public static function log( $msg, $ctx = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$line = '[VoicePress] ' . ( is_scalar( $msg ) ? (string) $msg : wp_json_encode( $msg ) );
		if ( ! empty( $ctx ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $ctx ) );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Decode a JSON string (or pass through an array/object) into an array. Never fails.
	 *
	 * @param mixed $s JSON string, array or object.
	 * @return array
	 */
	public static function json_decode_array( $s ) {
		if ( is_array( $s ) ) {
			return $s;
		}
		if ( is_object( $s ) ) {
			$s = wp_json_encode( $s );
		}
		if ( ! is_string( $s ) || '' === trim( $s ) ) {
			return array();
		}
		$decoded = json_decode( $s, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Is this a real (non-refund, non-draft) order object?
	 *
	 * @param mixed $order Candidate.
	 * @return bool
	 */
	private static function is_real_order( $order ) {
		return $order instanceof WC_Order && 'checkout-draft' !== $order->get_status();
	}

	/**
	 * 'Friday, 31 October' in the site timezone/locale, or '' when there is no date.
	 *
	 * @param WC_DateTime|null $date Date.
	 * @return string
	 */
	private static function format_wc_date( $date ) {
		if ( ! $date instanceof WC_DateTime ) {
			return '';
		}
		return (string) $date->date_i18n( 'l, j F' );
	}

	/**
	 * Plain text for speech: no tags, no HTML entities, trimmed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function plain( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Unix timestamp for a UTC 'Y-m-d H:i:s' string, 0 when empty/invalid.
	 *
	 * @param string $mysql_gmt Datetime.
	 * @return int
	 */
	private static function gmt_timestamp( $mysql_gmt ) {
		if ( ! is_string( $mysql_gmt ) || '' === $mysql_gmt || 0 === strpos( $mysql_gmt, '0000-00-00' ) ) {
			return 0;
		}
		$timestamp = strtotime( $mysql_gmt . ' UTC' );
		return false === $timestamp ? 0 : (int) $timestamp;
	}

	/**
	 * Replace secret-looking values before logging.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/api_?key|secret|token|authorization|password|sip_password/i', $key ) ) {
				$data[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}
}
