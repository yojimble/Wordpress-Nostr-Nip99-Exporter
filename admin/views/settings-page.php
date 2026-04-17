<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wne-wrap">
    <h1>Nostr Export &mdash; Settings</h1>
    <?php settings_errors( 'wne_settings_group' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'wne_settings_group' ); ?>

        <table class="form-table wne-form-table">
            <tr>
                <th scope="row"><label for="wne_relay_urls">Relay URLs</label></th>
                <td>
                    <textarea
                        id="wne_relay_urls"
                        name="wne_relay_urls"
                        rows="6"
                        class="large-text"
                        placeholder="wss://relay.damus.io&#10;wss://relay.nostr.band&#10;wss://nos.lol"
                    ><?php echo esc_textarea( get_option( 'wne_relay_urls', '' ) ); ?></textarea>
                    <p class="description">One relay URL per line (must start with <code>wss://</code>).</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="wne_blossom_url">Blossom Server URL</label></th>
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
                        Product images will be uploaded to this Blossom server (BUD-01) before publishing,
                        making listings fully independent of your WordPress site.<br>
                        Leave empty to use your existing WordPress image URLs instead.<br>
                        Popular servers: <code>https://blossom.band</code>, <code>https://cdn.satellite.earth</code>, <code>https://nostr.build</code>
                    </p>
                </td>
            </tr>
        </table>

        <p class="description" style="max-width:600px;padding:10px 0;">
            <strong>Signing keys</strong> are never stored on the server. You will connect your Nostr signer
            (browser extension, Amber, or enter your key directly) on the Export page when you run an export.
        </p>

        <?php submit_button( 'Save Settings' ); ?>
    </form>
</div>
