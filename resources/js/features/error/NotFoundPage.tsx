import { useLocation, useNavigate } from 'react-router-dom'
import { ArrowRight, FileQuestion, Home, LayoutDashboard } from 'lucide-react'
import { Logo } from '@/shared/common/Logo'
import { useDocumentTitle } from '@/shared/hooks'
import { useAuth } from '@/shared/stores/authStore'

/**
 * مسیرِ ناشناخته‌ی داشبورد (مرحلهٔ ۷ · فاز ۱).
 *
 * ─── 🔴 چه چیزی خراب بود ─────────────────────────────────────────────────
 * مسیرِ نامعتبر صفحه‌ی **۴۰۳** («دسترسی غیرمجاز») را نشان می‌داد: کاربری که
 * فقط یک پیوندِ کهنه را زده بود، فکر می‌کرد اجازه‌اش گرفته شده.
 *
 * ─── تاریخچه‌ی مرورگر ─────────────────────────────────────────────────────
 * ① «صفحه‌ی قبل» فقط وقتی هست که کاربر با ناوبریِ **داخلِ** همین اپ به اینجا
 *    رسیده باشد (`location.key` جز `default`). کسی که آدرس را مستقیم باز
 *    کرده، «قبلِ» داخلی ندارد و `navigate(-1)` او را از سایت بیرون می‌برد.
 * ② رفتن به داشبورد یا خانه این ورودی را **جایگزین** می‌کند: ۴۰۴ بن‌بست
 *    است، و «بازگشت» از مقصد نباید دوباره کاربر را به همین بن‌بست برگرداند.
 *    «بازگشت» از آنجا به صفحه‌ای می‌رود که پیوندِ خراب را داشت.
 */
export function NotFoundPage() {
  useDocumentTitle('صفحه پیدا نشد')
  const navigate = useNavigate()
  const location = useLocation()
  const { isAuthenticated } = useAuth()

  const cameFromInside = location.key !== 'default'

  function leave() {
    if (isAuthenticated) void navigate('/dashboard', { replace: true })
    // صفحه‌ی اصلی سندِ MPAِ جداست، نه روتِ همین روتر
    else window.location.replace('/')
  }

  return (
    <main
      className="flex min-h-screen flex-col items-center justify-center px-6 text-center"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
      dir="rtl"
    >
      <div className="mb-10">
        <Logo size={36} />
      </div>

      <div
        className="flex h-28 w-28 items-center justify-center rounded-full"
        style={{ backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)' }}
      >
        <FileQuestion size={48} style={{ color: 'var(--color-brand-500)' }} aria-hidden="true" />
      </div>

      <p
        className="mt-8 text-5xl font-extrabold tracking-tight"
        style={{ color: 'var(--text-primary)' }}
        aria-hidden="true"
      >
        ۴۰۴
      </p>

      <h1
        className="mt-3 max-w-sm text-[15px] font-semibold"
        style={{ color: 'var(--text-primary)' }}
      >
        این صفحه پیدا نشد
      </h1>

      <p className="mt-2 max-w-sm text-sm leading-7" style={{ color: 'var(--text-tertiary)' }}>
        نشانی‌ای که باز کرده‌اید وجود ندارد یا جابه‌جا شده است.
      </p>

      <div className="mt-9 flex flex-wrap items-center justify-center gap-3">
        <button
          type="button"
          onClick={leave}
          className="flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold text-white shadow-sm"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isAuthenticated ? <LayoutDashboard size={16} /> : <Home size={16} />}
          {isAuthenticated ? 'داشبورد' : 'صفحه اصلی'}
        </button>

        {cameFromInside && (
          <button
            type="button"
            onClick={() => void navigate(-1)}
            className="flex items-center gap-2 rounded-xl border px-5 py-3 text-sm font-semibold"
            style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
          >
            <ArrowRight size={16} />
            صفحه‌ی قبل
          </button>
        )}
      </div>
    </main>
  )
}
