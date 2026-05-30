<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

echo "PHP " . PHP_VERSION . "\n";
echo "curl: " . (extension_loaded('curl') ? 'OK' : 'MISSING - run: sudo apt install php-curl') . "\n";
echo "json: " . (extension_loaded('json') ? 'OK' : 'MISSING') . "\n";
echo "openssl: " . (extension_loaded('openssl') ? 'OK' : 'MISSING') . "\n";

if (extension_loaded('curl')) {
    $ch = curl_init('https://graph.instagram.com/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_NOBODY         => true,
    ]);
    curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "instagram.com reach: HTTP {$code}" . ($err !== '' ? " ({$err})" : '') . "\n";
}

echo "callback readable: " . (is_readable(__DIR__ . '/callback.php') ? 'OK' : 'NG') . "\n";
echo "storage writable: " . (is_writable(__DIR__) || is_writable(__DIR__ . '/storage') ? 'OK' : 'check permissions') . "\n";
