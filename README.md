# WooCommerce Nostr NIP-99 Exporter

Export your WooCommerce products to Nostr as [NIP-99 classified listings](https://github.com/nostr-protocol/nostr/blob/master/99.md) (kind 30402), compatible with the [GammaMarkets marketplace protocol](https://github.com/GammaMarkets/market-spec).

Listings are viewable on Nostr marketplace clients such as [Shopstr](https://shopstr.store) and [Amethyst](https://github.com/vitorpamplona/amethyst).

---

## Features

- Exports simple and variable WooCommerce products as NIP-99 events
- Supports NIP-07 browser extensions (Alby, nos2x), NIP-46 remote signers (Amber, nsecBunker), and direct nsec/hex private keys
- Optional [Blossom](https://github.com/hzrd149/blossom) image hosting — uploads product images to your Blossom server before publishing
- Signing happens entirely in the browser — your private key never touches the server
- Deterministic `d` tags mean re-exporting updates existing listings rather than creating duplicates
- Maps WooCommerce visibility, stock status, categories, tags, attributes, weight, and dimensions to the correct Nostr tags
- Batched export with progress tracking

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- A Nostr signing method (browser extension, remote signer, or private key)

---

## Installation

### From ZIP (recommended)

1. Download the latest `wordpress-nostr-export.zip` from the [Releases](../../releases) page
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and click **Install Now**, then **Activate**

### From source

1. Clone or download this repository into your `wp-content/plugins/` directory
2. Activate the plugin from **Plugins** in WordPress admin

---

## Configuration

Go to **WooCommerce → Nostr Export → Settings**:

- **Relay URLs** — add one or more Nostr relay WebSocket URLs (e.g. `wss://nos.lol`)
- **Blossom URL** — optional, upload images to a Blossom server before publishing (e.g. `https://your.blossom.server`)

---

## Usage

1. Go to **WooCommerce → Nostr Export**
2. Connect your signer (NIP-07 extension, NIP-46 bunker URI, or paste your nsec/hex key)
3. Click **Start Export**
4. Products are exported in batches — progress and per-relay results are shown in real time

Re-exporting a product updates the existing Nostr listing — it does not create a duplicate.

---

## How It Works

1. PHP fetches product data from WooCommerce and builds unsigned NIP-99 event templates
2. The browser signs each event using your chosen signer
3. If Blossom is configured, product images are uploaded before signing so the listing contains permanent Blossom URLs
4. Signed events are published directly from the browser to your configured relays

---

## Tag Mapping

| Nostr Tag | WooCommerce Source |
|---|---|
| `d` | SHA256(site_url + product_id) — deterministic, globally unique |
| `title` | Product name |
| `summary` | Short description |
| `price` | Price + currency |
| `stock` | Stock quantity |
| `sku` | SKU |
| `type` | simple / variable / variation + physical / digital |
| `visibility` | on-sale / hidden / pre-order based on stock status and catalog visibility |
| `image` | Product images (main + gallery) |
| `imeta` | Image URL + MIME type |
| `weight` | Product weight + WooCommerce weight unit |
| `dim` | Product dimensions + WooCommerce dimension unit |
| `location` | WooCommerce store address |
| `t` | Categories (including parent categories), product tags, brand |
| `spec` | Product attributes |
| `a` | Parent variable product reference (for variations) |

---

## License

GPLv2 or later
