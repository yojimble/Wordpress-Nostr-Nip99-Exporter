<?php defined( 'ABSPATH' ) || exit;
$relays = array_filter( array_map( 'trim', explode( "\n", get_option( 'wne_relay_urls', '' ) ) ) );
?>
<div class="wrap wne-wrap">
    <h1><?php esc_html_e( 'Nostr Export &mdash; Products', 'woo-nostr-export' ); ?></h1>

    <?php if ( empty( $relays ) ) : ?>
        <div class="notice notice-warning">
            <p><?php printf(
                /* translators: %s: URL to settings page */
                esc_html__( 'Please %sconfigure relay URLs%s before exporting.', 'woo-nostr-export' ),
                '<a href="' . esc_url( admin_url( 'admin.php?page=wne-settings' ) ) . '">',
                '</a>'
            ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Step 1: Connect Signer -->
    <div class="wne-card" id="wne-signer-section">
        <h2><?php esc_html_e( 'Step 1 &mdash; Connect Signer', 'woo-nostr-export' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Your private key never leaves the browser. Choose how to sign events:', 'woo-nostr-export' ); ?></p>

        <div class="wne-signer-tabs">
            <button class="wne-tab-btn active" data-tab="nip07"><?php esc_html_e( 'Browser Extension (NIP-07)', 'woo-nostr-export' ); ?></button>
            <button class="wne-tab-btn" data-tab="nip46"><?php esc_html_e( 'Remote Signer / Amber (NIP-46)', 'woo-nostr-export' ); ?></button>
            <button class="wne-tab-btn" data-tab="privkey"><?php esc_html_e( 'Private Key', 'woo-nostr-export' ); ?></button>
        </div>

        <!-- NIP-07 -->
        <div class="wne-tab-pane active" id="wne-tab-nip07">
            <p><?php esc_html_e( 'Use a browser extension like Alby, nos2x, or Nostore that injects window.nostr.', 'woo-nostr-export' ); ?></p>
            <button id="wne-connect-nip07" class="button button-primary"><?php esc_html_e( 'Connect Extension', 'woo-nostr-export' ); ?></button>
            <span id="wne-nip07-status" class="wne-status"></span>
        </div>

        <!-- NIP-46 -->
        <div class="wne-tab-pane" id="wne-tab-nip46">
            <p><?php esc_html_e( 'For Amber (Android) or any NIP-46 bunker. Paste the bunker:// URI shown by your signer app.', 'woo-nostr-export' ); ?></p>
            <input type="text" id="wne-bunker-uri" class="regular-text" placeholder="bunker://pubkey?relay=wss://...&secret=..." style="width:100%;max-width:560px;" />
            <br><br>
            <button id="wne-connect-nip46" class="button button-primary"><?php esc_html_e( 'Connect Bunker', 'woo-nostr-export' ); ?></button>
            <span id="wne-nip46-status" class="wne-status"></span>
        </div>

        <!-- Private Key -->
        <div class="wne-tab-pane" id="wne-tab-privkey">
            <p><?php esc_html_e( 'Enter your hex or nsec1... private key. It is used only in this browser tab and is never sent to the server.', 'woo-nostr-export' ); ?></p>
            <input type="password" id="wne-privkey-input" class="regular-text" placeholder="nsec1... or 64-char hex" style="width:100%;max-width:400px;" autocomplete="new-password" />
            <br><br>
            <button id="wne-connect-privkey" class="button button-primary"><?php esc_html_e( 'Use Key', 'woo-nostr-export' ); ?></button>
            <span id="wne-privkey-status" class="wne-status"></span>
        </div>

        <!-- Connected pubkey display -->
        <div id="wne-pubkey-display" style="display:none;margin-top:14px;" class="wne-info-box">
            <strong><?php esc_html_e( 'Signed in as:', 'woo-nostr-export' ); ?></strong> <code id="wne-pubkey-value"></code>
        </div>
    </div>

    <!-- Step 2: Export -->
    <div class="wne-card" id="wne-export-section">
        <h2><?php esc_html_e( 'Step 2 &mdash; Export', 'woo-nostr-export' ); ?></h2>

        <?php if ( ! empty( $relays ) ) : ?>
        <p>
            <strong><?php esc_html_e( 'Relays:', 'woo-nostr-export' ); ?></strong>
            <?php foreach ( $relays as $r ) echo '<code>' . esc_html( $r ) . '</code> '; ?>
        </p>
        <?php endif; ?>

        <p class="description">
            <?php esc_html_e( 'Products are exported as NIP-99 classified listings (kind 30402). Variable products publish one event per variation, each tagged with its attributes, price, and stock level.', 'woo-nostr-export' ); ?>
        </p>

        <button id="wne-start-export" class="button button-primary button-large" disabled>
            <?php esc_html_e( 'Export All Products to Nostr', 'woo-nostr-export' ); ?>
        </button>
        <span id="wne-spinner" class="spinner" style="float:none;visibility:hidden;margin-left:8px;vertical-align:middle;"></span>
        <p id="wne-no-signer-msg" class="description" style="color:#b32d2e;"><?php esc_html_e( 'Connect a signer above before exporting.', 'woo-nostr-export' ); ?></p>

        <div id="wne-progress-wrap" style="display:none;margin-top:16px;">
            <div class="wne-progress-bar-bg">
                <div id="wne-progress-bar" class="wne-progress-bar" style="width:0%"></div>
            </div>
            <p id="wne-progress-text" style="margin-top:6px;"></p>
        </div>
    </div>

    <!-- Results -->
    <div id="wne-results" style="margin-top:8px;"></div>
</div>
