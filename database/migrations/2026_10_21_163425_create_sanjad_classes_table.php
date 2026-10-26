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

            // شناسه‌ی اختصاصی سنجاد (بسیار مهم برای آپدیت‌های بعدی)
            $table->unsignedBigInteger('sanjad_class_id')->unique()->comment('آیدی کلاس در سیستم سنجاد');

            // اطلاعات اصلی کلاس
            $table->string('name')->comment('نام کلاس');

            // ذخیره‌ی نام‌ها برای جلوگیری از پیچیدگی روابط در زمان سینک
            $table->string('academic_level_name')->nullable()->comment('نام مقطع تحصیلی از سنجاد');
            $table->string('academic_field_name')->nullable()->comment('نام رشته تحصیلی از سنجاد');

            $table->boolean('is_deleted_at_source')->default(false)->index()->comment('آیا کلاس در سنجاد حذف شده است؟');

            $table->timestamps();

             $table->index('academic_level_name');
             $table->index('academic_field_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sanjad_classes');
    }
};
