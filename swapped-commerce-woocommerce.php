<?php
/**
 * Plugin Name: Swapped Commerce
 * Plugin URI: https://swapped.com/commerce
 * Description: Accept cryptocurrency payments via Swapped Commerce in WooCommerce.
 * Author: Swapped.com
 * Author URI: https://swapped.com
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * WC requires at least: 7.0
 * WC tested up to: 9.2
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: swapped-commerce
 *
 * @package SwappedCommerceWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SWAPPED_COMMERCE_WOO_VERSION', '1.0.0' );
define( 'SWAPPED_COMMERCE_WOO_SLUG', 'swapped-commerce' );
define( 'SWAPPED_COMMERCE_WOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWAPPED_COMMERCE_WOO_URL', plugin_dir_url( __FILE__ ) );

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
    require_once SWAPPED_COMMERCE_WOO_PATH . 'includes/class-wc-gateway-swapped-commerce.php';

    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        $methods[] = 'WC_Gateway_Swapped_Commerce';
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
            'callback' => 'swapped_commerce_webhook_handler',
            'permission_callback' => '__return_true',
        )
    );
} );

/**
 * Webhook handler.
 * Supports ONLY the new Swapped format.
 */
function swapped_commerce_webhook_handler( WP_REST_Request $request ) {
    $raw  = $request->get_body();
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'reason' => 'invalid_json' ), 400 );
    }

    // Required fields
    $event_type   = isset( $data['event_type'] ) ? sanitize_text_field( $data['event_type'] ) : '';
    $sw_order_id  = isset( $data['order_id'] ) ? sanitize_text_field( $data['order_id'] ) : '';
    $order_status = isset( $data['order_status'] ) ? sanitize_text_field( $data['order_status'] ) : '';
    $ext_order_id = isset( $data['external_order_id'] ) ? absint( $data['external_order_id'] ) : 0;

    if ( 'ORDER_COMPLETED' !== $event_type || 'PAYMENT_CONFIRMED_ACCURATE' !== $order_status || ! $ext_order_id || ! $sw_order_id ) {
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
    $amount          = $data['order_purchase_amount'] ?? '';
    $currency        = isset( $data['order_purchase_currency'] ) ? sanitize_text_field( $data['order_purchase_currency'] ) : '';
    $crypto_symbol   = isset( $data['order_crypto'] ) ? sanitize_text_field( $data['order_crypto'] ) : '';
    $crypto_amount   = $data['order_crypto_amount'] ?? '';
    $merchant_id     = isset( $data['merchant_id'] ) ? sanitize_text_field( $data['merchant_id'] ) : '';
    $ext_customer_id = isset( $data['external_customer_id'] ) ? sanitize_text_field( $data['external_customer_id'] ) : '';
    
    /* translators: %s: Swapped Commerce order ID (e.g., SP-XXXXXX) */
    $note_parts = array(
        sprintf( __( 'Swapped payment confirmed. Swapped Order: %s', 'swapped-commerce' ), $sw_order_id ),
    );
    if ( $amount !== '' && $currency !== '' ) {
        /* translators: 1: fiat amount (e.g., 8), 2: currency code (e.g., DKK) */
        $note_parts[] = sprintf( __( 'Fiat: %1$s %2$s', 'swapped-commerce' ), $amount, $currency );
    }
    if ( $crypto_symbol !== '' && $crypto_amount !== '' ) {
        /* translators: 1: crypto amount (e.g., 0.01), 2: crypto symbol (e.g., LTC) */
        $note_parts[] = sprintf( __( 'Crypto: %1$s %2$s', 'swapped-commerce' ), $crypto_amount, $crypto_symbol );
    }
    if ( $merchant_id !== '' ) {
        /* translators: %s: merchant ID (UUID) */
        $note_parts[] = sprintf( __( 'Merchant ID: %s', 'swapped-commerce' ), $merchant_id );
    }
    if ( $ext_customer_id !== '' ) {
        /* translators: %s: external customer ID (string) */
        $note_parts[] = sprintf( __( 'External customer ID: %s', 'swapped-commerce' ), $ext_customer_id );
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
            'swapped-commerce-blocks',
            SWAPPED_COMMERCE_WOO_URL . 'assets/js/blocks.js',
            array( 'wp-i18n', 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
            SWAPPED_COMMERCE_WOO_VERSION,
            true
        );

        $settings = get_option( 'woocommerce_' . SWAPPED_COMMERCE_WOO_SLUG . '_settings', array() );

        wp_localize_script( 'swapped-commerce-blocks', 'SWAPPED_COMMERCE_BLOCKS_DATA', array(
            'title'       => $settings['title'] ?? __( 'Swapped Commerce', 'swapped-commerce' ),
            'description' => $settings['description'] ?? __( 'Pay with crypto via Swapped', 'swapped-commerce' ),
            'enabled'     => $settings['enabled'] ?? 'no',
            'slug'        => SWAPPED_COMMERCE_WOO_SLUG,
            'icons'       => array(
                'btc'  => SWAPPED_COMMERCE_WOO_URL . 'assets/img/BTC.svg',
                'eth'  => SWAPPED_COMMERCE_WOO_URL . 'assets/img/ETH.svg',
                'usdt' => SWAPPED_COMMERCE_WOO_URL . 'assets/img/USDT.svg',
            ),
            'moreText'    => '(+20 more)',
        ) );

        wp_enqueue_script( 'swapped-commerce-blocks' );
    }
} );

/**
 * Settings link.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . SWAPPED_COMMERCE_WOO_SLUG );
    $links[] = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'swapped-commerce' ) . '</a>';
    return $links;
} );