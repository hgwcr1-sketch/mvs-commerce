<?php

namespace Database\Factories\MvsPrint;

use App\Models\MvsPrint\MvsPrintTerminal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MvsPrintTerminal>
 */
class MvsPrintTerminalFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(2),
            'terminal_uuid' => (string) Str::uuid(),
            'printer_name' => null,
            'paper_width' => MvsPrintTerminal::PAPER_WIDTH_80,
            'auto_print' => false,
            'auto_cut' => true,
            'open_drawer' => false,
            'drawer_command' => null,
            'enabled' => true,
            'last_seen_at' => null,
        ];
    }
}