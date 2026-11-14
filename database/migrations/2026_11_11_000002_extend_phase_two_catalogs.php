<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('sectors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('floor_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['floor_id', 'name']);
        });
        Schema::table('vehicles', function (Blueprint $t) {
            $t->boolean('is_active')->default(true)->index();
        });
        Schema::table('spaces', function (Blueprint $t) {
            $t->dropUnique('spaces_code_unique');
            $t->foreignId('floor_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('sector_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('is_active')->default(true)->index();
            $t->unique(['floor_id', 'sector_id', 'code']);
        });
        Schema::table('rates', function (Blueprint $t) {
            $t->string('unit', 20)->change();
            $t->unsignedSmallInteger('fraction_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rates', function (Blueprint $t) {
            $t->dropColumn('fraction_minutes');
            $t->enum('unit', ['hour', 'day', 'month'])->change();
        });
        Schema::table('spaces', function (Blueprint $t) {
            $t->dropUnique(['floor_id', 'sector_id', 'code']);
            $t->dropConstrainedForeignId('sector_id');
            $t->dropConstrainedForeignId('floor_id');
            $t->dropColumn('is_active');
            $t->unique('code');
        });
        Schema::table('vehicles', function (Blueprint $t) {
            $t->dropColumn('is_active');
        });
        Schema::dropIfExists('sectors');
        Schema::dropIfExists('floors');
    }
};
