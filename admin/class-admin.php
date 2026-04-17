<?php
/**
 * Admin UI: menus, settings, AJAX handlers.
 */
class WNE_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_wne_get_product_ids',    [ $this, 'ajax_get_product_ids' ] );
        add_action( 'wp_ajax_wne_get_templates_batch', [ $this, 'ajax_get_templates_batch' ] );
    }

    public function register_menus(): void {
        add_menu_page(
            'Nostr Export', 'Nostr Export',
            'manage_woocommerce', 'wne-export',
            [ $this, 'render_export_page' ],
            'dashicons-share', 56
        );
        add_submenu_page(
            'wne-export', 'Export Products', 'Export Products',
            'manage_woocommerce', 'wne-export',
            [ $this, 'render_export_page' ]
        );
        add_submenu_page(
            'wne-export', 'Settings', 'Settings',
            'manage_woocommerce', 'wne-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'wne_settings_group', 'wne_relay_urls', [
            'sanitize_callback' => 'sanitize_textarea_field',
        ] );
        register_setting( 'wne_settings_group', 'wne_blossom_url', [
            'sanitize_callback' => 'esc_url_raw',
        ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'wne-' ) === false ) return;

        wp_enqueue_style(
            'wne-admin',
            WNE_PLUGIN_URL . 'assets/css/admin.css',
            [], WNE_VERSION
        );

        if ( strpos( $hook, 'wne-export' ) !== false ) {
            // nostr-tools browser bundle (exposes window.NostrTools)
            // Source: https://github.com/nbd-wtf/nostr-tools v2.13.0
            wp_enqueue_script(
                'nostr-tools',
                WNE_PLUGIN_URL . 'assets/js/nostr.bundle.js',
                [], '2.13.0', true
            );

            wp_enqueue_script(
                'wne-export',
                WNE_PLUGIN_URL . 'assets/js/export.js',
                [ 'jquery', 'nostr-tools' ],
                WNE_VERSION, true
            );

            wp_localize_script( 'wne-export', 'wneData', [
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'wne_export' ),
                'relay_urls' => array_values( array_filter(
                    array_map( 'trim', explode( "\n", get_option( 'wne_relay_urls', '' ) ) )
                ) ),
                'blossom_url' => rtrim( get_option( 'wne_blossom_url', '' ), '/' ),
                'batch'      => 5,
            ] );
        }
    }

    public function render_settings_page(): void {
        include WNE_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public function render_export_page(): void {
        include WNE_PLUGIN_DIR . 'admin/views/export-page.php';
    }

    /** AJAX: return all published product IDs. */
    public function ajax_get_product_ids(): void {
        check_ajax_referer( 'wne_export', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( esc_html__( 'Insufficient permissions.', 'woo-nostr-export' ) );
        }
        $ids = WNE_Nostr_Exporter::get_all_product_ids();
        wp_send_json_success( [ 'ids' => $ids, 'total' => count( $ids ) ] );
    }

    /** AJAX: return unsigned event templates for a batch of product IDs. */
    public function ajax_get_templates_batch(): void {
        check_ajax_referer( 'wne_export', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( esc_html__( 'Insufficient permissions.', 'woo-nostr-export' ) );
        }

        $ids = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( esc_html__( 'No IDs provided.', 'woo-nostr-export' ) );
        }

        $batch_templates = [];
        foreach ( $ids as $pid ) {
            $product = wc_get_product( $pid );
            $templates = WNE_Nostr_Exporter::get_templates( (int) $pid );
            $batch_templates[] = [
                'product_id'   => $pid,
                'product_name' => $product ? $product->get_name() : 'Product #' . $pid,
                'templates'    => $templates,
            ];
        }

        wp_send_json_success( [ 'batch' => $batch_templates ] );
    }
}
