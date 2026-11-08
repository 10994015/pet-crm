<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a customer sign in to the website with their phone and a password.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('member_since')->nullable();
            // A one-off code the shop hands out, so nobody can claim a phone number they don't own.
            $table->string('activation_code')->nullable();
            $table->timestamp('activation_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['password', 'remember_token', 'member_since', 'activation_code', 'activation_expires_at']);
        });
    }
};
