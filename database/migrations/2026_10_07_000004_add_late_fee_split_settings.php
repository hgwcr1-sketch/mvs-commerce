<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // % general de mora que gana el cobrador (default 50.00).
            $table->decimal('collector_late_fee_pct', 5, 2)->default(50);
        });

        Schema::table('users', function (Blueprint $table) {
            // Override opcional por cobrador: NULL = hereda el general.
            $table->decimal('collector_late_fee_pct', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('collector_late_fee_pct');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('collector_late_fee_pct');
        });
    }
};
