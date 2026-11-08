<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Old records may have no phone; the form still requires one for new customers.
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable()->after('backup_phone');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->string('chip_number')->nullable()->after('coat_color');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn(['chip_number', 'legacy_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn(['email', 'legacy_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
