<?php
/**
 * Swapped Commerce WooCommerce Gateway
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Gateway_Swapped_Commerce extends WC_Payment_Gateway {

    public $api_key = '';
    public $debug   = 'no';

    public function __construct() {
        $this->id                 = SWAPPED_COMMERCE_WOO_SLUG;
        $this->method_title       = __( 'Swapped Commerce', 'swapped-commerce' );
        $this->method_description = __( 'Accept cryptocurrency payments via Swapped Commerce.', 'swapped-commerce' );
        $this->icon = SWAPPED_COMMERCE_WOO_URL . 'assets/img/swapped-commerce-logo.svg';
        $this->has_fields         = false;
        $this->supports           = array( 'products' );
        
        
        add_action( 'init', function () {
        $this->method_title       = __( 'Swapped Commerce', 'swapped-commerce' );
        $this->method_description = __( 'Accept cryptocurrency payments via Swapped Commerce.', 'swapped-commerce' );
        } );

        $this->init_form_fields();
        $this->init_settings();


        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );
        $this->api_key     = $this->get_option( 'api_key' );
        $this->debug       = $this->get_option( 'debug', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'woocommerce_settings_checkout', array( $this, 'webhook_hint' ) );
    }

    protected function logger() {
        return function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
    }

    public function webhook_hint() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check for current section.
        $section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
        if ( $section !== $this->id ) {
            return;
        }
    
        $url       = esc_url( rest_url( 'swapped/v1/webhook' ) );
        $dash_url  = 'https://dashboard.swapped.com/commerce/developers';
    
        /* translators: 1: Swapped dashboard Developers URL, 2: Webhook URL */
        $markup = sprintf(
            __( 'Set your Webhook URL in the Swapped dashboard (<a href="%1$s" target="_blank" rel="noreferrer noopener">Developers</a>) to: <code>%2$s</code>', 'swapped-commerce' ),
            esc_url( $dash_url ),
            esc_html( $url )
        );
    
        echo '<div class="notice notice-info"><p>' . wp_kses_post( $markup ) . '</p></div>';
    }

    public function admin_notices() {
        if ( 'yes' === $this->enabled && empty( $this->api_key ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Swapped Commerce is enabled but API Key is missing.', 'swapped-commerce' ) . '</p></div>';
        }
    }
    
    public function get_icon() {
    $icons  = '<img src="' . SWAPPED_COMMERCE_WOO_URL . 'assets/img/BTC.svg" alt="BTC" style="height:20px; margin-right:4px;" />';
    $icons .= '<img src="' . SWAPPED_COMMERCE_WOO_URL . 'assets/img/ETH.svg" alt="ETH" style="height:20px; margin-right:4px;" />';
    $icons .= '<img src="' . SWAPPED_COMMERCE_WOO_URL . 'assets/img/USDT.svg" alt="USDT" style="height:20px; margin-right:4px;" />';
    $icons .= '<span style="font-size:12px; margin-left:4px;">(+20 more)</span>';

    return apply_filters( 'woocommerce_gateway_icon', $icons, $this->id );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'swapped-commerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Swapped Commerce', 'swapped-commerce' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'   => __( 'Title', 'swapped-commerce' ),
                'type'    => 'text',
                'default' => __( 'Swapped Commerce (Crypto)', 'swapped-commerce' ),
            ),
            'description' => array(
                'title'   => __( 'Description', 'swapped-commerce' ),
                'type'    => 'textarea',
                'default' => __( 'Pay securely with cryptocurrency via Swapped Commerce.', 'swapped-commerce' ),
            ),
            'api_key' => array(
                'title'       => __( 'API Key', 'swapped-commerce' ),
                'type'        => 'password',
                'description' => __( 'Your Swapped Commerce API key from <a href="https://dashboard.swapped.com/commerce/developers" target="_blank">dashboard.swapped.com</a>.', 'swapped-commerce' ),
                'default'     => '',
            ),
            'debug' => array(
                'title'   => __( 'Debug logging', 'swapped-commerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable logging to WooCommerce → Status → Logs (source: swapped-commerce).', 'swapped-commerce' ),
                'default' => 'no',
            ),
            'signup' => array(
                'title'       => __( 'Don’t have an account?', 'swapped-commerce' ),
                'type'        => 'title',
                'description' => __( 'No API key yet? <a href="https://dashboard.swapped.com/commerce/register" target="_blank">Sign up to Swapped Commerce here</a> to get started.', 'swapped-commerce' ),
            ),
        );
    }

    public function is_available() {
        return ( 'yes' === $this->enabled && ! empty( $this->api_key ) );
    }

    public function process_payment( $order_id ) {
        $order    = wc_get_order( $order_id );
        $amount   = number_format( (float) $order->get_total(), 2, '.', '' ); // **string**
        $currency = $order->get_currency();

        $payload = array(
            'purchase' => array(
                /* translators: %d: WooCommerce order ID */
                'name'        => sprintf( __( 'Order #%d', 'swapped-commerce' ), $order_id ),
                'description' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
                'imageUrl'    => get_site_icon_url() ? get_site_icon_url() : wc_placeholder_img_src(),
                'price'       => $amount,
                'currency'    => $currency,
            ),
            'metadata' => array(
                'externalId'  => (string) $order_id,
                'userId'      => (string) $order->get_user_id(),
                'userCountry' => $order->get_billing_country(),
                'userName'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'userEmail'   => $order->get_billing_email(),
                'redirectUrl' => $this->get_return_url( $order ),
            ),
        );

        $logger = $this->logger();
        if ( $logger && 'yes' === $this->debug ) {
            $logger->info( 'Creating Swapped order (request): ' . wp_json_encode( $payload ), array( 'source' => 'swapped-commerce' ) );
        }

        $response = $this->api_request( 'POST', '/v1/orders', $payload );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( __( 'Network error connecting to Swapped. Please try again.', 'swapped-commerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $body     = json_decode( $body_raw, true );

        if ( $logger && 'yes' === $this->debug ) {
            $logger->info( 'Create order response (' . $code . '): ' . $body_raw, array( 'source' => 'swapped-commerce' ) );
        }

        // Cloudflare challenge or 403?
        if ( false !== stripos( $body_raw, 'cdn-cgi/challenge-platform' ) || $code == 403 ) {
            $admin_msg = __( 'Swapped API is behind a bot protection challenge (HTTP 403). Ask Swapped to whitelist your server IP or provide an API hostname without challenges.', 'swapped-commerce' );
            if ( current_user_can( 'manage_woocommerce' ) ) wc_add_notice( $admin_msg, 'error' );
            else wc_add_notice( __( 'Payment temporarily unavailable. Please try another method.', 'swapped-commerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        if ( $code >= 200 && $code < 300 && is_array( $body ) ) {
            $link        = $body['data']['order']['link'] ?? $body['data']['link'] ?? $body['link'] ?? $body['url'] ?? $body['paymentUrl'] ?? '';
            $sw_order_id = $body['data']['order']['id']   ?? $body['data']['id']   ?? $body['id']   ?? '';

            if ( $sw_order_id ) $order->update_meta_data( '_swapped_order_id', sanitize_text_field( $sw_order_id ) );
            if ( $link )        $order->update_meta_data( '_swapped_order_link', esc_url_raw( $link ) );
            $order->save();

            if ( empty( $link ) ) {
                wc_add_notice( __( 'Could not start Swapped Commerce session. Please contact support.', 'swapped-commerce' ), 'error' );
                return array( 'result' => 'failure' );
            }

            // Always pending until webhook confirms payment.
            $order->update_status( 'pending', __( 'Awaiting crypto payment via Swapped.', 'swapped-commerce' ) );
            WC()->cart->empty_cart();

            return array( 'result' => 'success', 'redirect' => $link );
        }
        /* translators: %s: error message from API */
        $message = ( is_array( $body ) && ( $body['message'] ?? '' ) ) ? $body['message'] : sprintf( __( 'Unexpected error creating payment. HTTP %d', 'swapped-commerce' ), $code );
        wc_add_notice( sprintf( __( 'Payment error: %s', 'swapped-commerce' ), esc_html( $message ) ), 'error' );
        return array( 'result' => 'failure' );
    }

    protected function api_request( $method, $path, $body = array() ) {
        $url = 'https://pay-api.swapped.com' . $path;

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => '*/*',
                'X-API-Key'    => $this->api_key,
                'User-Agent'   => 'SwappedPayWoo/'. SWAPPED_COMMERCE_WOO_VERSION .' (+'. home_url() .')',
            ),
            'timeout' => 45,
            'body'    => ! empty( $body ) ? wp_json_encode( $body ) : null,
        );

        return wp_remote_request( $url, $args );
    }
}