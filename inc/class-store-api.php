<?php
/**
 * Store API integration.
 */
namespace WP_Update_Server_Plugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Class Store_Api
 *
 * Handles the integration and customization of WooCommerce Store API functionalities,
 * including product metadata and specific query modifications.
 */
class Store_Api {

	private $downloads_data_store;
	/**
	 * Initialize the store API functionality.
	 */
	public function __construct() {
		add_action('rest_api_init', array($this, 'register_product_icon_data'));
		add_filter('woocommerce_rest_prepare_product_object', array($this, 'add_product_icon_to_api'), 10, 3);
		add_filter('woocommerce_product_data_store_cpt_get_products_query', array($this, 'modify_subscription_query'), 10, 3);
		add_action('pre_get_posts', array($this, 'modify_store_api_subscription_query'), 10);
		add_action('init', array($this, 'maybe_backfill_download_permissions'), 1);
	}

	/**
	 * Get the downloads data store, initializing lazily to avoid calling
	 * wc_get_container() before WooCommerce is loaded.
	 *
	 * @return \WC_Data_Store|null
	 */
	private function get_downloads_data_store() {
		if (null === $this->downloads_data_store) {
			if (! function_exists('wc_get_container')) {
				return null;
			}
			$this->downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');
		}
		return $this->downloads_data_store;
	}

