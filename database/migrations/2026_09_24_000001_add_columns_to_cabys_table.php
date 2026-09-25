<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabys', function (Blueprint $table) {
            if (! Schema::hasColumn('cabys', 'code')) {
                $table->string('code', 20)->nullable()->unique();
            }
            if (! Schema::hasColumn('cabys', 'description')) {
                $table->text('description')->nullable();
            }
            foreach (range(1, 9) as $n) {
                if (! Schema::hasColumn('cabys', "category{$n}_code")) {
                    $table->string("category{$n}_code", 20)->nullable();
                }
                if (! Schema::hasColumn('cabys', "category{$n}_description")) {
                    $table->text("category{$n}_description")->nullable();
                }
            }
            if (! Schema::hasColumn('cabys', 'tax_rate')) {
                $table->decimal('tax_rate', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('cabys', 'note1')) {
                $table->text('note1')->nullable();
            }
            if (! Schema::hasColumn('cabys', 'note2')) {
                $table->text('note2')->nullable();
            }
            if (! Schema::hasColumn('cabys', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cabys', function (Blueprint $table) {
            if (Schema::hasColumn('cabys', 'code')) {
                $table->dropUnique(['code']);
            }
        });

        Schema::table('cabys', function (Blueprint $table) {
            $columns = array_merge(
                ['code', 'description', 'tax_rate', 'note1', 'note2', 'is_active'],
                ...array_map(fn (int $n) => ["category{$n}_code", "category{$n}_description"], range(1, 9))
            );

            foreach ($columns as $column) {
                if (Schema::hasColumn('cabys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
