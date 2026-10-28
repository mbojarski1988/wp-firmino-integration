<?php

declare(strict_types=1);

namespace FirminoIntegration\WooCommerce;

use WC_Order;

/** Order details shared by the customer and document payloads. */
final class OrderData
{
    // `_billing_nip` is written by the Biuro Natalia checkout; the rest cover other NIP plugins.
    private const TIN_KEYS = ['_billing_nip', 'billing_nip', '_billing_vat', 'billing_vat', 'vat_number', 'nip'];

    public static function tin(WC_Order $order): string
    {
        foreach ( self::TIN_KEYS as $key ) {
            $value = $order->get_meta($key, true);
            if ( is_string($value) && trim($value) !== '' ) {
                return (string) preg_replace('/[\s-]/', '', $value);
            }
        }

        return '';
    }

    /** Prices are gross when WooCommerce taxes are disabled, as nothing is added at checkout. */
    public static function pricesAreGross(): bool
    {
        return ! wc_tax_enabled() || wc_prices_include_tax();
    }

    /** Turns "23%", "23.0000" or "10" into the plain rate Firmino expects. */
    public static function formatRate(string $rate): string
    {
        $rate = trim(str_replace('%', '', $rate));
        if ( $rate === '' || ! is_numeric($rate) ) {
            return '0';
        }

        return (string) (float) $rate;
    }
}
