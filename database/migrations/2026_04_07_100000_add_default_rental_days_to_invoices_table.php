<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'quotes', 'recurring_invoices', 'credits'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('default_rental_days', 8, 2)->default(1)->after('discount');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'quotes', 'recurring_invoices', 'credits'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('default_rental_days');
            });
        }
    }
};
