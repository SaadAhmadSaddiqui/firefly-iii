<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('rule_triggers', static function (Blueprint $table): void {
            $table->text('trigger_value')->change();
        });
    }

    public function down(): void
    {
        Schema::table('rule_triggers', static function (Blueprint $table): void {
            $table->string('trigger_value', 255)->change();
        });
    }
};
