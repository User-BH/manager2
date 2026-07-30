import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle, RotateCcw } from 'lucide-react'

/**
 * دیوارِ آتشِ خطاهای رندر.
 *
 * ─── چرا کلاس و نه هوک؟ ────────────────────────────────────────────────────
 * React هنوز هیچ معادلِ هوکی برای `getDerivedStateFromError` ندارد. این تنها
 * جای این پروژه است که کامپوننتِ کلاسی توجیه دارد.
 *
 * ─── چه چیزی را می‌گیرد و چه چیزی را نمی‌گیرد ───────────────────────────────
 * فقط خطاهای **رندر** (و چرخه‌ی عمر و سازنده) را می‌گیرد. اینها را نمی‌گیرد:
 *   • خطای درونِ هندلرِ رویداد (onClick و …) — آنجا try/catch لازم است
 *   • خطای async و promiseِ رد‌شده — کارِ لایه‌ی API و `useAction` است
 *   • خطای خودِ درخواست‌ها — TanStack Query آن را در `error` می‌دهد
 * پس این جایگزینِ مدیریتِ خطا نیست؛ تورِ ایمنیِ آخر است.
 *
 * ─── چرا `resetKey`؟ ───────────────────────────────────────────────────────
 * بدونش، یک‌بار خطا یعنی همیشه خطا: کاربر به صفحه‌ی دیگری می‌رود و همان صفحه‌ی
 * خطا را می‌بیند، چون boundary در حالتِ خطا گیر کرده. با دادنِ `pathname` به
 * عنوان کلید، هر ناوبری وضعیت را پاک می‌کند.
 */

interface Props {
  children: ReactNode
  /** با تغییرِ این مقدار، وضعیتِ خطا پاک می‌شود (معمولاً مسیرِ صفحه). */
  resetKey?: unknown
  /** نمای جایگزین. اگر ندهید، کارتِ پیش‌فرضِ داخلِ صفحه نشان داده می‌شود. */
  fallback?: (error: Error, reset: () => void) => ReactNode
  /** برای گزارش به سرویسِ پایش (در R8 به Sentry وصل می‌شود). */
  onError?: (error: Error, info: ErrorInfo) => void
}

interface State {
  error: Error | null
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidUpdate(prevProps: Props) {
    // ناوبری به مسیرِ تازه یعنی «از نو امتحان کن»
    if (this.state.error && prevProps.resetKey !== this.props.resetKey) {
      this.setState({ error: null })
    }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    /*
     * فعلاً فقط کنسول. R8 اینجا را به Sentry وصل می‌کند و چون همه‌ی خطاهای
     * رندر از همین یک نقطه رد می‌شوند، آن اتصال یک‌خطی خواهد بود.
     */
    console.error('[ErrorBoundary]', error, info.componentStack)
    this.props.onError?.(error, info)
  }

  reset = () => this.setState({ error: null })

  render() {
    const { error } = this.state
    if (!error) return this.props.children

    if (this.props.fallback) return this.props.fallback(error, this.reset)
    return <ErrorFallbackCard error={error} onReset={this.reset} />
  }
}

/**
 * آیا خطا از «چانکِ ناموجود» است؟
 *
 * سناریوی واقعی: کاربر تبِ داشبورد را باز گذاشته، ما نسخه‌ی تازه‌ای منتشر
 * می‌کنیم و نامِ فایل‌ها (که هش دارند) عوض می‌شود. حالا اولین باری که کاربر به
 * صفحه‌ای می‌رود که چانکش هنوز دانلود نشده بود، مرورگر دنبالِ فایلی می‌گردد که
 * دیگر روی سرور نیست.
 *
 * تفاوتش مهم است: اینجا «تلاش دوباره» بی‌فایده است، چون همان آدرسِ کهنه دوباره
 * درخواست می‌شود. تنها درمان، بارگذاری دوباره‌ی صفحه است تا مرورگر فهرستِ تازه‌ی
 * فایل‌ها را بگیرد.
 *
 * این حالت را هنگام راستی‌آزماییِ همین مرحله در مرورگر دیدم: پس از یک بیلدِ
 * تازه، تبِ باز دقیقاً همین خطا را داد.
 */
