<?php

namespace App\Services\Assistant\Tools;

use App\Models\Building;
use App\Models\Complex;
use App\Models\Unit;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\ComplexMention;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;

/** اطلاعاتِ مجتمع (بندِ ۴.۵-الف) — همان «اطلاعاتِ مجتمع» ِ داشبورد، از ردیفِ مجتمع و ساختمان‌ها و واحدها. */
final class ComplexInfoTool extends Tool
{
    public function __construct(private readonly ComplexMention $mentions) {}

    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'complex.info',
            title: 'اطلاعاتِ مجتمع',
            dashboards: ['unit', 'manager', 'platform'],
            source: 'complexes, buildings, units (ComplexInformationController)',
            fields: ['name' => 'string', 'type' => 'enum', 'address' => 'string', 'phone' => 'string', 'buildings' => 'int', 'units' => 'int', 'floors' => 'int', 'blocks' => 'int'],
            relations: ['buildings', 'units'],
            filters: ['complex (فقط سوپر ادمین در زمینه‌ی سکو، با نام)'],
            operations: ['read', 'aggregate'],
            derivation: 'تعدادِ ساختمان، واحد (و واحدِ فعال) و بلوک شمارش از ردیف‌های همان مجتمع؛ بیشترین طبقه از ساختمان‌ها',
            access: 'اعضای همان مجتمع؛ سوپر ادمین هر مجتمعِ عملیاتی با نام',
            privacy: 'کدِ پیوستن، تنظیماتِ درگاه و ستونِ مرده‌ی صندوق هرگز',
            time: 'وضعیتِ فعلی',
            limit: 6,
            presentation: 'فهرستِ مشخصات؛ فیلدِ ثبت‌نشده «ثبت نشده»، نه صفر',
            keywords: ['اطلاعات مجتمع' => 6, 'مشخصات مجتمع' => 6, 'آدرس' => 4, 'نشانی' => 4, 'نوع مجتمع' => 5, 'تلفن مجتمع' => 5,
                'تعداد واحد' => 5, 'چند واحد' => 5, 'واحدهای فعال' => 5, 'ساختمان' => 3, 'بلوک' => 3, 'طبقات' => 3, 'هیئت مدیره' => 4, 'مجتمع' => 1],
            cacheable: true,
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        if ($context->isPlatform()) {
            $found = $this->mentions->forPlatform($context, $question);
            if ($found->count() > 1) {
                return ToolResult::clarify('چند مجتمع با این نام هست؛ کدام؟', $found->pluck('name')->all());
            }
            $complex = $found->first();
            if ($complex === null) {
                return ToolResult::notFound($this->mentions->phrase($question) === null
                    ? 'نامِ مجتمع را در پرسش بنویسید (مثلاً «اطلاعات مجتمع آفتاب»).'
                    : 'مجتمعی با این نام پیدا نشد.');
            }
        } else {
            $complex = Complex::findOrFail($context->complex?->id);
        }

        return ToolResult::found("اطلاعاتِ «{$complex->name}»:", $this->facts($complex), basis: 'ردیفِ مجتمع و شمارشِ ساختمان‌ها و واحدهای ثبت‌شده‌ی همان مجتمع');
    }

    /** @return list<array{label: string, value: string}> */
    private function facts(Complex $complex): array
    {
        $units = Unit::query()->where('complex_id', $complex->id);
        $buildings = Building::query()->where('complex_id', $complex->id);
        $blocks = (clone $units)->whereNotNull('block')->where('block', '!=', '')->distinct()->count('block');
        $floors = (int) (clone $buildings)->max('floors_count');

        $optional = fn (?string $value) => filled($value) ? (string) $value : 'ثبت نشده';

        return [
            ToolResult::fact('نام', $complex->name),
            ToolResult::fact('نوع', $complex->type->label()),
            ToolResult::fact('نشانی', $optional(trim(implode('، ', array_filter([$complex->province, $complex->city, $complex->address]))))),
            ToolResult::fact('تلفن', $optional($complex->phone)),
            ToolResult::fact('ساختمان‌ها', $this->count((clone $buildings)->count())),
            ToolResult::fact('بلوک‌ها', $blocks > 0 ? $this->count($blocks) : 'ثبت نشده'),
            ToolResult::fact('بیشترین تعدادِ طبقات', $floors > 0 ? $this->count($floors) : 'ثبت نشده'),
            ToolResult::fact('واحدها', $this->count((clone $units)->count()).' (فعال: '.$this->count((clone $units)->where('is_active', true)->count()).')'),
            ToolResult::fact('هیئت‌مدیره', $optional($complex->board)),
            ToolResult::fact('ساعتِ پاسخ‌گویی', $optional($complex->response_hours)),
        ];
    }
}
