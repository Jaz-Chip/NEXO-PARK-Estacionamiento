<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('slug')->unique();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('is_active')->default(true)->index();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('document')->unique();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vehicle_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_type_id')->constrained()->restrictOnDelete();
            $t->string('plate', 20)->unique();
            $t->string('brand')->nullable();
            $t->string('model')->nullable();
            $t->string('color')->nullable();
            $t->timestamps();
        });
        Schema::create('spaces', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->foreignId('vehicle_type_id')->nullable()->constrained()->restrictOnDelete();
            $t->enum('status', ['available', 'occupied', 'maintenance'])->default('available')->index();
            $t->timestamps();
        });
        Schema::create('rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_type_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->enum('unit', ['hour', 'day', 'month']);
            $t->decimal('amount', 12, 2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('rate_id')->constrained()->restrictOnDelete();
            $t->foreignId('space_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->decimal('amount', 12, 2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['starts_on', 'ends_on']);
        });
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('space_id')->constrained()->restrictOnDelete();
            $t->foreignId('rate_id')->constrained()->restrictOnDelete();
            $t->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('entered_at')->index();
            $t->timestamp('exited_at')->nullable();
            $t->decimal('rate_amount', 12, 2);
            $t->decimal('total', 12, 2)->default(0);
            $t->enum('status', ['open', 'closed', 'cancelled'])->default('open')->index();
            $t->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('reference')->nullable();
            $t->enum('status', ['paid', 'void'])->default('paid')->index();
            $t->timestamp('paid_at')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payments', 'payment_methods', 'tickets', 'subscriptions', 'rates', 'spaces', 'vehicles', 'vehicle_types', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn('is_active');
        });
        Schema::dropIfExists('roles');
    }
};
