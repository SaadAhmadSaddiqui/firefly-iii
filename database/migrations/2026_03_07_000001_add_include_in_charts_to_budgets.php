<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('budgets', static function (Blueprint $table): void {
            $table->boolean('include_in_charts')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', static function (Blueprint $table): void {
            $table->dropColumn('include_in_charts');
        });
    }
};
