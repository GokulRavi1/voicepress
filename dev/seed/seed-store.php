<?php
/**
 * Seeds the "ShopEase" demo store: India/INR settings, COD + prepaid gateways, free shipping,
 * products with images and a few historical orders.
 *
 * Run: php wp-cli.phar --path=<wordpress> eval-file dev/seed/seed-store.php
 * Sample orders use dummy +91 00000 1000X numbers on purpose so no real person is ever called.
 */

defined( 'ABSPATH' ) || exit;

$options = array(
	'blogname'                                 => 'ShopEase',
	'blogdescription'                          => 'Everyday essentials, delivered across India',
	'timezone_string'                          => 'Asia/Kolkata',
	'woocommerce_default_country'              => 'IN:KA',
	'woocommerce_currency'                     => 'INR',
	'woocommerce_store_address'                => '12, MG Road',
	'woocommerce_store_city'                   => 'Bengaluru',
	'woocommerce_store_postcode'               => '560001',
	'woocommerce_price_num_decimals'           => '0',
	'woocommerce_coming_soon'                  => 'no',
	'woocommerce_task_list_hidden'             => 'yes',
	'woocommerce_task_list_complete'           => 'yes',
	'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_allow_tracking'               => 'no',
	'woocommerce_checkout_phone_field'         => 'required',
	'woocommerce_enable_guest_checkout'        => 'yes',
	'woocommerce_calc_taxes'                   => 'no',
);
foreach ( $options as $key => $value ) {
	update_option( $key, $value );
}
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option(
	'woocommerce_cod_settings',
	array(
		'enabled'            => 'yes',
		'title'              => 'Cash on delivery',
		'description'        => 'Pay with cash or UPI when your order arrives.',
		'instructions'       => 'Pay with cash or UPI when your order arrives.',
		'enable_for_methods' => array(),
		'enable_for_virtual' => 'yes',
	)
);
update_option(
	'woocommerce_bacs_settings',
	array(
		'enabled'      => 'yes',
		'title'        => 'UPI / Bank transfer (prepaid)',
		'description'  => 'Pay now via UPI or bank transfer. Your order ships once payment is received.',
		'instructions' => 'Use your order number as the payment reference.',
	)
);

// Free shipping across India.
if ( empty( WC_Shipping_Zones::get_zones() ) ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'India' );
	$zone->set_zone_order( 1 );
	$zone->add_location( 'IN', 'country' );
	$zone->save();
	$zone->add_shipping_method( 'free_shipping' );
}

// Land visitors on the shop.
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', wc_get_page_id( 'shop' ) );

$category_ids = array();
foreach ( array( 'Ethnic Wear', 'Accessories', 'Footwear', 'Home & Kitchen', 'Beauty', 'Electronics' ) as $name ) {
	$term = term_exists( $name, 'product_cat' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'product_cat' );
	}
	$category_ids[ $name ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
}

$products = array(
	array( 'cotton-kurta-set', 'Cotton Kurta Set', 1299, 899, 'Ethnic Wear', 'Breathable pure-cotton kurta with palazzo, hand block printed in Jaipur.' ),
	array( 'silver-jhumka-earrings', 'Silver Jhumka Earrings', 699, 499, 'Accessories', 'Oxidised silver jhumkas with a lightweight, everyday-wear design.' ),
	array( 'ultralight-running-shoes', 'Ultralight Running Shoes', 2999, 1999, 'Footwear', 'Cushioned, breathable running shoes built for Indian roads.' ),
	array( 'steel-water-bottle-pack-of-2', 'Steel Water Bottle (Pack of 2)', 799, 599, 'Home & Kitchen', 'Leak-proof 1 litre stainless steel bottles that keep water cold for 24 hours.' ),
	array( 'vitamin-c-face-serum', 'Vitamin C Face Serum', 599, 449, 'Beauty', '10% Vitamin C serum for brighter, even-toned skin. Dermatologically tested.' ),
	array( 'non-stick-cookware-set', 'Non-stick Cookware Set', 3499, 2499, 'Home & Kitchen', 'Induction-friendly 3-piece cookware set with a toxin-free non-stick coating.' ),
	array( 'banarasi-silk-saree', 'Banarasi Silk Saree', 3999, 2999, 'Ethnic Wear', 'Handwoven Banarasi silk saree with zari border and an unstitched blouse piece.' ),
	array( 'wireless-earbuds-pro', 'Wireless Earbuds Pro', 2499, 1299, 'Electronics', 'Active noise cancellation, 30 hour battery and fast charging.' ),
);

