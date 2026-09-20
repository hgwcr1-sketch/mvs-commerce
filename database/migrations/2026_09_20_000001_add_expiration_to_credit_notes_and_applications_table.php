<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('issued_at');
            $table->foreignId('customer_id')->nullable()->change();
        });

        Schema::table('credit_note_applications', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });

        // NOTE: Reverting nullable to NOT NULL on SQLite requires table recreation.
        // This down() is safe for PostgreSQL production. For SQLite dev, run migrate:fresh.
        if (config('database.default') !== 'sqlite') {
            DB::statement('ALTER TABLE credit_notes ALTER COLUMN customer_id SET NOT NULL');
            DB::statement('ALTER TABLE credit_note_applications ALTER COLUMN customer_id SET NOT NULL');
        }
    }
};
