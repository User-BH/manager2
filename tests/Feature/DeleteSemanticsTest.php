<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * معنای حذف برای داده‌ی تاریخی — مرحلهٔ ۱۰ · فاز ۱۴ (بندِ ۷۹ ِ فایلِ معماری).
 *
 * برای هر کلیدِ خارجیِ جدول‌های تاریخی (پیام، پرداخت، کیف، قبض، تاریخچه‌ی عضویت و سکونت، …) یکی از این‌ها صریح است:
 *
 * - **RESTRICT** — والدِ دارای تاریخچه حذفِ سخت نمی‌شود (مجتمع، واحد، صاحبِ کیف و دوره‌ی سکونت و رأی)؛
 * - **SET NULL** — پیوندِ ثانوی می‌رود، ردیف می‌ماند (تأییدکننده، سازنده، قبض/پرداختِ مرتبط، پاسخ‌به، …)؛
 * - **CASCADE** — فقط برای جزءِ خودِ ردیف (واکنش، گزینه و رأیِ نظرسنجی، سنجاق) که والدش هرگز حذفِ سخت نمی‌شود؛
 * - **Soft Delete** — واحد، درآمد، هزینه؛ **Historical Record** — قبض و پرداخت (`NeverDeleted`)، کیف (`Immutable`)،
 *   مجتمع، عضویت، دوره‌ی سکونت، گفت‌وگو و پیام (`KeepsHistory`).
 *
 * `REGISTER` همان تصمیم است؛ کلیدِ تازه روی این جدول‌ها بی تصمیمِ صریح این آزمون را قرمز می‌کند.
 */
class DeleteSemanticsTest extends TestCase
{
    use RefreshDatabase;

