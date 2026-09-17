<?php

namespace App\Services\Facturaencr;

use Illuminate\Support\Facades\Http;

class FacturaencrCabysService
{
    private FacturaencrClient $client;

    public function __construct(FacturaencrClient $client)
    {
        $this->client = $client;
    }

    public function search(string $query, int $top = 30): array
    {
        $response = $this->client->get('catalogs/cabys', [
            'q' => $query,
            'top' => $top,
        ]);

        if (!$response->isSuccess()) {
            return ['found' => false, 'items' => []];
        }

        $data = $response->data;

        return [
            'found' => !empty($data['items']),
            'items' => $data['items'] ?? [],
        ];
    }

    public function validate(string $cabys): array
    {
        $response = $this->client->get('catalogs/cabys', [
            'q' => $cabys,
            'top' => 1,
        ]);

        if (!$response->isSuccess()) {
            return ['found' => false, 'error' => $response->errorCode];
        }

        $data = $response->data;
        $items = $data['items'] ?? [];

        if (empty($items)) {
            return ['found' => false];
        }

        $item = $items[0];

        return [
            'found' => true,
            'codigo' => $item['codigo'] ?? '',
            'descripcion' => $item['descripcion'] ?? '',
            'impuesto' => $item['impuesto'] ?? null,
        ];
    }
}