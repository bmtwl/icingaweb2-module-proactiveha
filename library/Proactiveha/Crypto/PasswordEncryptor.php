<?php

namespace Icinga\Module\Proactiveha\Crypto;

use RuntimeException;

class PasswordEncryptor
{
    public static function encrypt($plaintext, $keyPath)
    {
        $key = self::loadKey($keyPath);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Failed to encrypt data');
        }
        $hmac = hash_hmac('sha256', $iv . $encrypted, $key, true);
        return base64_encode($iv . $hmac . $encrypted);
    }

    public static function decrypt($ciphertext, $keyPath)
    {
        $key = self::loadKey($keyPath);
        $data = base64_decode($ciphertext);
        $iv = substr($data, 0, 16);
        $hmac = substr($data, 16, 32);
        $encrypted = substr($data, 48);

        $calculated = hash_hmac('sha256', $iv . $encrypted, $key, true);
        if (!hash_equals($hmac, $calculated)) {
            throw new RuntimeException('Invalid ciphertext');
        }

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            throw new RuntimeException('Failed to decrypt data');
        }
        return $decrypted;
    }

    public static function generateKey($keyPath)
    {
        $dir = dirname($keyPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($keyPath, random_bytes(32));
        chmod($keyPath, 0600);
        return $keyPath;
    }

    private static function loadKey($keyPath)
    {
        if (!file_exists($keyPath)) {
            throw new RuntimeException("Encryption key not found at $keyPath");
        }
        return file_get_contents($keyPath);
    }
}
