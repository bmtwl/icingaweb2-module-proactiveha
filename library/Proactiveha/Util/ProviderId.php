<?php

namespace Icinga\Module\Proactiveha\Util;

use InvalidArgumentException;

class ProviderId
{
    /**
     * Convert any recognized provider ID form to the canonical vSphere hex string:
     * "52 09 7c ea b7 28 9b 09-31 23 f4 ba d0 8a b8 a2"
     */
    public static function normalize($input)
    {
        $input = self::extractValue($input);
        $binary = self::toBinary($input);
        if ($binary === '') {
            return '';
        }

        return self::formatBinary($binary);
    }

    public static function toUuid($input)
    {
        $input = self::extractValue($input);
        $hex = self::toHex($input);
        if ($hex === '') {
            return '';
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    public static function toHex($input)
    {
        $input = self::extractValue($input);
        if ($input === null || $input === '') {
            return '';
        }

        if (preg_match('/^[0-9a-fA-F]{32}$/', $input)) {
            return strtolower($input);
        }

        $binary = self::toBinary($input);
        return $binary === '' ? '' : bin2hex($binary);
    }

    public static function toBinary($input)
    {
        $input = self::extractValue($input);
        if ($input === null || $input === '') {
            return '';
        }

        if (preg_match('/^[0-9a-fA-F]{32}$/', $input)) {
            return hex2bin($input);
        }

        if (strlen($input) === 16) {
            return $input;
        }

        $hex = preg_replace('/[^0-9a-fA-F]/', '', $input);
        if (strlen($hex) === 32) {
            return hex2bin($hex);
        }

        throw new InvalidArgumentException('Invalid provider ID: ' . bin2hex($input));
    }

    private static function extractValue($input)
    {
        if ($input === null || is_string($input) || is_int($input) || is_float($input)) {
            return $input;
        }

        if (is_object($input)) {
            if (isset($input->_)) {
                return $input->_;
            }
            if (isset($input->value)) {
                return $input->value;
            }
            if (isset($input->id)) {
                return $input->id;
            }
            if (method_exists($input, '__toString')) {
                return (string) $input;
            }
            return null;
        }

        if (is_array($input)) {
            return $input['_'] ?? $input['value'] ?? $input['id'] ?? null;
        }

        return null;
    }

    private static function formatBinary($binary)
    {
        $bytes = unpack('C*', $binary);
        $parts = [];
        foreach ($bytes as $byte) {
            $parts[] = sprintf('%02x', $byte);
        }

        return implode(' ', array_slice($parts, 0, 8))
            . '-'
            . implode(' ', array_slice($parts, 8, 8));
    }
}
