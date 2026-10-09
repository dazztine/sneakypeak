<?php
namespace SneakyPeak\Pricing;

defined('ABSPATH') || exit;

/**
 * Interface PriceSource
 *
 * Provides campaign pricing contract so Phase 2 can replace the price source
 * without altering front-end badge or resolver contracts.
 */
interface PriceSource {
    /**
     * Determine if product has an eligible campaign price or custom teaser configured.
     *
     * @param \WC_Product $product
     * @param \SneakyPeak\Campaign $campaign
     * @return bool
     */
    public function has_campaign_price_or_teaser(\WC_Product $product, \SneakyPeak\Campaign $campaign): bool;

    /**
     * Get the resolved campaign price (numeric string or empty if none).
     *
     * @param \WC_Product $product
     * @param \SneakyPeak\Campaign $campaign
     * @return string
     */
    public function get_campaign_price(\WC_Product $product, \SneakyPeak\Campaign $campaign): string;

    /**
     * Get the teaser price string (e.g. masked "8,???" or custom text).
     *
     * @param \WC_Product $product
     * @param \SneakyPeak\Campaign $campaign
     * @return string
     */
    public function get_teaser_price_string(\WC_Product $product, \SneakyPeak\Campaign $campaign): string;
}
