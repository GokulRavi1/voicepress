<?php
/**
 * VoicePress example: confirm a cash-on-delivery order with an AI phone call from ANY PHP shop.
 *
 * No WordPress needed: the only dependency is includes/class-voicepress-bolna-client.php
 * (outside WordPress it uses cURL and throws RuntimeException on errors).
 *
 * Run it from a terminal (never hard-code your API key — read it from the environment):
 *
 *   export BOLNA_API_KEY="bn-..."                 # Bolna dashboard → Developers → API keys
 *   export BOLNA_AGENT_ID="<agent id>"            # e.g. the "COD confirmation" agent VoicePress created
 *   export BOLNA_FROM_NUMBER="+1..."              # optional: a number from your Bolna account
 *   php examples/plain-php-order-call.php +919876543210 --wait
 *
 *   PowerShell: $env:BOLNA_API_KEY = "bn-..."; $env:BOLNA_AGENT_ID = "..."; php examples\plain-php-order-call.php +919876543210
 *
 * In your own shop, call voicepress_example_confirm_order( $bolna, $agent_id, $order ) right after checkout,
 * store the returned execution_id on the order, and read the outcome later with $bolna->get_execution().
 *
 * @package VoicePress
 */

// Command-line example only: a web request to this file must never be able to place calls.
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

require_once dirname( __DIR__ ) . '/includes/class-voicepress-bolna-client.php';

/**
 * Variables for the agent prompt ({{customer_name}}, {{order_total}} …). All values are strings, written the way
 * the agent should SAY them. These match the VoicePress "COD confirmation" agent, so it works unchanged.
 *
 * @param array $order Your order.
 * @return array
 */
function voicepress_example_user_data( array $order ) {
	$items = array();
	foreach ( $order['items'] as $item ) {
		$items[] = $item['name'] . ' (quantity ' . (int) $item['qty'] . ')';
	}
	$cents  = (int) round( $order['total'] * 100 );
	$rupees = intdiv( $cents, 100 );
	$paise  = $cents % 100;

	return array(
		'customer_name'     => $order['first_name'],
		'store_name'        => $order['store_name'],
		'agent_name'        => 'Riya',
		'call_reason_text'  => 'to confirm your cash on delivery order before we ship it',
		'order_id'          => $order['number'],
		'items'             => implode( ', ', $items ),
		'order_total'       => $rupees . ' rupees' . ( $paise > 0 ? ' ' . $paise . ' paise' : '' ),
		'payment_method'    => $order['payment_method'],
		'delivery_address'  => $order['address'],
		'expected_delivery' => '3 to 5 working days',
	);
}

/**
 * Place the confirmation call for one order.
 *
 * @param VoicePress_Bolna_Client $bolna    Client.
 * @param string                  $agent_id Bolna agent id.
 * @param array                   $order    Your order.
 * @param string                  $from     Optional caller id from your Bolna account.
 * @return string Execution id — save it on the order.
 * @throws RuntimeException When Bolna rejects the call (bad key, invalid number, low balance …).
 */
function voicepress_example_confirm_order( VoicePress_Bolna_Client $bolna, $agent_id, array $order, $from = '' ) {
	$call = $bolna->make_call( $agent_id, $order['phone'], voicepress_example_user_data( $order ), $from );
	return isset( $call['execution_id'] ) ? (string) $call['execution_id'] : '';
}

/**
 * The customer's decision, read from the "Order Decision" extraction of a finished call.
 *
 * @param array $execution Execution from $bolna->get_execution().
 * @return string confirmed|cancelled|reschedule|address_change|wants_prepaid|no_decision|unknown
 */
function voicepress_example_decision( array $execution ) {
	$extracted = isset( $execution['extracted_data'] ) && is_array( $execution['extracted_data'] ) ? $execution['extracted_data'] : array();
	foreach ( $extracted as $category ) {
		if ( is_array( $category ) && isset( $category['Order Decision']['objective'] ) && is_scalar( $category['Order Decision']['objective'] ) ) {
			return (string) $category['Order Decision']['objective'];
		}
	}
	return 'unknown';
}

// ---------------------------------------------------------------------------------------------------------------

$api_key  = (string) getenv( 'BOLNA_API_KEY' );
$agent_id = (string) getenv( 'BOLNA_AGENT_ID' );
$from     = (string) getenv( 'BOLNA_FROM_NUMBER' );
$phone    = isset( $argv[1] ) ? trim( $argv[1] ) : '';
$wait     = in_array( '--wait', $argv, true );

if ( '' === $api_key || '' === $agent_id ) {
	fwrite( STDERR, "Set the BOLNA_API_KEY and BOLNA_AGENT_ID environment variables first.\n" );
	exit( 1 );
}
if ( ! preg_match( '/^\+[1-9]\d{7,14}$/', $phone ) ) {
	fwrite( STDERR, "Usage: php examples/plain-php-order-call.php +919876543210 [--wait]\n(the customer's phone number in E.164 format)\n" );
	exit( 1 );
}

// Your shop's order, however you store it (database row, ORM model, array …).
$order = array(
	'number'         => 'A1042',
	'store_name'     => 'Demo Store',
	'first_name'     => 'Asha',
	'phone'          => $phone,
	'items'          => array(
		array(
			'name' => 'Cotton Kurta Set',
			'qty'  => 1,
		),
		array(
			'name' => 'Silver Jhumkas',
			'qty'  => 2,
		),
	),
	'total'          => 1299.50,
	'payment_method' => 'Cash on delivery',
	'address'        => '12 MG Road, Indiranagar, Bengaluru',
);

$base_url = getenv( 'BOLNA_BASE_URL' ) ? (string) getenv( 'BOLNA_BASE_URL' ) : VoicePress_Bolna_Client::DEFAULT_BASE_URL;
$bolna    = new VoicePress_Bolna_Client( $api_key, $base_url );

try {
	$execution_id = voicepress_example_confirm_order( $bolna, $agent_id, $order, $from );
	echo 'Calling ' . $order['first_name'] . ' about order ' . $order['number'] . ' (execution ' . $execution_id . ")\n";

	if ( ! $wait || '' === $execution_id ) {
		exit( 0 );
	}

	// Follow the call until it finishes (the extraction arrives a few seconds after the call ends).
	$terminal = array( 'completed', 'no-answer', 'busy', 'failed', 'canceled', 'stopped', 'error', 'balance-low' );
	$last     = '';
	for ( $i = 0; $i < 90; $i++ ) {
		sleep( 5 );
		$execution = $bolna->get_execution( $execution_id );
		$status    = isset( $execution['status'] ) ? (string) $execution['status'] : 'unknown';
		if ( $status !== $last ) {
			echo '  status: ' . $status . "\n";
			$last = $status;
		}
		if ( in_array( $status, $terminal, true ) ) {
			if ( ! empty( $execution['summary'] ) ) {
				echo "\nSummary: " . $execution['summary'] . "\n";
			}
			$decision = voicepress_example_decision( $execution );
			echo 'Decision: ' . $decision . "\n";
			// Now update your order: confirmed → ship it, cancelled → cancel it (and skip a costly RTO), etc.
			exit( 0 );
		}
	}
	echo "Still in progress — check again later with get_execution().\n";
} catch ( RuntimeException $e ) {
	fwrite( STDERR, 'Bolna error: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
