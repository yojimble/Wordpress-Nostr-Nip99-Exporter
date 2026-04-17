# Marketplace Protocol Specification

`draft`

A protocol specification for decentralized marketplaces on Nostr that provides an interoperable, full-featured e-commerce framework.

## Table of Contents
1. Protocol Requirements
2. Core Protocol Components
3. Events and Kinds
4. Order Communication Flow and Payment Processing
5. Product Reviews
6. Implementation Guidelines

## 1. Protocol Requirements

The protocol defines both required core components and optional features to support diverse marketplace needs.

### Required Components
Implementations MUST support the following core features:

- Product listing events (Kind: 30402)
- Product collection events (Kind: 30405) for product-to-collection references
- Merchant's preferences
- Order communication and processing via [NIP-17](17.md) encrypted messages

### Optional Components
These features MAY be implemented based on specific marketplace needs:

- Extended product metadata
- Shipping options (Kind: 30406)
- Product collections (Kind: 30405) 
- Drafts following [NIP-37](37.md)
- Product reviews (Kind: 31555)
- Service assisted order and payment processing

#### Watch-only clients
Watch-only clients are applications that allow users to display products without implementing full e-commerce capabilities. These clients don't need to support all required components - product rendering alone can be sufficient. However, ideally, they should also handle logic for looking up collections, reviews, and shipping options. Support for order communication using [NIP-17](17.md) is optional.

## 2. Core Protocol Components

### Core Flows
1. Merchant Preferences
   - Application preferences via [NIP-89](89.md)
   - Payment method preferences via kind `0` tags

2. Order Processing
   - Encrypted buyer-seller communication
   - Status updates and confirmations
   
3. Shipping
   - Option definition and pricing
   - Geographic restrictions
   
4. Payment
   - Multiple payment methods
   - Verification and receipts

Standard e-commerce flow:
1. Product discovery
2. Cart addition
3. Merchant preference verification
4. Shipping calculation
5. Payment processing
6. Order confirmation
7. Encrypted message follow-up

### Merchant Preferences
Merchants MAY specify preferences for how they want users to interact with them, including which applications to use and payment methods to accept. These preferences ensure a consistent experience and streamline operations. Merchants indicate their preferences through two mechanisms:

1. Application Preferences ([NIP-89](89.md)):
- The recommended application MUST publish a kind `31990` event
- The merchant MUST publish a kind `31989` event recommending that application

2. Payment Preferences:
- Set via `payment_preference` tag in the merchant's kind `0` event
- Valid values: `manual | ecash | lud16` 
- Defaults to `manual` if not specified

Applications implementing this NIP MUST handle preferences as follows:

