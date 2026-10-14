import { Link } from 'react-router-dom'
import { ArrowLeft, Building2 } from 'lucide-react'

/**
 * یادآوریِ «… را تکمیل کنید» (مرحلهٔ ۱۰ · فاز ۷؛ بندهای ۷ و ۹) — برای مدیر «اطلاعاتِ مجتمع» و برای عضو
 * «اطلاعاتِ واحد». تا نخستین تکمیل (از سرور: `information_completed_at`) نشان داده می‌شود؛ اجباری نیست و
 * صفحه را نمی‌بندد.
 */
export function CompletionPrompt({ title, text, to }: { title: string; text: string; to: string }) {
  return (
    <section
      role="status"
      aria-label={title}
      className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4"
      style={{ borderColor: 'var(--color-brand-500)', backgroundColor: 'var(--surface-base)' }}
    >
      <div className="flex items-start gap-3">
        <Building2
          size={20}
          className="mt-0.5 shrink-0"
          style={{ color: 'var(--color-brand-500)' }}
        />
        <div>
          <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
            {title}
          </p>
          <p className="mt-0.5 text-xs leading-6" style={{ color: 'var(--text-secondary)' }}>
            {text}
          </p>
        </div>
      </div>
      <Link
        to={to}
        className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold text-white"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        تکمیل
        <ArrowLeft size={14} />
      </Link>
    </section>
  )
}
