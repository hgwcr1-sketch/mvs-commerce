<?php

namespace App\Services\Fiscal\Hacienda;

use RuntimeException;

/**
 * La API oficial no devolvió una respuesta utilizable tras reintentar.
 *
 * Se distingue de un contribuyente no encontrado: "no sé" no es "no existe".
 * Confundirlas haría que MVS propusiera borrar datos de un cliente que en
 * realidad existe solo porque Hacienda estaba caída.
 */
class HaciendaUnavailableException extends RuntimeException
{
}
