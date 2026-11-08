<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A prepaid package belongs to the owner and can cover several of their dogs.
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            // Terms are copied at sale, so later settings changes never alter a sold package.
            $table->string('service_name');
            // Add-ons paid up front for every visit, as [{id, name}].
            $table->json('included_addons')->nullable();
            $table->decimal('discount_rate', 3, 1);
            $table->unsignedInteger('price');
            $table->string('payment_method')->nullable();
            $table->dateTime('paid_at')->nullable()->index();
            $table->date('starts_on');
            $table->date('expires_on')->index();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedInteger('refund_amount')->default(0);
            $table->dateTime('refunded_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('legacy_order_id')->nullable()->unique();
            $table->timestamps();
        });

        // Each dog in a package has its own price and its own visits.
        Schema::create('package_pet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('unit_price');
            $table->unsignedSmallInteger('total_visits');
            // Visits counted outside appointments: used before this system, or an owner's correction.
            $table->smallInteger('used_adjustment')->default(0);
            $table->unique(['package_id', 'pet_id']);
        });

        Schema::create('package_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('from_used');
            $table->unsignedSmallInteger('to_used');
            $table->text('reason');
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('groomer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('legacy_order_item_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
            $table->dropUnique(['legacy_order_item_id']);
            $table->dropColumn('legacy_order_item_id');
        });

        Schema::dropIfExists('package_adjustments');
        Schema::dropIfExists('package_pet');
        Schema::dropIfExists('packages');
    }
};
