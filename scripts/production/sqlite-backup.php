<?php

declare(strict_types=1);

if ($argc < 3 || ! in_array($argv[1], ['backup', 'integrity'], true)) {
    fwrite(STDERR, "Uso: php sqlite-backup.php backup <origen> <destino> | integrity <archivo>\n");
    exit(64);
}

$operation = $argv[1];
$sourcePath = $argv[2];

try {
    if ($operation === 'backup') {
        if ($argc !== 4) {
            throw new InvalidArgumentException('La operación backup requiere origen y destino.');
        }

        $source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($argv[3]);
        if (! $source->backup($destination)) {
            throw new RuntimeException('SQLite no pudo crear la copia consistente.');
        }
        $destination->close();
        $source->close();
        exit(0);
    }

    if ($argc !== 3) {
        throw new InvalidArgumentException('La operación integrity requiere un archivo.');
    }

    $database = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
    $result = $database->querySingle('PRAGMA integrity_check');
    $database->close();
    if ($result !== 'ok') {
        throw new RuntimeException('integrity_check: '.(string) $result);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