    /** جدول.ستون ← [والد، قاعده] */
    private const REGISTER = [
        // ── مالی ──
        'bills.complex_id' => ['complexes', 'RESTRICT'],
        'bills.unit_id' => ['units', 'RESTRICT'],
        'payments.complex_id' => ['complexes', 'RESTRICT'],
        'payments.unit_id' => ['units', 'RESTRICT'],
        'payments.bill_id' => ['bills', 'SET NULL'],
        'payments.user_id' => ['users', 'SET NULL'],
        'payments.reviewed_by' => ['users', 'SET NULL'],
        'payment_allocations.complex_id' => ['complexes', 'RESTRICT'],
        'payment_allocations.payment_id' => ['payments', 'SET NULL'],
        'payment_allocations.bill_id' => ['bills', 'SET NULL'],
        'wallet_transactions.complex_id' => ['complexes', 'RESTRICT'],
        'wallet_transactions.unit_id' => ['units', 'RESTRICT'],
        'wallet_transactions.user_id' => ['users', 'RESTRICT'],
        'wallet_transactions.payment_id' => ['payments', 'SET NULL'],
        'wallet_transactions.bill_id' => ['bills', 'SET NULL'],
        'wallet_transactions.created_by' => ['users', 'SET NULL'],
        'incomes.complex_id' => ['complexes', 'RESTRICT'],
        'incomes.created_by' => ['users', 'SET NULL'],
        'expenses.complex_id' => ['complexes', 'RESTRICT'],
        'expenses.created_by' => ['users', 'SET NULL'],
        'subscriptions.complex_id' => ['complexes', 'RESTRICT'],
        'subscriptions.user_id' => ['users', 'SET NULL'],
        'subscriptions.plan_id' => ['plans', 'SET NULL'],
        'subscriptions.reviewed_by' => ['users', 'SET NULL'],
        'checkout_intents.complex_id' => ['complexes', 'RESTRICT'],
        // ۱۲/۲: ادمینی که بازپرداخت را ثبت کرد — حذفِ حسابش سابقه‌ی بازپرداخت را نمی‌برد
        'checkout_intents.refunded_by' => ['users', 'SET NULL'],
        'checkout_intents.subscription_id' => ['subscriptions', 'RESTRICT'],
        'checkout_intents.plan_id' => ['plans', 'SET NULL'],
        'checkout_intents.user_id' => ['users', 'SET NULL'],
        // ── عضویت و سکونت ──
        'units.complex_id' => ['complexes', 'RESTRICT'],
        'units.building_id' => ['buildings', 'SET NULL'],
        'unit_user.complex_id' => ['complexes', 'RESTRICT'],
        'unit_user.unit_id' => ['units', 'RESTRICT'],
        'unit_user.user_id' => ['users', 'RESTRICT'],
        'complex_memberships.complex_id' => ['complexes', 'RESTRICT'],
        'complex_memberships.user_id' => ['users', 'RESTRICT'],
        'complex_memberships.invitation_id' => ['complex_invitations', 'SET NULL'],
        'complex_memberships.suspended_by' => ['users', 'SET NULL'],
        'complex_memberships.ended_by' => ['users', 'SET NULL'],
        // ── پیام‌رسان ──
        'conversations.complex_id' => ['complexes', 'RESTRICT'],
        'conversations.unit_id' => ['units', 'SET NULL'],
        'conversations.user_id' => ['users', 'SET NULL'],
        'conversations.peer_id' => ['users', 'SET NULL'],
        'conversations.created_by' => ['users', 'SET NULL'],
        'messages.complex_id' => ['complexes', 'RESTRICT'],
        'messages.conversation_id' => ['conversations', 'SET NULL'],
        'messages.unit_id' => ['units', 'SET NULL'],
        'messages.user_id' => ['users', 'SET NULL'],
        'messages.reply_to_id' => ['messages', 'SET NULL'],
        'messages.forwarded_from_id' => ['messages', 'SET NULL'],
        'messages.deleted_by' => ['users', 'SET NULL'],
        'messages.hidden_by' => ['users', 'SET NULL'],
        'message_polls.message_id' => ['messages', 'CASCADE'],
        'poll_options.message_poll_id' => ['message_polls', 'CASCADE'],
        'poll_votes.message_poll_id' => ['message_polls', 'CASCADE'],
        'poll_votes.poll_option_id' => ['poll_options', 'CASCADE'],
        'poll_votes.user_id' => ['users', 'RESTRICT'],
        'poll_votes.unit_id' => ['units', 'SET NULL'],
        'message_reactions.message_id' => ['messages', 'CASCADE'],
        'message_reactions.user_id' => ['users', 'CASCADE'],
        'conversation_pins.conversation_id' => ['conversations', 'CASCADE'],
        'conversation_pins.message_id' => ['messages', 'CASCADE'],
        'conversation_pins.pinned_by' => ['users', 'SET NULL'],
        // ── ردِ ممیزی ──
        'audit_logs.user_id' => ['users', 'SET NULL'],
        'audit_logs.complex_id' => ['complexes', 'SET NULL'],
    ];

    /** 🔴 هر کلیدِ جدول‌های تاریخی دقیقاً همان قاعده‌ای را دارد که ثبت شده — نه کمتر، نه کلیدِ بی‌تصمیم. */
    public function test_every_history_key_follows_the_register(): void
    {
        $tables = array_unique(array_map(fn (string $key) => explode('.', $key)[0], array_keys(self::REGISTER)));
        $actual = [];

        foreach ($tables as $table) {
            foreach (DB::select("PRAGMA foreign_key_list('{$table}')") as $key) {
                $actual["{$table}.{$key->from}"] = [$key->table, strtoupper($key->on_delete)];
            }
        }
        ksort($actual);
        $register = self::REGISTER;
        ksort($register);

        $this->assertSame($register, $actual);
    }

