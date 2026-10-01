<?php

namespace App\Services\Facturaencr;

class FacturaencrTaxpayerService
{
    private FacturaencrClient $client;

    public function __construct(FacturaencrClient $client)
    {
        $this->client = $client;
    }

    public function getRegimen(string $identificacion): array
    {
        $response = $this->client->get("contribuyentes/{$identificacion}/regimen");

        if (!$response->isSuccess()) {
            return [
                'encontrado' => false,
                'contribuyente' => null,
                'regimen' => null,
                'actividadesEconomicas' => [],
                'error' => $response->errorCode,
            ];
        }

        $data = $response->data;

        return [
            'encontrado' => $data['encontrado'] ?? false,
            'contribuyente' => $data['contribuyente'] ?? null,
            'regimen' => $data['regimen'] ?? null,
            'actividadesEconomicas' => $data['actividadesEconomicas'] ?? [],
        ];
    }

    public function getFull(string $identificacion): array
    {
        $response = $this->client->get("contribuyentes/{$identificacion}");

        if (!$response->isSuccess()) {
            return [
                'encontrado' => false,
                'contribuyente' => null,
                'regimen' => null,
                'actividadesEconomicas' => [],
                'situacion' => null,
                'error' => $response->errorCode,
            ];
        }

        $data = $response->data;

        return [
            'encontrado' => $data['encontrado'] ?? false,
            'contribuyente' => $data['contribuyente'] ?? null,
            'regimen' => $data['regimen'] ?? null,
            'actividadesEconomicas' => $data['actividadesEconomicas'] ?? [],
            'situacion' => $data['situacion'] ?? null,
        ];
    }
}