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

		// Check cache
		$cache_key = 'versions_' . $product_id;
		$cached    = get_transient($cache_key);

		if ($cached !== false) {
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

		// Cache the results
		set_transient($cache_key, $versions, self::CACHE_EXPIRATION);

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
			$cached_version_info = self::get_cached_remote_version_info($remote_cache_key);

			if ($cached_version_info) {
				return $cached_version_info;
			}

			// Download temporarily if we need to inspect the archive.
			$tmp = self::download_remote_archive_for_inspection($filepath, $file->get_name());

			if (null === $tmp) {
				return null;
			}

			$filepath = $tmp;
		}

		try {
			if ( ! is_file($filepath) || ! is_readable($filepath)) {
				return null;
			}

			// Try to get version from Wpup_Package
			$package  = \Wpup_Package::fromArchive($filepath);
			$metadata = $package->getMetadata();

			$version_info = ['version' => $metadata['version'] ?? '0.0.0'];

			if ($remote_cache_key) {
				set_transient($remote_cache_key, $version_info, self::REMOTE_VERSION_CACHE_EXPIRATION);
			}

			return $version_info;
		} catch (\Throwable $e) {
			// Fallback to filename extraction
			$version = self::extract_version_from_filename($file->get_name());

			return $version ? ['version' => $version] : null;
		} finally {
			self::delete_temporary_archive($tmp);
		}
	}

	/**
	 * Build a privacy-safe transient key for remote archive metadata.
	 *
	 * The raw URL may contain signed download credentials, so only a hash is used
	 * in the transient name.
	 *
	 * @param int    $product_id The product ID.
	 * @param string $file_id    The WooCommerce download file ID.
	 * @param string $file_name  The WooCommerce download file name.
	 * @param string $url        The remote archive URL.
	 * @return string The transient cache key.
	 */
	private static function get_remote_version_cache_key(int $product_id, string $file_id, string $file_name, string $url): string {

		$identity = implode('|', [$product_id, $file_id, $file_name, $url]);

		return 'remote_version_' . hash('sha256', $identity);
	}

	/**
	 * Retrieve cached remote version metadata if it has the expected shape.
	 *
	 * @param string $cache_key The transient cache key.
	 * @return array|null Cached version metadata or null when absent/invalid.
	 */
	private static function get_cached_remote_version_info(string $cache_key): ?array {

		$cached = get_transient($cache_key);

		if ( ! is_array($cached) || ! isset($cached['version']) || ! is_string($cached['version'])) {
			return null;
		}

		return ['version' => $cached['version']];
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

		// Match pattern from file name like "Plugin Name - 1.2.3"
		if (preg_match('/ - (\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9.]+)?)$/i', $filename, $matches)) {
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

		// Group by product, keeping first permission per product for reference
		$products_map          = [];
		$reference_permissions = [];

		foreach ($permissions as $permission) {
			$product_id = $permission->get_product_id();

			if ( ! isset($reference_permissions[$product_id])) {
				$reference_permissions[$product_id] = $permission;
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

		if ( ! $order) {
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

			wc_downloadable_file_permission($file_id, $product, $order);

			// Sync the expiry to match the existing permission's subscription window.
			// wc_downloadable_file_permission() calculates expiry from now, which
			// would bypass the yearly subscription limit.
			$new_permissions = $downloads_data_store->get_downloads([
				'user_id'     => $permission->get_user_id(),
				'product_id'  => $product_id,
				'download_id' => $file_id,
				'orderby'     => 'permission_id',
				'order'       => 'DESC',
				'limit'       => 1,
			]);

			if ( ! empty($new_permissions)) {
				$new_perm = $new_permissions[0];
				$new_perm->set_access_expires($reference_expires);
				$new_perm->save();
			}
		}
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

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		$permissions = $downloads_data_store->get_downloads([
			'product_id' => $product_id,
			'user_id'    => $user_id,
			'limit'      => 1,
		]);

		if (empty($permissions)) {
			return null;
		}

		$permission = $permissions[0];

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

		delete_transient('versions_' . $product_id);
	}
}
