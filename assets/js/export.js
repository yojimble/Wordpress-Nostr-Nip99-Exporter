/* global jQuery, wneData, NostrTools */
(function ($) {
    'use strict';

    // ── State ──────────────────────────────────────────────────────────────
    var signer      = null;   // { getPublicKey, signEvent }
    var signerType  = null;
    var nip46Signer = null;   // BunkerSigner instance (kept for cleanup)

    var allIds      = [];
    var batchSize   = wneData.batch || 5;
    var relayUrls   = wneData.relay_urls || [];
    var blossomUrl  = wneData.blossom_url || '';
    var offset      = 0;
    var total       = 0;
    var processed   = 0;
    var running     = false;
    var pool        = null;   // shared SimplePool for the whole export session
    var blossomCache = {};    // wcImageUrl → blossomUrl (avoids re-uploading same image)

    var { finalizeEvent, generateSecretKey, getPublicKey, nip19, SimplePool } = NostrTools;

    // ── Tab switching ──────────────────────────────────────────────────────
    $(document).on('click', '.wne-tab-btn', function () {
        var tab = $(this).data('tab');
        $('.wne-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.wne-tab-pane').removeClass('active');
        $('#wne-tab-' + tab).addClass('active');
    });

    // ── Signer helpers ─────────────────────────────────────────────────────
    function setSignerReady(pubkey, type) {
        signerType = type;
        $('#wne-pubkey-value').text(pubkey);
        $('#wne-pubkey-display').show();
        $('#wne-start-export').prop('disabled', false);
        $('#wne-no-signer-msg').hide();
    }

    function statusEl(id, msg, isError) {
        var el = $(id);
        el.text(msg).css('color', isError ? '#b32d2e' : '#46b450');
    }

    // ── NIP-07 ────────────────────────────────────────────────────────────
    $('#wne-connect-nip07').on('click', async function () {
        if (typeof window.nostr === 'undefined') {
            statusEl('#wne-nip07-status', 'No extension found. Install Alby or nos2x.', true);
            return;
        }
        try {
            var pubkey = await window.nostr.getPublicKey();
            signer = {
                getPublicKey: () => window.nostr.getPublicKey(),
                signEvent:    (event) => window.nostr.signEvent(event),
            };
            statusEl('#wne-nip07-status', 'Connected!', false);
            setSignerReady(pubkey, 'nip07');
        } catch (err) {
            statusEl('#wne-nip07-status', 'Error: ' + err.message, true);
        }
    });

    // ── NIP-46 ────────────────────────────────────────────────────────────
    $('#wne-connect-nip46').on('click', async function () {
        var input = $('#wne-bunker-uri').val().trim();
        if (!input) {
            statusEl('#wne-nip46-status', 'Paste a bunker:// URI first.', true);
            return;
        }
        statusEl('#wne-nip46-status', 'Connecting…', false);

        try {
            var bp = await NostrTools.nip46.parseBunkerInput(input);
            if (!bp) throw new Error('Could not parse bunker URI.');

            var clientSecretKey = generateSecretKey();
            var bunker = new NostrTools.nip46.BunkerSigner(clientSecretKey, bp);
            await bunker.connect();

            var pubkey = await bunker.getPublicKey();
            nip46Signer = bunker;

            signer = {
                getPublicKey: () => bunker.getPublicKey(),
                signEvent:    (event) => bunker.signEvent(event),
            };

            statusEl('#wne-nip46-status', 'Connected!', false);
            setSignerReady(pubkey, 'nip46');
        } catch (err) {
            statusEl('#wne-nip46-status', 'Error: ' + err.message, true);
        }
    });

    // ── Private key ────────────────────────────────────────────────────────
    $('#wne-connect-privkey').on('click', function () {
        var raw = $('#wne-privkey-input').val().trim();
        if (!raw) {
            statusEl('#wne-privkey-status', 'Enter a key first.', true);
            return;
        }

        var secretKey;
        try {
            if (raw.startsWith('nsec1')) {
                var decoded = nip19.decode(raw);
                if (decoded.type !== 'nsec') throw new Error('Not an nsec key.');
                secretKey = decoded.data; // Uint8Array
            } else if (/^[0-9a-fA-F]{64}$/.test(raw)) {
                secretKey = hexToBytes(raw);
            } else {
                throw new Error('Must be nsec1... or 64-char hex.');
            }

            var pubkey = getPublicKey(secretKey);
            var keyCopy = secretKey; // closure

            signer = {
                getPublicKey: () => Promise.resolve(pubkey),
                signEvent: (event) => {
                    var signed = finalizeEvent(Object.assign({}, event), keyCopy);
                    return Promise.resolve(signed);
                },
            };

            statusEl('#wne-privkey-status', 'Key loaded.', false);
            setSignerReady(pubkey, 'privkey');
        } catch (err) {
            statusEl('#wne-privkey-status', 'Error: ' + err.message, true);
        }
    });

    function hexToBytes(hex) {
        var bytes = new Uint8Array(hex.length / 2);
        for (var i = 0; i < bytes.length; i++) {
            bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
        }
        return bytes;
    }

    // ── Blossom image upload (BUD-01) ─────────────────────────────────────

    async function sha256Hex(arrayBuffer) {
        var hashBuffer = await crypto.subtle.digest('SHA-256', arrayBuffer);
        return Array.from(new Uint8Array(hashBuffer))
            .map(function (b) { return b.toString(16).padStart(2, '0'); })
            .join('');
    }

    async function uploadToBlossom(imageUrl) {
        // Return cached result if already uploaded this session
        if (blossomCache[imageUrl]) return blossomCache[imageUrl];

        // Fetch image bytes
        var res = await fetch(imageUrl);
        if (!res.ok) throw new Error('Failed to fetch image (' + res.status + '): ' + imageUrl);
        var blob     = await res.blob();
        var buf      = await blob.arrayBuffer();
        var sha256   = await sha256Hex(buf);
        var mimeType = blob.type || 'application/octet-stream';

        var uploadEndpoint = blossomUrl + '/upload';
        var expiration     = String(Math.floor(Date.now() / 1000) + 300); // 5 min

        // Blossom auth event (BUD-01) — kind 24242, NOT NIP-98 kind 27235
        var authEvent = await signer.signEvent({
            kind:       24242,
            created_at: Math.floor(Date.now() / 1000),
            tags: [
                ['t',          'upload'],
                ['x',          sha256],
                ['expiration', expiration],
            ],
            content: 'Upload image',
        });

        var authHeader = 'Nostr ' + btoa(JSON.stringify(authEvent));

        var uploadRes = await fetch(uploadEndpoint, {
            method:  'PUT',
            headers: {
                'Authorization': authHeader,
                'Content-Type':  mimeType,
                'X-SHA-256':     sha256,
            },
            body: buf,
        });

        if (!uploadRes.ok) {
            var errText = await uploadRes.text().catch(function () { return uploadRes.statusText; });
            throw new Error('Blossom upload failed (' + uploadRes.status + '): ' + errText);
        }

        var result   = await uploadRes.json();
        var finalUrl = result.url;
        if (!finalUrl) throw new Error('Blossom server returned no URL.');

        blossomCache[imageUrl] = finalUrl;
        return finalUrl;
    }

    /**
     * Replace all ["image", wcUrl] tags with Blossom URLs.
     * Falls back to the original WC URL if upload fails (with a console warning).
     */
    async function resolveImages(tags) {
        if (!blossomUrl) return tags; // Blossom not configured — use WC URLs as-is

        // First pass: upload all image tags and build old→new URL map
        var urlMap = {};
        for (var i = 0; i < tags.length; i++) {
            var tag = tags[i];
            if (tag[0] === 'image' && tag[1] && !urlMap.hasOwnProperty(tag[1])) {
                try {
                    urlMap[tag[1]] = await uploadToBlossom(tag[1]);
                } catch (err) {
                    console.warn('Blossom upload failed for ' + tag[1] + ', using original URL.', err);
                    urlMap[tag[1]] = tag[1];
                }
            }
        }

        // Second pass: rewrite image and imeta tags with Blossom URLs
        return tags.map(function (tag) {
            if (tag[0] === 'image' && tag[1] && urlMap.hasOwnProperty(tag[1])) {
                var replaced = tag.slice();
                replaced[1] = urlMap[tag[1]];
                return replaced;
            }
            if (tag[0] === 'imeta') {
                return tag.map(function (el, idx) {
                    if (idx === 0) return el;
                    if (typeof el === 'string' && el.startsWith('url ')) {
                        var oldUrl = el.slice(4);
                        return urlMap.hasOwnProperty(oldUrl) ? 'url ' + urlMap[oldUrl] : el;
                    }
                    return el;
                });
            }
            return tag;
        });
    }

    // ── Publishing via shared SimplePool ──────────────────────────────────
    async function publishToRelays(signedEvent) {
        var results = [];
        var promises = pool.publish(relayUrls, signedEvent);
        for (var j = 0; j < promises.length; j++) {
            var url = relayUrls[j];
            try {
                await promises[j];
                results.push({ relay: url, success: true, message: '' });
            } catch (err) {
                results.push({ relay: url, success: false, message: String(err) });
            }
        }
        return results;
    }

    // ── Export flow ─────────────────────────────────────────────────────────
    function setProgress(done, outOf) {
        var pct = outOf > 0 ? Math.round((done / outOf) * 100) : 0;
        $('#wne-progress-bar').css('width', pct + '%');
        $('#wne-progress-text').text('Processed ' + done + ' of ' + outOf + ' products (' + pct + '%)');
    }

    async function processBatch() {
        if (offset >= allIds.length) {
            running = false;
            pool.destroy();
            pool = null;
            $('#wne-spinner').css('visibility', 'hidden');
            $('#wne-start-export').prop('disabled', false);
            setProgress(allIds.length, allIds.length);
            $('#wne-progress-text').append(' — <strong>Export complete!</strong>');
            return;
        }

        var batch = allIds.slice(offset, offset + batchSize);

        // Get unsigned templates from PHP
        var data;
        try {
            data = await $.post(wneData.ajax_url, {
                action: 'wne_get_templates_batch',
                nonce:  wneData.nonce,
                ids:    batch,
            });
        } catch (err) {
            appendResult({ error: 'Network error fetching templates: ' + err.statusText });
            offset += batchSize;
            setTimeout(processBatch, 0);
            return;
        }

        if (!data.success || !data.data || !data.data.batch) {
            appendResult({ error: 'Bad response from server.' });
            offset += batchSize;
            setTimeout(processBatch, 0);
            return;
        }

        // Sign + publish each product's templates
        for (var i = 0; i < data.data.batch.length; i++) {
            var item = data.data.batch[i];
            if (!item.templates || item.templates.length === 0) {
                appendResult({ product_id: item.product_id, product_name: item.product_name, status: 'skipped', message: 'No events generated.' });
                continue;
            }

            var eventResults = [];
            var hadError = false;

            for (var t = 0; t < item.templates.length; t++) {
                var template = item.templates[t];
                try {
                    // Resolve parent placeholder → proper NIP-33 a tag
                    var pubkey = await signer.getPublicKey();
                    template.tags = template.tags.map(function (tag) {
                        if (tag[0] === 'parent' && tag[1]) {
                            return ['a', '30402:' + pubkey + ':' + tag[1]];
                        }
                        return tag;
                    });

                    // Upload images to Blossom before signing (if configured)
                    template.tags = await resolveImages(template.tags);
                    var signed = await signer.signEvent(template);
                    var relayResults = await publishToRelays(signed);
                    eventResults.push({ event_id: signed.id, relay_results: relayResults });
                    var anyFail = relayResults.some(function (r) { return !r.success; });
                    if (anyFail) hadError = true;
                } catch (err) {
                    eventResults.push({ error: 'Sign/publish error: ' + err.message });
                    hadError = true;
                }
            }

            appendResult({
                product_id:    item.product_id,
                product_name:  item.product_name,
                event_count:   item.templates.length,
                status:        hadError ? 'partial' : 'success',
                event_results: eventResults,
            });
        }

        processed += batch.length;
        setProgress(processed, total);

        offset += batchSize;
        setTimeout(processBatch, 0); // yield to browser
    }

    function appendResult(r) {
        var html = '';
        if (r.error) {
            html = '<div class="wne-result wne-result-error"><strong>Error:</strong> ' + escHtml(r.error) + '</div>';
        } else {
            var statusClass = r.status === 'success' ? 'wne-result-ok' : (r.status === 'skipped' ? 'wne-result-skip' : 'wne-result-partial');
            var icon = r.status === 'success' ? '&#10003;' : (r.status === 'skipped' ? '&#8212;' : '&#9888;');
            html = '<div class="wne-result ' + statusClass + '">';
            html += '<strong>' + icon + ' ' + escHtml(r.product_name || ('Product #' + r.product_id)) + '</strong>';
            if (r.event_count) html += ' &mdash; ' + r.event_count + ' event(s)';
            if (r.message) html += ' <em>(' + escHtml(r.message) + ')</em>';

            if (r.event_results && r.event_results.length) {
                html += '<details style="margin-top:4px;"><summary style="cursor:pointer;font-size:12px;">Relay detail</summary><ul style="margin:4px 0 0 16px;">';
                r.event_results.forEach(function (ev) {
                    if (ev.error) {
                        html += '<li style="color:#b32d2e;">' + escHtml(ev.error) + '</li>';
                        return;
                    }
                    (ev.relay_results || []).forEach(function (rr) {
                        var icon2 = rr.success ? '&#10003;' : '&#10007;';
                        html += '<li><code>' + escHtml(rr.relay) + '</code> ' + icon2;
                        if (rr.message) html += ' — ' + escHtml(rr.message);
                        html += '</li>';
                    });
                });
                html += '</ul></details>';
            }
            html += '</div>';
        }
        $('#wne-results').append(html);
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    $('#wne-start-export').on('click', function () {
        if (running || !signer) return;
        if (!relayUrls.length) {
            alert('No relay URLs configured. Go to Settings and add at least one relay.');
            return;
        }

        running      = true;
        offset       = 0;
        processed    = 0;
        blossomCache = {};
        pool         = new SimplePool();
        $('#wne-results').empty();
        $('#wne-start-export').prop('disabled', true);
        $('#wne-spinner').css('visibility', 'visible');
        $('#wne-progress-wrap').show();
        setProgress(0, 0);
        $('#wne-progress-text').text('Fetching product list…');

        $.post(wneData.ajax_url, { action: 'wne_get_product_ids', nonce: wneData.nonce }, function (response) {
            if (response.success && response.data && response.data.ids) {
                allIds = response.data.ids;
                total  = response.data.total;
                setProgress(0, total);
                processBatch();
            } else {
                running = false;
                $('#wne-spinner').css('visibility', 'hidden');
                $('#wne-start-export').prop('disabled', false);
                $('#wne-progress-text').text('Failed to retrieve product list.');
            }
        }).fail(function () {
            running = false;
            $('#wne-spinner').css('visibility', 'hidden');
            $('#wne-start-export').prop('disabled', false);
            $('#wne-progress-text').text('Network error.');
        });
    });

}(jQuery));
