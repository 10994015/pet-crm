<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->restrictOnDelete();
            $table->foreignId('groomer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('status')->default('scheduled')->index();
            $table->text('notes')->nullable();
            $table->text('grooming_notes')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->json('before_photos')->nullable();
            $table->json('after_photos')->nullable();
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->string('payment_method')->nullable();
            $table->dateTime('paid_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('appointment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            // Copied from the service so past records survive later renames and price changes.
            $table->string('name');
            $table->unsignedInteger('price')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_items');
        Schema::dropIfExists('appointments');
    }
};
