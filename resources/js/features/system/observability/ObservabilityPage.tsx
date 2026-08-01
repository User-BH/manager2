import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Activity,
  AlertTriangle,
  ListChecks,
  BarChart3,
  CheckCircle2,
  ExternalLink,
  Eye,
  Save,
  ServerCrash,
} from 'lucide-react'

import { Card } from '@/shared/ui/Card'
import { TextField } from '@/shared/ui/Field'
import { ErrorState } from '@/shared/ui/PageState'
import { FormSkeleton } from '@/shared/ui/Skeleton'
import { useDocumentTitle } from '@/shared/hooks'
import { useApiAction } from '@/shared/hooks/useAction'
import { api } from '@/shared/lib/api'
import { errorMessage } from '@/shared/lib/queryClient'
import { queryKeys } from '@/shared/lib/queryKeys'
import { formatNumber } from '@/shared/lib/format'
import { ErrorList } from './ErrorList'

/**
 * پایش و تحلیل — تنظیمات و گزارش‌ها.
 *
 * هدفِ این صفحه یک جمله است: **اگر صاحبِ پروژه عوض شد، بتواند بدونِ هیچ
 * تغییری در کد حسابِ تحلیلیِ خودش را وصل کند.**
 *
 * هر شناسه دو منبع دارد — `.env` و همین صفحه — و همیشه این صفحه مقدم است.
 * کنارِ هر فیلد نوشته می‌شود که مقدارِ فعلی از کجا می‌آید، تا اگر کسی چیزی در
 * `.env` گذاشت و اثری ندید، دلیلش را ببیند.
 */

interface FieldState {
  value: string
  source: 'panel' | 'env' | 'unset'
  isSecret: boolean
}

interface ServiceStatus {
  id: string
  label: string
  enabled: boolean
  source: string
  docsUrl: string
}

interface Summary {
  queue: { pending: number; failed: number; oldestPendingMinutes: number }
  openErrors: number
  last24h: number
  last7days: number
  serverVsClient: { server: number; client: number }
  topErrors: { id: number; type: string; message: string; occurrences: number }[]
  daily: { day: string; total: number }[]
}

interface ObservabilityResponse {
  fields: Record<string, FieldState>
  services: ServiceStatus[]
  summary: Summary
}

/** ترتیب و راهنمای فیلدها — تنها جایی که چیدمانِ فرم تعریف می‌شود. */
const GROUPS = [
  {
    title: 'Sentry — رهگیری خطا',
    hint: 'DSN را از Settings ← Projects ← Client Keys در Sentry بردارید.',
    fields: [
      { key: 'sentry_dsn', label: 'DSN سرور', placeholder: 'https://…@…ingest.sentry.io/…' },
      {
        key: 'sentry_client_dsn',
        label: 'DSN مرورگر (اختیاری)',
        placeholder: 'اگر خالی بماند، همان DSN سرور استفاده می‌شود',
      },
      { key: 'sentry_environment', label: 'محیط', placeholder: 'production' },
      {
        key: 'sentry_traces_sample_rate',
        label: 'نرخ نمونه‌برداری کارایی (۰ تا ۱)',
        placeholder: '0',
      },
      { key: 'sentry_auth_token', label: 'توکن (فقط برای آپلود source map)', placeholder: '' },
    ],
  },
  {
    title: 'Google Analytics 4',
    hint: 'شناسه در Admin ← Data Streams است و با G- شروع می‌شود.',
    fields: [
      { key: 'ga4_measurement_id', label: 'Measurement ID', placeholder: 'G-XXXXXXXXXX' },
      { key: 'ga4_api_secret', label: 'API Secret (اختیاری)', placeholder: '' },
    ],
  },
  {
    title: 'Google Tag Manager',
    hint: 'اگر GTM را فعال کنید معمولاً GA4 را هم خودش مدیریت می‌کند؛ هر دو را هم‌زمان روشن نکنید مگر آگاهانه، وگرنه هر بازدید دوبار شمرده می‌شود.',
    fields: [{ key: 'gtm_container_id', label: 'Container ID', placeholder: 'GTM-XXXXXXX' }],
  },
  {
    title: 'Microsoft Clarity',
    hint: 'شناسه‌ی پروژه در Settings ← Overview.',
    fields: [{ key: 'clarity_project_id', label: 'Project ID', placeholder: 'abcdefghij' }],
  },
] as const