require_once ABSPATH . 'wp-admin/includes/image.php';
$image_dir   = dirname( __FILE__ ) . '/images/';
$product_ids = array();
foreach ( $products as $p ) {
	list( $slug, $name, $regular, $sale, $category, $short ) = $p;
	$existing = get_page_by_path( $slug, OBJECT, 'product' );
	if ( $existing ) {
		$product_ids[ $slug ] = $existing->ID;
		continue;
	}
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_regular_price( (string) $regular );
	$product->set_sale_price( (string) $sale );
	$product->set_short_description( $short );
	$product->set_description( $short . ' Free delivery across India, cash on delivery available and easy 7 day returns.' );
	$product->set_category_ids( array( $category_ids[ $category ] ) );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 25 );
	$product->set_status( 'publish' );
	$id = $product->save();

	$file = $image_dir . $slug . '.png';
	if ( file_exists( $file ) ) {
		$upload = wp_upload_bits( $slug . '.png', null, file_get_contents( $file ) );
		if ( empty( $upload['error'] ) ) {
			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => 'image/png',
					'post_title'     => $name,
					'post_status'    => 'inherit',
				),
				$upload['file'],
				$id
			);
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
			$product->set_image_id( $attachment_id );
			$product->save();
		}
	}
	$product_ids[ $slug ] = $id;
}

// Historical orders so the dashboard and order list are not empty.
if ( ! get_option( 'shopease_seeded_orders' ) ) {
	$orders = array(
		array( 'Asha', 'Menon', '+91 00000 10001', 'Panampilly Nagar', 'Kochi', 'KL', '682036', 'cotton-kurta-set', 1, 'cod', 'processing', 1 ),
		array( 'Rahul', 'Verma', '+91 00000 10002', 'Koregaon Park', 'Pune', 'MH', '411001', 'ultralight-running-shoes', 1, 'bacs', 'completed', 4 ),
		array( 'Priya', 'Nair', '+91 00000 10003', 'Adyar', 'Chennai', 'TN', '600020', 'wireless-earbuds-pro', 1, 'cod', 'on-hold', 2 ),
		array( 'Arjun', 'Singh', '+91 00000 10004', 'Lajpat Nagar', 'New Delhi', 'DL', '110024', 'non-stick-cookware-set', 1, 'bacs', 'processing', 3 ),
	);
	foreach ( $orders as $o ) {
		list( $first, $last, $phone, $street, $city, $state, $pin, $slug, $qty, $method, $status, $days_ago ) = $o;
		$order   = wc_create_order();
		$address = array(
			'first_name' => $first,
			'last_name'  => $last,
			'email'      => strtolower( $first ) . '@example.com',
			'phone'      => $phone,
			'address_1'  => $street,
			'city'       => $city,
			'state'      => $state,
			'postcode'   => $pin,
			'country'    => 'IN',
		);
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );
		$order->add_product( wc_get_product( $product_ids[ $slug ] ), $qty );
		$gateways = WC()->payment_gateways()->payment_gateways();
		$order->set_payment_method( isset( $gateways[ $method ] ) ? $gateways[ $method ] : $method );
		$order->calculate_totals();
		$order->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		$order->set_status( $status );
		if ( 'completed' === $status ) {
			$order->set_date_completed( time() - ( $days_ago - 2 ) * DAY_IN_SECONDS );
		}
		$order->save();
	}
	update_option( 'shopease_seeded_orders', 1 );
}

WP_CLI::success( sprintf( 'Store seeded: %d products, shop is the front page.', count( $product_ids ) ) );
