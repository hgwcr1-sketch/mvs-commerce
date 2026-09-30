<?php

namespace App\Services\MvsPrint;

use Exception;

/**
 * Firma QZ Tray del lado servidor.
 *
 * La impresión física ocurre en el navegador mediante QZ Tray. El servidor
 * nunca habla con la impresora directamente: solo valida, prepara payloads de
 * prueba y firma las peticiones QZ para que el cliente local confíe en ellas.
 *
 * Flujo oficial de firma (https://qz.io/docs/signing):
 *  - qz-tray entrega el mensaje crudo "toSign" al promise del navegador.
 *  - El navegador lo envía al endpoint de firma del servidor.
 *  - El servidor firma ESE string crudo (no construye cadenas propias) y
 *    devuelve la firma base64 en texto plano.
 *  - Algoritmo: SHA512 por defecto (QZ Tray 2.1+).
 *
 * Seguridad:
 *  - La clave privada RSA vive únicamente en storage/app/private (no versionado).
 *  - La clave pública se entrega al navegador (es pública por diseño de QZ).
 *  - La clave privada jamás se incluye en respuestas ni en código del navegador.
 */
class QzSigningService
{
    public const ENV_PRIVATE_KEY_PATH = 'MVS_PRINT_QZ_PRIVATE_KEY_PATH';

    public const DEFAULT_PRIVATE_KEY_PATH = 'app/private/mvs-print/qz-signing-private.pem';

    /** Algoritmo de firma usado también por el frontend (qz.security.setSignatureAlgorithm). */
    public const SIGNATURE_ALGORITHM_NAME = 'SHA512';

    /** Algoritmo OpenSSL equivalente a SIGNATURE_ALGORITHM_NAME. */
    public const SIGNATURE_ALGORITHM = OPENSSL_ALGO_SHA512;

    /**
     * Ruta absoluta de la clave privada en disco.
     */
    public function privateKeyPath(): string
    {
        return env(QzSigningService::ENV_PRIVATE_KEY_PATH, storage_path(QzSigningService::DEFAULT_PRIVATE_KEY_PATH));
    }

    /**
     * Ruta absoluta de la clave pública (opcional, se deriva de la privada si no existe).
     */
    public function publicKeyPath(): string
    {
        return storage_path('app/private/mvs-print/qz-signing-public.pem');
    }

    /**
     * Ruta del certificado x509 público (digital-certificate.txt) que QZ Tray
     * valida para operar en modo firmado silencioso. Solo se activa cuando se
     * provisiona; Fase 1 no lo genera.
     */
    public function certificatePath(): string
    {
        return storage_path('app/private/mvs-print/qz-signing-certificate.txt');
    }

    /**
     * Indica si el certificado público para modo firmado ya está provisionado.
     */
    public function certificateConfigured(): bool
    {
        return file_exists($this->certificatePath());
    }

    /**
     * Indica si el par de claves QZ está disponible en el servidor.
     */
    public function isConfigured(): bool
    {
        return file_exists($this->privateKeyPath());
    }

    /**
     * Genera un par RSA-2048 y lo persiste únicamente en storage/app/private.
     * Idempotente: si la clave ya existe no la regenera.
     */
    public function ensureKeyPair(): void
    {
        if ($this->isConfigured()) {
            return;
        }

        $this->generateKeyPair();
    }

    /**
     * Genera (o regenera) el par de claves y lo guarda en disco servidor-only.
     *
     * En algunos hosts (p. ej. PHP en Windows) openssl_pkey_new falla cuando no
     * puede localizar una configuración OpenSSL; en ese caso se reintenta con
     * una configuración detectada en el propio binario de PHP, sin alterar la
     * configuración global del entorno.
     */
    public function generateKeyPair(): array
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($options);
        $configUsed = null;

        if ($privateKey === false) {
            $configUsed = $this->detectOpenSslConfig();

            if ($configUsed !== null) {
                $privateKey = openssl_pkey_new($options + ['config' => $configUsed]);
            }
        }

        if ($privateKey === false) {
            throw new Exception('No se pudo generar la clave de firma QZ (openssl): '.(openssl_error_string() ?: 'error desconocido'));
        }

        $privatePem = null;
        $exported = openssl_pkey_export(
            $privateKey,
            $privatePem,
            null,
            $configUsed !== null ? ['config' => $configUsed] : null
        );

