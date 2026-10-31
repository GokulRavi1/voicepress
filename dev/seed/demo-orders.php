<?php
/**
 * Adds demo orders for the presenter's own phone number, so test calls and the inbound
 * "where is my order?" demo have real data to talk about.
 *
 * Run: php wp-cli.phar --path=<wordpress> eval-file dev/seed/demo-orders.php "+91XXXXXXXXXX" "Name" [state]
 * Orders are created directly in "processing", so no VoicePress automation fires a call.
 */

defined( 'ABSPATH' ) || exit;

$phone = isset( $args[0] ) ? $args[0] : '';
$name  = isset( $args[1] ) ? $args[1] : 'Demo Customer';
$state = isset( $args[2] ) ? $args[2] : 'TN';
if ( '' === $phone ) {
	WP_CLI::error( 'Pass the phone number as the first argument.' );
}

$parts   = explode( ' ', $name, 2 );
$address = array(
	'first_name' => $parts[0],
	'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
	'email'      => 'demo@example.com',
	'phone'      => $phone,
	'address_1'  => '24, Anna Salai',
	'city'       => 'Chennai',
	'state'      => $state,
	'postcode'   => '600002',
	'country'    => 'IN',
);

$demo = array(
	array( 'cotton-kurta-set', 1, 'cod', 1 ),
	array( 'wireless-earbuds-pro', 1, 'bacs', 3 ),
);

$gateways = WC()->payment_gateways()->payment_gateways();
foreach ( $demo as $row ) {
	list( $slug, $qty, $method, $days_ago ) = $row;
	$product = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $product ) {
		continue;
	}
	$order = wc_create_order( array( 'status' => 'processing' ) );
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->add_product( wc_get_product( $product->ID ), $qty );
	$order->set_payment_method( isset( $gateways[ $method ] ) ? $gateways[ $method ] : $method );
	$order->calculate_totals();
	$order->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
	$order->save();
	WP_CLI::log( sprintf( 'Order #%d: %s, %s, %s', $order->get_id(), $product->post_title, $method, $order->get_total() ) );
}
WP_CLI::success( 'Demo orders created for ' . $phone );
