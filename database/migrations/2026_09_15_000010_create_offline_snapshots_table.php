<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('terminal_uuid', 36)->index();
            $table->unsignedInteger('schema_version')->default(1);
            $table->timestamp('generated_at');
            $table->json('snapshot_data');
            $table->unsignedBigInteger('snapshot_size_bytes')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->unsignedInteger('customer_count')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'terminal_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_snapshots');
    }
};
