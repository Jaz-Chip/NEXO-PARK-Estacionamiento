<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->string('module', 40)->index();
            $table->boolean('is_pending')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->timestamp('permissions_initialized_at')->nullable();
        });

        // Initial grants are populated by the following Spatie conversion.
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('permissions_initialized_at'));
    }
};
