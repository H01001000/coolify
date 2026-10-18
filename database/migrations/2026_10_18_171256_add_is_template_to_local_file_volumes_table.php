<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->boolean('is_template')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropColumn('is_template');
        });
    }
};
