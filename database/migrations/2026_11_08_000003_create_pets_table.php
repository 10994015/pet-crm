<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name')->index();
            $table->string('species')->default('dog');
            $table->string('breed')->nullable();
            $table->string('size')->default('small');
            $table->string('gender')->default('unknown');
            $table->boolean('is_neutered')->default(false);
            $table->date('birthday')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('coat_color')->nullable();
            $table->string('temperament')->default('gentle');
            $table->string('photo')->nullable();
            $table->text('allergies')->nullable();
            $table->text('health_notes')->nullable();
            $table->text('grooming_preferences')->nullable();
            $table->date('rabies_vaccine_expires_on')->nullable();
            $table->unsignedSmallInteger('visit_interval_weeks')->default(6);
            $table->date('next_visit_due_on')->nullable()->index();
            $table->timestamp('last_contacted_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pets');
    }
};
