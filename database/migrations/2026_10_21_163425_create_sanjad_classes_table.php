<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // جدول ذخیره کلاس‌های همگام‌شده با سنجاد
        Schema::create('sanjad_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sanjad_class_id')->unique()->comment('آیدی کلاس در سیستم سنجاد');
            $table->string('name');
            $table->timestamps();
        });

        // جدول pivot اختصاصی برای وصل کردن کلاس‌ها به پرمیشن‌های Spatie
        Schema::create('sanjad_class_permissions', function (Blueprint $table) {
            $table->foreignId('sanjad_class_id')
                ->constrained('sanjad_classes')
                ->cascadeOnDelete();

            // ارجاع به جدول permissions که Spatie ساخته است
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();

            $table->primary(['sanjad_class_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sanjad_class_permissions');
        Schema::dropIfExists('sanjad_classes');
    }
};
