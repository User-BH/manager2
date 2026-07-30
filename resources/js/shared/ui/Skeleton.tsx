/**
 * اسکلتون‌های هم‌شکل با محتوای واقعی.
 *
 * ─── چرا اسکلتونِ شکل‌دار و نه اسپینر؟ ──────────────────────────────────────
 * اسپینر فقط می‌گوید «صبر کن»؛ اسکلتون می‌گوید «چه چیزی دارد می‌آید». وقتی
 * جای عناصر از قبل رزرو شده باشد، با رسیدنِ داده صفحه **نمی‌پرد** (همان
 * جابه‌جاییِ چیدمان که هم آزاردهنده است و هم امتیازِ CLS را خراب می‌کند).
 *
 * به همین دلیل هر اسکلتون باید ابعادش نزدیکِ محتوای واقعی باشد؛ یک بلوکِ
 * عمومیِ ۶۴ پیکسلی برای همه‌ی صفحه‌ها همان مشکلِ پرش را دارد.
 *
 * `aria-hidden` روی خودِ شکل‌هاست و پیامِ وضعیت یک بار در ظرفِ بیرونی اعلام
 * می‌شود، وگرنه صفحه‌خوان ده‌ها بلوکِ بی‌معنا را می‌خواند.
 */

function Bar({ className = '', style }: { className?: string; style?: React.CSSProperties }) {
  return (
    <div
      aria-hidden
      className={`animate-pulse rounded-lg ${className}`}
      style={{ backgroundColor: 'var(--surface-sunken)', ...style }}
    />
  )
}

function Frame({ children }: { children: React.ReactNode }) {
  return (
    <div role="status" aria-label="در حال بارگذاری" className="flex flex-col gap-3">
      {children}
    </div>
  )
}

/** جدول‌های فهرستی: واحدها، ساکنین، قبوض، اعضا، گزارش‌ها. */
export function TableSkeleton({ rows = 6, columns = 4 }: { rows?: number; columns?: number }) {
  return (
    <Frame>
      <div
        className="rounded-2xl border p-4"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      >
        {/* سرستون‌ها */}
        <div className="mb-4 flex gap-3">
          {Array.from({ length: columns }).map((_, i) => (
            <Bar key={i} className="h-3.5 flex-1" />
          ))}
        </div>

        <div className="flex flex-col gap-3.5">
          {Array.from({ length: rows }).map((_, r) => (
            <div key={r} className="flex items-center gap-3">
              {Array.from({ length: columns }).map((_, c) => (
                /* ستونِ اول معمولاً نام است و بلندتر دیده می‌شود */
                <Bar key={c} className="h-4" style={{ flex: c === 0 ? 1.6 : 1 }} />
              ))}
            </div>
          ))}
        </div>
      </div>
    </Frame>
  )
}

/** داشبورد: چند کارتِ آمار و بعد نمودار. */
export function DashboardSkeleton() {
  return (
    <Frame>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className="flex flex-col gap-3 rounded-2xl border p-4"
            style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
          >
            <Bar className="h-3 w-20" />
            <Bar className="h-6 w-28" />
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Bar className="h-64 rounded-2xl lg:col-span-2" />
        <Bar className="h-64 rounded-2xl" />
      </div>
    </Frame>
  )
}

/** فرم‌های تنظیمات: چند برچسب و ورودی پشتِ هم. */
export function FormSkeleton({ fields = 5 }: { fields?: number }) {
  return (
    <Frame>
      <div
        className="flex flex-col gap-5 rounded-2xl border p-5"
        style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      >
        {Array.from({ length: fields }).map((_, i) => (
          <div key={i} className="flex flex-col gap-2">
            <Bar className="h-3 w-24" />
            <Bar className="h-11 w-full rounded-xl" />
          </div>
        ))}
        <Bar className="h-11 w-32 rounded-xl" />
      </div>
    </Frame>
  )
}

/** فهرست‌های کارتی: اطلاعیه‌ها، تخفیف‌ها، پکیج‌ها. */
export function CardListSkeleton({ items = 4 }: { items?: number }) {
  return (
    <Frame>
      {Array.from({ length: items }).map((_, i) => (
        <div
          key={i}
          className="flex flex-col gap-2.5 rounded-2xl border p-4"
          style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
        >
          <div className="flex items-center justify-between gap-3">
            <Bar className="h-4 w-40" />
            <Bar className="h-3 w-20" />
          </div>
          <Bar className="h-3 w-full" />
          <Bar className="h-3 w-3/4" />
        </div>
      ))}
    </Frame>
  )
}
