<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('document_type')->default('quote')->after('invoice_id');
            $table->unsignedInteger('source_quote_id')->nullable()->after('document_type');

            $table->index(['company_id', 'document_type']);
            $table->index('source_quote_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'document_type']);
            $table->dropIndex(['source_quote_id']);
            $table->dropColumn(['document_type', 'source_quote_id']);
        });
    }
};
