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

        Schema::create('file_activity_logs', function (Blueprint $table) {
            $table->id();

            // کاربری که عملیات را انجام داده است
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // نوع عملیات: upload, delete, rename, move, browse, clear_cache
            $table->string('action', 30)->index();

            // وضعیت: success, failed
            $table->string('status', 20)->default('success')->index();

            // مسیر اصلی یا مسیر پوشه درخواستی
            $table->text('path')->nullable();

            // برای عملیات انتقال (Move) و تغییر نام (Rename)
            $table->text('source_path')->nullable();
            $table->text('destination_path')->nullable();

            // نام فایل و حجم (برای آپلود / حذف / تغییر نام)
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // به بایت

            // شناسه آپلود چانک (جهت ردیابی فرآیند آپلود)
            $table->string('upload_id')->nullable()->index();

            // پیام خطا در صورت شکست عملیات
            $table->text('error_message')->nullable();

            // داده‌های متغیر و اضافی (مثل تعداد فایل‌های دریافت شده در browse، اکستنشن و ...)
            $table->json('meta')->nullable();

            // مشخصات کلاینت
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            // ایندکس ترکیبی برای گزارش‌گیری سریع
            $table->index(['user_id', 'action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_activity_logs');
    }
};
