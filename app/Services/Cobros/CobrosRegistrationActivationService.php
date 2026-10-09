<?php

namespace App\Services\Cobros;

use App\Http\Controllers\Cobros\PublicLandingController;
use App\Http\Controllers\Cobros\PublicRegistrationController;
use App\Models\CobrosRegistrationDraft;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 16C-1 — Preparación de la activación del borrador de registro.
 *
 * Solo LEE. Valida el borrador de forma integral y construye el plan de
 * activación por ruta con los archivos privados ya guardados (16B-2B),
 * asociándolos SIN procesarlos todavía.
 *
 * Esta fase NO crea ni modifica empresas, usuarios, rutas, clientes,
 * créditos, CxC, licencias ni membresías, y NO importa archivos. La fase de
 * activación real (posterior) reutilizará:
 *  - CompanyProvisioner (empresa + sucursal + rol Administrador);
 *  - CobrosModalityService::assign (modalidad de la membresía);
 *  - CustomerImportChunkReader / motores 12B (procesamiento de archivos);
 *  - alta de rutas y cobradores con sus servicios existentes.
 *
 * Idempotencia: idempotencyKey() entrega una clave determinista por borrador
 * para que la activación pueda reintentarse sin duplicar empresas, usuarios,
 * rutas o importaciones.
 *
 * Invitación laboral: la modalidad Cobrador vinculado exige una invitación
 * válida en el payload; si falta o es inválida se devuelve un error
 * controlado. Aquí NO se inventa ningún vínculo entre el cobrador y un
 * prestamista: verificar la invitación contra su registro y crear la
 * membresía pertenece a la fase de activación.
 */
class CobrosRegistrationActivationService
{
    /**
     * Errores por campo; arreglo vacío = borrador listo para activar.
     *
     * @return array<string, string>
     */
    public function validate(CobrosRegistrationDraft $draft): array
    {
        $payload = is_array($draft->payload) ? $draft->payload : [];

        if ($payload === []) {
            return ['draft' => 'El borrador está vacío o incompleto.'];
        }

        $errors = [];

        $iso = $payload['country_iso'] ?? null;
        if (! is_string($iso) || ! array_key_exists($iso, PublicRegistrationController::AVAILABLE_COUNTRIES)) {
            $errors['country_iso'] = 'Seleccione un país habilitado para iniciar.';
        }

        $modality = $payload['modality'] ?? null;
        if (! is_string($modality) || ! CobrosModalityCatalog::has($modality)) {
            $errors['modality'] = 'Seleccione una de las modalidades disponibles.';
            $modality = null;
        }

        // BLOQUE 16R-1 — el servidor rechaza la modalidad multiprestamista
        // mientras la bandera del Panel Maestro no la habilite. El catálogo
        // y los servicios existentes no se modifican: solo se bloquea aquí
        // el alta de NUEVOS registros bajo esa modalidad.
        if ($modality === CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA
            && ! (bool) config('cobros.registration.multi_lender_enabled', false)) {
            $errors['modality'] = 'La modalidad multiprestamista no está habilitada para nuevos registros.';
        }

        $errors = [
            ...$errors,
            ...$this->validatePersonal($payload),
            ...$this->validatePortfolioPlan($payload),
        ];

        if ($modality === CobrosModalityCatalog::COBRADOR_VINCULADO) {
            $personalEmail = $payload['personal']['email'] ?? null;
            $invitationErrors = $this->laborInvitationErrors($payload, is_string($personalEmail) ? $personalEmail : '');

            if ($invitationErrors !== []) {
                $errors['labor_invitation'] = implode(' ', $invitationErrors);
            }
        }

        $routeIds = $this->validateRoutes($payload, $modality, $errors);

        $errors = [
            ...$errors,
            ...$this->validateReferral($payload),
            ...$this->validateUploads($payload, $draft, $routeIds),
        ];

        return $errors;
    }

