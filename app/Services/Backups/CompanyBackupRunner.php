<?php

namespace App\Services\Backups;

use Symfony\Component\Process\Process;

class CompanyBackupRunner
{
    /**
     * @param  array<int, string>  $command
     *  @param  array<string, string>  $env
     * @return array{exit_code: int, output: string}
     */
    public function run(array $command, array $env = []): array
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(null);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
        ];
    }
}
