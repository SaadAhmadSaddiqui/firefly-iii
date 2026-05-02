<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('piggy_banks', function (Blueprint $table) {
            $table->integer('budget_id', false, true)->nullable()->after('account_id');
            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('piggy_banks', function (Blueprint $table) {
            $table->dropForeign(['budget_id']);
            $table->dropColumn('budget_id');
        });
    }
};
