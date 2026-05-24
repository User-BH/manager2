<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');           // مثلا payment.approved
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['complex_id', 'created_at']);
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            // null = بکاپ کل سیستم (ادمین کل)؛ مقداردار = بکاپ مخصوص مجتمع
            $table->foreignId('complex_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10)->default('full'); // full | complex
            $table->string('status', 12)->default('pending'); // pending | completed | failed
            $table->string('disk')->default('local');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // null = تنظیم سراسری سیستم؛ مقداردار = تنظیم مخصوص مجتمع
            $table->foreignId('complex_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['complex_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('audit_logs');
    }
};
