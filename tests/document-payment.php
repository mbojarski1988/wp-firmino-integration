<?php
declare(strict_types=1);

namespace {
    require __DIR__ . '/../src/Contracts/HttpClientInterface.php';
    function wp_parse_args(array $args, array $defaults): array { return array_merge($defaults, $args); }
    function get_option(string $key, mixed $default = false): mixed { return $key === 'firmino_settings' ? [] : $default; }
}

namespace FirminoIntegration\Tests {
    final class FakeHttpClient implements \FirminoIntegration\Contracts\HttpClientInterface {
        public array $requests = [];
        public array $responses = [];
        public function request(string $endpoint, array $body = [], string $method = 'POST'): array {
            $this->requests[] = [$endpoint, $body, $method];
            return array_shift($this->responses) ?? [];
        }
        public function streamToFile(string $endpoint): string { return ''; }
    }
}

namespace {
    require __DIR__ . '/../src/Exceptions/FirminoException.php';
    require __DIR__ . '/../src/Exceptions/ApiException.php';
    require __DIR__ . '/../src/Exceptions/ValidationException.php';
    require __DIR__ . '/../src/ValueObjects/Settings.php';
    require __DIR__ . '/../src/ValueObjects/DocumentItem.php';
    require __DIR__ . '/../src/Services/DocumentService.php';

    use FirminoIntegration\Exceptions\ApiException;
    use FirminoIntegration\Services\DocumentService;
    use FirminoIntegration\Tests\FakeHttpClient;
    use FirminoIntegration\ValueObjects\Settings;

    $assertions = 0;
    $assert = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) { throw new \RuntimeException($message); }
    };
    $document = static fn(array $fields): array => ['success' => true, 'response' => ['id' => 81] + $fields];

    $client = new FakeHttpClient();
    $client->responses = [$document(['documentState' => 'new', 'payment' => ['total' => '20.00', 'remain' => '20.00']]), ['success' => true]];
    $service = new DocumentService($client, Settings::fromOption());
    $service->markPaid(81);
    $assert(array_column($client->requests, 0) === ['sale-documents/get/81', 'sale-documents/payoff/81'], 'Outstanding invoice is checked then settled.');

    $paidClient = new FakeHttpClient();
    $paidClient->responses = [$document(['documentState' => 'paid', 'payment' => ['total' => '20.00', 'remain' => '0.00']])];
    (new DocumentService($paidClient, Settings::fromOption()))->markPaid(81);
    $assert(count($paidClient->requests) === 1, 'Already paid invoice is not settled again.');

    $responseLostClient = new FakeHttpClient();
    $responseLostClient->responses = [$document(['documentState' => 'partpaid', 'payment' => ['total' => '20.00', 'paid' => '20.00', 'ramain' => '0.00']])];
    (new DocumentService($responseLostClient, Settings::fromOption()))->markPaid(81);
    $assert(count($responseLostClient->requests) === 1, 'Fully settled invoice with legacy ramain field is not paid twice.');

    $missingClient = new FakeHttpClient();
    $missingClient->responses = [['success' => true, 'response' => ['documentState' => 'new']]];
    try {
        (new DocumentService($missingClient, Settings::fromOption()))->markPaid(81);
        throw new \RuntimeException('Missing document identity should be rejected.');
    } catch (ApiException $exception) {
        $assert(count($missingClient->requests) === 1, 'Missing or mismatched document identity never triggers payoff.');
    }

    $unknownBalanceClient = new FakeHttpClient();
    $unknownBalanceClient->responses = [$document(['documentState' => 'new', 'payment' => ['total' => '20.00']]), ['success' => true]];
    (new DocumentService($unknownBalanceClient, Settings::fromOption()))->markPaid(81);
    $assert(count($unknownBalanceClient->requests) === 2, 'Unpaid invoice without a reported balance is still settled.');

    $invalidClient = new FakeHttpClient();
    try {
        (new DocumentService($invalidClient, Settings::fromOption()))->markPaid(0);
        throw new \RuntimeException('Invalid ID should be rejected.');
    } catch (\FirminoIntegration\Exceptions\ValidationException $exception) {
        $assert($invalidClient->requests === [], 'Invalid ID never calls the API.');
    }

    fwrite(STDOUT, sprintf("PASS: %d Firmino payment API assertions.\n", $assertions));
}
