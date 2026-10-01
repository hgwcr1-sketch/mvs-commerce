<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_plans', function (Blueprint $table) {
            $table->decimal('base_price_usd', 10, 2)->nullable()->after('name');
            $table->decimal('extra_branch_price_usd', 10, 2)->nullable()->after('base_price_usd');
            $table->decimal('extra_user_price_usd', 10, 2)->nullable()->after('extra_branch_price_usd');
            $table->string('fiscal_plan_code', 30)->default('none')->after('extra_user_price_usd');
            $table->decimal('fiscal_monthly_price_crc', 12, 2)->nullable()->after('fiscal_plan_code');
            $table->unsignedInteger('fiscal_included_quota')->nullable()->after('fiscal_monthly_price_crc');
            $table->boolean('is_custom')->default(false)->after('fiscal_included_quota');
        });

        Schema::table('company_licenses', function (Blueprint $table) {
            $table->json('contract_snapshot')->nullable()->after('license_plan_id');
        });

        $now = now();

        foreach ($this->templates() as $code => $template) {
            $existing = DB::table('license_plans')->where('code', $code)->first();

            if ($existing !== null) {
                DB::table('license_plans')->where('code', $code)->update($template);

                continue;
            }

            DB::table('license_plans')->insert(['code' => $code] + $template + [
                'modules' => json_encode(array_keys(\App\Services\Modules\ModuleRegistry::MODULES)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('company_licenses', function (Blueprint $table) {
            $table->dropColumn('contract_snapshot');
        });

        Schema::table('license_plans', function (Blueprint $table) {
            $table->dropColumn([
                'base_price_usd',
                'extra_branch_price_usd',
                'extra_user_price_usd',
                'fiscal_plan_code',
                'fiscal_monthly_price_crc',
                'fiscal_included_quota',
                'is_custom',
            ]);
        });
    }

    /**
     * Catálogo comercial oficial. Los precios de extras son los mismos para
     * todas las plantillas; la plantilla Personalizado no tiene precio base.
     *
     * @return array<string, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            'mvs-commerce' => [
                'name' => 'MVS Commerce',
                'branch_limit' => 1,
                'user_limit' => 2,
                'base_price_usd' => 48.00,
                'extra_branch_price_usd' => 25.00,
                'extra_user_price_usd' => 10.00,
                'fiscal_plan_code' => 'none',
                'fiscal_monthly_price_crc' => null,
                'fiscal_included_quota' => null,
                'is_custom' => false,
                'is_active' => true,
            ],
            'multi-sucursal' => [
                'name' => 'MultiSucursal',
                'branch_limit' => 2,
                'user_limit' => 5,
                'base_price_usd' => 90.00,
                'extra_branch_price_usd' => 25.00,
                'extra_user_price_usd' => 10.00,
                'fiscal_plan_code' => 'none',
                'fiscal_monthly_price_crc' => null,
                'fiscal_included_quota' => null,
                'is_custom' => false,
                'is_active' => true,
            ],
            'personalizado' => [
                'name' => 'Personalizado',
                'branch_limit' => null,
                'user_limit' => null,
                'base_price_usd' => null,
                'extra_branch_price_usd' => null,
                'extra_user_price_usd' => null,
                'fiscal_plan_code' => 'custom',
                'fiscal_monthly_price_crc' => null,
                'fiscal_included_quota' => null,
                'is_custom' => true,
                'is_active' => true,
            ],
        ];
    }
};