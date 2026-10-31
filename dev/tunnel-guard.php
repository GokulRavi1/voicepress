<?php
/**
 * Plugin Name: VoicePress dev tunnel guard
 * Description: Local-dev safety net. Requests arriving through the public Cloudflare tunnel may only reach the
 *              VoicePress endpoints Bolna calls (token-protected tools + webhook). Everything else (wp-admin,
 *              wp-login.php, xmlrpc.php, the storefront) stays reachable from localhost only.
 *
 * Installed by dev/setup.sh into wp-content/mu-plugins/. Not part of the VoicePress plugin itself.
 */

if ( empty( $_SERVER['HTTP_CF_RAY'] ) && empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
	return; // Not a tunnel request.
}

$voicepress_route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : ''; // phpcs:ignore WordPress.Security.NonceVerification
$voicepress_path  = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );

$voicepress_allowed = false;
foreach ( array( $voicepress_route, preg_replace( '#^/wp-json#', '', $voicepress_path ) ) as $voicepress_candidate ) {
	if ( 0 === strpos( $voicepress_candidate, '/voicepress/v1/tools/' ) || '/voicepress/v1/webhook' === rtrim( $voicepress_candidate, '/' ) ) {
		$voicepress_allowed = true;
	}
}

if ( ! $voicepress_allowed ) {
	header( 'Content-Type: text/plain; charset=utf-8', true, 403 );
	exit( 'Forbidden: only VoicePress agent endpoints are exposed through this tunnel.' );
}
