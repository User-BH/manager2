<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عضویت به‌عنوانِ موجودیتِ جدا (مرحلهٔ ۱۰ · فاز ۲؛ بندهای ۳، ۴، ۱۱ و ۱۳ ِ فایلِ معماری).
 *
 * تا امروز «عضویت» فقط `users.complex_id` + `users.role` بود: یک مقدار، بی تاریخچه،
 * بی شروع و پایان. هر ردیفِ این جدول یک عضویتِ یک حساب در یک مجتمع است با نقش،
 * وضعیت (`active` / `ended`)، زمانِ شروع و پایان، پایان‌دهنده و دلیل، و منبع
 * (دعوت، درخواستِ پیوستن، خرید، …). «در انتظار»، «ردشده» و «ابطال‌شده» مالِ دعوت و
 * درخواست‌اند (`complex_invitations`) و این‌جا تکرار نمی‌شوند.
 *
 * ─── یک عضویتِ فعال برای هر (حساب، مجتمع)، در خودِ پایگاه‌داده ─────────────────
 * MySQL ایندکسِ یکتای شرطی ندارد؛ پس همان الگوی `live_key` ِ دعوت: `active_key`
 * فقط برای عضویتِ فعال پر است و یکتاست. چند عضویتِ فعال در **مجتمع‌های مختلف**
 * را خودِ schema منع نمی‌کند — آن قاعده‌ی کسب‌وکار است و تا ۱۰/۳ در سرویس می‌ماند.
 *
 * ─── تاریخچه با حذفِ والد پاک نمی‌شود (بندِ ۷۹) ─────────────────────────────────
 * `restrictOnDelete` به حساب و مجتمع؛ دعوت `nullOnDelete`.
 *
 * ─── داده‌ی موجود ───────────────────────────────────────────────────────────────
 * هر حسابِ عضو (مالک، مستأجر، مدیر با `complex_id`) یک عضویتِ فعال با منبعِ
 * `backfill` می‌گیرد؛ شروع = قدیمی‌ترین دوره‌ی واحدِ او در همان مجتمع، وگرنه زمانِ
 * ساختِ حساب. عضویت‌های **پایان‌یافته‌ی** پیش از این مهاجرت حدس زده نمی‌شوند: سابقه‌شان
 * در دوره‌های واحد (`unit_user`) و ردِ حسابرسی است. ادمینِ کل عضوِ مجتمع نیست.
 */
return new class extends Migration
{
    private const CONSTRAINTS = [
        ['chk_memberships_status', "status in ('active', 'ended')"],
        ['chk_memberships_role', "role in ('owner', 'tenant', 'complex_admin')"],
        // فعال: بی پایان و با کلیدِ یکتا؛ پایان‌یافته: با زمانِ پایان و بی کلید
        ['chk_memberships_lifecycle', "(status = 'active' and ended_at is null and active_key is not null) or (status = 'ended' and ended_at is not null and active_key is null)"],
    ];

    public function up(): void
    {
        Schema::create('complex_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('complex_id')->constrained('complexes')->restrictOnDelete();
            $table->string('role', 20);
            $table->string('status', 10);
            $table->string('source', 20);
            $table->foreignId('invitation_id')->nullable()->constrained('complex_invitations')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 20)->nullable();
            $table->string('active_key', 40)->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['complex_id', 'status']);
        });

        $this->addConstraints();
        $this->backfill();
    }

    private function backfill(): void
    {
        $members = DB::table('users')
            ->whereNotNull('complex_id')
            ->whereIn('role', ['owner', 'tenant', 'complex_admin'])
            ->orderBy('id')
            ->get(['id', 'complex_id', 'role', 'created_at']);

        foreach ($members as $member) {
            $firstTenure = DB::table('unit_user')
                ->join('units', 'units.id', '=', 'unit_user.unit_id')
                ->where('unit_user.user_id', $member->id)
                ->where('units.complex_id', $member->complex_id)
                ->min('unit_user.start_date');

            $now = now();
            DB::table('complex_memberships')->insert([
                'user_id' => $member->id,
                'complex_id' => $member->complex_id,
                'role' => $member->role,
                'status' => 'active',
                'source' => 'backfill',
                'started_at' => $firstTenure ?? $member->created_at ?? $now,
                'active_key' => $member->id.':'.$member->complex_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** همان روشِ `add_financial_check_constraints`: رفتار سنجیده می‌شود، نه نامِ موتور. */
    private function addConstraints(): void
    {
        $probe = 'probe_membership_constraint_support';

        try {
            DB::statement("DROP TABLE IF EXISTS {$probe}");
            DB::statement("CREATE TABLE {$probe} (n integer)");
            DB::statement("ALTER TABLE {$probe} ADD CONSTRAINT chk_probe_membership CHECK (n > 0)");
        } catch (Throwable) {
            // SQLite: قید پس از ساختِ جدول افزودنی نیست؛ `SchemaConstraintTest` روی MySQL ِ CI می‌سنجد
            return;
        } finally {
            try {
                DB::statement("DROP TABLE IF EXISTS {$probe}");
            } catch (Throwable) {
                // جدولِ یک‌بارمصرف
            }
        }

        foreach (self::CONSTRAINTS as [$name, $condition]) {
            DB::statement("ALTER TABLE complex_memberships ADD CONSTRAINT {$name} CHECK ({$condition})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_memberships');
    }
};
