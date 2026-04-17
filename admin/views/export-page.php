<?php defined( 'ABSPATH' ) || exit;
$relays = array_filter( array_map( 'trim', explode( "\n", get_option( 'wne_relay_urls', '' ) ) ) );
?>
<div class="wrap wne-wrap">
    <h1>Nostr Export &mdash; Products</h1>

    <?php if ( empty( $relays ) ) : ?>
        <div class="notice notice-warning">
            <p>Please <a href="<?php echo esc_url( admin_url( 'admin.php?page=wne-settings' ) ); ?>">configure relay URLs</a> before exporting.</p>
        </div>
    <?php endif; ?>

    <!-- Step 1: Connect Signer -->
    <div class="wne-card" id="wne-signer-section">
        <h2>Step 1 &mdash; Connect Signer</h2>
        <p class="description">Your private key never leaves the browser. Choose how to sign events:</p>

        <div class="wne-signer-tabs">
            <button class="wne-tab-btn active" data-tab="nip07">Browser Extension (NIP-07)</button>
            <button class="wne-tab-btn" data-tab="nip46">Remote Signer / Amber (NIP-46)</button>
            <button class="wne-tab-btn" data-tab="privkey">Private Key</button>
        </div>

        <!-- NIP-07 -->
        <div class="wne-tab-pane active" id="wne-tab-nip07">
            <p>Use a browser extension like <strong>Alby</strong>, <strong>nos2x</strong>, or <strong>Nostore</strong> that injects <code>window.nostr</code>.</p>
            <button id="wne-connect-nip07" class="button button-primary">Connect Extension</button>
            <span id="wne-nip07-status" class="wne-status"></span>
        </div>

        <!-- NIP-46 -->
        <div class="wne-tab-pane" id="wne-tab-nip46">
            <p>For <strong>Amber</strong> (Android) or any NIP-46 bunker. Paste the <code>bunker://</code> URI shown by your signer app.</p>
            <input type="text" id="wne-bunker-uri" class="regular-text" placeholder="bunker://pubkey?relay=wss://...&secret=..." style="width:100%;max-width:560px;" />
            <br><br>
            <button id="wne-connect-nip46" class="button button-primary">Connect Bunker</button>
            <span id="wne-nip46-status" class="wne-status"></span>
        </div>

        <!-- Private Key -->
        <div class="wne-tab-pane" id="wne-tab-privkey">
            <p>Enter your hex or <code>nsec1...</code> private key. It is used only in this browser tab and is never sent to the server.</p>
            <input type="password" id="wne-privkey-input" class="regular-text" placeholder="nsec1... or 64-char hex" style="width:100%;max-width:400px;" autocomplete="new-password" />
            <br><br>
            <button id="wne-connect-privkey" class="button button-primary">Use Key</button>
            <span id="wne-privkey-status" class="wne-status"></span>
        </div>

        <!-- Connected pubkey display -->
        <div id="wne-pubkey-display" style="display:none;margin-top:14px;" class="wne-info-box">
            <strong>Signed in as:</strong> <code id="wne-pubkey-value"></code>
        </div>
    </div>

    <!-- Step 2: Export -->
    <div class="wne-card" id="wne-export-section">
        <h2>Step 2 &mdash; Export</h2>

        <?php if ( ! empty( $relays ) ) : ?>
        <p>
            <strong>Relays:</strong>
            <?php foreach ( $relays as $r ) echo '<code>' . esc_html( $r ) . '</code> '; ?>
        </p>
        <?php endif; ?>

        <p class="description">
            Products are exported as <strong>NIP-99</strong> classified listings (kind 30402).
            Variable products publish one event per variation, each tagged with its attributes, price, and stock level.
        </p>

        <button id="wne-start-export" class="button button-primary button-large" disabled>
            Export All Products to Nostr
        </button>
        <span id="wne-spinner" class="spinner" style="float:none;visibility:hidden;margin-left:8px;vertical-align:middle;"></span>
        <p id="wne-no-signer-msg" class="description" style="color:#b32d2e;">Connect a signer above before exporting.</p>

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
