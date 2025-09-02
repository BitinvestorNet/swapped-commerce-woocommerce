<?php
/**
 * Plugin Name: Swapped Pay: Accept cryptocurrency payments
 * Plugin URI: https://swapped.com
 * Description: Accept cryptocurrency payments via Swapped Pay in WooCommerce.
 * Author: Swapped.com
 * Version: 1.1.3
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * WC requires at least: 7.0
 * WC tested up to: 9.2
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: swapped-pay
 *
 * @package SwappedPayWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SWAPPED_PAY_WOO_VERSION', '1.1.3' );
define( 'SWAPPED_PAY_WOO_SLUG', 'swapped-pay' );
define( 'SWAPPED_PAY_WOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWAPPED_PAY_WOO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with HPOS (High-Performance Order Storage).
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/**
 * Initialize the gateway after WooCommerce loads.
 */
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }
    require_once SWAPPED_PAY_WOO_PATH . 'includes/class-wc-gateway-swapped-pay.php';

    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        $methods[] = 'WC_Gateway_Swapped_Pay';
        return $methods;
    } );
}, 20 );

/**
 * REST API route for webhooks: /wp-json/swapped/v1/webhook
 */
add_action( 'rest_api_init', function () {
    register_rest_route(
        'swapped/v1',
        '/webhook',
        array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => 'swapped_pay_webhook_handler',
            'permission_callback' => '__return_true',
        )
    );
} );

/**
 * Webhook handler.
 */
function swapped_pay_webhook_handler( WP_REST_Request $request ) {
    $raw = $request->get_body();
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_json' ), 400 );
    }

    // Required fields
    $event_type  = isset( $data['event_type'] ) ? sanitize_text_field( $data['event_type'] ) : '';
    $sw_order_id = isset( $data['order_id'] ) ? sanitize_text_field( $data['order_id'] ) : '';
    $order_status= isset( $data['order_status'] ) ? sanitize_text_field( $data['order_status'] ) : '';
    $ext_order_id= isset( $data['external_order_id'] ) ? absint( $data['external_order_id'] ) : 0;

    if ( 'ORDER_COMPLETED' !== $event_type || 'PAYMENT_CONFIRMED_ACCURATE' !== $order_status || ! $ext_order_id || ! $sw_order_id ) {
        // Strictly ignore anything that doesn't match the required combination.
        return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
    }

    // Locate Woo order by external_order_id (Woo order ID)
    $order = wc_get_order( $ext_order_id );
    if ( ! $order ) {
        return new WP_REST_Response( array( 'ok' => false, 'reason' => 'order_not_found' ), 404 );
    }

    // Idempotency: if already paid, acknowledge
    if ( $order->is_paid() ) {
        return new WP_REST_Response( array( 'ok' => true, 'already_paid' => true, 'order_id' => $order->get_id() ), 200 );
    }

    // Mark as paid
    $order->payment_complete( $sw_order_id );

    // Add a detailed note
    $amount          = isset( $data['order_purchase_amount'] ) ? $data['order_purchase_amount'] : '';
    $currency        = isset( $data['order_purchase_currency'] ) ? sanitize_text_field( $data['order_purchase_currency'] ) : '';
    $crypto_symbol   = isset( $data['order_crypto'] ) ? sanitize_text_field( $data['order_crypto'] ) : '';
    $crypto_amount   = isset( $data['order_crypto_amount'] ) ? $data['order_crypto_amount'] : '';
    $merchant_id     = isset( $data['merchant_id'] ) ? sanitize_text_field( $data['merchant_id'] ) : '';
    $ext_customer_id = isset( $data['external_customer_id'] ) ? sanitize_text_field( $data['external_customer_id'] ) : '';

    $note_parts = array(
        sprintf( __( 'Swapped payment confirmed. Swapped Order: %s', 'swapped-pay' ), $sw_order_id ),
    );
    if ( $amount !== '' && $currency !== '' ) {
        $note_parts[] = sprintf( __( 'Fiat: %s %s', 'swapped-pay' ), $amount, $currency );
    }
    if ( $crypto_symbol !== '' && $crypto_amount !== '' ) {
        $note_parts[] = sprintf( __( 'Crypto: %s %s', 'swapped-pay' ), $crypto_amount, $crypto_symbol );
    }
    if ( $merchant_id !== '' ) {
        $note_parts[] = sprintf( __( 'Merchant ID: %s', 'swapped-pay' ), $merchant_id );
    }
    if ( $ext_customer_id !== '' ) {
        $note_parts[] = sprintf( __( 'External customer ID: %s', 'swapped-pay' ), $ext_customer_id );
    }

    $order->add_order_note( implode( ' | ', $note_parts ) );
    $order->save();

    return new WP_REST_Response( array( 'ok' => true, 'order_id' => $order->get_id(), 'status' => $order->get_status() ), 200 );
}

/**
 * Blocks integration (Checkout block).
 */
add_action( 'enqueue_block_assets', function() {
    if ( is_admin() ) return;
    if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
        wp_register_script(
            'swapped-pay-blocks',
            SWAPPED_PAY_WOO_URL . 'assets/js/blocks.js',
            array( 'wp-i18n', 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
            SWAPPED_PAY_WOO_VERSION,
            true
        );

        $settings = get_option( 'woocommerce_' . SWAPPED_PAY_WOO_SLUG . '_settings', array() );
        wp_localize_script( 'swapped-pay-blocks', 'SWAPPED_PAY_BLOCKS_DATA', array(
            'title'       => isset( $settings['title'] ) ? $settings['title'] : __( 'Swapped Pay', 'swapped-pay' ),
            'description' => isset( $settings['description'] ) ? $settings['description'] : __( 'Pay with crypto via Swapped', 'swapped-pay' ),
            'enabled'     => isset( $settings['enabled'] ) ? $settings['enabled'] : 'no',
            'slug'        => SWAPPED_PAY_WOO_SLUG,
        ) );

        wp_enqueue_script( 'swapped-pay-blocks' );
    }
} );

/**
 * Settings link.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . SWAPPED_PAY_WOO_SLUG );
    $links[] = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'swapped-pay' ) . '</a>';
    return $links;
} );