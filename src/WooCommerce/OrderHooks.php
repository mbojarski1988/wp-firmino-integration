<?php

declare(strict_types=1);

namespace FirminoIntegration\WooCommerce;

use FirminoIntegration\Exceptions\ApiException;
use FirminoIntegration\Exceptions\FirminoException;
use FirminoIntegration\Services\CustomerService;
use FirminoIntegration\Services\DocumentService;
use FirminoIntegration\ValueObjects\Settings;
use WC_Order;

final class OrderHooks
{
    private bool $customerLinkRendered = false;

    public function __construct(
        private readonly CustomerService $customerService,
        private readonly DocumentService $documentService,
        private readonly Settings $settings,
    ) {}

    public function register(): void
    {
        add_filter('woocommerce_order_actions', [$this, 'addOrderAction']);
        add_action('woocommerce_order_action_firmino_send', [$this, 'handleOrderAction']);
        add_action('admin_post_firmino_download_invoice', [$this, 'handleDownloadInvoice']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'renderAdminDownloadLink']);
        add_action('woocommerce_order_details_after_order_table', [$this, 'renderCustomerDownloadLink']);
        add_filter('woocommerce_admin_order_actions', [$this, 'addAdminListAction'], 10, 2);
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'addCustomerListAction'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'maybeGenerateOnStatusChange'], 10, 4);
    }

    public function addOrderAction(array $actions): array
    {
        $actions['firmino_send'] = __('Wyślij do Firmino', 'firmino-integration');
        return $actions;
    }

    public function handleOrderAction(WC_Order $order): void
    {
        try {
            $this->processOrder($order);
        } catch ( FirminoException $e ) {
            $order->add_order_note(sprintf('Błąd Firmino: %s', $e->getMessage()));
        }
    }

    public function maybeGenerateOnStatusChange(int $orderId, string $oldStatus, string $newStatus, WC_Order $order): void
    {
        if ( $newStatus !== 'processing' && $newStatus !== 'w-trakcie-realizacji' ) {
            return;
        }

        $documentId = $order->get_meta('_firmino_document_id', true);
        if ( ! empty($documentId) ) {
            return;
        }

        $this->handleOrderAction($order);
    }

    public function renderAdminDownloadLink(WC_Order $order): void
    {
        if ( ! current_user_can('manage_woocommerce') ) {
            return;
        }

        $documentId = $order->get_meta('_firmino_document_id', true);
        if ( empty($documentId) ) {
            return;
        }

        $label = esc_html__('Pobierz dokument Firmino', 'firmino-integration');
        $number = $order->get_meta('_firmino_document_number', true);
        if ( ! empty($number) ) {
            $label .= ' (' . esc_html($number) . ')';
        }

        echo '<p><a class="button" href="' . esc_url($this->getDownloadUrl($order)) . '">' . $label . '</a></p>';
    }

    public function renderCustomerDownloadLink(WC_Order $order): void
    {
        if ( $this->customerLinkRendered ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( (int) $order->get_user_id() !== get_current_user_id() ) {
            return;
        }

        $documentId = $order->get_meta('_firmino_document_id', true);
        if ( empty($documentId) ) {
            return;
        }

        $label = esc_html__('Pobierz dokument', 'firmino-integration');
        $number = $order->get_meta('_firmino_document_number', true);
        if ( ! empty($number) ) {
            $label .= ' (' . esc_html($number) . ')';
        }

        echo '<p><a class="button" href="' . esc_url($this->getDownloadUrl($order)) . '">' . $label . '</a></p>';
        $this->customerLinkRendered = true;
    }

    public function addAdminListAction(array $actions, WC_Order $order): array
    {
        $documentId = $order->get_meta('_firmino_document_id', true);
        if ( empty($documentId) ) {
            return $actions;
        }

        $actions['firmino_download'] = [
            'url'    => $this->getDownloadUrl($order),
            'name'   => __('Pobierz dokument Firmino', 'firmino-integration'),
            'action' => 'firmino-download',
        ];

        return $actions;
    }

    public function addCustomerListAction(array $actions, WC_Order $order): array
    {
        if ( function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('view-order') ) {
            return $actions;
        }

        $documentId = $order->get_meta('_firmino_document_id', true);
        if ( empty($documentId) ) {
            return $actions;
        }

        $actions['firmino_download'] = [
            'url'  => $this->getDownloadUrl($order),
            'name' => __('Pobierz dokument', 'firmino-integration'),
        ];

        return $actions;
    }

    public function handleDownloadInvoice(): void
    {
        if ( ! is_user_logged_in() ) {
            wp_die(esc_html__('Brak dostępu.', 'firmino-integration'));
        }

        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if ( $orderId <= 0 ) {
            wp_die(esc_html__('Nieprawidłowe zamówienie.', 'firmino-integration'));
        }

        if ( ! wp_verify_nonce($_GET['_wpnonce'] ?? '', 'firmino_download_invoice_' . $orderId) ) {
            wp_die(esc_html__('Nieprawidłowy token.', 'firmino-integration'));
        }

        $order = wc_get_order($orderId);
        if ( ! $order instanceof WC_Order ) {
            wp_die(esc_html__('Nie znaleziono zamówienia.', 'firmino-integration'));
        }

        $isAdmin = current_user_can('manage_woocommerce');
        if ( ! $isAdmin && (int) $order->get_user_id() !== get_current_user_id() ) {
            wp_die(esc_html__('Brak dostępu.', 'firmino-integration'));
        }

        $documentId = (int) $order->get_meta('_firmino_document_id', true);
        if ( $documentId <= 0 ) {
            wp_die(esc_html__('Brak ID dokumentu Firmino.', 'firmino-integration'));
        }

        try {
            $file = $this->documentService->downloadPdf($documentId);
        } catch ( FirminoException $e ) {
            wp_die(esc_html($e->getMessage()));
        }

        if ( ! file_exists($file) ) {
            wp_die(esc_html__('Brak pliku faktury.', 'firmino-integration'));
        }

        $filename = sprintf('firmino-document-%d.pdf', $order->get_id());

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        @unlink($file);
        exit;
    }

    private function processOrder(WC_Order $order, bool $retried = false): void
    {
        $customerId = $this->customerService->resolveForOrder($order);

        try {
            $result = $this->documentService->createFromOrder($order, $customerId);
        } catch ( ApiException $e ) {
            $body    = $e->getResponseBody();
            $message = is_array($body) ? (string) ($body['response'] ?? '') : '';

            if ( ! $retried && str_contains($message, 'Nie znaleziono obiektu o id=') ) {
                $this->customerService->clearCachedId($order);
                $this->processOrder($order, retried: true);
                return;
            }

            throw $e;
        }

        if ( $result['id'] > 0 ) {
            $order->update_meta_data('_firmino_document_id', $result['id']);
            $order->update_meta_data('_firmino_document_number', $result['number']);
            $order->save();
            $order->add_order_note(sprintf('Dokument Firmino utworzony (ID %s).', $result['id']));
        }
    }

    private function getDownloadUrl(WC_Order $order): string
    {
        return wp_nonce_url(
            add_query_arg(
                ['action' => 'firmino_download_invoice', 'order_id' => $order->get_id()],
                admin_url('admin-post.php'),
            ),
            'firmino_download_invoice_' . $order->get_id(),
        );
    }
}
