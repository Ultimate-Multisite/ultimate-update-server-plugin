<?php
/**
 * New Release Email (Plain Text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/new-release.php.
 *
 * @package WP_Update_Server_Plugin\Templates\Emails\Plain
 * @version 1.0.0
 *
 * @var WC_Product $product       The product object.
 * @var string     $version       The version string.
 * @var string     $changelog     The changelog excerpt.
 * @var string     $email_heading The email heading.
 * @var string     $additional_content Additional content.
 * @var bool       $sent_to_admin Whether sent to admin.
 * @var bool       $plain_text    Whether plain text.
 * @var WC_Email   $email         The email object.
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: Product name */
echo sprintf( esc_html__( 'Great news! A new version of %s is now available.', 'wp-update-server-plugin' ), esc_html( $product->get_name() ) ) . "\n\n";

/* translators: %s: Version number */
echo sprintf( esc_html__( 'Version %s', 'wp-update-server-plugin' ), esc_html( $version ) ) . "\n";
echo "----------------------------------------\n\n";

if ( ! empty( $changelog ) ) {
	echo esc_html__( "What's New:", 'wp-update-server-plugin' ) . "\n\n";
	echo esc_html( $changelog ) . "\n\n";
	echo "----------------------------------------\n\n";
}

echo esc_html__( 'Download Now:', 'wp-update-server-plugin' ) . "\n";
echo esc_url( wc_get_account_endpoint_url( 'downloads' ) ) . "\n\n";

echo "----------------------------------------\n\n";

echo esc_html__( 'You are receiving this email because you purchased this product.', 'wp-update-server-plugin' ) . "\n";
/* translators: %s: URL to account edit page */
echo sprintf( esc_html__( 'Unsubscribe from release emails: %s', 'wp-update-server-plugin' ), esc_url( wc_get_account_endpoint_url( 'edit-account' ) ) ) . "\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
