<?php
/**
 * Swapped Pay WooCommerce Gateway
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Gateway_Swapped_Pay extends WC_Payment_Gateway {

    public $api_key = '';
    public $debug   = 'no';

    public function __construct() {
        $this->id                 = SWAPPED_PAY_WOO_SLUG;
        $this->method_title       = __( 'Swapped Pay', 'swapped-pay' );
        $this->method_description = __( 'Accept cryptocurrency payments via Swapped Pay.', 'swapped-pay' );
        $this->icon               = apply_filters( 'swapped_pay_icon', '' );
        $this->has_fields         = false;
        $this->supports           = array( 'products' );

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
        if ( isset( $_GET['section'] ) && $this->id === $_GET['section'] ) {
            $url = esc_html( rest_url( 'swapped/v1/webhook' ) );
            echo '<div class="notice notice-info"><p>' .
                sprintf( __( 'Set your Webhook URL in the Swapped dashboard (<a href="%1$s" target="_blank" rel="noreferrer noopener">Developers</a>) to: <code>%2$s</code>', 'swapped-pay' ),
                    'https://dashboard.swapped.com/pay/developers', $url ) .
                '</p></div>';
        }
    }

    public function admin_notices() {
        if ( 'yes' === $this->enabled && empty( $this->api_key ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Swapped Pay is enabled but API Key is missing.', 'swapped-pay' ) . '</p></div>';
        }
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'swapped-pay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Swapped Pay', 'swapped-pay' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'   => __( 'Title', 'swapped-pay' ),
                'type'    => 'text',
                'default' => __( 'Swapped Pay (Crypto)', 'swapped-pay' ),
            ),
            'description' => array(
                'title'   => __( 'Description', 'swapped-pay' ),
                'type'    => 'textarea',
                'default' => __( 'Pay securely with cryptocurrency via Swapped.', 'swapped-pay' ),
            ),
            'api_key' => array(
                'title'       => __( 'API Key', 'swapped-pay' ),
                'type'        => 'password',
                'description' => __( 'Your Swapped API key from dashboard.swapped.com.', 'swapped-pay' ),
                'default'     => '',
            ),
            'debug' => array(
                'title'   => __( 'Debug logging', 'swapped-pay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable logging to WooCommerce → Status → Logs (source: swapped-pay).', 'swapped-pay' ),
                'default' => 'no',
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
                'name'        => sprintf( __( 'Order #%d', 'swapped-pay' ), $order_id ),
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
            $logger->info( 'Creating Swapped order (request): ' . wp_json_encode( $payload ), array( 'source' => 'swapped-pay' ) );
        }

        $response = $this->api_request( 'POST', '/v1/orders', $payload );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( __( 'Network error connecting to Swapped. Please try again.', 'swapped-pay' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $body     = json_decode( $body_raw, true );

        if ( $logger && 'yes' === $this->debug ) {
            $logger->info( 'Create order response (' . $code . '): ' . $body_raw, array( 'source' => 'swapped-pay' ) );
        }

        // Cloudflare challenge or 403?
        if ( false !== stripos( $body_raw, 'cdn-cgi/challenge-platform' ) || $code == 403 ) {
            $admin_msg = __( 'Swapped API is behind a bot protection challenge (HTTP 403). Ask Swapped to whitelist your server IP or provide an API hostname without challenges.', 'swapped-pay' );
            if ( current_user_can( 'manage_woocommerce' ) ) wc_add_notice( $admin_msg, 'error' );
            else wc_add_notice( __( 'Payment temporarily unavailable. Please try another method.', 'swapped-pay' ), 'error' );
            return array( 'result' => 'failure' );
        }

        if ( $code >= 200 && $code < 300 && is_array( $body ) ) {
            $link        = $body['data']['order']['link'] ?? $body['data']['link'] ?? $body['link'] ?? $body['url'] ?? $body['paymentUrl'] ?? '';
            $sw_order_id = $body['data']['order']['id']   ?? $body['data']['id']   ?? $body['id']   ?? '';

            if ( $sw_order_id ) $order->update_meta_data( '_swapped_order_id', sanitize_text_field( $sw_order_id ) );
            if ( $link )        $order->update_meta_data( '_swapped_order_link', esc_url_raw( $link ) );
            $order->save();

            if ( empty( $link ) ) {
                wc_add_notice( __( 'Could not start Swapped Pay session. Please contact support.', 'swapped-pay' ), 'error' );
                return array( 'result' => 'failure' );
            }

            // Always pending until webhook confirms payment.
            $order->update_status( 'pending', __( 'Awaiting crypto payment via Swapped.', 'swapped-pay' ) );
            WC()->cart->empty_cart();

            return array( 'result' => 'success', 'redirect' => $link );
        }

        $message = ( is_array( $body ) && ( $body['message'] ?? '' ) ) ? $body['message'] : sprintf( __( 'Unexpected error creating payment. HTTP %d', 'swapped-pay' ), $code );
        wc_add_notice( sprintf( __( 'Payment error: %s', 'swapped-pay' ), esc_html( $message ) ), 'error' );
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
                 'User-Agent' => 'SwappedPayWoo/'. SWAPPED_PAY_WOO_VERSION .' (+'. home_url() .')',
            ),
            'timeout' => 45,
            'body'    => ! empty( $body ) ? wp_json_encode( $body ) : null,
        );

        return wp_remote_request( $url, $args );
    }
}