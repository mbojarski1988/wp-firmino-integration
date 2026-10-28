<?php

declare(strict_types=1);

namespace FirminoIntegration\Services;

use FirminoIntegration\Contracts\HttpClientInterface;
use FirminoIntegration\Exceptions\ApiException;
use FirminoIntegration\Exceptions\ValidationException;
use FirminoIntegration\ValueObjects\DocumentItem;
use FirminoIntegration\ValueObjects\Settings;
use FirminoIntegration\WooCommerce\OrderData;
use WC_Order;
use WC_Order_Item_Fee;
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

        // The invoice is issued now; the sale happened when the order was paid.
        $issueDate = current_time('Y-m-d');
        $saleDate  = $order->get_date_paid()?->date('Y-m-d') ?? $issueDate;

        $payload = [
            'customer'     => ['id' => $customerId],
            'documentDate' => $issueDate,
            'saleDate'     => $saleDate,
            'documentType' => $this->resolveDocumentType($order),
            'priceType'    => OrderData::pricesAreGross() ? 'gross' : 'net',
            'items'        => array_map(static fn(DocumentItem $i) => $i->toArray(), $items),
        ];

        $response = $this->client->request('sale-documents/add', $payload);

        return [
            'id'     => (int) ($response['response']['id'] ?? 0),
            'number' => (string) ($response['response']['number'] ?? $response['response']['documentName'] ?? ''),
        ];
    }

    /** Mark a Firmino document as fully paid after the WooCommerce payment succeeds. */
    public function markPaid(int $documentId): void
    {
        if ( $documentId <= 0 ) {
            throw new ValidationException(['Nieprawidłowe ID dokumentu.']);
        }

        // A previous request may have reached Firmino even if the HTTP response was lost.
        // Read its current state first so retrying a paid order never registers payment twice.
        $document = $this->client->request("sale-documents/get/{$documentId}");
        $data     = $document['response'] ?? null;
        if ( ! is_array($data) || (int) ($data['id'] ?? 0) !== $documentId ) {
            throw new ApiException('Firmino nie zwróciło danych dokumentu przed rozliczeniem.');
        }
        $payment  = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $remain   = $payment['remain'] ?? $payment['ramain'] ?? null;
        $total    = (float) ($payment['total'] ?? 0);
        // A missing balance is unknown, not settled: only a reported zero balance skips payoff.
        if ( ($data['documentState'] ?? '') === 'paid' || ($total > 0 && $remain !== null && (float) $remain <= 0) ) {
            return;
        }

        $this->client->request("sale-documents/payoff/{$documentId}");
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
            $price     = OrderData::pricesAreGross()
                ? ($lineTotal + $lineTax) / $quantity
                : $lineTotal / $quantity;

            $vatRate = $this->resolveVatRate($this->getItemVatRate($item));
            // Match ProductImportService: virtual and downloadable products are services.
            $product = $item->get_product();
            $isService = $product && ($product->is_virtual() || $product->is_downloadable());

            $items[] = new DocumentItem(
                name:        $item->get_name(),
                unit:        'szt',
                vatRate:     $vatRate,
                price:       wc_format_decimal($price, 2),
                quantity:    wc_format_decimal($quantity, 4),
                articleType: $isService ? 'service' : 'good',
            );
        }

        $shippingTotal = (float) $order->get_shipping_total();
        if ( $shippingTotal > 0 ) {
            $shippingTax = (float) $order->get_shipping_tax();
            $price       = OrderData::pricesAreGross()
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

        foreach ( $order->get_items('fee') as $fee ) {
            if ( ! $fee instanceof WC_Order_Item_Fee ) {
                continue;
            }

            $feeTotal = (float) $fee->get_total();
            if ( $feeTotal == 0.0 ) {
                continue;
            }
            if ( $feeTotal < 0 ) {
                // A wrong invoice cannot be corrected through the API, so stop before issuing one.
                throw new ValidationException([sprintf('Zamówienie zawiera ujemną opłatę „%s”, której Firmino nie przyjmie jako pozycji.', $fee->get_name())]);
            }

            $price = OrderData::pricesAreGross() ? $feeTotal + (float) $fee->get_total_tax() : $feeTotal;

            $items[] = new DocumentItem(
                name:        $fee->get_name(),
                unit:        'usl',
                vatRate:     $this->resolveVatRate($this->getTaxesVatRate($fee->get_taxes())),
                price:       wc_format_decimal($price, 2),
                quantity:    wc_format_decimal(1, 4),
                articleType: 'service',
            );
        }

        return $items;
    }

    private function getTaxesVatRate(array $taxes): string
    {
        if ( ! empty($taxes['total']) && is_array($taxes['total']) ) {
            $rateIds = array_keys($taxes['total']);
            return $this->formatTaxRate((string) reset($rateIds));
        }

        return '0';
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

        return OrderData::tin($order) === '';
    }

    private function getItemVatRate(WC_Order_Item_Product $item): string
    {
        return $this->getTaxesVatRate($item->get_taxes());
    }

    private function getShippingVatRate(WC_Order $order): string
    {
        foreach ( $order->get_items('shipping') as $item ) {
            $rate = $this->getTaxesVatRate($item->get_taxes());
            if ( $rate !== '0' ) {
                return $rate;
            }
        }

        return '0';
    }

    private function formatTaxRate(string $rateId): string
    {
        if ( $rateId === '' ) {
            return '0';
        }

        return OrderData::formatRate((string) WC_Tax::get_rate_percent($rateId));
    }

    private function resolveVatRate(string $rate): string
    {
        if ( $rate === '0' && $this->settings->defaultVatRate !== '' ) {
            return $this->settings->defaultVatRate;
        }

        return $rate;
    }
}
