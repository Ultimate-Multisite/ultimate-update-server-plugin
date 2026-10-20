<?php
/**
 * Release Notifier
 *
 * Handles detection of new downloadable files and schedules email notifications.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Release Notifier Class.
 */
class Release_Notifier {

	/**
	 * User meta key for opt-out preference.
	 *
	 * @var string
	 */
	const OPTOUT_META_KEY = '_wu_release_email_optout';

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	const BATCH_HOOK = 'wu_process_release_notification_batch';

	/**
	 * Number of customers to process per batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Store previous downloads before product save.
	 *
	 * @var array
	 */
	private $previous_downloads = [];

	/**
	 * Download IDs already handled during the current request.
	 *
	 * WooCommerce fires its download-path hook before it writes the product
	 * meta. The meta hook below catches direct meta updates too, so remember
	 * files handled by the WooCommerce hook to avoid scheduling twice.
	 *
	 * @var array<int,array<string,bool>>
	 */
	private $processed_downloads = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Hook before product save to store previous downloads.
		add_action( 'woocommerce_before_product_object_save', [ $this, 'store_previous_downloads' ], 10, 1 );

		// Hook after download paths saved to detect new downloads.
		add_action( 'woocommerce_process_product_file_download_paths', [ $this, 'detect_new_downloads' ], 10, 3 );

		// Some release tooling updates _downloadable_files directly instead of
		// saving a WC_Product. Capture the old file list before that update and
		// notify after it has been persisted.
		add_filter( 'update_post_metadata', [ $this, 'store_previous_downloads_before_meta_update' ], 10, 5 );
		add_action( 'updated_post_meta', [ $this, 'detect_new_downloads_from_meta' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'detect_new_downloads_from_meta' ], 10, 4 );

		// Register Action Scheduler hook.
		add_action( self::BATCH_HOOK, [ $this, 'process_notification_batch' ], 10, 4 );

		// Register email class with WooCommerce.
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email_class' ] );

