<?php

namespace App\Services\Cobros;

use RuntimeException;

class CollectorPasswordService
{
    /**
     * Generate a secure temporary password.
     *
     * Format: 6 characters — exactly 1 uppercase, exactly 1 lowercase, exactly 4 digits.
     * Example pattern: Aa4821 (not used as fixed value).
     * Easy to type on mobile.
     */
    public function generate(): string
    {
        $upper = $this->randomChar('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $lower = $this->randomChar('abcdefghijklmnopqrstuvwxyz');
        $digits = $this->randomDigits(4);

        $chars = str_split($upper . $lower . $digits);

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    public function validateFormat(string $password): bool
    {
        if (strlen($password) !== 6) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9]{6}$/', $password) !== 1) {
            return false;
        }

        if (preg_match('/[A-Z]/', $password) !== 1) {
            return false;
        }

        if (preg_match('/[a-z]/', $password) !== 1) {
            return false;
        }

        if (preg_match_all('/[0-9]/', $password) !== 4) {
            return false;
        }

        return true;
    }

    private function randomChar(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }

    private function randomDigits(int $count): string
    {
        $result = '';
        for ($i = 0; $i < $count; $i++) {
            $result .= random_int(0, 9);
        }

        return $result;
    }
}
