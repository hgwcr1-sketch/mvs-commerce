<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
    {
        public function up(): void
        {
            Schema::table('inventory_transfers', function (Blueprint $table) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('company_id');

                $table->foreignId('prepared_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('created_by');

                $table->foreignId('dispatched_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('prepared_by');

                $table->foreignId('received_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('dispatched_by');

                $table->foreignId('confirmed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('received_by');

                $table->timestamp('prepared_at')->nullable()->after('dispatched_by');
                $table->timestamp('dispatched_at')->nullable()->after('prepared_at');
                $table->timestamp('received_at')->nullable()->after('dispatched_at');
                $table->timestamp('confirmed_at')->nullable()->after('received_at');

                $table->decimal('received_quantity_total', 14, 4)->nullable()->after('confirmed_at');
                $table->text('differences_notes')->nullable()->after('received_quantity_total');

                $table->boolean('is_multiproduct')->default(false)->after('differences_notes');
            });

        // Backfill: existing completed transfers keep user_id as created_by
        DB::statement('UPDATE inventory_transfers SET created_by = user_id WHERE created_by IS NULL');

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->decimal('sent_quantity', 14, 4)->nullable()->after('quantity');
            $table->decimal('received_quantity', 14, 4)->nullable()->after('sent_quantity');
            $table->decimal('difference', 14, 4)->nullable()->after('received_quantity');
            $table->text('item_notes')->nullable()->after('difference');
        });

        // Backfill: existing items have sent_quantity = quantity
        DB::statement('UPDATE inventory_transfer_items SET sent_quantity = quantity WHERE sent_quantity IS NULL');
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->dropColumn(['sent_quantity', 'received_quantity', 'difference', 'item_notes']);
        });

        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropColumn([
                'created_by', 'prepared_by', 'received_by', 'confirmed_by',
                'prepared_at', 'received_at', 'confirmed_at',
                'received_quantity_total', 'differences_notes', 'is_multiproduct',
            ]);
        });
    }
};