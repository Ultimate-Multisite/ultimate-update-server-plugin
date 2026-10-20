<?php
/**
 * Product Versions Handler
 *
 * Handles retrieval of all versions for downloadable products.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;

class Product_Versions {

	/**
	 * Cache group for version data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wu_product_versions';

	/**
	 * Cache expiration in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Remote archive inspection timeout, in seconds.
	 *
	 * @var int
	 */
	const REMOTE_ARCHIVE_TIMEOUT = 15;

	/**
	 * Maximum redirects allowed while fetching remote archives for inspection.
	 *
	 * @var int
	 */
	const REMOTE_ARCHIVE_REDIRECTION_LIMIT = 3;

	/**
	 * Maximum bytes to download when inspecting a remote archive.
	 *
	 * @var int
	 */
	const REMOTE_ARCHIVE_MAX_BYTES = 52428800;

	/**
	 * Cache expiration for successfully extracted remote archive metadata.
	 *
	 * @var int
	 */
	const REMOTE_VERSION_CACHE_EXPIRATION = DAY_IN_SECONDS;

	/**
	 * Cache expiration for failed remote archive inspections.
	 *
	 * @var int
	 */
	const REMOTE_VERSION_FAILURE_CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Get all versions of a product by SKU.
	 *
	 * @param string $sku The product SKU.
	 * @return array Array of version data: [['version' => '2.0.0', 'file_id' => 'abc', 'name' => '...'], ...]
	 */
	public static function get_all_versions(string $sku): array {

		$product_id = wc_get_product_id_by_sku($sku);

		if ( ! $product_id) {
			return [];
		}

		return self::get_all_versions_by_product_id($product_id);
	}

	/**
	 * Get all versions of a product by product ID.
	 *
	 * @param int $product_id The product ID.
	 * @return array Array of version data.
	 */
	public static function get_all_versions_by_product_id(int $product_id): array {

		$product = wc_get_product($product_id);

		if ( ! $product || ! $product->exists() || ! $product->is_downloadable()) {
			return [];
		}

		// Check persistent WordPress cache storage.
		$cache_key = 'versions_' . $product_id;
		$found     = false;
		$cached    = self::get_cached_value($cache_key, $found);

		if ($found && is_array($cached)) {
			return $cached;
		}

		$versions = [];
		$files    = $product->get_downloads();

		foreach ($files as $file_id => $file) {
			if ( ! $file->get_enabled()) {
				continue;
			}

			$version_info = self::extract_version_from_file($product, $file_id, $file);

			if ($version_info) {
				$versions[] = array_merge(
					$version_info,
					[
						'file_id' => $file_id,
						'name'    => $file->get_name(),
					]
				);
			}
		}

		// Sort by version descending (latest first)
		usort($versions, function ($a, $b) {
			return version_compare($b['version'], $a['version']);
		});

		// Cache the results, including an empty result, to avoid repeated inspection.
		self::set_cached_value($cache_key, $versions, self::CACHE_EXPIRATION);

		return $versions;
	}

	/**
	 * Extract version information from a download file.
	 *
	 * @param \WC_Product              $product The product.
	 * @param string                   $file_id The file ID.
	 * @param \WC_Product_Download     $file    The download file.
	 * @return array|null Version info or null if extraction failed.
	 */
	private static function extract_version_from_file(\WC_Product $product, string $file_id, \WC_Product_Download $file): ?array {

		$file_info = \WC_Download_Handler::parse_file_path($product->get_file_download_path($file_id));
		$filepath         = $file_info['file_path'];
		$tmp              = null;
		$remote_cache_key = null;

		// Handle remote files
		if ($file_info['remote_file']) {
			// Try to extract version from filename first to avoid downloading
			$version = self::extract_version_from_filename($file->get_name());

			if ($version) {
				return ['version' => $version];
			}

			$remote_cache_key = self::get_remote_version_cache_key(
				(int) $product->get_id(),
				$file_id,
				$file->get_name(),
				$filepath
			);
			$cache_found         = false;
			$cached_version_info = self::get_cached_remote_version_info($remote_cache_key, $cache_found);

			if ($cache_found) {
				return $cached_version_info;
			}

			// Download temporarily if we need to inspect the archive.
			$tmp = self::download_remote_archive_for_inspection($filepath, $file->get_name());

			if (null === $tmp) {
				self::set_cached_remote_version_info($remote_cache_key, null);

				return null;
			}

			$filepath = $tmp;
		}

		try {
			if ( ! is_file($filepath) || ! is_readable($filepath)) {
				if ($remote_cache_key) {
					self::set_cached_remote_version_info($remote_cache_key, null);
				}

				return null;
			}

			// Try to get version from Wpup_Package
			$package  = \Wpup_Package::fromArchive($filepath);
			$metadata = $package->getMetadata();

			$version_info = ['version' => $metadata['version'] ?? '0.0.0'];

			if ($remote_cache_key) {
				self::set_cached_remote_version_info($remote_cache_key, $version_info);
			}

			return $version_info;
		} catch (\Throwable $e) {
			// Fallback to filename extraction
			$version = self::extract_version_from_filename($file->get_name());
			$version_info = $version ? ['version' => $version] : null;

			if ($remote_cache_key) {
				self::set_cached_remote_version_info($remote_cache_key, $version_info);
			}

			return $version_info;
		} finally {
			self::delete_temporary_archive($tmp);
		}
	}

	/**
	 * Build a privacy-safe object-cache key for remote archive metadata.
	 *
	 * The raw URL may contain signed download credentials, so only a hash is used
	 * in the cache key.
	 *
	 * @param int    $product_id The product ID.
	 * @param string $file_id    The WooCommerce download file ID.
	 * @param string $file_name  The WooCommerce download file name.
	 * @param string $url        The remote archive URL.
	 * @return string The object-cache key.
	 */
	private static function get_remote_version_cache_key(int $product_id, string $file_id, string $file_name, string $url): string {

		$identity = implode('|', [$product_id, $file_id, $file_name, $url]);

		return 'remote_version_' . hash('sha256', $identity);
	}

	/**
	 * Retrieve cached remote version metadata if it has the expected shape.
	 *
	 * A cached null version is a negative cache hit. This prevents unavailable or
	 * malformed archives from being downloaded again on every page request.
	 *
	 * @param string $cache_key The object-cache key.
	 * @param bool   $found     Set to true when valid positive or negative cache data exists.
	 * @return array|null Cached version metadata, or null for a miss/negative hit.
	 */
	private static function get_cached_remote_version_info(string $cache_key, bool &$found): ?array {

		$found  = false;
		$cached = self::get_cached_value($cache_key, $found);

		if ( ! $found || ! is_array($cached) || ! array_key_exists('version', $cached)) {
			$found = false;

			return null;
		}

		if (null === $cached['version']) {
			return null;
		}

		if ( ! is_string($cached['version'])) {
			$found = false;

			return null;
		}

		return ['version' => $cached['version']];
	}

	/**
	 * Cache positive or negative remote archive inspection results.
	 *
	 * @param string     $cache_key   The object-cache key.
	 * @param array|null $version_info Version metadata, or null when inspection failed.
	 * @return void
	 */
	private static function set_cached_remote_version_info(string $cache_key, ?array $version_info): void {

		$expiration = null === $version_info
			? self::REMOTE_VERSION_FAILURE_CACHE_EXPIRATION
			: self::REMOTE_VERSION_CACHE_EXPIRATION;

		self::set_cached_value($cache_key, $version_info ?? ['version' => null], $expiration);
	}

	/**
	 * Retrieve a value from persistent WordPress cache storage.
	 *
	 * A dedicated object-cache group is used when a persistent backend is active.
	 * Transients provide cross-request persistence when WordPress uses its default
	 * request-local object cache.
	 *
	 * @param string $cache_key The cache key.
	 * @param bool   $found     Set to true when the cache key exists.
	 * @return mixed Cached value, or false on a miss.
	 */
	private static function get_cached_value(string $cache_key, bool &$found) {

		if (wp_using_ext_object_cache()) {
			$object_cache_found = null;
			$cached            = wp_cache_get($cache_key, self::CACHE_GROUP, false, $object_cache_found);
			$found             = true === $object_cache_found;

			return $cached;
		}

		$cached = get_transient(self::CACHE_GROUP . '_' . $cache_key);
		$found  = false !== $cached;

		return $cached;
	}

	/**
	 * Store a value in persistent WordPress cache storage.
	 *
	 * @param string $cache_key The cache key.
	 * @param mixed  $value     The value to cache.
	 * @param int    $expiration Cache lifetime in seconds.
	 * @return void
	 */
	private static function set_cached_value(string $cache_key, $value, int $expiration): void {

		if (wp_using_ext_object_cache()) {
			wp_cache_set($cache_key, $value, self::CACHE_GROUP, $expiration);

			return;
		}

		set_transient(self::CACHE_GROUP . '_' . $cache_key, $value, $expiration);
	}

	/**
	 * Delete a value from persistent WordPress cache storage.
	 *
	 * @param string $cache_key The cache key.
	 * @return void
	 */
	private static function delete_cached_value(string $cache_key): void {

		if (wp_using_ext_object_cache()) {
			wp_cache_delete($cache_key, self::CACHE_GROUP);

			return;
		}

		delete_transient(self::CACHE_GROUP . '_' . $cache_key);
	}

	/**
	 * Download a remote archive to a temporary file using bounded HTTP settings.
	 *
	 * @param string $url       The remote archive URL.
	 * @param string $file_name The WooCommerce download file name.
	 * @return string|null Temporary file path, or null on controlled failure.
	 */
	private static function download_remote_archive_for_inspection(string $url, string $file_name): ?string {

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam($file_name);

		if ( ! is_string($tmp) || '' === $tmp) {
			return null;
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => self::REMOTE_ARCHIVE_TIMEOUT,
				'redirection'         => self::REMOTE_ARCHIVE_REDIRECTION_LIMIT,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::REMOTE_ARCHIVE_MAX_BYTES,
			]
		);

		if (is_wp_error($response)) {
			self::delete_temporary_archive($tmp);

			return null;
		}

		$response_code = (int) wp_remote_retrieve_response_code($response);

		if ($response_code < 200 || $response_code >= 300) {
			self::delete_temporary_archive($tmp);

			return null;
		}

		$content_length = wp_remote_retrieve_header($response, 'content-length');

		if (is_array($content_length)) {
			$content_length = reset($content_length);
		}

		if (is_numeric($content_length) && (int) $content_length > self::REMOTE_ARCHIVE_MAX_BYTES) {
			self::delete_temporary_archive($tmp);

			return null;
		}

		if ( ! is_file($tmp) || ! is_readable($tmp)) {
			self::delete_temporary_archive($tmp);

			return null;
		}

		$downloaded_bytes = filesize($tmp);

		if (false === $downloaded_bytes || $downloaded_bytes <= 0 || $downloaded_bytes >= self::REMOTE_ARCHIVE_MAX_BYTES) {
			self::delete_temporary_archive($tmp);

			return null;
		}

		return $tmp;
	}

	/**
	 * Delete a temporary archive if one was created.
	 *
	 * @param string|null $filepath The temporary archive path.
	 * @return void
	 */
	private static function delete_temporary_archive(?string $filepath): void {

		if (null !== $filepath && file_exists($filepath)) {
			wp_delete_file($filepath);
		}
	}

	/**
	 * Extract version from filename.
	 *
	 * @param string $filename The filename.
	 * @return string|null The version or null.
	 */
	private static function extract_version_from_filename(string $filename): ?string {

		// Match patterns like: plugin-name-1.2.3.zip or plugin-name-v1.2.3.zip
		if (preg_match('/-v?(\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9.]+)?)\.zip$/i', $filename, $matches)) {
			return $matches[1];
		}

		// Match display names like "Plugin Name - 1.2.3" or "Plugin Name v1.2.3".
		if (preg_match('/(?:^|[\s-])v?(\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9.]+)?)$/i', $filename, $matches)) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Get user's accessible products with all their versions.
	 *
	 * @param int $user_id The user ID.
	 * @return array Array of products with versions and download URLs.
	 */
	public static function get_user_products_with_versions(int $user_id): array {

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		// Get all download permissions for this user
		$permissions = $downloads_data_store->get_downloads([
			'user_id' => $user_id,
		]);

		// Group by product, then pick a permission whose order still allows downloads.
		$products_map           = [];
		$permissions_by_product = [];

		foreach ($permissions as $permission) {
			$permissions_by_product[ $permission->get_product_id() ][] = $permission;
		}

		$reference_permissions = [];

		foreach ($permissions_by_product as $product_id => $product_permissions) {
			$reference = self::select_reference_permission($product_permissions);

			if ($reference) {
				$reference_permissions[ $product_id ] = $reference;
			}
		}

		foreach ($reference_permissions as $product_id => $permission) {
			$product = wc_get_product($product_id);

			if ( ! $product || ! $product->exists()) {
				continue;
			}

			$versions = self::get_all_versions_by_product_id($product_id);

			// Ensure the user has download permissions for all current files
			self::ensure_download_permissions($product, $permission);

			// Build download URLs for each version
			$versions_with_urls = [];
			foreach ($versions as $version_data) {
				$versions_with_urls[] = array_merge(
					$version_data,
					[
						'download_url' => self::get_version_download_url_by_permission($permission, $version_data['file_id']),
					]
				);
			}

			$products_map[$product_id] = [
				'product_id'    => $product_id,
				'name'          => $product->get_name(),
				'sku'           => $product->get_sku(),
				'icon'          => Product_Icon::get_product_icon($product_id, 'thumbnail'),
				'type'          => self::get_product_type($product_id),
				'requires'      => get_post_meta($product_id, '_requires_wp', true),
				'tested_up_to'  => get_post_meta($product_id, '_tested_up_to', true),
				'versions'      => $versions_with_urls,
				'latest_version' => ! empty($versions_with_urls) ? $versions_with_urls[0]['version'] : null,
			];
		}

		return array_values($products_map);
	}

	/**
	 * Ensure a user has download permissions for all current files on a product.
	 *
	 * When new downloadable files are added to a product after purchase,
	 * WooCommerce does not automatically grant existing customers permission
	 * to download them. This method creates the missing permission records
	 * so customers always have access to all versions of products they own.
	 *
	 * @param \WC_Product          $product    The product.
	 * @param \WC_Customer_Download $permission An existing permission for reference (order, user, etc.).
	 * @return void
	 */
	public static function ensure_download_permissions(\WC_Product $product, \WC_Customer_Download $permission): void {

		$product_id = $product->get_id();
		$order_id   = $permission->get_order_id();
		$order      = wc_get_order($order_id);

		// Never attach new files to a cancelled, refunded or otherwise
		// non-downloadable order: WooCommerce rejects those links with
		// "Invalid order", so the customer would see a version they cannot get.
		if ( ! $order || ! $order->is_download_permitted()) {
			return;
		}

		$files = $product->get_downloads();

		if (empty($files)) {
			return;
		}

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		// Get all existing permissions for this user + product + order
		$existing = $downloads_data_store->get_downloads([
			'user_id'    => $permission->get_user_id(),
			'product_id' => $product_id,
			'order_id'   => $order_id,
		]);

		$existing_file_ids = [];

		foreach ($existing as $perm) {
			$existing_file_ids[$perm->get_download_id()] = true;
		}

		// Capture the access_expires from the reference permission so new
		// permissions inherit the same subscription expiry window instead of
		// getting a fresh window calculated from now.
		$reference_expires = $permission->get_access_expires();

		// Grant permissions for any files the user doesn't have yet
		foreach ($files as $file_id => $file) {
			if (isset($existing_file_ids[$file_id])) {
				continue;
			}

			$new_permission_id = wc_downloadable_file_permission($file_id, $product, $order);

			// Sync the expiry to match the existing permission's subscription window.
			// wc_downloadable_file_permission() calculates expiry from now, which
			// would bypass the yearly subscription limit.
			if ($new_permission_id) {
				$new_perm = new \WC_Customer_Download($new_permission_id);
				$new_perm->set_access_expires($reference_expires);
				$new_perm->save();
			}
		}
	}

	/**
	 * Action Scheduler hook that grants newly released files to existing buyers.
	 *
	 * @var string
	 */
	const BACKFILL_HOOK = 'wu_backfill_product_download_permissions';

	/**
	 * Get the permission to build download links from for a user and product.
	 *
	 * A customer can hold permissions from several orders for the same product,
	 * for example a cancelled or refunded subscription plus a newer active one.
	 * Only an order that still allows downloads produces working links.
	 *
	 * @param int $user_id    The user ID.
	 * @param int $product_id The product ID.
	 * @return \WC_Customer_Download|null Null when no order grants access.
	 */
	public static function get_reference_permission(int $user_id, int $product_id): ?\WC_Customer_Download {

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		$permissions = $downloads_data_store->get_downloads([
			'user_id'    => $user_id,
			'product_id' => $product_id,
		]);

		return self::select_reference_permission($permissions);
	}

	/**
	 * Pick the best usable permission from a list for one product.
	 *
	 * Usable means the order exists, allows downloads and the permission has
	 * not expired. Among usable permissions, prefer unlimited access, then the
	 * latest expiry, then the newest permission.
	 *
	 * @param \WC_Customer_Download[] $permissions Permissions for one product.
	 * @return \WC_Customer_Download|null
	 */
	public static function select_reference_permission(array $permissions): ?\WC_Customer_Download {

		$best         = null;
		$best_expires = null;
		$order_states = [];

		foreach ($permissions as $permission) {
			$order_id = $permission->get_order_id();

			if ( ! isset($order_states[ $order_id ])) {
				$order                     = wc_get_order($order_id);
				$order_states[ $order_id ] = $order && $order->is_download_permitted();
			}

			if ( ! $order_states[ $order_id ] || self::is_permission_expired($permission)) {
				continue;
			}

			$expires = $permission->get_access_expires();
			$expires = $expires ? $expires->getTimestamp() : PHP_INT_MAX;

			if (
				null === $best
				|| $expires > $best_expires
				|| ($expires === $best_expires && $permission->get_id() > $best->get_id())
			) {
				$best         = $permission;
				$best_expires = $expires;
			}
		}

		return $best;
	}

	/**
	 * Whether a permission has expired, using WooCommerce's own cut-off.
	 *
	 * @param \WC_Customer_Download $permission The permission.
	 * @return bool
	 */
	private static function is_permission_expired(\WC_Customer_Download $permission): bool {

		$expires = $permission->get_access_expires();

		return null !== $expires && $expires->getTimestamp() < strtotime('midnight', time());
	}

	/**
	 * Queue a grant of the product's current files to every order that owns it.
	 *
	 * Release tooling may write `_downloadable_files` directly, which bypasses
	 * WooCommerce Subscriptions' own new-file grant. Without this, customers
	 * only received new versions lazily, through whichever order happened to
	 * be looked up first.
	 *
	 * @param int $product_id The product ID.
	 * @return void
	 */
	public static function schedule_permission_backfill(int $product_id): void {

		$args = [$product_id];

		if (function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action')) {
			if ( ! as_has_scheduled_action(self::BACKFILL_HOOK, $args, 'wu-update-server')) {
				as_enqueue_async_action(self::BACKFILL_HOOK, $args, 'wu-update-server');
			}

			return;
		}

		self::backfill_product_permissions($product_id);
	}

	/**
	 * Grant all current product files to every order that still allows downloads.
	 *
	 * @param int $product_id The product ID.
	 * @return int Number of orders checked.
	 */
	public static function backfill_product_permissions(int $product_id): int {

		$product = wc_get_product($product_id);

		if ( ! $product || ! $product->is_downloadable()) {
			return 0;
		}

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		$permissions = $downloads_data_store->get_downloads([
			'product_id' => $product_id,
			'orderby'    => 'permission_id',
			'order'      => 'ASC',
		]);

		// Keep the newest unexpired permission per order as that order's reference.
		$latest_by_order = [];

		foreach ($permissions as $permission) {
			if ( ! self::is_permission_expired($permission)) {
				$latest_by_order[ $permission->get_order_id() ] = $permission;
			}
		}

		foreach ($latest_by_order as $permission) {
			self::ensure_download_permissions($product, $permission);
		}

		return count($latest_by_order);
	}

	/**
	 * Get download URL for a specific version using permission.
	 *
	 * @param \WC_Customer_Download $permission The download permission.
	 * @param string                $file_id    The specific file ID for the version.
	 * @return string The download URL.
	 */
	private static function get_version_download_url_by_permission(\WC_Customer_Download $permission, string $file_id): string {

		return add_query_arg(
			[
				'download_file' => $permission->get_product_id(),
				'order'         => $permission->get_order_key(),
				'email'         => rawurlencode($permission->get_user_email()),
				'key'           => $file_id,
			],
			home_url('/')
		);
	}

	/**
	 * Get download URL for specific product version.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $sku     The product SKU.
	 * @param string $version The version to download.
	 * @return string|null The download URL or null if not accessible.
	 */
	public static function get_version_download_url(int $user_id, string $sku, string $version): ?string {

		$product_id = wc_get_product_id_by_sku($sku);

		if ( ! $product_id) {
			return null;
		}

		$permission = self::get_reference_permission($user_id, (int) $product_id);

		if ( ! $permission) {
			return null;
		}

		// Ensure user has permissions for all current files
		$product = wc_get_product($product_id);

		if ($product) {
			self::ensure_download_permissions($product, $permission);
		}

		// Find the file ID for the requested version
		$versions = self::get_all_versions_by_product_id($product_id);

		foreach ($versions as $version_data) {
			if ($version_data['version'] === $version) {
				return self::get_version_download_url_by_permission($permission, $version_data['file_id']);
			}
		}

		return null;
	}

	/**
	 * Determine product type (plugin or theme).
	 *
	 * @param int $product_id The product ID.
	 * @return string 'plugin' or 'theme'.
	 */
	private static function get_product_type(int $product_id): string {

		// Check product tags first
		$terms = get_the_terms($product_id, 'product_tag');

		if ( ! empty($terms) && ! is_wp_error($terms)) {
			foreach ($terms as $term) {
				if ($term->slug === 'theme' || $term->slug === 'themes') {
					return 'theme';
				}
			}
		}

		// Check product meta
		$type_meta = get_post_meta($product_id, '_product_type_software', true);

		if ($type_meta === 'theme') {
			return 'theme';
		}

		return 'plugin';
	}

	/**
	 * Check if a version string is a pre-release (alpha, beta, rc, etc.).
	 *
	 * @param string $version The version string.
	 * @return bool True if the version is a pre-release.
	 */
	public static function is_prerelease(string $version): bool {

		return (bool) preg_match('/-(?:alpha|beta|rc|dev|preview)\b/i', $version);
	}

	/**
	 * Get the latest version for a product, optionally filtering out pre-releases.
	 *
	 * When $include_prerelease is true, a pre-release like 1.0.0-beta.1 is considered
	 * newer than 1.0.0 (its base version). PHP's version_compare treats pre-release
	 * suffixes as lower, so we need custom logic here.
	 *
	 * @param int  $product_id          The product ID.
	 * @param bool $include_prerelease  Whether to include pre-release versions.
	 * @return array|null Latest version data or null if none found.
	 */
	public static function get_latest_version_by_product_id(int $product_id, bool $include_prerelease = false): ?array {

		$versions = self::get_all_versions_by_product_id($product_id);

		if (empty($versions)) {
			return null;
		}

		if (! $include_prerelease) {
			foreach ($versions as $version_data) {
				if (! self::is_prerelease($version_data['version'])) {
					return $version_data;
				}
			}

			return null;
		}

		// When including pre-releases, we need to find the true latest.
		// PHP's version_compare sorts 1.0.0 > 1.0.0-beta.1, but semver says
		// 1.0.0-beta.1 is a pre-release OF 1.0.0, meaning it was published
		// before/after the stable and should be considered newer when opted in.
		// Strategy: find the latest stable, then check if any pre-release has
		// a base version >= that stable version. If so, the pre-release wins.
		$latest_stable     = null;
		$latest_prerelease = null;

		foreach ($versions as $version_data) {
			if (self::is_prerelease($version_data['version'])) {
				if (! $latest_prerelease) {
					$latest_prerelease = $version_data;
				}
			} else {
				if (! $latest_stable) {
					$latest_stable = $version_data;
				}
			}
		}

		// Only pre-releases exist
		if (! $latest_stable) {
			return $latest_prerelease;
		}

		// No pre-releases exist
		if (! $latest_prerelease) {
			return $latest_stable;
		}

		// Both exist — extract the base version from the pre-release
		$prerelease_base = preg_replace('/-(?:alpha|beta|rc|dev|preview)[\w.]*/i', '', $latest_prerelease['version']);

		// If the pre-release's base version >= the stable version, the pre-release is newer
		if (version_compare($prerelease_base, $latest_stable['version'], '>=')) {
			return $latest_prerelease;
		}

		return $latest_stable;
	}

	/**
	 * Clear version cache for a product.
	 *
	 * @param int $product_id The product ID.
	 * @return void
	 */
	public static function clear_cache(int $product_id): void {

		self::delete_cached_value('versions_' . $product_id);

		$product = wc_get_product($product_id);

		if ( ! $product || ! $product->exists()) {
			return;
		}

		foreach ($product->get_downloads() as $file_id => $file) {
			$file_info = \WC_Download_Handler::parse_file_path($product->get_file_download_path($file_id));

			if (empty($file_info['remote_file'])) {
				continue;
			}

			$cache_key = self::get_remote_version_cache_key(
				$product_id,
				$file_id,
				$file->get_name(),
				$file_info['file_path']
			);

			self::delete_cached_value($cache_key);
		}
	}
}