export function ObservabilityPage() {
  useDocumentTitle('پایش و تحلیل')

  const { data, error, isLoading, refetch } = useQuery({
    queryKey: queryKeys.system.observability(),
    queryFn: ({ signal }) => api<ObservabilityResponse>('/system/observability', { signal }),
  })

  if (isLoading) return <FormSkeleton fields={7} />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          پایش و تحلیل
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          شناسه‌ها را اینجا وارد کنید یا در فایل <code>.env</code> بگذارید. مقدارِ این صفحه همیشه
          مقدم است و تغییرش نیاز به بیلد دوباره ندارد.
        </p>
      </header>

      <SummaryCards summary={data.summary} />
      <QueueHealth queue={data.summary.queue} />
      <ServiceCards services={data.services} />

      <SettingsForm fields={data.fields} />

      <ErrorList />
    </div>
  )
}

/**
 * فرمِ شناسه‌ها.
 *
 * ─── چرا کامپوننتِ جدا و نه `useEffect` در صفحه؟ ────────────────────────────
 * حالتِ اولیه‌ی فرم باید از سرور بیاید. راهِ وسوسه‌انگیز یک `useEffect` است که
 * با رسیدنِ داده `setState` کند — ولی آن یعنی رندرِ آبشاری و همان الگویی که
 * `react-hooks/set-state-in-effect` درست هشدار می‌دهد.
 *
 * اینجا به‌جایش، فرم فقط وقتی رندر می‌شود که داده رسیده باشد و مقدارِ اولیه را
 * **یک بار هنگام ساخت** از props می‌گیرد. نتیجه: بدونِ effect، بدونِ رندرِ
 * اضافه، و بعد از آن کاربر مالکِ فرم است و پاسخِ تازه‌ی سرور تایپِ او را
 * بازنویسی نمی‌کند.
 */
function SettingsForm({ fields }: { fields: Record<string, FieldState> }) {
  const { call, isBusy } = useApiAction()
  const [form, setForm] = useState<Record<string, string>>(() =>
    Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field.value])),
  )

  function save() {
    void call(
      '/system/observability',
      { method: 'PUT', body: form },
      {
        success: 'تنظیمات پایش ذخیره شد.',
        errorFallback: 'ذخیره‌ی تنظیمات ممکن نشد.',
        invalidate: [queryKeys.system.observability()],
      },
    )
  }

  return (
    <Card title="شناسه‌ها" subtitle="خالی گذاشتنِ هر فیلد یعنی «از .env استفاده کن»" delay={0.1}>
      <div className="flex flex-col gap-6">
        {GROUPS.map((group) => (
          <section key={group.title} className="flex flex-col gap-3">
            <div>
              <h3 className="text-[13.5px] font-bold" style={{ color: 'var(--text-primary)' }}>
                {group.title}
              </h3>
              <p className="mt-0.5 text-[11.5px]" style={{ color: 'var(--text-tertiary)' }}>
                {group.hint}
              </p>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {group.fields.map((field) => (
                <div key={field.key} className="flex flex-col gap-1">
                  <TextField
                    label={field.label}
                    placeholder={field.placeholder}
                    dir="ltr"
                    value={form[field.key] ?? ''}
                    onChange={(e) => setForm((f) => ({ ...f, [field.key]: e.target.value }))}
                  />
                  <SourceBadge source={fields[field.key]?.source} />
                </div>
              ))}
            </div>
          </section>
        ))}

        <button
          onClick={save}
          disabled={isBusy}
          className="flex w-fit items-center gap-1.5 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-50"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <Save size={15} />
          ذخیره‌ی تنظیمات
        </button>
      </div>
    </Card>
  )
}

/** از کجا آمده: پنل، `.env`، یا هیچ‌جا. */
function SourceBadge({ source }: { source?: string }) {
  const map: Record<string, { text: string; color: string }> = {
    panel: { text: 'از همین پنل', color: 'var(--color-brand-600)' },
    env: { text: 'از فایل .env', color: 'var(--color-accent-600)' },
    unset: { text: 'تنظیم نشده', color: 'var(--text-tertiary)' },
  }
  const badge = map[source ?? 'unset'] ?? map.unset

  return (
    <span className="text-[11px]" style={{ color: badge.color }}>
      {badge.text}
    </span>
  )
}

