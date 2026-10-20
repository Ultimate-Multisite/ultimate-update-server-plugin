<?php
/**
 * Regression checks for release notification scheduling from direct product-meta updates.
 *
 * @package WP_Update_Server_Plugin
 */

declare(strict_types=1);

namespace {

	define('ABSPATH', __DIR__ . '/');

	$GLOBALS['wu_actions']          = [];
	$GLOBALS['wu_filters']          = [];
	$GLOBALS['wu_scheduled_actions'] = [];
	$GLOBALS['wu_product_meta']     = [];

	function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
		$GLOBALS['wu_actions'][$hook][] = [$callback, $priority, $accepted_args];
	}

	function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
		$GLOBALS['wu_filters'][$hook][] = [$callback, $priority, $accepted_args];
	}

	function get_post_meta(int $product_id, string $meta_key, bool $single = false) {
		return $GLOBALS['wu_product_meta'][$product_id][$meta_key] ?? [];
	}

	function wc_get_product(int $product_id) {
		return $GLOBALS['wu_products'][$product_id] ?? null;
	}

	function as_schedule_single_action(int $timestamp, string $hook, array $args, string $group): void {
		$GLOBALS['wu_scheduled_actions'][] = compact('timestamp', 'hook', 'args', 'group');
	}

	class WC_Product_Download {

		private $name;

		public function __construct(string $name) {
			$this->name = $name;
		}

		public function get_name(): string {
			return $this->name;
		}
	}

	class WC_Product {

		private $id;
		private $downloads;

		public function __construct(int $id, array $downloads) {
			$this->id        = $id;
			$this->downloads = $downloads;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function is_downloadable(): bool {
			return true;
		}

		public function get_downloads(): array {
			return $this->downloads;
		}
	}
}

namespace WP_Update_Server_Plugin {

	class Product_Versions {

		public static function is_prerelease(string $version): bool {
			return false;
		}

		public static function clear_cache(int $product_id): void {
		}

		public static function get_all_versions_by_product_id(int $product_id): array {
			return [];
		}

		public static function schedule_permission_backfill(int $product_id): void {
			$GLOBALS['wu_permission_backfills'][] = $product_id;
		}
	}

	require_once dirname(__DIR__) . '/inc/class-release-notifier.php';
}

namespace {

	function assert_true(bool $condition, string $message): void {
		if ( ! $condition) {
			throw new RuntimeException($message);
		}
	}

	$product_id = 8049;
	$old_id     = 'old-download';
	$new_id     = 'new-download';

	$GLOBALS['wu_product_meta'][$product_id]['_downloadable_files'] = [$old_id => ['name' => 'Ultimate Multisite: WooCommerce Integration v2.2.3']];
	$GLOBALS['wu_products'][$product_id] = new WC_Product(
		$product_id,
		[
			$old_id => new WC_Product_Download('Ultimate Multisite: WooCommerce Integration v2.2.3'),
			$new_id => new WC_Product_Download('Ultimate Multisite: WooCommerce Integration v2.2.4'),
		]
	);

	$notifier = new \WP_Update_Server_Plugin\Release_Notifier();

	assert_true(isset($GLOBALS['wu_filters']['update_post_metadata']), 'direct product-meta updates must capture the previous download list');
	assert_true(isset($GLOBALS['wu_actions']['updated_post_meta']), 'direct product-meta updates must schedule release notifications');

	$notifier->store_previous_downloads_before_meta_update(null, $product_id, '_downloadable_files', [], '');
	$GLOBALS['wu_product_meta'][$product_id]['_downloadable_files'][$new_id] = ['name' => 'Ultimate Multisite: WooCommerce Integration v2.2.4'];
	$notifier->detect_new_downloads_from_meta(1, $product_id, '_downloadable_files', $GLOBALS['wu_product_meta'][$product_id]['_downloadable_files']);

	assert_true(1 === count($GLOBALS['wu_scheduled_actions']), 'a direct download update must schedule exactly one release batch');
	assert_true('2.2.4' === $GLOBALS['wu_scheduled_actions'][0]['args']['version'], 'the release version must be extracted from a v-prefixed download name');
	assert_true('wu-release-notifications' === $GLOBALS['wu_scheduled_actions'][0]['group'], 'the release batch must use the release notification Action Scheduler group');
	assert_true([$product_id] === ($GLOBALS['wu_permission_backfills'] ?? []), 'a direct download update must grant the new files to existing buyers');

	$notifier->detect_new_downloads($product_id, 0, $GLOBALS['wu_product_meta'][$product_id]['_downloadable_files']);
	assert_true(1 === count($GLOBALS['wu_scheduled_actions']), 'WooCommerce and direct-meta hooks must not schedule duplicate batches');

	fwrite(STDOUT, "Release notifier direct-download regression checks passed.\n");
}
