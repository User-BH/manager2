<?php

namespace App\Services\Assistant\Tools;

use App\Models\WalletTransaction;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;

/**
 * کیفِ پولِ **حساب** (بندِ ۴.۵-د؛ تصمیمِ ۲.۳-۱۱): مانده از دفتر (`WalletService::balance`) — کیف مالِ حساب است و بین
 * مجتمع‌های همان حساب مشترک، پس مانده همه‌جا یکی است؛ تراکنش‌های نمایش‌داده فقط همین مجتمع‌اند.
 */
final class WalletTool extends Tool
{
    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'finance.wallet',
            title: 'کیفِ پول',
            dashboards: ['unit', 'manager'],
            source: 'wallet_transactions — WalletService::balance',
            fields: ['balance' => 'decimal', 'direction' => 'enum', 'amount' => 'decimal', 'created_at' => 'datetime'],
            relations: ['user', 'unit', 'payment', 'bill'],
            filters: ['period'],
            operations: ['read', 'aggregate'],
            derivation: 'مانده = Σ واریز − Σ برداشت از دفترِ کیف',
            access: 'فقط کیفِ خودِ حساب',
            privacy: 'کیفِ هیچ حسابِ دیگری',
            time: 'مانده تا همین لحظه؛ تراکنش‌ها با زمانِ ثبت',
            limit: 5,
            presentation: 'مانده و پنج تراکنشِ آخرِ همین مجتمع',
            keywords: ['کیف پول' => 6, 'کیفپول' => 6, 'کیف' => 4, 'اعتبار' => 3, 'موجودی' => 1],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        $balance = app(WalletService::class)->balance($context->user);
        $recent = WalletTransaction::query()->where('user_id', $context->user->id)
            ->where('complex_id', $context->complex?->id)->latest('id')->limit(5)->get();

        return ToolResult::found(
            'مانده‌ی کیفِ پولِ شما: '.$this->money($context, $balance).'.',
            [ToolResult::fact('مانده', $this->money($context, $balance))],
            basis: 'دفترِ کیفِ حساب (مشترک بین مجتمع‌های همین حساب)',
            columns: $recent->isEmpty() ? [] : ['تاریخ', 'نوع', 'مبلغ'],
            rows: $recent->map(fn (WalletTransaction $t) => [
                Jalali::date($t->created_at),
                $t->direction === WalletTransaction::DEBIT ? 'برداشت' : 'واریز',
                $this->money($context, $t->amount),
            ])->all(),
        );
    }
}
