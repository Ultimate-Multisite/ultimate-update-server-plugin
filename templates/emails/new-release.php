<?php
/**
 * New Release Email (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/new-release.php.
 *
 * @package WP_Update_Server_Plugin\Templates\Emails
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
/* translators: %s: Product name */
printf( esc_html__( 'Great news! A new version of %s is now available.', 'wp-update-server-plugin' ), '<strong>' . esc_html( $product->get_name() ) . '</strong>' );
?>
</p>

<h2 style="margin-top: 20px;">
	<?php
	/* translators: %s: Version number */
	printf( esc_html__( 'Version %s', 'wp-update-server-plugin' ), esc_html( $version ) );
	?>
</h2>

<?php if ( ! empty( $changelog ) ) : ?>
<div style="background-color: #f8f8f8; border-left: 4px solid #0073aa; padding: 15px; margin: 20px 0;">
	<h3 style="margin-top: 0;"><?php esc_html_e( "What's New", 'wp-update-server-plugin' ); ?></h3>
	<div style="white-space: pre-line;"><?php echo wp_kses_post( $changelog ); ?></div>
</div>
<?php endif; ?>

<p style="margin: 25px 0;">
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'downloads' ) ); ?>" style="display: inline-block; background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;">
		<?php esc_html_e( 'Download Now', 'wp-update-server-plugin' ); ?>
	</a>
</p>

<p style="color: #666; font-size: 12px; margin-top: 30px;">
	<?php esc_html_e( 'You are receiving this email because you purchased this product.', 'wp-update-server-plugin' ); ?>
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>" style="color: #666;">
		<?php esc_html_e( 'Unsubscribe from release emails', 'wp-update-server-plugin' ); ?>
	</a>
</p>

<?php
/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
