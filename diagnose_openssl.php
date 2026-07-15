<?php
echo "PHP version: " . PHP_VERSION . "\n";
echo "OPENSSL_CONF env: " . (getenv('OPENSSL_CONF') ?: '(مش متظبط)') . "\n";
echo "openssl.cafile ini: " . (ini_get('openssl.cafile') ?: '(مش متظبط)') . "\n\n";

$res = openssl_pkey_new([
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if ($res === false) {
    echo "فشل openssl_pkey_new. الأخطاء من OpenSSL:\n";
    while ($msg = openssl_error_string()) {
        echo " - $msg\n";
    }
} else {
    echo "نجح! المفتاح اتعمل تمام.\n";
}