import { Info, Share } from 'lucide-react'
import type { InstallGuide } from './installState'
import { GUIDE_STEPS } from './installGuides'

/** چرا دکمه‌ی نصب پنجره‌ی مرورگر را باز نکرد */
export type InstallHelpKind = InstallGuide | 'unavailable' | 'dismissed'

/**
 * راهنمای کنارِ دکمه‌ی نصبِ Hero (مرحلهٔ ۱۳ · فاز ۲) — **تنبل**: فقط وقتی کسی دکمه را می‌زند و مرورگر نصبِ مستقیم را
 * پیشنهاد نداده.
 *
 * هیچ‌کدام ادعای نصب نمی‌کند: راهنمای iOS و سافاری و فایرفاکسِ اندروید مشروط است («اگر هنوز…»)، چون آن مرورگرها
 * نمی‌گویند ساکنا نصب است یا نه؛ «در دسترس نیست» فقط راهِ دیگر را می‌گوید؛ «انصراف» فقط می‌گوید نصب انجام نشد.
 */
export default function InstallHelp({
  kind,
  onClose,
}: {
  kind: InstallHelpKind
  onClose: () => void
}) {
  return (
    <div
      id="hero-install-help"
      role="region"
      aria-label="راهنمای نصبِ اپلیکیشن"
      data-testid="hero-install-help"
      className="flex basis-full items-start gap-2.5 rounded-2xl border p-3.5 text-xs leading-6"
      style={{
        borderColor: 'var(--border-subtle)',
        backgroundColor: 'var(--surface-base)',
        color: 'var(--text-secondary)',
      }}
    >
      <span className="mt-0.5 shrink-0" style={{ color: 'var(--color-brand-600)' }} aria-hidden>
        {kind === 'unavailable' || kind === 'dismissed' ? <Info size={16} /> : <Share size={16} />}
      </span>
      <p className="flex-1">
        {kind === 'dismissed' ? (
          <>
            نصب انجام نشد. هر وقت خواستید دوباره همین دکمه یا نشانِ نصب در نوارِ آدرسِ مرورگر را
            بزنید.
          </>
        ) : kind === 'unavailable' ? (
          <>
            این مرورگر همین حالا نصبِ مستقیم را پیشنهاد نمی‌دهد. در کروم یا اج (اندروید و رایانه) از
            نشانِ نصب در نوارِ آدرس یا منوی مرورگر استفاده کنید؛ در آیفون و آیپد با سافاری. اگر
            ساکنا را قبلاً نصب کرده‌اید، از همان آیکون بازش کنید.
          </>
        ) : (
          <>اگر هنوز نصبش نکرده‌اید: {GUIDE_STEPS[kind]}</>
        )}
      </p>
      <button
        type="button"
        onClick={onClose}
        className="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold"
        style={{ color: 'var(--color-brand-600)' }}
      >
        متوجه شدم
      </button>
    </div>
  )
}
