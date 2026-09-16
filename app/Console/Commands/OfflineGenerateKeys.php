<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OfflineGenerateKeys extends Command
{
    protected $signature = 'offline:generate-keys
                            {--force : Overwrite existing keys}';

    protected $description = 'Generate RSA-2048 key pair for offline authorization signing';

    public function handle(): int
    {
        $keysDir = storage_path('app/private/mvs-offline');
        $privatePath = config('offline.keys.private');
        $publicPath = config('offline.keys.public');

        $fullPrivatePath = storage_path($privatePath);
        $fullPublicPath = storage_path($publicPath);

        if (File::exists($fullPrivatePath) && ! $this->option('force')) {
            $this->error('Private key already exists. Use --force to overwrite.');

            return static::FAILURE;
        }

        if (! File::isDirectory($keysDir)) {
            File::makeDirectory($keysDir, 0700, true, true);
        }

        $opensslConfigPath = $this->findOpenSslConfigPath();
        $genConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        if ($opensslConfigPath) {
            $genConfig['config'] = $opensslConfigPath;
        }

        $res = openssl_pkey_new($genConfig);

        if ($res === false) {
            $this->error('Failed to generate RSA key pair.');

            return static::FAILURE;
        }

        // Export private key (no passphrase for server-side use)
        $privateKeyContent = '';
        $exportConfig = $opensslConfigPath ? ['config' => $opensslConfigPath] : [];
        if (! openssl_pkey_export($res, $privateKeyContent, null, $exportConfig)) {
            $this->error('Failed to export private key.');

            return static::FAILURE;
        }

        // Export public key
        $publicKeyDetails = openssl_pkey_get_details($res);
        $publicKeyContent = $publicKeyDetails['key'];

        // Write with restrictive permissions
        File::put($fullPrivatePath, $privateKeyContent);
        chmod($fullPrivatePath, 0600);

        File::put($fullPublicPath, $publicKeyContent);
        chmod($fullPublicPath, 0644);

        openssl_free_key($res);

        $this->info('Offline authorization key pair generated successfully.');
        $this->info("Private key: {$fullPrivatePath}");
        $this->info("Public key:  {$fullPublicPath}");
        $this->warn('NEVER commit the private key to version control.');
        $this->warn('Add storage/app/private/mvs-offline/ to .gitignore.');

        return static::SUCCESS;
    }

    private function findOpenSslConfigPath(): ?string
    {
        // Check default OpenSSL config path
        $defaultPath = 'C:\\Program Files\\Common Files\\SSL\\openssl.cnf';
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        // Check bundled config in storage
        $bundledPath = storage_path('app/openssl.cnf');
        if (file_exists($bundledPath)) {
            return $bundledPath;
        }

        // Check common alternative paths on Windows
        $alternatives = [
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf',
            'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf',
        ];
        foreach ($alternatives as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