		// Add opt-out checkbox to My Account.
		add_action( 'woocommerce_edit_account_form', [ $this, 'add_optout_checkbox' ] );
		add_action( 'woocommerce_save_account_details', [ $this, 'save_optout_preference' ] );
	}

	/**
	 * Register the New Release Email class with WooCommerce.
	 *
	 * @param array $email_classes Array of email classes.
	 * @return array Modified array of email classes.
	 */
	public function register_email_class( array $email_classes ): array {
        if ( ! class_exists( 'WC_Email', false ) ) {
            include_once WC_ABSPATH . 'includes/emails/class-wc-email.php';
        }
        require_once WP_UPDATE_SERVER_PLUGIN_PATH . '/inc/class-new-release-email.php';
		$email_classes['WU_New_Release_Email'] = new New_Release_Email();
		return $email_classes;
	}

	/**
	 * Store previous downloads before product save.
	 *
	 * @param \WC_Product $product The product object.
	 * @return void
	 */
	public function store_previous_downloads( \WC_Product $product ): void {
		if ( ! $product->is_downloadable() ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! $product_id ) {
			return;
		}

		$this->previous_downloads[ $product_id ] = $this->get_download_ids_from_meta(
			get_post_meta( $product_id, '_downloadable_files', true )
		);
	}

	/**
	 * Capture download IDs before a direct product-meta update.
	 *
	 * @param mixed  $check      Short-circuit value for the metadata update.
	 * @param int    $product_id Product ID.
	 * @param string $meta_key   Metadata key being updated.
	 * @param mixed  $meta_value New metadata value.
	 * @param mixed  $prev_value Previous metadata value constraint.
	 * @return mixed Unmodified short-circuit value.
	 */
	public function store_previous_downloads_before_meta_update( $check, int $product_id, string $meta_key, $meta_value, $prev_value ) {
		if ( '_downloadable_files' !== $meta_key ) {
			return $check;
		}

		$this->previous_downloads[ $product_id ] = $this->get_download_ids_from_meta(
			get_post_meta( $product_id, '_downloadable_files', true )
		);

		return $check;
	}

	/**
	 * Detect new downloads after product save.
	 *
	 * @param int   $product_id The product ID.
	 * @param int   $variation_id The variation ID (0 for non-variations).
	 * @param array $downloads Array of download data.
	 * @return void
	 */
	public function detect_new_downloads( int $product_id, int $variation_id, array $downloads ): void {
		// Use variation ID if set, otherwise product ID.
		$actual_product_id = $variation_id > 0 ? $variation_id : $product_id;
		$this->schedule_new_download_notifications( $actual_product_id, array_keys( $downloads ) );
	}

	/**
	 * Detect download changes made outside WooCommerce's product data store.
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $product_id Product ID.
	 * @param string $meta_key   Metadata key that changed.
	 * @param mixed  $meta_value New metadata value.
	 * @return void
	 */
	public function detect_new_downloads_from_meta( int $meta_id, int $product_id, string $meta_key, $meta_value ): void {
		if ( '_downloadable_files' !== $meta_key ) {
			return;
		}

		$this->schedule_new_download_notifications(
			$product_id,
			$this->get_download_ids_from_meta( $meta_value )
		);
	}

	/**
	 * Schedule notification batches for newly added download files.
	 *
	 * @param int   $product_id           Product ID.
	 * @param array $current_download_ids Current download file IDs.
	 * @return void
	 */
	private function schedule_new_download_notifications( int $product_id, array $current_download_ids ): void {

		$previous_download_ids = $this->previous_downloads[ $product_id ] ?? [];

		// Find newly added downloads.
		$new_download_ids = array_diff( $current_download_ids, $previous_download_ids );

		if ( empty( $new_download_ids ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		// Give existing buyers the new files on every order that still allows
		// downloads, including when release tooling bypasses WooCommerce's save.
		Product_Versions::schedule_permission_backfill( $product_id );

		foreach ( $new_download_ids as $download_id ) {
			if ( isset( $this->processed_downloads[ $product_id ][ $download_id ] ) ) {
				continue;
			}

			$this->processed_downloads[ $product_id ][ $download_id ] = true;

			// Determine version from the new download.
			$version = $this->get_version_from_download( $product, $download_id );

			// Skip notifications for pre-release versions.
			if ( Product_Versions::is_prerelease( $version ) ) {
				continue;
			}

			$this->schedule_notifications( $product_id, $version );
		}

		// Clear version cache.
		Product_Versions::clear_cache( $product_id );
	}

	/**
	 * Extract download IDs from the stored WooCommerce metadata value.
	 *
	 * @param mixed $downloads Stored downloadable-files metadata.
	 * @return array
	 */
	private function get_download_ids_from_meta( $downloads ): array {
		return is_array( $downloads ) ? array_keys( $downloads ) : [];
	}

	/**
	 * Get version string from a download.
	 *
	 * @param \WC_Product $product The product.
	 * @param string      $file_id The download file ID.
	 * @return string The version string.
	 */
	private function get_version_from_download( \WC_Product $product, string $file_id ): string {
		$downloads = $product->get_downloads();

		if ( ! isset( $downloads[ $file_id ] ) ) {
			return 'new version';
		}

		$file = $downloads[ $file_id ];

		// Try to extract version from filename.
		$name = $file->get_name();

		// Match patterns like plugin-name-1.2.3.zip, "Plugin Name - 1.2.3",
		// or "Plugin Name v1.2.3".
		if ( preg_match( '/(?:^|[\s-])v?(\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9.]+)?)(?:\.zip)?$/i', $name, $matches ) ) {
			return $matches[1];
		}

		// Try getting versions from Product_Versions.
		$versions = Product_Versions::get_all_versions_by_product_id( $product->get_id() );

		if ( ! empty( $versions ) ) {
			foreach ( $versions as $version_data ) {
				if ( $version_data['file_id'] === $file_id ) {
					return $version_data['version'];
				}
			}
			// Return the latest version if we can't find the specific file.
			return $versions[0]['version'];
		}

		return 'new version';
	}

	/**
	 * Schedule notification batch processing.
	 *
	 * @param int    $product_id The product ID.
	 * @param string $version    The version string.
	 * @return void
	 */
	private function schedule_notifications( int $product_id, string $version ): void {
		// Check if Action Scheduler is available.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Fallback: process synchronously (not recommended for large customer bases).
			$this->process_notification_batch( $product_id, $version, 0, self::BATCH_SIZE );
			return;
		}

		// Schedule the first batch.
		as_schedule_single_action(
			time(),
			self::BATCH_HOOK,
			[
				'product_id' => $product_id,
				'version'    => $version,
				'offset'     => 0,
				'limit'      => self::BATCH_SIZE,
			],
			'wu-release-notifications'
		);
	}

	/**
	 * Process a batch of notification emails.
	 *
	 * @param int    $product_id The product ID.
	 * @param string $version    The version string.
	 * @param int    $offset     The offset for customer query.
	 * @param int    $limit      The limit for customer query.
	 * @return void
	 */
	public function process_notification_batch( int $product_id, string $version, int $offset, int $limit ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		// Get customers who have purchased this product.
		$customer_ids = $this->get_product_customers( $product_id, $offset, $limit );

		if ( empty( $customer_ids ) ) {
			return;
		}

		// Get the email class instance.
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( ! isset( $emails['WU_New_Release_Email'] ) ) {
			return;
		}

		/** @var New_Release_Email $email */
		$email = $emails['WU_New_Release_Email'];

		// Get changelog.
		$changelog = Changelog_Manager::get_changelog_excerpt( $product_id );

		// Send emails to each customer.
		foreach ( $customer_ids as $customer_id ) {
			// Check opt-out status.
			if ( $this->is_opted_out( $customer_id ) ) {
				continue;
			}

			$email->trigger( $customer_id, $product, $version, $changelog );
		}

		// Schedule next batch if there might be more customers.
		if ( count( $customer_ids ) === $limit && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 5, // Small delay between batches.
				self::BATCH_HOOK,
				[
					'product_id' => $product_id,
					'version'    => $version,
					'offset'     => $offset + $limit,
					'limit'      => $limit,
				],
				'wu-release-notifications'
			);
		}
	}

	/**
	 * Get customer IDs who have purchased a product.
	 *
	 * @param int $product_id The product ID.
	 * @param int $offset     Query offset.
	 * @param int $limit      Query limit.
	 * @return array Array of customer user IDs.
	 */
	private function get_product_customers( int $product_id, int $offset, int $limit ): array {
		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get( LegacyProxy::class )->get_instance_of( \WC_Data_Store::class, 'customer-download' );

		// Get all download permissions for this product.
		$permissions = $downloads_data_store->get_downloads(
			[
				'product_id' => $product_id,
				'orderby'    => 'permission_id',
				'order'      => 'ASC',
			]
		);

		// Extract unique user IDs.
		$user_ids = [];
		foreach ( $permissions as $permission ) {
			$user_id = $permission->get_user_id();
			if ( $user_id && ! in_array( $user_id, $user_ids, true ) ) {
				$user_ids[] = $user_id;
			}
		}

		// Apply offset and limit.
		return array_slice( $user_ids, $offset, $limit );
	}

	/**
	 * Check if a user has opted out of release emails.
	 *
	 * @param int $user_id The user ID.
	 * @return bool True if opted out.
	 */
	public function is_opted_out( int $user_id ): bool {
		return get_user_meta( $user_id, self::OPTOUT_META_KEY, true ) === '1';
	}

	/**
	 * Add opt-out checkbox to My Account page.
	 *
	 * @return void
	 */
	public function add_optout_checkbox(): void {
		$user_id = get_current_user_id();
		$opted_out = $this->is_opted_out( $user_id );

		?>
		<fieldset>
			<legend><?php esc_html_e( 'Email Preferences', 'wp-update-server-plugin' ); ?></legend>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="wu_release_email_optout">
					<input
						type="checkbox"
						name="wu_release_email_optout"
						id="wu_release_email_optout"
						value="1"
						<?php checked( $opted_out, true ); ?>
					/>
					<?php esc_html_e( 'Do not send me emails when new product versions are released', 'wp-update-server-plugin' ); ?>
				</label>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Save opt-out preference.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 */
	public function save_optout_preference( int $user_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		$opted_out = isset( $_POST['wu_release_email_optout'] ) ? '1' : '0';
		update_user_meta( $user_id, self::OPTOUT_META_KEY, $opted_out );
	}
}
