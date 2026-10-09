<?php
namespace SneakyPeak\Pricing;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;
use WC_Product;

/**
 * Class LegacyPriceSource
 *
 * Implements PriceSource contract for reading WooCommerce sale price.
 */
class LegacyPriceSource implements PriceSource {

    public function has_campaign_price_or_teaser(WC_Product $product, Campaign $campaign): bool {
        $product_id = $product->get_id();

        // 1. Variable product: check variation sale prices
        if ($product->is_type('variable')) {
            $children = $product->get_children();
            if (!empty($children)) {
                foreach ($children as $child_id) {
                    $raw_sale = get_post_meta($child_id, '_sale_price', true);
                    if ($raw_sale !== '' && $raw_sale !== null && (float) $raw_sale > 0) {
                        return true;
                    }
                }
            }
            return false;
        }

        // 2. Simple / other products: check raw _sale_price post meta
        $sale_price = get_post_meta($product_id, '_sale_price', true);
        return ($sale_price !== '' && $sale_price !== null && (float) $sale_price > 0);
    }

    public function get_campaign_price(WC_Product $product, Campaign $campaign): string {
        $product_id = $product->get_id();
        $sale_price = get_post_meta($product_id, '_sale_price', true);
        if ($sale_price !== '' && $sale_price !== null) {
            return (string) $sale_price;
        }
        return '';
    }

    public function get_teaser_price_string(WC_Product $product, Campaign $campaign): string {
        $product_id = $product->get_id();

        $mask_rule = (string) $campaign->get_setting('mask_rule', 'keep_first');
        $prefix = (string) $campaign->get_setting('teaser_variable_prefix', 'From');

        if ($product->is_type('variable')) {
            $children = $product->get_children();
            $min_sale_price = PHP_FLOAT_MAX;
            $found_sale = false;

            if (!empty($children)) {
                foreach ($children as $child_id) {
                    $child_sale = get_post_meta($child_id, '_sale_price', true);
                    if ($child_sale !== '' && $child_sale !== null && (float) $child_sale > 0) {
                        $min_sale_price = min($min_sale_price, (float) $child_sale);
                        $found_sale = true;
                    }
                }
            }

            if (!$found_sale) {
                return '';
            }

            $masked = $this->mask_price_numeric($min_sale_price, $mask_rule);
            $formatted = $this->format_currency($masked);
            return (!empty($prefix) ? $prefix . ' ' : '') . $formatted;
        }

        // Simple or other product
        $raw_sale = get_post_meta($product_id, '_sale_price', true);
        if ($raw_sale === '' || $raw_sale === null || (float) $raw_sale <= 0) {
            return '';
        }

        $masked = $this->mask_price_numeric((float) $raw_sale, $mask_rule);
        return $this->format_currency($masked);
    }

    public function mask_price_numeric(float $amount, string $mask_rule = 'keep_first'): string {
        $decimals = (int) get_option('woocommerce_price_num_decimals', 2);
        $dec_point = (string) get_option('woocommerce_price_decimal_sep', '.');
        $thousands_sep = (string) get_option('woocommerce_price_thousand_sep', ',');

        $num_str = number_format($amount, $decimals, $dec_point, $thousands_sep);

        if ($mask_rule === 'mask_last_3') {
            // Mask last 3 non-separator numeric characters
            $chars = preg_split('//u', $num_str, -1, PREG_SPLIT_NO_EMPTY);
            $digits_masked = 0;
            for ($i = count($chars) - 1; $i >= 0; $i--) {
                if (ctype_digit($chars[$i])) {
                    $chars[$i] = '?';
                    $digits_masked++;
                    if ($digits_masked >= 3) {
                        break;
                    }
                }
            }
            return implode('', $chars);
        }

        // Default 'keep_first': keep the very first significant digit, mask all subsequent digits
        $chars = preg_split('//u', $num_str, -1, PREG_SPLIT_NO_EMPTY);
        $first_found = false;

        for ($i = 0; $i < count($chars); $i++) {
            if (ctype_digit($chars[$i])) {
                if (!$first_found) {
                    $first_found = true;
                } else {
                    $chars[$i] = '?';
                }
            }
        }

        return implode('', $chars);
    }

    public function format_currency(string $masked_str): string {
        $currency_symbol = get_woocommerce_currency_symbol();
        $currency_pos = get_option('woocommerce_currency_pos', 'left');

        switch ($currency_pos) {
            case 'left_space':
                return $currency_symbol . ' ' . $masked_str;
            case 'right':
                return $masked_str . $currency_symbol;
            case 'right_space':
                return $masked_str . ' ' . $currency_symbol;
            case 'left':
            default:
                return $currency_symbol . $masked_str;
        }
    }
}
