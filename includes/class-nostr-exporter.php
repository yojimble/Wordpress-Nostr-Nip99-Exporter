<?php
/**
 * Builds unsigned NIP-99 event templates from WooCommerce products.
 * No signing or publishing — that is handled by the browser.
 *
 * Tag mapping:
 *   WC short description → ["summary", "..."]
 *   WC attributes        → ["spec", "Attribute Name", "Value"]
 *   WC brand (taxonomy or attribute named "brand") → ["t", "brand-slug"]
 *   WC product tags      → ["t", "tag-slug"]
 *   WC categories        → ["t", "category-slug"]
 *
 * d tag = first 32 chars of sha256(site_url + ":" + product_id[s])
 *   → globally unique, deterministic, store-scoped
 */
class WNE_Nostr_Exporter {

    /**
     * Generate a globally unique, deterministic d tag.
     * Scoped to this store's URL so two stores with product ID 13 never collide.
     * Using the first 32 hex chars of SHA256 gives 128 bits — collision-proof in practice.
     */
    /**
     * Build a human-readable location string from WooCommerce store settings.
     * Returns e.g. "Austin, TX, US" or "London, GB" or just "GB".
     */
    private static function get_store_location(): string {
        $city     = get_option( 'woocommerce_store_city', '' );
        $postcode = get_option( 'woocommerce_store_postcode', '' );
        $country_raw = get_option( 'woocommerce_default_country', '' ); // e.g. "US:CA" or "GB"

        $country = '';
        $state   = '';
        if ( strpos( $country_raw, ':' ) !== false ) {
            [ $country, $state ] = explode( ':', $country_raw, 2 );
        } else {
            $country = $country_raw;
        }

        $countries    = WC()->countries->get_countries();
        $country_name = trim( preg_replace( '/\s*\(.*?\)/', '', $countries[ $country ] ?? $country ) );

        $parts = array_filter( [ $city, $state, $country_name, $postcode ] );
        return implode( ', ', $parts );
    }

    private static function make_d_tag( int ...$ids ): string {
        $site = rtrim( get_site_url(), '/' );
        $key  = $site . ':' . implode( '-', $ids );
        return substr( hash( 'sha256', $key ), 0, 32 );
    }

