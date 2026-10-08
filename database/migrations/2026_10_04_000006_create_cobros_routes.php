<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('cobros_route_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('cobros_route_id');
            $table->foreignId('user_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'cobros_route_id', 'user_id'], 'cobros_route_user_unique');
            $table->foreign(['company_id', 'cobros_route_id'])->references(['company_id', 'id'])->on('cobros_routes')->restrictOnDelete();
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'user_id'])->on('company_user')->restrictOnDelete();
        });

        // Same license lifecycle as collector licenses; the paid unit is a route.
        Schema::create('cobros_route_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('cobros_route_id')->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('renewed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'cobros_route_id'])->references(['company_id', 'id'])->on('cobros_routes')->restrictOnDelete();
        });

        foreach (['cobros_assignments', 'accounts_receivable', 'cobros_receipts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('cobros_route_id')->nullable();
                $table->index(['company_id', 'cobros_route_id']);
                $table->foreign(['company_id', 'cobros_route_id'])->references(['company_id', 'id'])->on('cobros_routes')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: route attribution must never be discarded.
        throw new LogicException('La base de rutas requiere una migracion compensatoria explicita.');
    }
};
