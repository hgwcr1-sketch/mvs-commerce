<?php

namespace App\Services\MvsPrint;

use App\Models\MvsPrint\MvsPrintTerminal;

/**
 * Construye el payload ESC/POS del ticket de prueba.
 *
 * El backend solo prepara comandos de alto nivel (líneas, corte y cajón);
 * el navegador (qz.js) los convierte en bytes ESC/POS y los envía a QZ Tray.
 * De esta forma el servidor nunca necesita acceso directo a la impresora.
 */
class EscPosTestTicket
{
    public const COMMAND_CENTER = 'center';

    public const COMMAND_LEFT = 'left';

    /**
     * Construye el descriptor de ticket de prueba para una terminal.
     */
    public static function build(MvsPrintTerminal $terminal): array
    {
        $lines = [
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_CENTER, 'value' => '*** MVS PRINT ***', 'emphasized' => true, 'size' => 'double'],
            ['type' => 'empty'],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_CENTER, 'value' => 'Prueba de impresion', 'emphasized' => true],
            ['type' => 'empty'],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_LEFT, 'value' => 'Terminal: '.($terminal->name ?? '-')],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_LEFT, 'value' => 'UUID: '.$terminal->terminal_uuid],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_LEFT, 'value' => 'Papel: '.($terminal->paper_width ?? '80').' mm'],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_LEFT, 'value' => 'Corte: '.($terminal->auto_cut ? 'automatico' : 'manual')],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_LEFT, 'value' => 'Cajon: '.($terminal->open_drawer ? 'abrir' : 'no')],
            ['type' => 'empty'],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_CENTER, 'value' => now()->format('d/m/Y H:i:s')],
            ['type' => 'empty'],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_CENTER, 'value' => 'Si ve este ticket, QZ Tray', 'emphasized' => false],
            ['type' => 'text', 'align' => EscPosTestTicket::COMMAND_CENTER, 'value' => 'funciona correctamente.'],
            ['type' => 'empty'],
        ];

        return [
            'lines' => $lines,
            'paper_width' => $terminal->paper_width ?? '80',
            'auto_cut' => (bool) $terminal->auto_cut,
            'open_drawer' => (bool) $terminal->open_drawer,
            'drawer_command' => $terminal->drawer_command ?? EscPosTestTicket::defaultDrawerCommand(),
            'default_drawer_command' => $terminal->open_drawer
                ? EscPosTestTicket::defaultDrawerCommand()
                : null,
        ];
    }

    /**
     * Payload mínimo para abrir el cajón de forma independiente (sin imprimir ticket).
     * Solo contiene el comando de apertura ESC/POS configurado en la terminal.
     */
    public static function openDrawerPayload(MvsPrintTerminal $terminal): array
    {
        return [
            'lines' => [],
            'auto_cut' => false,
            'open_drawer' => true,
            'drawer_command' => $terminal->drawer_command ?? self::defaultDrawerCommand(),
        ];
    }

    /**
     * Comando ESC/POS estándar de apertura de cajón (ESC p 0 25 255).
     */
    public static function defaultDrawerCommand(): array
    {
        return [27, 112, 0, 25, 255];
    }
}