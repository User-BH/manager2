import { motion } from 'framer-motion'
import { Download, Share, X } from 'lucide-react'

/**
 * خودِ کارتِ پیشنهادِ نصب — **تنبل** (مرحلهٔ ۷ · فاز ۵).
 *
 * ⚠️ چرا جدا از `InstallPrompt`: آن یکی در همه‌ی صفحه‌های عمومی و داشبورد
 * از بارگذاریِ اول هست، چون رویدادِ مرورگر هر لحظه‌ای ممکن است برسد و باید
 * گوش داده شود. ولی بیشترِ بازدیدها هرگز این کارت را نمی‌بینند (برنامه نصب
 * است، مرورگر پیشنهاد نداده، یا کاربر قبلاً بسته)؛ پس متن و آیکون‌ها فقط وقتی
 * دانلود می‌شوند که واقعاً لازم‌اند — در همان مکثِ کوتاهِ پیش از نمایش.
 * بودجه‌ی باندلِ پشتیبانی و دمو همین را گرفت.
 */
export default function InstallPromptCard({
  placement,
  ios,
  onInstall,
  onDismiss,
}: {
  placement: 'public' | 'public-bare' | 'dashboard'
  ios: boolean
  onInstall: () => void
  onDismiss: () => void
}) {
  return (
    <motion.aside
      aria-label="پیشنهادِ نصبِ اپ"
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0, x: 0 }}
      exit={{ opacity: 0, x: -96, transition: { duration: 0.35, ease: [0.4, 0, 1, 1] } }}
      transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
      dir="rtl"
      data-testid="install-prompt"
      /*
       * `bottom-safe` نداریم، پس فاصله‌ی پایین با `env(safe-area-inset-bottom)`
       * گرفته می‌شود: روی آیفون، نوارِ خانه دقیقاً همین‌جا می‌نشیند.
       */
      style={{
        bottom: 'calc(1.25rem + env(safe-area-inset-bottom, 0px))',
        backgroundColor: 'var(--surface-overlay)',
        borderColor: 'var(--border-subtle)',
        color: 'var(--text-primary)',
      }}
      className={
        placement === 'dashboard'
          ? 'fixed inset-x-4 z-50 mx-auto max-w-md rounded-2xl border p-4 shadow-xl sm:inset-x-auto sm:right-4'
          : placement === 'public'
            ? // ‎۵٫۵rem = فاصله‌ی ‎۱٫۲۵rem + دکمه‌ی ‎۵۶px + ‎۰٫۷۵rem؛ کارت زیرِ پنلِ بازِ گفت‌وگو (z-50) می‌ماند
              'fixed left-4 right-[5.5rem] z-40 rounded-2xl border p-4 shadow-xl sm:left-auto sm:w-[22rem]'
            : 'fixed inset-x-4 z-40 rounded-2xl border p-4 shadow-xl sm:inset-x-auto sm:right-4 sm:w-[22rem]'
      }
    >
      <div className="flex items-start gap-3">
        <span
          className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
          style={{ backgroundColor: 'var(--tone-brand-bg)', color: 'var(--tone-brand-fg)' }}
          aria-hidden
        >
          {ios ? <Share className="h-4 w-4" /> : <Download className="h-4 w-4" />}
        </span>

        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold">نصبِ اپلیکیشنِ ساکنا</p>

          {ios ? (
            <p className="mt-1 text-xs leading-6" style={{ color: 'var(--text-secondary)' }}>
              در نوارِ مرورگر دکمه‌ی «اشتراک‌گذاری»{' '}
              <Share className="inline h-3.5 w-3.5 align-[-2px]" aria-label="اشتراک‌گذاری" /> را
              بزنید و «Add to Home Screen» (افزودن به صفحه‌ی اصلی) را انتخاب کنید.
            </p>
          ) : (
            <p className="mt-1 text-xs leading-6" style={{ color: 'var(--text-secondary)' }}>
              ساکنا را مثلِ یک اپ روی گوشی یا رایانه نصب کنید؛ با یک لمس و بدونِ نوارِ مرورگر باز
              می‌شود.
            </p>
          )}

          <button
            type="button"
            onClick={ios ? onDismiss : onInstall}
            className="mt-3 rounded-lg px-3 py-1.5 text-xs font-semibold"
            style={{ backgroundColor: 'var(--tone-brand-fg)', color: 'var(--text-on-brand)' }}
          >
            {ios ? 'متوجه شدم' : 'نصب'}
          </button>
        </div>

        <button
          type="button"
          onClick={onDismiss}
          aria-label="بستنِ پیشنهادِ نصب"
          className="shrink-0 rounded-md p-1"
          style={{ color: 'var(--text-secondary)' }}
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>
    </motion.aside>
  )
}
