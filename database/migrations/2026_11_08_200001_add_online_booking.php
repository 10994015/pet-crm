<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // 'shop' when staff booked it, 'online' when the owner booked on the website.
            $table->string('source')->default('shop')->index();
            // What the owner types with their phone to look the booking up again.
            $table->string('booking_code', 12)->nullable()->unique();
            // Online bookings wait here until the shop has looked at them.
            $table->dateTime('confirmed_at')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('photo')->nullable();
            $table->boolean('is_bookable_online')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['booking_code']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'booking_code', 'confirmed_at']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['photo', 'is_bookable_online']);
        });
    }
};