    /** Lanza ValidationException con los errores cuando el borrador no está listo. */
    public function assertReady(CobrosRegistrationDraft $draft): void
    {
        $errors = $this->validate($draft);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Clave idempotente por borrador: mismo borrador => misma clave en
     * cualquier reintento, para no duplicar altas ni importaciones.
     */
    public function idempotencyKey(CobrosRegistrationDraft $draft): string
    {
        return hash(
            'sha256',
            'cobros-registration-activation|'.$draft->id.'|'.mb_strtolower(trim((string) $draft->email))
        );
    }

    /**
     * Plan de activación POR RUTA con los archivos privados existentes
     * asociados y todavía sin procesar. Requiere un borrador válido.
     *
     * @return array<string, mixed>
     */
    public function plan(CobrosRegistrationDraft $draft): array
    {
        $this->assertReady($draft);

        $payload = $draft->payload;
        $uploadsByRoute = collect($payload['uploads'] ?? [])->groupBy('route_id');

        $routes = [];
        foreach ($payload['routes'] as $route) {
            $uploads = collect($uploadsByRoute[$route['id']] ?? [])
                ->map(fn (array $upload): array => [
                    'kind' => $upload['kind'],
                    'path' => $upload['path'],
                    'original_name' => $upload['original_name'] ?? null,
                    'rows' => $upload['rows'],
                    'sha256' => $upload['sha256'] ?? null,
                    'status' => $upload['status'],
                ])
                ->values()
                ->all();

            $routes[] = [
                'draft_route_id' => $route['id'],
                'name' => $route['name'],
                'code' => $route['code'] ?? null,
                'lender' => $route['lender'] ?? null,
                'uploads' => $uploads,
            ];
        }

        $personal = is_array($payload['personal'] ?? null) ? $payload['personal'] : [];

        return [
            'idempotency_key' => $this->idempotencyKey($draft),
            'draft_id' => $draft->id,
            'email' => $draft->email,
            'country_iso' => $payload['country_iso'],
            'modality' => $payload['modality'],
            'portfolio_plan' => $payload['portfolio_plan'],
            'referral' => $payload['referral']['code'] ?? null,
            'labor_invitation' => $this->laborInvitationPlan($payload),
            'owner' => [
                'name' => $personal['name'] ?? null,
                'email' => $personal['email'] ?? null,
                'phone' => $personal['phone'] ?? null,
            ],
            'routes' => $routes,
            'pending_uploads' => count($payload['uploads'] ?? []),
            'processes_files' => false,
        ];
    }

    /** @return array<string, string> */
    private function validatePersonal(array $payload): array
    {
        $errors = [];
        $personal = is_array($payload['personal'] ?? null) ? $payload['personal'] : [];

        $name = trim((string) ($personal['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            $errors['personal.name'] = 'Indique su nombre completo (mínimo 3 caracteres).';
        }

        $email = trim((string) ($personal['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['personal.email'] = 'Indique un correo electrónico válido.';
        } elseif (User::where('email', $email)->exists()) {
            $errors['personal.email'] = 'Ya existe una cuenta con ese correo electrónico.';
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validatePortfolioPlan(array $payload): array
    {
        $plan = $payload['portfolio_plan'] ?? null;

        if (! is_string($plan) || ! array_key_exists($plan, PublicRegistrationController::PORTFOLIO_PLANS)) {
            return ['portfolio_plan' => 'Seleccione cómo iniciará su cartera.'];
        }

        return [];
    }

    /**
     * Rutas del borrador + requisito de prestamista cuando la modalidad
     * opera para varios prestamistas.
     *
     * @return list<string> ids de ruta válidos
     */
    private function validateRoutes(array $payload, ?string $modality, array &$errors): array
    {
        $routes = is_array($payload['routes'] ?? null) ? $payload['routes'] : [];

        if ($routes === []) {
            $errors['routes'] = 'Defina al menos una ruta antes de activar su cuenta.';

            return [];
        }

        if (count($routes) > PublicRegistrationController::MAX_DRAFT_ROUTES) {
            $errors['routes'] = PublicRegistrationController::MAX_DRAFT_ROUTES === 1
                ? 'El registro admite una sola ruta por cuenta de cobrador.'
                : 'Máximo '.PublicRegistrationController::MAX_DRAFT_ROUTES.' rutas en borrador.';
        }

        $requiresLender = $modality !== null && CobrosModalityCatalog::allowsMultipleLenders($modality);
        $routeIds = [];

        foreach (array_values($routes) as $index => $route) {
            if (! is_array($route)) {
                $errors["routes.{$index}"] = 'Ruta inválida.';

                continue;
            }

            $id = $route['id'] ?? null;
            if (! is_string($id) || $id === '') {
                $errors["routes.{$index}.id"] = 'La ruta no tiene identificador válido en el borrador.';
            } else {
                $routeIds[] = $id;
            }

            $name = trim((string) ($route['name'] ?? ''));
            if (mb_strlen($name) < 2) {
                $errors["routes.{$index}.name"] = 'Cada ruta necesita un nombre.';
            }

            if ($requiresLender && trim((string) ($route['lender'] ?? '')) === '') {
                $errors["routes.{$index}.lender"] = 'Indique el prestamista asociado de la ruta «'.$name.'».';
            }
        }

        return $routeIds;
    }

    /** @return array<string, string> */
    private function validateReferral(array $payload): array
    {
        $referral = $payload['referral'] ?? null;

        if ($referral === null) {
            return [];
        }

        $code = is_array($referral) ? ($referral['code'] ?? null) : null;

        if (! is_string($code) || preg_match(PublicLandingController::REFERRAL_PATTERN, $code) !== 1) {
            return ['referral' => 'La referencia (?ref=) no tiene un formato válido.'];
        }

        return [];
    }

    /**
     * Archivos del borrador: propio el directorio, ruta existente en el
     * borrador, archivo presente en el disco privado y metadatos coherentes.
     * NO se abre ni se procesa el contenido.
     *
     * @param  list<string>  $routeIds
     * @return array<string, string>
     */
    private function validateUploads(array $payload, CobrosRegistrationDraft $draft, array $routeIds): array
    {
        $uploads = is_array($payload['uploads'] ?? null) ? $payload['uploads'] : [];

        if ($uploads === []) {
            return [];
        }

        $errors = [];
        $prefix = 'registration-uploads/'.$draft->id.'/';

        foreach (array_values($uploads) as $index => $upload) {
            if (! is_array($upload)) {
                $errors["uploads.{$index}"] = 'Registro de archivo inválido.';

                continue;
            }

            $kind = $upload['kind'] ?? null;
            if (! is_string($kind) || ! in_array($kind, PublicRegistrationController::UPLOAD_KINDS, true)) {
                $errors["uploads.{$index}.kind"] = 'Tipo de archivo desconocido.';
            }

            $routeId = $upload['route_id'] ?? null;
            if (! is_string($routeId) || ! in_array($routeId, $routeIds, true)) {
                $errors["uploads.{$index}.route_id"] = 'El archivo está asociado a una ruta que ya no existe en el borrador.';
            }

            $path = $upload['path'] ?? null;
            if (! is_string($path) || $path === '' || ! str_starts_with($path, $prefix)) {
                $errors["uploads.{$index}.path"] = 'El archivo no pertenece al directorio privado de este borrador.';
            } elseif (! Storage::disk('local')->exists($path)) {
                $errors["uploads.{$index}.path"] = 'El archivo ya no está disponible; vuelva a cargarlo antes de activar.';
            }

            $rows = $upload['rows'] ?? null;
            if (! is_int($rows) || $rows < 0 || $rows > PublicRegistrationController::UPLOAD_MAX_ROWS) {
                $errors["uploads.{$index}.rows"] = 'El archivo no indica un número de filas válido.';
            }

            if (($upload['status'] ?? null) !== PublicRegistrationController::UPLOAD_STATUS) {
                $errors["uploads.{$index}.status"] = 'El archivo no está pendiente de activación.';
            }

            $sha = $upload['sha256'] ?? null;
            if (! is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
                $errors["uploads.{$index}.sha256"] = 'El archivo no tiene huella (sha256) válida.';
            }
        }

        return $errors;
    }

    /**
     * BLOQUE 16C-2B f1 — la modalidad Cobrador vinculado exige una invitación
     * laboral REAL, vigente y emitida para este correo por el prestamista que
     * aparece en el borrador. Se verifica contra cobros_labor_invitations
     * (vigencia, revocación, destinatario y prestamista) sin consumirla: aquí
     * solo se decide si el borrador puede avanzar a la preparación de la
     * activación. Ninguna invitación inválida o revocada crea vínculos.
     *
     * @return list<string>
     */
    private function laborInvitationErrors(array $payload, string $draftEmail): array
    {
        $invitation = $payload['labor_invitation'] ?? null;

        if (! is_array($invitation)) {
            return ['La modalidad Cobrador vinculado requiere una invitación laboral válida del prestamista; sin ella no se puede activar la cuenta.'];
        }

        $errors = [];

        $hash = $invitation['token_hash'] ?? null;
        if (! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            $errors[] = 'El enlace de invitación laboral no conserva una referencia válida.';
        }

        $invitedBy = $invitation['invited_by'] ?? null;
        if (! is_string($invitedBy) || filter_var($invitedBy, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'La invitación laboral debe indicar el correo del prestamista que la emitió.';
        }

        $id = $invitation['id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            $errors[] = 'El código de invitación laboral no tiene un formato válido.';
        }

        if ($errors !== []) {
            return $errors;
        }

        try {
            $record = app(LaborInvitationService::class)->verifyReference((int) $id, $hash, $draftEmail);
        } catch (ValidationException $exception) {
            return array_values(Arr::flatten($exception->errors()));
        }

        $lender = $record->lender;
        $recordedLender = $lender !== null ? mb_strtolower(trim((string) $lender->email)) : '';

        if ($recordedLender === '' || $recordedLender !== mb_strtolower(trim($invitedBy))) {
            return ['La invitación laboral no corresponde al prestamista indicado en el borrador.'];
        }

        return [];
    }

    /**
     * Referencia de la invitación para la fase de activación, sin huella ni
     * token: solo lo necesario para enlazar el cobrador con su prestamista.
     *
     * @return array{id: int, invited_by: string, lender_name: string, company: string}|null
     */
    private function laborInvitationPlan(array $payload): ?array
    {
        $invitation = $payload['labor_invitation'] ?? null;

        if (! is_array($invitation)) {
            return null;
        }

        $id = $invitation['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return [
            'id' => (int) $id,
            'invited_by' => (string) ($invitation['invited_by'] ?? ''),
            'lender_name' => (string) ($invitation['lender_name'] ?? ''),
            'company' => (string) ($invitation['company'] ?? ''),
        ];
    }
}
