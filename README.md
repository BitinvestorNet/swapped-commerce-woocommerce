# Swapped Pay for WooCommerce

Accept cryptocurrency payments in WooCommerce using Swapped Pay. This plugin adds a payment gateway that redirects customers to Swapped Pay for a secure crypto payment experience, then updates the WooCommerce order when payment is confirmed.

– Version: 1.1.3
– Requires WordPress: 6.2+
– Requires WooCommerce: 7.0+
– Requires PHP: 7.4+

## Quick start

1. Download the plugin ZIP
2. In WordPress Admin: Plugins → Add New → Upload Plugin → select the ZIP → Install Now → Activate
3. Go to WooCommerce → Settings → Payments → Swapped Pay → Manage
4. Enter your Swapped API Key and enable the gateway. Save!
5. In your Swapped dashboard, set the webhook URL to: `https://your-domain.tld/wp-json/swapped/v1/webhook`

Done — customers can now pay with crypto via Swapped.

## Notes

- Supports WooCommerce Checkout Blocks and HPOS *(High-Performance Order Storage)*.
- Optional debug logging *(WooCommerce → Status → Logs, source: `swapped-pay`)*.

## Troubleshooting

- Orders not updating: verify the webhook URL above and that your site is publicly reachable over HTTPS.
- 403 or bot challenge: if the API is behind bot protection, ask Swapped to whitelist your server IP or provide an alternative API hostname.
- Need help? Email us at support (at) swapped (dot) com.

## Project 

- `swapped-pay-woocommerce.php`: Bootstrap, gateway registration, REST route, Blocks script registration, settings link
- `includes/class-wc-gateway-swapped-pay.php`: Gateway (settings, API calls, `process_payment`, notices)
- `assets/js/blocks.js`: Checkout Blocks registration for the payment method
- `readme.txt`: WordPress.org-style header

## License

- GPL-2.0+


