<?php

declare(strict_types=1);

namespace FirminoIntegration\Services;

use FirminoIntegration\Contracts\HttpClientInterface;
use FirminoIntegration\Exceptions\ApiException;
use FirminoIntegration\ValueObjects\ImportOptions;
use FirminoIntegration\ValueObjects\ImportResult;
use FirminoIntegration\ValueObjects\Settings;
use WC_Product;
use WC_Product_Variable;
use WC_Tax;

final class ProductImportService
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Settings $settings,
    ) {}

    /**
     * Imports a batch of WooCommerce products as Firmino articles.
     */
    public function importBatch(ImportOptions $options): ImportResult
    {
        $types = array_keys(wc_get_product_types());
        if ( $options->includeVariations && ! in_array('variation', $types, true) ) {
            $types[] = 'variation';
        }

        $products = wc_get_products([
            'status' => ['publish'],
            'limit'  => $options->limit,
            'offset' => $options->offset,
            'type'   => $types,
            'return' => 'objects',
        ]);

        $added   = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ( $products as $product ) {
            if ( $product instanceof WC_Product_Variable && $options->includeVariations ) {
                $skipped++;
                continue;
            }

            $firminoId = $product->get_meta('_firmino_article_id', true);

            if ( $firminoId && ! $options->updateExisting && ! $options->forceImport ) {
                $skipped++;
                continue;
            }

            $payload = $this->buildPayload($product, $options->codeSource);

            try {
                if ( $firminoId && ! $options->forceImport ) {
                    $payload['id'] = (int) $firminoId;
                    $response      = $this->client->request('articles/update', $payload);
                } else {
                    $response = $this->client->request('articles/add', $payload);
                }
            } catch ( ApiException ) {
                $errors++;
                continue;
            }

            $articleId = $response['response']['id'] ?? null;
            if ( $articleId ) {
                $product->update_meta_data('_firmino_article_id', $articleId);
                if ( ! $firminoId || $options->forceImport ) {
                    $this->appendArticleHistory($product, (int) $articleId);
                }
                $product->save();
            }

            if ( $firminoId && ! $options->forceImport ) {
                $updated++;
            } else {
                $added++;
            }
        }

        return new ImportResult(
            added:   $added,
            updated: $updated,
            skipped: $skipped,
            errors:  $errors,
            hasMore: count($products) === $options->limit,
        );
    }

    private function buildPayload(WC_Product $product, string $codeSource): array
    {
        $vatRate = $this->getProductVatRate($product);
        if ( $vatRate === '0' && $this->settings->defaultVatRate !== '' ) {
            $vatRate = $this->settings->defaultVatRate;
        }
        if ( $vatRate === '' ) {
            $vatRate = '23';
        }

        $payload = [
            'name'    => $product->get_name(),
            'code'    => $this->getProductCode($product, $codeSource),
            'type'    => $this->getProductType($product),
            'unit'    => 'szt',
            'vatRate' => $vatRate,
        ];

        $price = $product->get_price();
        if ( $price !== '' ) {
            if ( wc_prices_include_tax() ) {
                $payload['priceGross'] = wc_format_decimal(wc_get_price_including_tax($product), 2);
            } else {
                $payload['priceNet'] = wc_format_decimal(wc_get_price_excluding_tax($product), 2);
            }
        }

        if ( $product->managing_stock() ) {
            $quantity = $product->get_stock_quantity();
            if ( $quantity !== null ) {
                $payload['quantity'] = wc_format_decimal($quantity, 4);
            }
        }

        return $payload;
    }

    private function getProductCode(WC_Product $product, string $source): string
    {
        $sku = $product->get_sku();
        if ( $source === 'sku' && $sku !== '' ) {
            return $sku;
        }

        return (string) $product->get_id();
    }

    private function getProductType(WC_Product $product): string
    {
        return $product->is_virtual() || $product->is_downloadable() ? 'service' : 'good';
    }

    private function getProductVatRate(WC_Product $product): string
    {
        $rates = WC_Tax::get_rates($product->get_tax_class());
        if ( empty($rates) ) {
            return '0';
        }

        $rate = reset($rates);
        if ( isset($rate['rate']) ) {
            $value = rtrim(rtrim((string) $rate['rate'], '0'), '.');
            return $value === '' ? '0' : $value;
        }

        return '0';
    }

    private function appendArticleHistory(WC_Product $product, int $articleId): void
    {
        $history = $product->get_meta('_firmino_article_ids', true);
        if ( ! is_array($history) ) {
            $history = [];
        }

        $id = (string) $articleId;
        if ( ! in_array($id, $history, true) ) {
            $history[] = $id;
            $product->update_meta_data('_firmino_article_ids', $history);
        }
    }
}
