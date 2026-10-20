<?php
/**
 * Update server class.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Update server class.
 */
class Update_Server extends \Wpup_UpdateServer {

	/**
	 * Use our prefixed query vars.
	 *
	 * @param \Wpup_Package $package the package.
	 *
	 * @return string
	 */
	protected function generateDownloadUrl(\Wpup_Package $package) {
		$user_id = apply_filters('determine_current_user', null);
		if ($user_id) {
			$download_url = $this->getDownloadUrlForUser($user_id, $package->slug);
			if ($download_url) {
				return $download_url;
			}
		}
		$query = [
			'update_action' => 'download',
			'update_slug'   => $package->slug,
		];

		return self::addQueryArg($query, $this->serverUrl);
	}

	/**
	 * Retrieves the download URL for a specific user and product specified by its SKU.
	 *
	 * @param int $user_id The ID of the user for whom the download URL is being retrieved.
	 * @param string $slug The SKU identifying the product for which the download URL is needed.
	 *
	 * @return string|null The download URL if available, or null if no valid downloads are found.
	 */
	protected function getDownloadUrlForUser($user_id, $slug) {
		/** @var \WC_Product_Data_Store_Interface $product_data_store */
		$product_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'product');
		$product_id         = $product_data_store->get_product_id_by_sku($slug);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$include_beta = ! empty($_GET['beta']);

		// Find the appropriate version (stable or beta) to serve
		$target_version = Product_Versions::get_latest_version_by_product_id($product_id, $include_beta);

		if (! $target_version || ! isset($target_version['file_id'])) {
			return null;
		}

		$permission = Product_Versions::get_reference_permission((int) $user_id, (int) $product_id);

		if ( $permission ) {

			// Ensure the user has permissions for all current files (including newly uploaded versions)
			$product = wc_get_product($product_id);

			if ($product) {
				Product_Versions::ensure_download_permissions($product, $permission);
			}

			return add_query_arg(
				array(
					'download_file' => $permission->get_product_id(),
					'order'         => $permission->get_order_key(),
					'email'         => rawurlencode($permission->get_user_email()),
					'key'           => $target_version['file_id'],
				),
				home_url('/')
			);
		}

		return null;
	}

	/**
	 * Finds the most recent package associated with a given product slug.
	 *
	 * @param string $slug The product slug to search for the package.
	 *
	 * @return \Wpup_Package|null The latest package if found, otherwise null.
	 */
	protected function findPackage($slug) {
		$product_id = wc_get_product_id_by_sku($slug);
		$product    = wc_get_product($product_id);

		if (! $product || ! $product->exists() || ! $product->is_downloadable()) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$include_beta = ! empty($_GET['beta']);

		// Use Product_Versions to find the correct version to serve
		$target = Product_Versions::get_latest_version_by_product_id($product_id, $include_beta);

		if (! $target || ! isset($target['file_id'])) {
			return null;
		}

		// Load the package from the target version's file
		$files = $product->get_downloads();

		if (! isset($files[$target['file_id']])) {
			return null;
		}

		$file      = $files[$target['file_id']];
		$file_info = \WC_Download_Handler::parse_file_path($product->get_file_download_path($target['file_id']));
		$filepath  = $file_info['file_path'];

		if ($file_info['remote_file']) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = wp_tempnam($file['name']);
			file_put_contents($tmp, file_get_contents($filepath));
			$filepath = $tmp;
		}

		if ($file->get_enabled() && is_file($filepath) && is_readable($filepath)) {
			return call_user_func($this->packageFileLoader, $filepath, $slug, $this->cache);
		}

		return null;
	}

	/**
	 * Handles the download action for a package request.
	 *
	 * @param \Wpup_Request $request The request object containing package details.
	 *
	 * @return void
	 */
	protected function actionDownload(\Wpup_Request $request) {
		$package = $request->package;
		$user_id = apply_filters('determine_current_user', null);
		if ($user_id) {
			$download_url = $this->getDownloadUrlForUser($user_id, $package->slug);
			if ($download_url) {
				wp_safe_redirect($download_url);
				exit();
			}
		}

		$this->exitWithError('You do not have a valid order for this package.', 403);
	}
}
