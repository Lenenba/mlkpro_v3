<?php

namespace App\Services;

class TotpService
{
    private const SECRET_LENGTH = 20;
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = self::SECRET_LENGTH): string
    {
        $bytes = random_bytes($length);
        return $this->base32Encode($bytes);
    }

    public function otpAuthUrl(string $issuer, string $accountName, string $secret): string
    {
        $labelIssuer = rawurlencode($issuer);
        $labelAccount = rawurlencode($accountName);
        $label = $labelIssuer . ':' . $labelAccount;
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'period' => self::PERIOD,
            'digits' => self::DIGITS,
        ]);

        return "otpauth://totp/{$label}?{$query}";
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if ($code === '' || !ctype_digit($code)) {
            return false;
        }

        $timestamp = (int) floor(time() / self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $expected = $this->generateCode($secret, $timestamp + $offset);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private function generateCode(string $secret, int $timestamp): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return '';
        }

        $time = pack('N*', 0) . pack('N*', $timestamp);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 5);
        $encoded = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $index = bindec($chunk);
            $encoded .= self::BASE32_ALPHABET[$index];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper($secret);
        $buffer = 0;
        $bits = 0;
        $output = '';

        $length = strlen($secret);
        for ($i = 0; $i < $length; $i++) {
            $char = $secret[$i];
            if ($char === '=') {
                continue;
            }

            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                return '';
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output;
    }
}
