<?php

namespace App\Services;

use App\Models\Customer;

class PhoneNumberService
{
    public function forCustomer(Customer $customer): ?string
    {
        return $this->forWhatsApp($customer->phone_country_code, $customer->phone);
    }

    public function normalizeCountryCode(?string $countryCode): ?string
    {
        $countryCode = $this->clean($countryCode);

        if ($countryCode === null) {
            return null;
        }

        return '+'.ltrim($countryCode, '+');
    }

    public function normalizePhone(?string $phone): ?string
    {
        return $this->clean($phone);
    }

    public function forWhatsApp(?string $countryCode, ?string $phone): ?string
    {
        $countryCode = $this->digits($countryCode);
        $phone = $this->digits($phone);

        if ($countryCode === null || $phone === null) {
            return null;
        }

        return $countryCode.$phone;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\s\-().]/', '', trim($value));

        return $value === '' ? null : $value;
    }

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        return $digits === '' ? null : $digits;
    }
}
