<?php
/**
 * Plugin Name:  WooCommerce Nostr Export
 * Description:  Export WooCommerce products to Nostr relays as NIP-99 classified listings (kind 30402). Handles simple products, variable products, attributes, and stock levels. Signing is done in the browser — no server-side crypto dependencies required.
 * Version:      1.0.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author:       Your Store
 * License:      GPLv2 or later
 * Text Domain:  woo-nostr-export
 *
 * HOW IT WORKS:
 *  1. PHP fetches product data from WooCommerce and builds unsigned NIP-99 event templates.
 *  2. The browser signs events using your choice of:
 *       - NIP-07 browser extension (Alby, nos2x, Nostore)
 *       - NIP-46 remote signer (Amber on Android, nsecbunker)
 *       - Direct hex/nsec private key (stays in browser, never sent to server)
 *  3. The browser publishes signed events directly to your configured Nostr relays.
 *
 * No unusual PHP extensions required.
 */

defined( 'ABSPATH' ) || exit;

define( 'WNE_VERSION',    '1.0.0' );
define( 'WNE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WNE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>WooCommerce Nostr Export</strong> requires WooCommerce to be installed and active.</p></div>';
        } );
        return;
    }

    require_once WNE_PLUGIN_DIR . 'includes/class-nostr-exporter.php';

    if ( is_admin() ) {
        require_once WNE_PLUGIN_DIR . 'admin/class-admin.php';
        new WNE_Admin();
    }
} );
