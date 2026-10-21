<?php
/*
Plugin Name: WP Update Server Plugin
Description: All the addon store stuff.
Version: 1.0.1
Author: David Stone
*/

if ( ! defined( 'ULTIMATE_UPDATE_SERVER_PLUGIN_VERSION' ) ) {
	define( 'ULTIMATE_UPDATE_SERVER_PLUGIN_VERSION', '1.0.1' );
}

define( 'WP_UPDATE_SERVER_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/vendor/yahnis-elsts/wp-update-server/loader.php';
require_once __DIR__ . '/inc/class-update-server.php';
require_once __DIR__ . '/inc/class-request-endpoint.php';
require_once __DIR__ . '/inc/class-product-icon.php';
require_once __DIR__ . '/inc/class-store-api.php';
require_once __DIR__ . '/inc/class-telemetry-table.php';
require_once __DIR__ . '/inc/class-telemetry-receiver.php';
require_once __DIR__ . '/inc/class-telemetry-admin.php';
require_once __DIR__ . '/inc/class-passive-installs-table.php';
require_once __DIR__ . '/inc/class-passive-install-tracker.php';
require_once __DIR__ . '/inc/class-site-discovery-table.php';
require_once __DIR__ . '/inc/class-site-discovery-scraper.php';
require_once __DIR__ . '/inc/class-site-discovery-admin.php';
require_once __DIR__ . '/inc/class-composer-token-table.php';
require_once __DIR__ . '/inc/class-composer-token.php';
require_once __DIR__ . '/inc/class-product-versions.php';
require_once __DIR__ . '/inc/class-composer-repository.php';
require_once __DIR__ . '/inc/class-downloads-page.php';
require_once __DIR__ . '/inc/class-changelog-manager.php';

$wp_update_server_plugin_request_endpoint = new \WP_Update_Server_Plugin\Request_Endpoint();
$wp_update_server_plugin_product_icon     = new \WP_Update_Server_Plugin\Product_Icon();
$wp_update_server_plugin_store_api        = new \WP_Update_Server_Plugin\Store_Api();

// Telemetry components
$wp_update_server_plugin_telemetry_table    = new \WP_Update_Server_Plugin\Telemetry_Table();
$wp_update_server_plugin_telemetry_receiver = new \WP_Update_Server_Plugin\Telemetry_Receiver();
$wp_update_server_plugin_telemetry_admin    = new \WP_Update_Server_Plugin\Telemetry_Admin();

// Passive install tracking components
$wp_update_server_plugin_passive_installs_table  = new \WP_Update_Server_Plugin\Passive_Installs_Table();
$wp_update_server_plugin_passive_install_tracker = new \WP_Update_Server_Plugin\Passive_Install_Tracker();

// Site discovery components (builds on passive install tracking from issue #3)
$wp_update_server_plugin_site_discovery_table   = new \WP_Update_Server_Plugin\Site_Discovery_Table();
$wp_update_server_plugin_site_discovery_scraper = new \WP_Update_Server_Plugin\Site_Discovery_Scraper();
$wp_update_server_plugin_site_discovery_admin   = new \WP_Update_Server_Plugin\Site_Discovery_Admin();

// Composer repository components
$wp_update_server_plugin_composer_token_table = new \WP_Update_Server_Plugin\Composer_Token_Table();
$wp_update_server_plugin_composer_repository  = new \WP_Update_Server_Plugin\Composer_Repository();
$wp_update_server_plugin_downloads_page       = new \WP_Update_Server_Plugin\Downloads_Page();

// Release notification components
$wp_update_server_plugin_changelog_manager = new \WP_Update_Server_Plugin\Changelog_Manager();

// PayPal Connect proxy and analytics (mirrors Stripe Connect proxy pattern)
require_once __DIR__ . '/inc/class-paypal-merchants-table.php';
require_once __DIR__ . '/inc/class-paypal-transaction-sync.php';
require_once __DIR__ . '/inc/class-paypal-connect.php';
$wp_update_server_plugin_paypal_merchants_table = new \WP_Update_Server_Plugin\PayPal_Merchants_Table();
$wp_update_server_plugin_paypal_transaction_sync = new \WP_Update_Server_Plugin\PayPal_Transaction_Sync();
$wp_update_server_plugin_paypal_connect          = new \WP_Update_Server_Plugin\PayPal_Connect();

// Stripe Connect analytics (issue #6)
require_once __DIR__ . '/inc/class-stripe-analytics-table.php';
require_once __DIR__ . '/inc/class-stripe-analytics.php';
require_once __DIR__ . '/inc/class-stripe-analytics-admin.php';
$wp_update_server_plugin_stripe_analytics_table = new \WP_Update_Server_Plugin\Stripe_Analytics_Table();
$wp_update_server_plugin_stripe_analytics       = new \WP_Update_Server_Plugin\Stripe_Analytics();
$wp_update_server_plugin_stripe_analytics_admin = new \WP_Update_Server_Plugin\Stripe_Analytics_Admin();

add_action('woocommerce_loaded', function () {
	require_once __DIR__ . '/inc/class-release-notifier.php';
	// Assign to a global so the object is not garbage-collected when the closure returns.
	// The Release_Notifier constructor registers hooks via [$this, 'method'] callbacks —
	// WordPress holds a reference to those, but assigning to a global makes the lifetime
	// explicit and avoids relying on that implementation detail.
	global $wp_update_server_plugin_release_notifier;
	$wp_update_server_plugin_release_notifier = new \WP_Update_Server_Plugin\Release_Notifier();

	add_action(
		\WP_Update_Server_Plugin\Product_Versions::BACKFILL_HOOK,
		function ($product_id) {
			\WP_Update_Server_Plugin\Product_Versions::backfill_product_permissions((int) $product_id);
		}
	);

	// Renewals must extend access to every file, not only files granted later.
	add_action(
		'woocommerce_subscription_renewal_payment_complete',
		[\WP_Update_Server_Plugin\Product_Versions::class, 'handle_subscription_renewal'],
		20,
		2
	);
});