        if ($exported === false || ! is_string($privatePem) || $privatePem === '') {
            throw new Exception('No se pudo exportar la clave privada de firma QZ: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $details = openssl_pkey_get_details($privateKey);
        $publicPem = $details['key'] ?? null;

        if (! is_string($publicPem) || $publicPem === '') {
            throw new Exception('No se pudo derivar la clave pública de firma QZ: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $path = $this->privateKeyPath();
        @mkdir(dirname($path), 0755, true);
        @file_put_contents($path, $privatePem);

        $publicPath = $this->publicKeyPath();
        @mkdir(dirname($publicPath), 0755, true);
        @file_put_contents($publicPath, $publicPem);

        return ['private_key' => $privatePem, 'public_key' => $publicPem];
    }

    /**
     * Localiza un archivo de configuración OpenSSL usable en el host,
     * sin modificar variables de entorno globales.
     */
    public function detectOpenSslConfig(): ?string
    {
        $candidates = [];

        $env = getenv('OPENSSL_CONF');

        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }

        $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.'openssl.cnf';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Devuelve el PEM de la clave pública para entregar al navegador.
     */
    public function publicKeyPem(): string
    {
        $path = $this->publicKeyPath();

        if (file_exists($path)) {
            return file_get_contents($path);
        }

        $this->ensureKeyPair();

        return file_get_contents($this->publicKeyPath());
    }

    /**
     * Construye la cadena canónica que QZ Tray espera firmar.
     */
    public function canonicalString(array $request): string
    {
        return 'request '.($request['request'] ?? '').'time_stamp '.($request['time_stamp'] ?? '').'hash '.($request['hash'] ?? '');
    }

    /**
     * Firma el mensaje crudo que QZ Tray envía ("toSign") y devuelve la
     * firma en base64. Por defecto usa SHA512 (QZ Tray 2.1+).
     */
    public function sign(string $data, ?int $algorithm = null): string
    {
        $this->ensureKeyPair();

        $privateKey = openssl_pkey_get_private(file_get_contents($this->privateKeyPath()));

        if ($privateKey === false) {
            throw new Exception('No se pudo cargar la clave privada de firma QZ: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $signature = null;
        $signed = openssl_sign(
            $data,
            $signature,
            $privateKey,
            $algorithm ?? self::SIGNATURE_ALGORITHM,
        );

        if ($signed === false || ! is_string($signature) || $signature === '') {
            throw new Exception('No se pudo firmar la petición QZ: '.(openssl_error_string() ?: 'error desconocido'));
        }

        return base64_encode($signature);
    }

    /**
     * Genera un certificado X509 autofirmado para QZ Tray (modo silencioso).
     *
     * El certificado se deriva de la clave privada existente y se guarda
     * en storage/app/private/mvs-print/qz-signing-certificate.txt
     * Idempotente: si ya existe no lo regenera.
     */
    public function ensureCertificate(): void
    {
        if ($this->certificateConfigured()) {
            return;
        }

        $this->generateCertificate();
    }

    /**
     * Genera un certificado X509 autofirmado válido por 10 años.
     *
     * Usa la clave privada RSA existente. El certificado es público
     * y se entrega al navegador vía certificatePromise.
     */
    public function generateCertificate(): string
    {
        $this->ensureKeyPair();

        $privateKey = openssl_pkey_get_private(file_get_contents($this->privateKeyPath()));

        if ($privateKey === false) {
            throw new Exception('No se pudo cargar la clave privada para generar certificado: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $details = openssl_pkey_get_details($privateKey);
        $publicKey = $details['key'] ?? null;

        if (! is_string($publicKey) || $publicKey === '') {
            throw new Exception('No se pudo obtener la clave pública para certificado: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $dn = [
            'commonName' => 'MVS Print Local',
            'organizationName' => 'MVS Commerce',
            'organizationalUnitName' => 'QZ Tray Signing',
        ];

        $config = [
            'digest_alg' => 'sha512',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'encrypt_key' => false,
        ];

        $configFile = $this->detectOpenSslConfig();
        if ($configFile !== null) {
            $config['config'] = $configFile;
        }

        $csr = openssl_csr_new($dn, $privateKey, $config);

        if ($csr === false && $configFile !== null) {
            // Reintentar sin config explícito si el detectado no es compatible
            $fallback = $config;
            unset($fallback['config']);
            $csr = openssl_csr_new($dn, $privateKey, $fallback);
        }

        if ($csr === false) {
            throw new Exception('No se pudo crear CSR para certificado QZ: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $x509 = openssl_csr_sign($csr, null, $privateKey, 3650, $config);

        if ($x509 === false) {
            throw new Exception('No se pudo firmar certificado X509: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $certPem = null;
        $exported = openssl_x509_export($x509, $certPem);

        if ($exported === false || ! is_string($certPem) || $certPem === '') {
            throw new Exception('No se pudo exportar certificado X509: '.(openssl_error_string() ?: 'error desconocido'));
        }

        $path = $this->certificatePath();
        @mkdir(dirname($path), 0755, true);
        @file_put_contents($path, $certPem);

        return $certPem;
    }

    /**
     * Devuelve el PEM del certificado X509 público para entregar al navegador.
     */
    public function certificatePem(): string
    {
        $path = $this->certificatePath();

        if (file_exists($path)) {
            return file_get_contents($path);
        }

        $this->ensureCertificate();

        return file_get_contents($this->certificatePath());
    }
}