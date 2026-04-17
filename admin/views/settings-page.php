<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wne-wrap">
    <h1><?php esc_html_e( 'Nostr Export &mdash; Settings', 'woo-nostr-export' ); ?></h1>
    <?php settings_errors( 'wne_settings_group' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'wne_settings_group' ); ?>

        <table class="form-table wne-form-table">
            <tr>
                <th scope="row"><label for="wne_relay_urls"><?php esc_html_e( 'Relay URLs', 'woo-nostr-export' ); ?></label></th>
                <td>
                    <textarea
                        id="wne_relay_urls"
                        name="wne_relay_urls"
                        rows="6"
                        class="large-text"
                        placeholder="wss://relay.damus.io&#10;wss://relay.nostr.band&#10;wss://nos.lol"
                    ><?php echo esc_textarea( get_option( 'wne_relay_urls', '' ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One relay URL per line (must start with wss://).', 'woo-nostr-export' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="wne_blossom_url"><?php esc_html_e( 'Blossom Server URL', 'woo-nostr-export' ); ?></label></th>
                <td>
                    <input
                        type="url"
                        id="wne_blossom_url"
                        name="wne_blossom_url"
                        class="regular-text"
                        value="<?php echo esc_attr( get_option( 'wne_blossom_url', '' ) ); ?>"
                        placeholder="https://blossom.band"
                    />
                    <p class="description">
                        <?php esc_html_e( 'Product images will be uploaded to this Blossom server (BUD-01) before publishing, making listings fully independent of your WordPress site.', 'woo-nostr-export' ); ?><br>
                        <?php esc_html_e( 'Leave empty to use your existing WordPress image URLs instead.', 'woo-nostr-export' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <p class="description" style="max-width:600px;padding:10px 0;">
            <?php esc_html_e( 'Signing keys are never stored on the server. You will connect your Nostr signer (browser extension, Amber, or enter your key directly) on the Export page when you run an export.', 'woo-nostr-export' ); ?>
        </p>

        <?php submit_button( esc_html__( 'Save Settings', 'woo-nostr-export' ) ); ?>
    </form>
</div>
