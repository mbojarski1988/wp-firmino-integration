<?php
declare(strict_types=1);

$tests = ['order-hook-flow.php', 'document-payment.php'];
$failures = 0;
foreach ($tests as $test) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $test), $status);
    $failures += $status === 0 ? 0 : 1;
}
exit($failures > 0 ? 1 : 0);
