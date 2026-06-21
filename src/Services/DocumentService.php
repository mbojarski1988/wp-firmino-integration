<?php

declare(strict_types=1);

namespace FirminoIntegration\Services;

use FirminoIntegration\Contracts\HttpClientInterface;
use FirminoIntegration\Exceptions\ApiException;
use FirminoIntegration\Exceptions\ValidationException;
use FirminoIntegration\ValueObjects\DocumentItem;
use FirminoIntegration\ValueObjects\Settings;
use WC_Order;
use WC_Order_Item_Product;
use WC_Tax;

final class DocumentService
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Settings $settings,
    ) {}

    /**
     * Creates a sale document in Firmino for the given order and customer.
     *
     * @return array{id: int, number: string}
     * @throws ApiException
     * @throws ValidationException
     */
    public function createFromOrder(WC_Order $order, int $customerId): array
    {
        $items = $this->buildItems($order);

        if ( empty($items) ) {
            throw new ValidationException(['Zamówienie nie ma pozycji do wysłania.']);
        }

        $date = $order->get_date_created()?->date('Y-m-d') ?? gmdate('Y-m-d');

        $payload = [
            'customer'     => ['id' => $customerId],
            'documentDate' => $date,
            'saleDate'     => $date,
            'documentType' => $this->resolveDocumentType($order),
            'priceType'    => wc_prices_include_tax() ? 'gross' : 'net',
            'items'        => array_map(static fn(DocumentItem $i) => $i->toArray(), $items),
        ];

        $response = $this->client->request('sale-documents/add', $payload);

        return [
            'id'     => (int) ($response['response']['id'] ?? 0),
            'number' => (string) ($response['response']['number'] ?? ''),
        ];
    }

    /**
     * Downloads the PDF for a document and returns the path to a temp file.
     *
     * @throws ApiException
     * @throws ValidationException
     */
    public function downloadPdf(int $documentId): string
    {
        if ( $documentId <= 0 ) {
            throw new ValidationException(['Nieprawidłowe ID dokumentu.']);
        }

        return $this->client->streamToFile("sale-documents/download/{$documentId}");
    }

    /**
     * @return DocumentItem[]
     */
    private function buildItems(WC_Order $order): array
    {
        $items = [];

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $quantity = (float) $item->get_quantity();
            if ( $quantity <= 0 ) {
                continue;
            }

            $lineTotal = (float) $item->get_total();
            $lineTax   = (float) $item->get_total_tax();
            $price     = wc_prices_include_tax()
                ? ($lineTotal + $lineTax) / $quantity
                : $lineTotal / $quantity;

            $vatRate = $this->resolveVatRate($this->getItemVatRate($item));

            $items[] = new DocumentItem(
                name:        $item->get_name(),
                unit:        'szt',
                vatRate:     $vatRate,
                price:       wc_format_decimal($price, 2),
                quantity:    wc_format_decimal($quantity, 4),
                articleType: 'good',
            );
        }

        $shippingTotal = (float) $order->get_shipping_total();
        if ( $shippingTotal > 0 ) {
            $shippingTax = (float) $order->get_shipping_tax();
            $price       = wc_prices_include_tax()
                ? $shippingTotal + $shippingTax
                : $shippingTotal;

            $vatRate = $this->resolveVatRate($this->getShippingVatRate($order));

            $items[] = new DocumentItem(
                name:        __('Wysyłka', 'firmino-integration'),
                unit:        'usl',
                vatRate:     $vatRate,
                price:       wc_format_decimal($price, 2),
                quantity:    wc_format_decimal(1, 4),
                articleType: 'service',
            );
        }

        return $items;
    }

    private function resolveDocumentType(WC_Order $order): string
    {
        $receiptType = $this->settings->receiptDocumentType;
        if ( $receiptType !== '' && $this->isIndividualCustomer($order) ) {
            return $receiptType;
        }

        return $this->settings->documentType;
    }

    private function isIndividualCustomer(WC_Order $order): bool
    {
        if ( trim((string) $order->get_billing_company()) !== '' ) {
            return false;
        }

        return $this->getOrderTin($order) === '';
    }

    private function getOrderTin(WC_Order $order): string
    {
        foreach ( ['billing_nip', 'billing_vat', 'vat_number', 'nip'] as $key ) {
            $value = $order->get_meta($key, true);
            if ( is_string($value) && trim($value) !== '' ) {
                return trim($value);
            }
        }

        return '';
    }

    private function getItemVatRate(WC_Order_Item_Product $item): string
    {
        $taxes  = $item->get_taxes();
        $rateId = '';

        if ( ! empty($taxes['total']) && is_array($taxes['total']) ) {
            $rateIds = array_keys($taxes['total']);
            $rateId  = (string) reset($rateIds);
        }

        return $this->formatTaxRate($rateId);
    }

    private function getShippingVatRate(WC_Order $order): string
    {
        foreach ( $order->get_items('shipping') as $item ) {
            $taxes = $item->get_taxes();
            if ( ! empty($taxes['total']) && is_array($taxes['total']) ) {
                $rateIds = array_keys($taxes['total']);
                return $this->formatTaxRate((string) reset($rateIds));
            }
        }

        return '0';
    }

    private function formatTaxRate(string $rateId): string
    {
        if ( $rateId === '' ) {
            return '0';
        }

        $rate = WC_Tax::get_rate_percent($rateId);
        $rate = str_replace('%', '', (string) $rate);
        $rate = rtrim(rtrim($rate, '0'), '.');

        return $rate === '' ? '0' : $rate;
    }

    private function resolveVatRate(string $rate): string
    {
        if ( $rate === '0' && $this->settings->defaultVatRate !== '' ) {
            return $this->settings->defaultVatRate;
        }

        return $rate;
    }
}
