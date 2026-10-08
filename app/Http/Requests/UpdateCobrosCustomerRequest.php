<?php

namespace App\Http\Requests;

/**
 * Fase 6B — edición de cliente desde el módulo Clientes de CobrOS.
 *
 * Hereda de UpdateCustomerRequest la normalización de teléfonos, el rechazo
 * (404) de clientes de otra empresa activa, el ignore de unicidad por cliente
 * y los mensajes en español.
 *
 * Al igual que en la alta, CobrOS no administra crédito, nivel de precio ni
 * geolocalización: esos campos no viajan en el formulario, por lo que la
 * actualización nunca los toca (R01 queda en manos de Commerce).
 *
 * La ruta debe nombrar el parámetro {cliente}: UpdateCustomerRequest lo usa
 * para el 404 cross-company y para ignorar la unicidad del propio cliente.
 */
class UpdateCobrosCustomerRequest extends UpdateCustomerRequest
{
    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(StoreCobrosCustomerRequest::FIELDS));
    }
}