    /**
     * Detect brand value from a product.
     * Supports: product_brand taxonomy (Perfect Brands / similar plugins),
     * and a "brand" product attribute as fallback.
     * Returns array of brand slugs.
     */
    private static function get_brand_slugs( int $product_id, WC_Product $product ): array {
        // 1. Dedicated brand taxonomy (e.g. Perfect WooCommerce Brands, YITH Brands)
        foreach ( [ 'product_brand', 'pwb-brand', 'yith_product_brand' ] as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) {
                $terms = wp_get_post_terms( $product_id, $taxonomy );
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    return wp_list_pluck( $terms, 'slug' );
                }
            }
        }

        // 2. Product attribute named "brand" or "Brand"
        foreach ( $product->get_attributes() as $key => $attr ) {
            /** @var WC_Product_Attribute $attr */
            $label = strtolower( wc_attribute_label( $attr->get_name() ) );
            if ( $label === 'brand' ) {
                $values = $attr->get_terms()
                    ? wp_list_pluck( $attr->get_terms(), 'slug' )
                    : array_map( 'sanitize_title', $attr->get_options() );
                return array_filter( $values );
            }
        }

        return [];
    }

    /**
     * Build shared base tags (common to simple products and variation parents).
     * Handles: summary, price, stock, SKU, images, t tags, spec tags.
     */
    private static function base_tags(
        string $d_tag,
        string $title,
        string $summary,
        string $price,
        string $currency,
        ?int   $stock,
        string $sku,
        array  $image_ids,
        int    $product_id,        // for categories / tags / brand lookup
        WC_Product $product,       // for attributes
        bool   $is_variation = false,
        int    $parent_id = 0
    ): array {
        $tags = [
            [ 'd',            $d_tag ],
            [ 'title',        $title ],
            [ 'summary',      $summary ],
            [ 'location',     self::get_store_location() ],
            [ 'price',        $price ?: '0', $currency ],
        ];

        if ( $stock !== null ) {
            $tags[] = [ 'stock', (string) $stock ];
        }

        if ( $sku ) {
            $tags[] = [ 'sku', $sku ];
        }

        if ( $weight = $product->get_weight() ) {
            $tags[] = [ 'weight', (string) $weight, get_option( 'woocommerce_weight_unit', 'kg' ) ];
        }

        $length = $product->get_length();
        $width  = $product->get_width();
        $height = $product->get_height();
        if ( $length && $width && $height ) {
            $dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
            $tags[]   = [ 'dim', $length . 'x' . $width . 'x' . $height, $dim_unit ];
        }

        $img_index = 0;
        foreach ( $image_ids as $img_id ) {
            if ( $url = wp_get_attachment_url( $img_id ) ) {
                $tags[] = [ 'image', $url, '', (string) $img_index ];

                $imeta = [ 'imeta', 'url ' . $url ];
                $mime  = get_post_mime_type( $img_id );
                if ( $mime ) $imeta[] = 'm ' . $mime;
                $tags[] = $imeta;

                $img_index++;
            }
        }

        if ( $is_variation && $parent_id ) {
            $tags[] = [ 'parent', self::make_d_tag( $parent_id ) ];
        }

        // ── t tags ────────────────────────────────────────────────────────

        $seen_slugs  = [];
        $add_t_slug  = function( string $slug ) use ( &$tags, &$seen_slugs ) {
            if ( ! isset( $seen_slugs[ $slug ] ) && $slug !== 'uncategorised' && $slug !== 'uncategorized' ) {
                $tags[]               = [ 't', $slug ];
                $seen_slugs[ $slug ]  = true;
            }
        };

        foreach ( self::get_brand_slugs( $product_id, $product ) as $slug ) {
            $add_t_slug( $slug );
        }

        $cat_id = $is_variation ? $parent_id : $product_id;
        foreach ( wp_get_post_terms( $cat_id, 'product_cat' ) as $term ) {
            $add_t_slug( $term->slug );
            $ancestor_ids = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
            foreach ( $ancestor_ids as $ancestor_id ) {
                $ancestor = get_term( $ancestor_id, 'product_cat' );
                if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                    $add_t_slug( $ancestor->slug );
                }
            }
        }

        $tag_id = $is_variation ? $parent_id : $product_id;
        foreach ( wp_get_post_terms( $tag_id, 'product_tag' ) as $term ) {
            $add_t_slug( $term->slug );
        }

        return $tags;
    }

    /**
     * Append spec tags for product attributes, skipping "brand" (already a t tag).
     */
    private static function append_spec_tags( array $tags, array $attributes ): array {
        foreach ( $attributes as $attr_name_raw => $attr_values ) {
            $attr_label = wc_attribute_label( $attr_name_raw );
            foreach ( $attr_values as $val ) {
                $tags[] = [ 'spec', $attr_label, (string) $val ];
            }
        }
        return $tags;
    }

    /**
     * Extract attribute name → values map from a WC_Product_Attribute array.
     */
    private static function extract_attribute_map( array $wc_attributes ): array {
        $map = [];
        foreach ( $wc_attributes as $attr ) {
            /** @var WC_Product_Attribute $attr */
            $name   = $attr->get_name();
            $values = $attr->get_terms()
                ? wp_list_pluck( $attr->get_terms(), 'name' )
                : $attr->get_options();
            if ( $values ) {
                $map[ $name ] = $values;
            }
        }
        return $map;
    }

    /**
     * Build NIP-99 tags for a simple product.
     */
    private static function tags_for_simple( WC_Product $product, string $currency ): array {
        $id    = $product->get_id();
        $price = (string) $product->get_price();
        $stock = ( $product->get_manage_stock() && $product->get_stock_quantity() !== null )
            ? (int) $product->get_stock_quantity() : null;

        $image_ids = array_filter( array_merge(
            [ $product->get_image_id() ],
            $product->get_gallery_image_ids()
        ) );

        $tags = self::base_tags(
            self::make_d_tag( $id ),
            $product->get_name(),
            wp_strip_all_tags( $product->get_short_description() ?: $product->get_name() ),
            $price,
            $currency,
            $stock,
            $product->get_sku(),
            $image_ids,
            $id,
            $product
        );

        $product_type = $product->is_virtual() ? 'digital' : 'physical';
        array_splice( $tags, 1, 0, [ [ 'type', 'simple', $product_type ] ] );

        if ( $product->get_catalog_visibility() === 'hidden' ) {
            $tags[] = [ 'visibility', 'hidden' ];
        } elseif ( $product->get_stock_status() === 'onbackorder' ) {
            $tags[] = [ 'visibility', 'pre-order' ];
        } elseif ( $product->get_stock_status() === 'instock' ) {
            $tags[] = [ 'visibility', 'on-sale' ];
        } else {
            $tags[] = [ 'visibility', 'hidden' ];
        }

        // Attributes → spec tags
        $attr_map = self::extract_attribute_map( $product->get_attributes() );
        $tags     = self::append_spec_tags( $tags, $attr_map );

        return $tags;
    }

    /**
     * Build NIP-99 tags for a product variation.
     */
    private static function tags_for_variation( WC_Product_Variation $variation, WC_Product $parent, string $currency ): array {
        $var_id    = $variation->get_id();
        $parent_id = $parent->get_id();

        $price = (string) $variation->get_price();
        if ( $price === '' ) $price = (string) $parent->get_price();

        // Stock: prefer variation, fall back to parent
        $stock = null;
        if ( $variation->get_manage_stock() && $variation->get_stock_quantity() !== null ) {
            $stock = (int) $variation->get_stock_quantity();
        } elseif ( $parent->get_manage_stock() && $parent->get_stock_quantity() !== null ) {
            $stock = (int) $parent->get_stock_quantity();
        }

        // Title: "Parent Name (Label: Value, ...)"
        $attr_labels = [];
        $spec_map    = [];
        foreach ( $variation->get_variation_attributes() as $attr_key => $attr_val ) {
            if ( $attr_val === '' ) continue;
            $label           = wc_attribute_label( str_replace( 'attribute_', '', $attr_key ) );
            $attr_labels[]   = $label . ': ' . ucfirst( $attr_val );
            $spec_map[$label] = [ ucfirst( $attr_val ) ];
        }
        $title = $parent->get_name();
        if ( $attr_labels ) {
            $title .= ' (' . implode( ', ', $attr_labels ) . ')';
        }

        $img_id    = $variation->get_image_id() ?: $parent->get_image_id();
        $image_ids = array_filter( [ $img_id ] );
        $sku       = $variation->get_sku() ?: $parent->get_sku();

        $tags = self::base_tags(
            self::make_d_tag( $parent_id, $var_id ),
            $title,
            wp_strip_all_tags( $parent->get_short_description() ?: $parent->get_name() ),
            $price,
            $currency,
            $stock,
            $sku,
            $image_ids,
            $parent_id,
            $parent,
            true,
            $parent_id
        );

        $product_type = $parent->is_virtual() ? 'digital' : 'physical';
        array_splice( $tags, 1, 0, [ [ 'type', 'variation', $product_type ] ] );

        $stock_status = $variation->get_stock_status() ?: $parent->get_stock_status();
        if ( $parent->get_catalog_visibility() === 'hidden' ) {
            $tags[] = [ 'visibility', 'hidden' ];
        } elseif ( $stock_status === 'onbackorder' ) {
            $tags[] = [ 'visibility', 'pre-order' ];
        } elseif ( $stock_status === 'instock' ) {
            $tags[] = [ 'visibility', 'on-sale' ];
        } else {
            $tags[] = [ 'visibility', 'hidden' ];
        }

        // Variation-specific attributes → spec tags
        $tags = self::append_spec_tags( $tags, $spec_map );

        return $tags;
    }

    /**
     * Build NIP-99 tags for a variable product parent.
     */
    private static function tags_for_variable( WC_Product $product, string $currency ): array {
        $id = $product->get_id();

        $price = (string) $product->get_price();

        $image_ids = array_filter( array_merge(
            [ $product->get_image_id() ],
            $product->get_gallery_image_ids()
        ) );

        $tags = self::base_tags(
            self::make_d_tag( $id ),
            $product->get_name(),
            wp_strip_all_tags( $product->get_short_description() ?: $product->get_name() ),
            $price,
            $currency,
            null,
            $product->get_sku(),
            $image_ids,
            $id,
            $product
        );

        array_splice( $tags, 1, 0, [ [ 'type', 'variable', 'physical' ] ] );

        $stock_status = $product->get_stock_status();
        if ( $product->get_catalog_visibility() === 'hidden' ) {
            $tags[] = [ 'visibility', 'hidden' ];
        } elseif ( $stock_status === 'onbackorder' ) {
            $tags[] = [ 'visibility', 'pre-order' ];
        } elseif ( $stock_status === 'instock' ) {
            $tags[] = [ 'visibility', 'on-sale' ];
        } else {
            $tags[] = [ 'visibility', 'hidden' ];
        }

        return $tags;
    }

    /**
     * Return unsigned NIP-99 event templates for a product.
     * Variable products return one template per variation.
     */
    public static function get_templates( int $product_id ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_status() !== 'publish' ) {
            return [];
        }

        $currency  = get_woocommerce_currency();
        $templates = [];

        if ( $product->is_type( 'variable' ) ) {
            /** @var WC_Product_Variable $product */

            // Parent variable product event
            $templates[] = [
                'kind'       => 30402,
                'created_at' => time(),
                'tags'       => self::tags_for_variable( $product, $currency ),
                'content'    => wp_strip_all_tags( $product->get_description() ),
            ];

            foreach ( $product->get_children() as $var_id ) {
                $variation = wc_get_product( $var_id );
                if ( ! $variation ) continue;

                $templates[] = [
                    'kind'       => 30402,
                    'created_at' => time(),
                    'tags'       => self::tags_for_variation( $variation, $product, $currency ),
                    'content'    => wp_strip_all_tags( $product->get_description() ),
                ];
            }
        } else {
            $templates[] = [
                'kind'       => 30402,
                'created_at' => time(),
                'tags'       => self::tags_for_simple( $product, $currency ),
                'content'    => wp_strip_all_tags( $product->get_description() ),
            ];
        }

        return $templates;
    }

    /**
     * Get all published product IDs.
     */
    public static function get_all_product_ids(): array {
        return wc_get_products( [
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ] );
    }
}
