<?php

declare(strict_types=1);

namespace FirminoIntegration;

use FirminoIntegration\Admin\ProductImportPage;
use FirminoIntegration\Admin\SettingsPage;
use FirminoIntegration\Http\WordPressHttpClient;
use FirminoIntegration\Services\CustomerService;
use FirminoIntegration\Services\DocumentService;
use FirminoIntegration\Services\ProductImportService;
use FirminoIntegration\ValueObjects\Settings;
use FirminoIntegration\WooCommerce\OrderHooks;

final class Plugin
{
    public static function init(): void
    {
        $settings = Settings::fromOption();
        $client   = new WordPressHttpClient($settings);

        ( new SettingsPage($client) )->register();

        if ( ! class_exists('WooCommerce') ) {
            return;
        }

        $customerService = new CustomerService($client, $settings);
        $documentService = new DocumentService($client, $settings);
        $importService   = new ProductImportService($client, $settings);

        ( new OrderHooks($customerService, $documentService, $settings) )->register();
        ( new ProductImportPage($importService, $settings) )->register();
    }
}
