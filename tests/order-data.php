<?php
declare(strict_types=1);

namespace {
    $GLOBALS['tax_enabled'] = true;
    $GLOBALS['prices_include_tax'] = false;
    function wc_tax_enabled(): bool { return $GLOBALS['tax_enabled']; }
    function wc_prices_include_tax(): bool { return $GLOBALS['tax_enabled'] && $GLOBALS['prices_include_tax']; }

    final class WC_Order {
        public function __construct(public array $meta = []) {}
        public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
    }

    require __DIR__ . '/../src/WooCommerce/OrderData.php';

    use FirminoIntegration\WooCommerce\OrderData;

    $assertions = 0;
    $assert = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) { throw new \RuntimeException($message); }
    };

    $assert(OrderData::tin(new WC_Order(['_billing_nip' => '501-007-89 92'])) === '5010078992', 'Reads and normalises the NIP saved by the shop checkout.');
    $assert(OrderData::tin(new WC_Order(['billing_nip' => '5010078992'])) === '5010078992', 'Still reads NIP meta from other plugins.');
    $assert(OrderData::tin(new WC_Order()) === '', 'Order without NIP has no TIN.');

    foreach (['23%' => '23', '10%' => '10', '20.0000' => '20', '8.5000' => '8.5', '0%' => '0', '' => '0', 'zw' => '0'] as $input => $expected) {
        $assert(OrderData::formatRate((string) $input) === $expected, "Formats tax rate {$input} as {$expected}.");
    }

    $GLOBALS['tax_enabled'] = false;
    $assert(OrderData::pricesAreGross(), 'Prices are final (gross) when WooCommerce taxes are disabled.');
    $GLOBALS['tax_enabled'] = true;
    $assert(!OrderData::pricesAreGross(), 'Net prices when taxes are added at checkout.');
    $GLOBALS['prices_include_tax'] = true;
    $assert(OrderData::pricesAreGross(), 'Gross prices when they include tax.');

    fwrite(STDOUT, sprintf("PASS: %d order data assertions.\n", $assertions));
}