1. When `payment_preference` is `manual`:
- If merchant recommends an app: MUST direct users to that app
- If no app recommendation: Use traditional interactive flow (buyer places order and waits for merchant's payment request)

2. When `payment_preference` is `ecash` or `lud16`:
- If merchant recommends an app: SHOULD direct users there first, but they MAY also offer to continue if compatible with the payment preference
- If no recommendations: Use specified payment method directly

3. When no preferences are set:
- Use traditional interactive flow
   - Buyer sends order
   - Wait for merchant's payment request

Buyers can verify merchant preferences by:
- Checking kind `31990` events for recommended applications
- Checking kind `0` events for payment preferences

This verification helps buyers follow merchant-approved paths and avoid potential scams or poor experiences.

## 3. Events and Kinds

### Product Listing (Kind: 30402)

Products are the core element in a marketplace. Each product listing MUST contain basic metadata and MAY contain additional details. Their configuration is the source of truth, overriding other possible configurations of other market elements such as collections, no configuration is cascaded to products, they MUST explicitly reference an attribute to inherit it.

**Content**: Product description, markdown is allowed
**Required tags**:
- `d`: Unique product identifier for referencing the listing
- `title`: Product name/title for display
- `price`: Price information array `[<amount>, <currency>, <optional frequency>]`
  - amount: Decimal number (e.g., "10.99")
  - currency: ISO 4217 code (e.g., "USD", "EUR")
  - frequency: Optional subscription interval using ISO 8601 duration units (e.g. 'D' for daily, 'W' for weekly, 'Y' for yearly).

**Optional tags**:
- Product Details:
  - `type`: Product classification `[<type>, <format>]`
    - type: "simple", "variable", or "variation"
    - format: "digital" or "physical"
    - Default/if not present: type: "simple", format: "digital"
  - `visibility`: Display status ("hidden", "on-sale", "pre-order"). Default/if not present: "on-sale"
  - `stock`: Available quantity as integer
  - `summary`: Short product description
  - `spec`: Product specifications `[<key>, <value>]`, can appear multiple times

- Media:
  - `image`: Product images `[<url>, <dimensions>, <sorting-order>]`, MAY appear multiple times
    - url: Direct image URL
    - dimensions: Optional, in pixels, "<width>x<height>" format, if not present the place in the array should be respected by using an empty string `""`
    - sorting order: Optional integer for order sorting. Values are sorted from lowest to highest, independent of starting value (not restricted to start with 0 or 1)

- Physical Properties:
  - `weight`: Product weight `[<value>, <unit>]` using ISO 80000-1
  - `dim`: Dimensions `[<l>x<w>x<h>, <unit>]` using ISO 80000-1

- Location:
  - `location`: Human-readable location string or collection coordinates
  - `g`: Geohash for precise location lookup or collection coordinates

- Organization:
  - `t`: Product categories/tags, MAY appear multiple times
  - `a`: Product reference "30402:<pubkey>:<d-tag>", MUST appear only once to reference parent products in a variable/variation configuration
  - `a`: Collection reference "30405:<pubkey>:<d-tag>", MAY appear multiple times
  - `shipping_option`: Shipping options, MAY appear multiple times
    - Format: "30406:<pubkey>:<d-tag>" for direct options
    - Format: "30405:<pubkey>:<d-tag>" for collection shipping
    - `extra-cost`: Optional third element in the array, to add extra cost (in the product's currency) for the shipping method. In case of reference a collection the extra cost should be applied to all shipping options from the collection.

```jsonc
{
  "kind": 30402,
  "created_at": <unix timestamp>,
  "content": "<product description in markdown>",
  "tags": [
    // Required tags
    ["d", "<product identifier>"],
    ["title", "<product title>"],
    ["price", "<amount>", "<currency>", "<optional frequency>"],

    // Product details
    ["type", "<simple|variable|variation>", "<digital|physical>"],  // Defaults: simple, digital
    ["visibility", "<hidden|on-sale|pre-order>"],  // Default: on-sale
    ["stock", "<integer>"],  // Available quantity
    ["summary", "<short description>"],
    
    // Media and specs
    ["image", "<url>", "<dimensions>", "<sorting-order>"],
    ["spec", "<key>", "<value>"],  // Product specifications (e.g., "screen-size", "21 inch"). MAY appear multiple times
    
    // Physical properties (for shipping)
    ["weight", "<value>", "<unit>"],  // ISO 80000-1 units (g, kg, etc)
    ["dim", "<l>x<w>x<h>", "<unit>"], // ISO 80000-1 units (mm, cm, m)
    
    // Location
    ["location", "<address string>"],
    ["g", "<geohash>"],
    
    // Classifications
    ["t", "<category>"],
    
    // References
    ["shipping_option", "<30406|30405>:<pubkey>:<d-tag>", "<extra-cost>"],  // Shipping options or collection, MAY appear multiple times
    ["a", "30405:<pubkey>:<d-tag>"]  // Product collection
  ]
}
```

#### Notes
1. Product Configuration:
   - Products can be simple, variable (with options), or variations of variable products
   - Digital products skip shipping requirements
   - Visibility controls product display status

2. Variable products: 
   - The parent or "root" product should use `variable` as value for `type`
   - The variations of the parent product should use `variation` as value for `type`. 
   - Variations MUST include an `a` tag pointing to the `variable` parent product.
 
2. Shipping Rules:
   - Shipping options can be defined directly by pointing to a shipping event, or inherited from collections
   - If the product specifies product-specific shipping, and also from a collection, shipping options MUST be merged.

3. Collections and Categories:
   - Products can refer to one o multiple collections using `a` tags, whether or not they are part of it, for discoverability purposes.
   - Categories ("t" tags) aid in discovery and organization

4. Location Support:
   - Optional location data aids in local marketplace features, they can point to a collection event to inherit it's value
   - Geohash enables precise location-based searches, they can point to a collection event to inherit it's value

### Product Collection (Kind: 30405)
A specialized event type using [NIP-51](51.md) like list format to organize related products into groups. Collections allow merchants or any user to create meaningful product groupings and share common attributes that products can also reference, establishing one-to-many relationships.

**Content**: Optional collection description

**Required tags**:
- `d`: Unique collection identifier
- `title`: Collection display name/title
- `a`: Product references `["a", "30402:<pubkey>:<d-tag>"]`
  - Multiple product references allowed
  - References must point to valid product listings

**Optional tags**:
- Display:
  - `image`: Collection banner/thumbnail URL
  - `summary`: Brief collection description

- Location:
  - `location`: Human-readable location string
  - `g`: Geohash for precise location lookup

- Reference Options:
  - `shipping_option`: Available shipping options `["shipping_option", "30406:<pubkey>:<d-tag>"]`, MAY appear multiple times

```jsonc
{
  "kind": 30405,
  "created_at": <unix timestamp>,
  "content": "<optional collection description>",
  "tags": [
    // Required tags
    ["d", "<collection identifier>"],
    ["title", "<collection name>"],
    ["a", "30402:<pubkey>:<d-tag>"],  // Product reference
    
    // Optional tags
    ["image", "<collection image URL>"],
    ["summary", "<collection description>"],
    
    // Location
    ["location", "<location string>"],
    ["g", "<geohash>"],
    
    // Reference Options
    ["shipping_option", "30406:<pubkey>:<d-tag>"],  // Available shipping options, MAY appear multiple times
  ]
}
```

#### Notes
1. Collection Management:
   - Collections can contain any number of products
   - Products can belong to multiple collections

2. Reference Model:
   - Collection settings (shipping, location, geohash) serve as references only
   - Products MUST explicitly reference collection resources to inherit collection attributes (e.g. shipping, location, geohash).
   - No automatic cascading of settings to products

3. Location Support:
   - Optional location data helps with marketplace organization
   - Enables geographic grouping of related products

### Drafts
Products and collections can be saved as private drafts while being prepared for publication. This allows merchants to work on listings before making them publicly visible. Implementation MUST follow [NIP-37](https://github.com/nostr-protocol/nips/blob/master/37.md) for draft management.

### Shipping Option (Kind: 30406)
A specialized event type for defining shipping methods, costs, and constraints. Shipping options can be published by merchants or third-party providers (delivery companies, DVMs, etc.) and referenced by product listings or collections.

**Content**: Optional human-friendly shipping description

**Required tags**:
- `d`: Unique shipping option identifier
- `title`: Display title for the shipping method
- `price`: Base cost array `[<base_cost>, <currency>]`
- `country`: Array of ISO 3166-1 alpha-2 country codes `[<code1>, <code2>, ...]`
- `service`: Service type ("standard", "express", "overnight", "pickup")

**Optional tags**:
- Extra details:
  - `carrier`: The name of the carrier that will be used for the delivery
- Time and Location:
  - `region`: Array of ISO 3166-2 region codes for which shipping method is available `[<code1>, <code2>, ...]`
  - `duration`: Delivery window `[<min>, <max>, <unit>]` using ISO 8601 duration units
    - min: Minimum delivery time
    - max: Maximum delivery time
    - unit: "H" (hours), "D" (days), "W" (weeks)
  - `location`: Physical address for pickup
  - `g`: Geohash for precise location

- Constraints:
  - `weight-min`: Minimum weight `[<value>, <unit>]` (ISO 80000-1)
  - `weight-max`: Maximum weight `[<value>, <unit>]`
  - `dim-min`: Minimum dimensions `[<l>x<w>x<h>, <unit>]`
  - `dim-max`: Maximum dimensions `[<l>x<w>x<h>, <unit>]`

- Price Calculations:
  - `price-weight`: Per weight pricing `[<price>, <unit>]`
  - `price-volume`: Per volume pricing `[<price>, <unit>]`
  - `price-distance`: Per distance pricing `[<price>, <unit>]`

```jsonc
{
  "kind": 30406,
  "created_at": <unix timestamp>,
  "content": "<optional shipping description>",
  "tags": [
    // Required tags
    ["d", "<shipping identifier>"],
    ["title", "<shipping method title>"],
    ["price", "<base_cost>", "<currency>"],
    ["country", "<ISO 3166-1 alpha-2>", "...", "..."],
    ["service", "<service-type>"],

    // Extra details
    ["carrier","<name of the carrier>"],
    
    // Time and Location
    ["region", "<ISO 3166-2 code>", "...", "..."],
    ["duration", "<min>", "<max>", "<unit>"],
    ["location", "<address string>"],
    ["g", "<geohash>"],
    
    // Constraints
    ["weight-min", "<value>", "<unit>"],
    ["weight-max", "<value>", "<unit>"],
    ["dim-min", "<l>x<w>x<h>", "<unit>"],
    ["dim-max", "<l>x<w>x<h>", "<unit>"],
    
    // Price Calculations
    ["price-weight", "<price>", "<unit>"],
    ["price-volume", "<price>", "<unit>"],
    ["price-distance", "<price>", "<unit>"]
  ]
}
```

## 4. Order Communication Flow and Payment Processing

Order processing and status updates use [NIP-17](17.md) encrypted direct messages, with three event kinds serving different purposes:

- Kind `14`: Regular communication between parties
- Kind `16`: Order processing and status (type 1: creation, type 2: payment requests, type 3: status updates, type 4: shipping)
- Kind `17`: Payment receipts and verification

## 5. Product Reviews (Kind: 31555)

Product reviews follow NIP-85 standards with mandatory thumb ratings and optional category scores:
- "value": Price vs quality
- "quality": Product quality
- "delivery": Shipping experience
- "communication": Merchant responsiveness

Score range: 0 to 1. Primary "thumb" rating is required.

## 6. Implementation Guidelines

- Tags are used for all structured, machine-readable data
- Content field is reserved for human-readable messages
- All timestamps in Unix format
- Order IDs consistent across all related messages

---
Source: https://github.com/GammaMarkets/market-spec/blob/main/spec.md