/**
 * سلامتِ صف.
 *
 * از R11 بکاپ‌ها در صف ساخته می‌شوند. اگر کارگر روی سرور بالا نباشد، کارها
 * بی‌صدا تلنبار می‌شوند و کاربر «در حال ساخت…» می‌بیند که هرگز تمام نمی‌شود.
 * هشدار بر اساس **سنِ قدیمی‌ترین کارِ منتظر** است و نه تعدادشان: صفِ شلوغ
 * لزوماً مشکل نیست، ولی کاری که ده دقیقه منتظر مانده یعنی کسی آن را برنمی‌دارد.
 */
function QueueHealth({ queue }: { queue: Summary['queue'] }) {
  const stalled = queue.oldestPendingMinutes >= 10

  return (
    <div
      className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border p-4"
      style={{
        borderColor: stalled ? 'var(--color-danger)' : 'var(--border-subtle)',
        backgroundColor: 'var(--surface-base)',
      }}
    >
      <span
        className="flex items-center gap-1.5 text-[12.5px] font-semibold"
        style={{ color: 'var(--text-primary)' }}
      >
        <ListChecks size={14} style={{ color: 'var(--text-tertiary)' }} />
        وضعیت صف
      </span>

      <span className="text-[12px]" style={{ color: 'var(--text-secondary)' }}>
        در انتظار: <b className="tabular-nums">{formatNumber(queue.pending)}</b>
      </span>

      <span
        className="text-[12px]"
        style={{ color: queue.failed > 0 ? 'var(--color-danger)' : 'var(--text-secondary)' }}
      >
        ناموفق: <b className="tabular-nums">{formatNumber(queue.failed)}</b>
      </span>

      {stalled && (
        <span
          className="flex items-center gap-1.5 text-[12px] font-semibold"
          style={{ color: 'var(--color-danger)' }}
        >
          <AlertTriangle size={13} />
          قدیمی‌ترین کار {formatNumber(queue.oldestPendingMinutes)} دقیقه منتظر است — احتمالاً
          کارگرِ صف (queue:work) روی سرور اجرا نمی‌شود.
        </span>
      )}
    </div>
  )
}

function SummaryCards({ summary }: { summary: Summary }) {
  const cards = [
    {
      label: 'خطاهای باز',
      value: summary.openErrors,
      icon: AlertTriangle,
      color: 'var(--color-danger)',
    },
    {
      label: 'رخداد ۲۴ ساعت اخیر',
      value: summary.last24h,
      icon: Activity,
      color: 'var(--color-accent-600)',
    },
    {
      label: 'رخداد ۷ روز اخیر',
      value: summary.last7days,
      icon: BarChart3,
      color: 'var(--color-brand-600)',
    },
    {
      label: 'سرور / مرورگر',
      value: `${summary.serverVsClient.server} / ${summary.serverVsClient.client}`,
      icon: ServerCrash,
      color: 'var(--text-secondary)',
    },
  ]

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {cards.map((card) => (
        <div
          key={card.label}
          className="flex flex-col gap-2 rounded-2xl border p-4"
          style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
        >
          <span
            className="flex items-center gap-1.5 text-[12px]"
            style={{ color: 'var(--text-tertiary)' }}
          >
            <card.icon size={14} style={{ color: card.color }} />
            {card.label}
          </span>
          <span
            className="tabular-nums text-xl font-extrabold"
            style={{ color: 'var(--text-primary)' }}
          >
            {typeof card.value === 'number' ? formatNumber(card.value) : card.value}
          </span>
        </div>
      ))}
    </div>
  )
}

/** وضعیتِ اتصالِ هر سرویس + لینکِ مستقیم به داشبوردِ خودش. */
function ServiceCards({ services }: { services: ServiceStatus[] }) {
  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {services.map((service) => (
        <div
          key={service.id}
          className="flex items-center justify-between gap-2 rounded-2xl border p-3.5"
          style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
        >
          <div className="flex min-w-0 flex-col gap-1">
            <span
              className="truncate text-[12.5px] font-semibold"
              style={{ color: 'var(--text-primary)' }}
            >
              {service.label}
            </span>
            <span
              className="flex items-center gap-1 text-[11px]"
              style={{
                color: service.enabled ? 'var(--state-success)' : 'var(--text-tertiary)',
              }}
            >
              {service.enabled ? <CheckCircle2 size={12} /> : <Eye size={12} />}
              {service.enabled ? 'فعال' : 'تنظیم نشده'}
            </span>
          </div>

          <a
            href={service.docsUrl}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={`باز کردن داشبورد ${service.label}`}
            className="shrink-0 rounded-lg border p-1.5"
            style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
          >
            <ExternalLink size={13} />
          </a>
        </div>
      ))}
    </div>
  )
}
