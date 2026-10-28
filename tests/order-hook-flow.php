<?php
declare(strict_types=1);

namespace {
    $GLOBALS['registered_actions'] = [];
    $GLOBALS['registered_filters'] = [];
    $GLOBALS['orders'] = [];

    function add_action(string $name, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {
        $GLOBALS['registered_actions'][$name] = [$callback, $priority, $acceptedArgs];
    }
    function add_filter(string $name, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {
        $GLOBALS['registered_filters'][$name] = [$callback, $priority, $acceptedArgs];
    }
    function wc_get_order(int $id): mixed { return $GLOBALS['orders'][$id] ?? null; }
    function __(string $text, string $domain = ''): string { return $text; }
    function wp_parse_args(array $args, array $defaults): array { return array_merge($defaults, $args); }
    $GLOBALS['options'] = [];
    function get_option(string $key, mixed $default = false): mixed { return $key === 'firmino_settings' ? [] : ($GLOBALS['options'][$key] ?? $default); }
    function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = true): bool {
        if (array_key_exists($key, $GLOBALS['options'])) { return false; }
        $GLOBALS['options'][$key] = $value;
        return true;
    }
    function update_option(string $key, mixed $value, bool $autoload = true): bool { $GLOBALS['options'][$key] = $value; return true; }
    function delete_option(string $key): bool { unset($GLOBALS['options'][$key]); return true; }

    final class WC_Order {
        public array $meta = [];
        public array $notes = [];
        public int $saves = 0;
        public function __construct(public int $id, public bool $paid) {}
        public function is_paid(): bool { return $this->paid; }
        public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
        public function update_meta_data(string $key, mixed $value): void { $this->meta[$key] = $value; }
        public function delete_meta_data(string $key): void { unset($this->meta[$key]); }
        public function save(): void { $this->saves++; }
        public function read_meta_data(bool $force = false): void {}
        public function get_id(): int { return $this->id; }
        public function add_order_note(string $note): void { $this->notes[] = $note; }
    }
}

namespace FirminoIntegration\Services {
    final class CustomerService {
        public int $calls = 0;
        public int $clears = 0;
        public function resolveForOrder(\WC_Order $order): int { $this->calls++; return 84; }
        public function clearCachedId(\WC_Order $order): void { $this->clears++; }
    }

    final class DocumentService {
        public int $creates = 0;
        public int $payments = 0;
        public bool $failNextPayment = false;
        public function createFromOrder(\WC_Order $order, int $customerId): array {
            $this->creates++;
            return ['id' => 700 + $this->creates, 'number' => 'FAS 1/2026'];
        }
        public function markPaid(int $documentId): void {
            $this->payments++;
            if ($this->failNextPayment) {
                $this->failNextPayment = false;
                throw new \FirminoIntegration\Exceptions\ApiException('API niedostępne');
            }
        }
    }
}

namespace {
    require __DIR__ . '/../src/Exceptions/FirminoException.php';
    require __DIR__ . '/../src/Exceptions/ApiException.php';
    require __DIR__ . '/../src/ValueObjects/Settings.php';
    require __DIR__ . '/../src/WooCommerce/OrderHooks.php';

    use FirminoIntegration\Services\CustomerService;
    use FirminoIntegration\Services\DocumentService;
    use FirminoIntegration\ValueObjects\Settings;
    use FirminoIntegration\WooCommerce\OrderHooks;

    $assertions = 0;
    $assert = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) { throw new \RuntimeException($message); }
    };
    $newHooks = static function (): array {
        $customers = new CustomerService();
        $documents = new DocumentService();
        return [new OrderHooks($customers, $documents, Settings::fromOption()), $customers, $documents];
    };

    [$hooks, $customers, $documents] = $newHooks();
    $hooks->register();
    $assert(isset($GLOBALS['registered_actions']['woocommerce_payment_complete']), 'Registers payment-complete hook.');
    $assert($GLOBALS['registered_actions']['woocommerce_payment_complete'][2] === 1, 'Payment-complete hook accepts one argument.');

    $unpaid = new WC_Order(1, false);
    $hooks->handleOrderAction($unpaid);
    $assert($customers->calls === 0 && $documents->creates === 0, 'Unpaid manual action does not create a customer or invoice.');
    $assert(str_contains($unpaid->notes[0], 'nie jest opłacone'), 'Unpaid order receives an explanatory note.');

    $processing = new WC_Order(2, true);
    $hooks->maybeGenerateOnStatusChange(2, 'pending', 'processing', $processing);
    $assert($documents->creates === 1 && $documents->payments === 1, 'Paid processing order gets one invoice and payment.');
    $assert($processing->meta['_firmino_document_id'] === 701, 'Invoice ID is persisted.');
    $assert($processing->meta['_firmino_document_number'] === 'FAS 1/2026', 'Invoice number is persisted.');
    $assert(!isset($processing->meta['_firmino_document_payment_pending']), 'Payment pending marker clears on success.');
    $GLOBALS['orders'][$processing->id] = $processing;
    $hooks->maybeGenerateOnPaymentComplete(2);
    $assert($documents->creates === 1 && $documents->payments === 1, 'Sequential status and payment hooks do not duplicate the invoice.');

    $digital = new WC_Order(3, true);
    $GLOBALS['orders'][$digital->id] = $digital;
    $hooks->maybeGenerateOnPaymentComplete(3);
    $assert($documents->creates === 2 && $documents->payments === 2, 'Paid virtual order completed directly gets an invoice.');

    $failed = new WC_Order(4, true);
    $documents->failNextPayment = true;
    $hooks->handleOrderAction($failed);
    $assert($documents->creates === 3 && $failed->meta['_firmino_document_payment_pending'] === 'yes', 'Payment failure keeps its invoice ID and retry marker.');
    $assert(str_contains($failed->notes[1], 'rejestracja płatności nie powiodła się'), 'Payment failure gives the administrator a retry instruction.');
    $hooks->handleOrderAction($failed);
    $assert($documents->creates === 3 && $documents->payments === 4, 'Retry settles the existing invoice without creating another.');
    $assert(!isset($failed->meta['_firmino_document_payment_pending']), 'Retry clears the pending marker.');

    $legacy = new WC_Order(5, true);
    $legacy->meta['_firmino_document_id'] = 99;
    $GLOBALS['orders'][$legacy->id] = $legacy;
    $hooks->maybeGenerateOnPaymentComplete(5);
    $hooks->maybeGenerateOnStatusChange(5, 'pending', 'processing', $legacy);
    $assert($documents->creates === 3 && $documents->payments === 4, 'Existing invoice without retry marker is not changed.');

    $awaiting = new WC_Order(6, false);
    $hooks->maybeGenerateOnStatusChange(6, 'pending', 'completed', $awaiting);
    $assert($documents->creates === 3, 'Unpaid completed order is ignored.');

    $concurrent = new WC_Order(7, true);
    $GLOBALS['options']['firmino_order_lock_7'] = time();
    $hooks->handleOrderAction($concurrent);
    $assert($documents->creates === 3 && $concurrent->notes === [], 'Order locked by a concurrent request is not invoiced twice.');
    $GLOBALS['options']['firmino_order_lock_7'] = time() - 3600;
    $hooks->handleOrderAction($concurrent);
    $assert($documents->creates === 4, 'Stale lock from a crashed request is taken over.');
    $assert(!isset($GLOBALS['options']['firmino_order_lock_7']), 'Lock is released after processing.');

    fwrite(STDOUT, sprintf("PASS: %d paid-order hook assertions.\n", $assertions));
}
