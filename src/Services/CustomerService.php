<?php

declare(strict_types=1);

namespace FirminoIntegration\Services;

use FirminoIntegration\Contracts\HttpClientInterface;
use FirminoIntegration\Exceptions\ApiException;
use FirminoIntegration\Exceptions\ValidationException;
use FirminoIntegration\ValueObjects\CustomerData;
use FirminoIntegration\ValueObjects\Settings;
use WC_Order;

final class CustomerService
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Settings $settings,
    ) {}

    /**
     * Returns Firmino customer ID, creating the customer if not cached on the order.
     *
     * @throws ApiException
     * @throws ValidationException
     */
    public function resolveForOrder(WC_Order $order): int
    {
        $cached = (int) $order->get_meta('_firmino_customer_id', true);
        if ( $cached > 0 ) {
            return $cached;
        }

        $data     = $this->buildCustomerData($order);
        $response = $this->client->request('customers/add', $data->toArray());
        $id       = (int) ($response['response']['id'] ?? 0);

        if ( $id <= 0 ) {
            throw new ApiException('Firmino nie zwróciło ID klienta.');
        }

        $order->update_meta_data('_firmino_customer_id', $id);
        $order->save();

        return $id;
    }

    /**
     * Clears the cached customer ID so the next call to resolveForOrder creates a fresh customer.
     */
    public function clearCachedId(WC_Order $order): void
    {
        $order->delete_meta_data('_firmino_customer_id');
        $order->save();
    }

    /**
     * @throws ValidationException
     */
    private function buildCustomerData(WC_Order $order): CustomerData
    {
        $billingCompany = $order->get_billing_company();
        $billingName    = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $fullName       = $billingCompany !== '' ? $billingCompany : $billingName;

        if ( $fullName === '' ) {
            $fullName = sprintf('Customer %d', $order->get_id());
        }

        $locality = $order->get_billing_city();
        if ( $locality === '' ) {
            $locality = $this->settings->defaultLocality;
        }
        if ( $locality === '' ) {
            throw new ValidationException([
                'Brak miejscowości. Ustaw miasto w zamówieniu lub domyślną miejscowość w ustawieniach Firmino.',
            ]);
        }

        $country = $order->get_billing_country();
        if ( $country === '' ) {
            $country = $this->settings->defaultCountry;
        }
        if ( $country === '' ) {
            throw new ValidationException([
                'Brak kodu kraju. Ustaw kraj w zamówieniu lub domyślny kraj w ustawieniach Firmino.',
            ]);
        }

        $address1 = $order->get_billing_address_1();
        $street   = $address1;
        $houseNo  = '';

        if ( preg_match('/^(.*)\s+(\d+[A-Za-z0-9\/-]*)$/', $address1, $matches) ) {
            $street  = trim($matches[1]);
            $houseNo = trim($matches[2]);
        }

        return new CustomerData(
            fullName:    $fullName,
            shortName:   sprintf('WC-%d', $order->get_id()),
            locality:    $locality,
            countryCode: $country,
            street:      $street,
            houseNo:     $houseNo,
            flatNo:      $order->get_billing_address_2(),
            postCode:    $order->get_billing_postcode(),
            email:       $order->get_billing_email(),
            phone:       $order->get_billing_phone(),
            tin:         $this->getOrderTin($order) ?: null,
        );
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
}