	/**
	 * Register product icon data with WooCommerce Store API.
	 */
	public function register_product_icon_data() {
		if (function_exists('woocommerce_store_api_register_endpoint_data')) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema::IDENTIFIER,
					'namespace'       => 'wp-update-server-plugin',
					'data_callback'   => array($this, 'get_product_icon_data'),
					'schema_callback' => array($this, 'get_product_icon_schema'),
					'schema_type'     => ARRAY_A,
				)
			);
		}
	}

	/**
	 * Get product icon data for Store API.
	 *
	 * @param \WC_Product $product The product data.
	 * @return array
	 */
	public function get_product_icon_data($product): array {
		$product_id = $product->get_id();

		if (! $product_id) {
			return array();
		}
		$icon_full_url = Product_Icon::get_product_icon($product_id, 'full');

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$include_beta = ! empty($_GET['beta']);

		$user_id = apply_filters('determine_current_user', null);

		$download_url = '';
		$data_store = $this->get_downloads_data_store();
		if ($user_id && $data_store) {
			$latest = Product_Versions::get_latest_version_by_product_id($product_id, $include_beta);

			if ($latest && isset($latest['file_id'])) {
				$permission = Product_Versions::get_reference_permission((int) $user_id, (int) $product_id);

				if ($permission) {
					// Ensure user has permissions for all current files
					Product_Versions::ensure_download_permissions($product, $permission);

					$download_url = add_query_arg(
						array(
							'download_file' => $permission->get_product_id(),
							'order'         => $permission->get_order_key(),
							'email'         => rawurlencode($permission->get_user_email()),
							'key'           => $latest['file_id'],
						),
						home_url('/')
					);
				}
			}
		}

		// Get latest beta version string if one exists
		$beta_version_data = Product_Versions::get_latest_version_by_product_id($product_id, true);
		$beta_version      = null;

		if ($beta_version_data && Product_Versions::is_prerelease($beta_version_data['version'])) {
			$beta_version = $beta_version_data['version'];
		}

		$data = array(
			'author'          => [
				'display_name' => 'David Stone',
			],
			'download_url'    => $download_url,
			'icon'            => $icon_full_url,
			'beta'            => $this->is_beta_product_by_id($product_id),
			'beta_version'    => $beta_version,
			'legacy'          => $this->is_legacy_product_by_id($product_id),
			'tested_up_to'    => get_post_meta($product_id, '_tested_up_to', true),
			'requires'        => get_post_meta($product_id, '_requires_wp', true),
			'active_installs' => get_post_meta($product_id, '_active_installs', true),
		);

		// Add template-specific metadata if product has "template" tag
		if ($this->is_template_product_by_id($product_id)) {
			$data = array_merge($data, $this->get_template_metadata($product_id));
		}

		return $data;
	}

	/**
	 * Get template-specific metadata for a product.
	 *
	 * @param int $product_id The product ID.
	 * @return array Template metadata.
	 */
	private function get_template_metadata($product_id): array {
		$included_plugins = get_post_meta($product_id, '_included_plugins', true);
		$included_themes  = get_post_meta($product_id, '_included_themes', true);

		return array(
			'demo_url'         => get_post_meta($product_id, '_demo_url', true),
			'industry_type'    => get_post_meta($product_id, '_industry_type', true),
			'page_count'       => (int) get_post_meta($product_id, '_page_count', true),
			'included_plugins' => is_array($included_plugins) ? $included_plugins : array(),
			'included_themes'  => is_array($included_themes) ? $included_themes : array(),
			'template_version' => get_post_meta($product_id, '_template_version', true),
			'compatibility'    => array(
				'wp_version' => get_post_meta($product_id, '_requires_wp', true),
				'wu_version' => get_post_meta($product_id, '_requires_wu', true),
			),
		);
	}

	/**
	 * Check if product is a template by ID.
	 *
	 * @param int $product_id The product ID.
	 * @return bool
	 */
	private function is_template_product_by_id($product_id): bool {
		$terms = get_the_terms($product_id, 'product_tag');

		if (empty($terms) || is_wp_error($terms)) {
			return false;
		}

		foreach ($terms as $term) {
			if ('template' === $term->slug) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get schema for product icon data.
	 *
	 * @return array
	 */
	public function get_product_icon_schema() {
		return array(
			'icon'             => array(
				'description' => __('Full size icon URL.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'format'      => 'uri',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'beta'             => array(
				'description' => __('Whether the product is marked as beta.', 'wp-update-server-plugin'),
				'type'        => 'boolean',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'beta_version'     => array(
				'description' => __('Latest pre-release version string, or null if none.', 'wp-update-server-plugin'),
				'type'        => array('string', 'null'),
				'context'     => array('view'),
				'readonly'    => true,
			),
			'legacy'           => array(
				'description' => __('Whether the product is marked as legacy.', 'wp-update-server-plugin'),
				'type'        => 'boolean',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'tested_up_to'     => array(
				'description' => __('WordPress version the product has been tested up to.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'requires'         => array(
				'description' => __('Minimum WordPress version required.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'active_installs'  => array(
				'description' => __('Number of active installations.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
			// Template-specific fields
			'demo_url'         => array(
				'description' => __('URL to live demo site.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'format'      => 'uri',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'industry_type'    => array(
				'description' => __('Industry category for the template.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'page_count'       => array(
				'description' => __('Number of pages in the template.', 'wp-update-server-plugin'),
				'type'        => 'integer',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'included_plugins' => array(
				'description' => __('List of plugins included in the template.', 'wp-update-server-plugin'),
				'type'        => 'array',
				'context'     => array('view'),
				'readonly'    => true,
				'items'       => array(
					'type' => 'object',
				),
			),
			'included_themes'  => array(
				'description' => __('List of themes included in the template.', 'wp-update-server-plugin'),
				'type'        => 'array',
				'context'     => array('view'),
				'readonly'    => true,
				'items'       => array(
					'type' => 'object',
				),
			),
			'template_version' => array(
				'description' => __('Version number of the template.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'compatibility'    => array(
				'description' => __('Compatibility requirements.', 'wp-update-server-plugin'),
				'type'        => 'object',
				'context'     => array('view'),
				'readonly'    => true,
				'properties'  => array(
					'wp_version' => array(
						'description' => __('Minimum WordPress version required.', 'wp-update-server-plugin'),
						'type'        => 'string',
					),
					'wu_version' => array(
						'description' => __('Minimum Ultimate Multisite version required.', 'wp-update-server-plugin'),
						'type'        => 'string',
					),
				),
			),
		);
	}

	/**
	 * Add product icon data to WooCommerce REST API responses.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WC_Product       $product The product object.
	 * @param \WP_REST_Request  $request The request object.
	 * @return \WP_REST_Response
	 */
	public function add_product_icon_to_api($response, $product, $request) {
		unset($request);
		$product_id    = $product->get_id();
		$icon_url      = Product_Icon::get_product_icon($product_id, 'thumbnail');
		$icon_full_url = Product_Icon::get_product_icon($product_id, 'full');

		$response->data['icon'] = $icon_url ? array(
			'thumbnail' => $icon_url,
			'full'      => $icon_full_url,
			'id'        => Product_Icon::get_product_icon_id($product_id),
		) : null;

		// Add additional metadata to REST API
		$response->data['author']          = [
			'display_name' => 'David Stone',
		];
		$response->data['beta']            = $this->is_beta_product_by_id($product_id);
		$response->data['legacy']          = $this->is_legacy_product_by_id($product_id);
		$response->data['tested_up_to']    = get_post_meta($product_id, '_tested_up_to', true);
		$response->data['requires']        = get_post_meta($product_id, '_requires_wp', true);
		$response->data['active_installs'] = get_post_meta($product_id, '_active_installs', true);

		// Add template-specific metadata if product has "template" tag
		if ($this->is_template_product_by_id($product_id)) {
			$template_data = $this->get_template_metadata($product_id);
			foreach ($template_data as $key => $value) {
				$response->data[ $key ] = $value;
			}
		}

		return $response;
	}

	/**
	 * Check if product is marked as beta by ID.
	 *
	 * @param int $product_id The product ID.
	 * @return bool
	 */
	private function is_beta_product_by_id($product_id) {
		$beta_meta = get_post_meta($product_id, '_is_beta', true);
		return 'yes' === $beta_meta || '1' === $beta_meta;
	}

	/**
	 * Check if product is marked as legacy by ID.
	 *
	 * @param int $product_id The product ID.
	 * @return bool
	 */
	private function is_legacy_product_by_id($product_id) {
		$legacy_meta = get_post_meta($product_id, '_is_legacy', true);
		return 'yes' === $legacy_meta || '1' === $legacy_meta;
	}

	/**
	 * Modify product query to include simple products with subscriptions when type=subscription is requested.
	 *
	 * @param array $wp_query_args WP_Query arguments.
	 * @param array $query_vars    Query variables from wc_get_products().
	 * @param mixed $data_store    The data store instance.
	 * @return array Modified WP_Query arguments.
	 */
	public function modify_subscription_query($wp_query_args, $query_vars, $data_store) {
		unset($data_store);
		// Check if type=subscription is requested
		if (isset($query_vars['type']) && 'subscription' === $query_vars['type']) {
			// Change the tax_query to look for 'simple' product type instead of 'subscription'
			// since we now use simple products that are converted to subscriptions via
			// WooCommerce All Products for Subscriptions
			if (isset($wp_query_args['tax_query'])) {
				foreach ($wp_query_args['tax_query'] as $key => $tax_query) {
					if (isset($tax_query['taxonomy']) && 'product_type' === $tax_query['taxonomy']
						&& isset($tax_query['terms']) && 'subscription' === $tax_query['terms']) {
						$wp_query_args['tax_query'][ $key ]['terms'] = 'simple';
					}
				}
			}

			// Add meta query to filter only products that have subscription schemes
			// and exclude products where subscriptions are disabled
			if (! isset($wp_query_args['meta_query'])) {
				$wp_query_args['meta_query'] = array();
			}

			// The WooCommerce All Products for Subscriptions plugin uses:
			// - _wcsatt_schemes: Contains the subscription schemes data
			// - _wcsatt_schemes_status: Can be 'inherit', 'override', or 'disable'
			$wp_query_args['meta_query'][] = array(
				'relation' => 'AND',
				array(
					'key'     => '_wcsatt_schemes',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_wcsatt_schemes_status',
					'value'   => 'disable',
					'compare' => '!=',
				),
			);
		}

		return $wp_query_args;
	}

	/**
	 * Modify Store API queries to include simple products with subscriptions when type=subscription is requested.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 */
	public function modify_store_api_subscription_query($query) {
		// Only modify product queries from the Store API
		if (! defined('REST_REQUEST') || ! REST_REQUEST) {
			return;
		}

		// Check if this is a product query
		$post_type = $query->get('post_type');
		if ($post_type !== 'product') {
			return;
		}

		// Check if there's a tax_query filtering by product_type
		$tax_query = $query->get('tax_query');
		if (empty($tax_query) || ! is_array($tax_query)) {
			return;
		}

		// Look for subscription type query and modify it
		$modified = false;
		foreach ($tax_query as $key => $tax_query_item) {
			if (isset($tax_query_item['taxonomy']) && 'product_type' === $tax_query_item['taxonomy']
				&& isset($tax_query_item['terms']) && 'subscription' === $tax_query_item['terms']) {
				// Change subscription to simple
				$tax_query[ $key ]['terms'] = 'simple';
				$modified                   = true;
				break;
			}
		}

		// Only modify the query if we found a subscription type filter
		if (! $modified) {
			return;
		}

		// Update the tax_query
		$query->set('tax_query', $tax_query);

		// Add meta_query to exclude products where subscriptions are explicitly disabled
		$meta_query = $query->get('meta_query');
		if (empty($meta_query)) {
			$meta_query = array();
		}

		// The WooCommerce All Products for Subscriptions plugin uses:
		// - _wcsatt_schemes_status: Can be 'inherit', 'override', or 'disable'
		// - When not set or set to 'inherit', the product uses global subscription plans
		// - Only exclude products where status is explicitly set to 'disable'
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_wcsatt_schemes_status',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_wcsatt_schemes_status',
				'value'   => 'disable',
				'compare' => '!=',
			),
		);

		$query->set('meta_query', $meta_query);
	}

	public static function get_download_url(\WC_Customer_Download $permission) {
		return add_query_arg(
			array(
				'download_file' => $permission->get_product_id(),
				'order'         => $permission->get_order_key(),
				'email'         => rawurlencode($permission->get_user_email()),
				'key'           => $permission->get_download_id(),
			),
			home_url('/')
		);
	}

	/**
	 * Backfill download permissions before WooCommerce's download handler runs.
	 *
	 * This hooks into 'init' with priority 1, before WC_Download_Handler::download_product()
	 * (which runs at priority 10). It detects download requests, finds any existing permission
	 * for the order+product, and ensures all current file versions have permissions.
	 *
	 * @return void
	 */
	public function maybe_backfill_download_permissions() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset($_GET['download_file'], $_GET['order'], $_GET['key']) || empty($_GET['email'])) {
			return;
		}

		$data_store = $this->get_downloads_data_store();
		if ( ! $data_store) {
			return;
		}

		$product_id = absint($_GET['download_file']);
		$product    = wc_get_product($product_id);

		if ( ! $product || ! $product->is_downloadable()) {
			return;
		}

		$order_key     = wc_clean(wp_unslash($_GET['order']));
		$email_address = sanitize_email(str_replace(' ', '+', wp_unslash($_GET['email'])));

		// Find any existing permission for this user+order+product
		$permissions = $data_store->get_downloads(
			array(
				'user_email' => $email_address,
				'order_key'  => $order_key,
				'product_id' => $product_id,
				'limit'      => 1,
			)
		);

		if (empty($permissions)) {
			// No permissions at all - WooCommerce will handle the error
			return;
		}

		// We have at least one permission - backfill any missing file permissions
		Product_Versions::ensure_download_permissions($product, $permissions[0]);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