    /**
     * 🔴 حذفِ خامِ والد در پایگاه‌داده رد می‌شود و هیچ ردیفِ تاریخی نمی‌رود — حتی مجتمعی که دیگر ردیفِ عضویت ندارد
     * (پیش از ۱۰/۱۴ همان `DELETE` قبض‌ها، پرداخت‌ها، کیف، سکونت، گفت‌وگو و پیام را با خود می‌برد).
     */
    public function test_a_raw_parent_delete_never_erases_history(): void
    {
        [$complex, $unit, $resident] = $this->history();
        DB::table('complex_memberships')->delete();
        $before = $this->counts();

        foreach ([
            'واحد' => fn () => DB::table('units')->where('id', $unit->id)->delete(),
            'مجتمع' => fn () => DB::table('complexes')->where('id', $complex->id)->delete(),
            'حساب' => fn () => DB::table('users')->where('id', $resident->id)->delete(),
        ] as $parent => $delete) {
            try {
                $delete();
                $this->fail("حذفِ خامِ {$parent} پذیرفته شد.");
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('constraint', $e->getMessage(), $parent);
            }
        }

        $this->assertSame($before, $this->counts());
    }

    /**
     * Eloquent هم: مجتمع، عضویت، دوره‌ی سکونت، گفت‌وگو و پیام حذف نمی‌شوند؛ واحد نرم حذف می‌شود و تاریخچه‌اش می‌ماند،
     * ولی `forceDelete` بسته است.
     */
    public function test_eloquent_never_hard_deletes_an_owner_of_history(): void
    {
        [$complex, $unit, $resident] = $this->history();
        $before = $this->counts();

        $owners = [
            'مجتمع' => $complex,
            'عضویت' => ComplexMembership::withoutGlobalScopes()->where('user_id', $resident->id)->firstOrFail(),
            'دوره‌ی سکونت' => UnitTenure::query()->where('user_id', $resident->id)->firstOrFail(),
            'گفت‌وگو' => Conversation::withoutGlobalScopes()->firstOrFail(),
            'پیام' => Message::withoutGlobalScopes()->firstOrFail(),
        ];
        foreach ($owners as $label => $model) {
            try {
                $model->delete();
                $this->fail("{$label} با Eloquent حذف شد.");
            } catch (DomainException $e) {
                $this->assertSame('history.not_deletable', $e->errorCode, $label);
            }
        }

        $unit->delete();
        $this->assertSoftDeleted('units', ['id' => $unit->id]);
        try {
            Unit::withTrashed()->withoutGlobalScopes()->findOrFail($unit->id)->forceDelete();
            $this->fail('forceDelete ِ واحد پذیرفته شد.');
        } catch (DomainException) {
            // KeepsHistory
        }

        $this->assertSame($before, $this->counts());
    }

    /**
     * یک مجتمع با هر نوع تاریخچه.
     *
     * @return array{0: Complex, 1: Unit, 2: User}
     */
    private function history(): array
    {
        $complex = Complex::factory()->create();
        $unit = Unit::factory()->create(['complex_id' => $complex->id]);
        // کارخانه‌ی حساب عضویتِ فعال را از دفترِ عضویت می‌سازد (۱۰/۲)
        $resident = User::factory()->create(['complex_id' => $complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        app(TenureService::class)->open($unit, $resident, ResidentRelation::Owner, 100, now()->subYear());

        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'period' => Jalali::currentPeriod(),
            'base_amount' => 100000, 'total_amount' => 100000, 'paid_amount' => 0, 'due_date' => now(),
        ]);
        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'bill_id' => $bill->id, 'user_id' => $resident->id,
            'amount' => 100000, 'method' => 'online', 'status' => 'success', 'period' => $bill->period,
        ]);
        PaymentAllocation::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 100000,
        ]);
        app(WalletService::class)->credit($resident, $unit, 5000, WalletTransaction::SOURCE_ADJUSTMENT);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'type' => 'channel', 'key' => 'channel', 'title' => 'گروه',
        ]);
        Message::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'conversation_id' => $conversation->id, 'user_id' => $resident->id,
            'author_name' => $resident->name, 'body' => 'سلام', 'audience' => 'all',
        ]);

        return [$complex, $unit, $resident];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $tables = ['bills', 'payments', 'payment_allocations', 'wallet_transactions', 'unit_user', 'units', 'conversations', 'messages', 'complexes', 'users'];

        return array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
    }
}
