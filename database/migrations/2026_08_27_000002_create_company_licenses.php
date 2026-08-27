<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('trial')->index();
            $table->string('plan', 80)->default('Prueba');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('next_renewal_at')->nullable();
            $table->dateTime('grace_until')->nullable()->index();
            $table->unsignedInteger('user_limit')->nullable();
            $table->unsignedInteger('branch_limit')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('company_license_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->json('snapshot');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        $now = now();
        DB::table('companies')->orderBy('id')->each(function ($company) use ($now) {
            DB::table('company_licenses')->insert([
                'company_id' => $company->id, 'status' => 'active', 'plan' => 'Legado',
                'starts_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_license_events');
        Schema::dropIfExists('company_licenses');
    }
};