function isStaleChunkError(error: Error): boolean {
  const text = `${error.name} ${error.message}`
  return (
    /Failed to fetch dynamically imported module/i.test(text) ||
    /Importing a module script failed/i.test(text) ||
    /error loading dynamically imported module/i.test(text)
  )
}

/**
 * نمای پیش‌فرضِ خطا.
 *
 * متنِ فنیِ خطا فقط در حالتِ توسعه دیده می‌شود. در محصول، پیامِ خام برای کاربر
 * نه معنایی دارد و نه امن است (می‌تواند جزئیاتِ درونیِ برنامه را لو بدهد).
 */
function ErrorFallbackCard({ error, onReset }: { error: Error; onReset: () => void }) {
  const staleChunk = isStaleChunkError(error)

  return (
    <div
      className="flex flex-col items-center gap-3 rounded-2xl border p-10 text-center"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
      role="alert"
    >
      <AlertTriangle size={30} style={{ color: 'var(--color-danger)' }} />

      <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
        {staleChunk ? 'نسخه‌ی تازه‌ای منتشر شده است' : 'این بخش درست بارگذاری نشد'}
      </p>
      <p className="max-w-sm text-xs" style={{ color: 'var(--text-tertiary)' }}>
        {staleChunk
          ? 'این صفحه با نسخه‌ی قدیمیِ برنامه باز مانده بود. با یک بار بارگذاری دوباره، آخرین نسخه می‌آید.'
          : 'مشکل از سمتِ ماست، نه شما. می‌توانید دوباره تلاش کنید؛ اگر باز هم تکرار شد به پشتیبانی اطلاع دهید.'}
      </p>

      {import.meta.env.DEV && (
        <pre
          className="mt-1 max-w-full overflow-x-auto rounded-xl p-3 text-left text-[11px] direction-ltr"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--color-danger)' }}
        >
          {error.message}
        </pre>
      )}

      <button
        // برای چانکِ کهنه، ریست کردنِ state دوباره همان آدرسِ ناموجود را می‌خواهد
        onClick={staleChunk ? () => window.location.reload() : onReset}
        className="mt-1 flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-semibold text-white"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        <RotateCcw size={14} />
        {staleChunk ? 'بارگذاری دوباره' : 'تلاش دوباره'}
      </button>
    </div>
  )
}

/**
 * نسخه‌ی تمام‌صفحه — برای ریشه‌ی برنامه، جایی که حتی پوسته هم بالا نیامده.
 *
 * اینجا دکمه صفحه را کامل بارگذاری می‌کند، نه اینکه فقط state را پاک کند: اگر
 * خطا در خودِ ریشه بوده، پاک‌کردنِ state احتمالاً دوباره به همان خطا می‌رسد.
 */
export function RootErrorFallback({ error }: { error: Error }) {
  return (
    <div
      className="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
      dir="rtl"
      role="alert"
    >
      <AlertTriangle size={40} style={{ color: 'var(--color-danger)' }} />

      <h1 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
        مشکلی پیش آمد
      </h1>
      <p className="max-w-sm text-[13px]" style={{ color: 'var(--text-secondary)' }}>
        برنامه به وضعیتِ غیرمنتظره‌ای رسید. با بارگذاری دوباره‌ی صفحه معمولاً درست می‌شود.
      </p>

      {import.meta.env.DEV && (
        <pre
          className="max-w-full overflow-x-auto rounded-xl p-3 text-left text-[11px]"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--color-danger)' }}
        >
          {error.message}
        </pre>
      )}

      <button
        onClick={() => window.location.reload()}
        className="flex items-center gap-1.5 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        <RotateCcw size={15} />
        بارگذاری دوباره
      </button>
    </div>
  )
}
