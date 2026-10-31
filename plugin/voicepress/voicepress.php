<?php
/**
 * Plugin Name:          VoicePress – Voice AI Agents for WooCommerce
 * Description:          AI phone & web voice agents for WooCommerce stores, powered by Bolna: COD confirmation, delivery updates, cart recovery, feedback and 24x7 order support.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * WC requires at least: 8.0
 * Text Domain:          voicepress
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

define( 'VOICEPRESS_VERSION', '0.1.0' );
define( 'VOICEPRESS_FILE', __FILE__ );
define( 'VOICEPRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'VOICEPRESS_URL', plugin_dir_url( __FILE__ ) );
define( 'VOICEPRESS_REST_NS', 'voicepress/v1' );

/*
 * Modules, in dependency order. A missing file never takes the site down: it is reported in wp-admin instead.
 * ($GLOBALS because WP-CLI and the activation sandbox include plugins from inside a function.)
 */
$GLOBALS['voicepress_missing_files'] = array();
foreach ( array( 'helpers', 'bolna-client', 'settings', 'db', 'agents', 'calls', 'automations', 'rest', 'admin', 'frontend' ) as $voicepress_module ) {
	$voicepress_module_file = VOICEPRESS_DIR . 'includes/class-voicepress-' . $voicepress_module . '.php';
	if ( is_readable( $voicepress_module_file ) ) {
		require_once $voicepress_module_file;
	} else {
		$GLOBALS['voicepress_missing_files'][] = 'includes/class-voicepress-' . $voicepress_module . '.php';
	}
}
unset( $voicepress_module, $voicepress_module_file );

/**
 * Activation: create tables, default settings (incl. the webhook/tool secret) and the cron tick.
 *
 * @return void
 */
function voicepress_activate() {
	if ( class_exists( 'VoicePress_DB' ) ) {
		VoicePress_DB::install();
	}
	if ( class_exists( 'VoicePress_Settings' ) ) {
		VoicePress_Settings::ensure_defaults();
	}
	if ( class_exists( 'VoicePress_Automations' ) && method_exists( 'VoicePress_Automations', 'schedule_cron' ) ) {
		VoicePress_Automations::schedule_cron();
	}
}
register_activation_hook( __FILE__, 'voicepress_activate' );

/**
 * Deactivation: stop the cron tick.
 *
 * @return void
 */
function voicepress_deactivate() {
	wp_clear_scheduled_hook( 'voicepress_tick' );
}
register_deactivation_hook( __FILE__, 'voicepress_deactivate' );

/**
 * Declare compatibility with WooCommerce HPOS (custom order tables) and the Cart/Checkout blocks.
 *
 * @return void
 */
function voicepress_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VOICEPRESS_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VOICEPRESS_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'voicepress_declare_wc_compatibility' );

/**
 * Boot: upgrade the schema if needed, then let every module register its hooks.
 *
 * @return void
 */
function voicepress_boot() {
	if ( class_exists( 'VoicePress_DB' ) ) {
		VoicePress_DB::maybe_upgrade();
	}
	foreach ( array( 'VoicePress_Settings', 'VoicePress_Agents', 'VoicePress_Calls', 'VoicePress_Automations', 'VoicePress_REST', 'VoicePress_Admin', 'VoicePress_Frontend' ) as $class ) {
		if ( 'VoicePress_Admin' === $class && ! is_admin() ) {
			continue;
		}
		if ( class_exists( $class ) && method_exists( $class, 'init' ) ) {
			call_user_func( array( $class, 'init' ) );
		}
	}
}
add_action( 'plugins_loaded', 'voicepress_boot' );

/**
 * "Settings" link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function voicepress_plugin_action_links( $links ) {
	$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=voicepress-settings' ) ) . '">' . esc_html__( 'Settings', 'voicepress' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'voicepress_plugin_action_links' );

/**
 * Admin notices for problems that would otherwise fail silently: WooCommerce inactive, plugin files missing.
 *
 * @return void
 */
function voicepress_environment_notices() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$missing = isset( $GLOBALS['voicepress_missing_files'] ) ? (array) $GLOBALS['voicepress_missing_files'] : array();
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p><strong>VoicePress</strong> &mdash; ' . esc_html__( 'WooCommerce must be installed and active for the voice agents to work with your orders.', 'voicepress' ) . '</p></div>';
	}
	if ( ! empty( $missing ) ) {
		echo '<div class="notice notice-error"><p><strong>VoicePress</strong> &mdash; ' . esc_html__( 'Some plugin files are missing. Please reinstall the plugin:', 'voicepress' ) . ' <code>' . esc_html( implode( ', ', $missing ) ) . '</code></p></div>';
	}
}
add_action( 'admin_notices', 'voicepress_environment_notices' );
