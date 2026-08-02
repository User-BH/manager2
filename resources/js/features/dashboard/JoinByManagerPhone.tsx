import { useEffect, useState } from 'react'
import { Building2, Check, Loader2, Send, X } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { useAction } from '@/shared/hooks/useAction'
import { toAsciiDigits } from '@/shared/lib/inputFilters'

interface LookupResult {
  found: boolean
  complexName: string | null
}

/** پاسخِ سرور، همراهِ شماره‌ای که برایش پرسیده شده. */
interface Answer extends LookupResult {
  phone: string
}

const PHONE_PATTERN = /^09\d{9}$/

/**
 * پیوستن با شماره‌ی موبایلِ مدیر (R21b).
 *
 * ─── چرا اعتبارسنجیِ لحظه‌ای ───────────────────────────────────────────────
 * کاربر دارد **نام و شماره‌ی خودش** را برای کسی می‌فرستد. اگر شماره را
 * اشتباه بزند، آن اطلاعات به مجتمعِ غریبه می‌رود و دیگر برنمی‌گردد. پس پیش
 * از فعال‌شدنِ دکمه، سرور تایید می‌کند که شماره واقعاً مالِ مدیرِ یک مجتمع
 * است و **نامِ همان مجتمع** زیرِ اینپوت نوشته می‌شود تا کاربر خودش تشخیص
 * بدهد درست است یا نه.
 */
export function JoinByManagerPhone() {
  const [phone, setPhone] = useState('')
  const [answer, setAnswer] = useState<Answer | null>(null)
  const [sent, setSent] = useState(false)
  const { run, pendingKey } = useAction()

  const complete = PHONE_PATTERN.test(phone)

  /*
   * پاسخ همراهِ شماره‌ای که برایش گرفته شده نگه داشته می‌شود، و فقط وقتی
   * نمایش داده می‌شود که با شماره‌ی فعلی بخواند.
   *
   * ساده‌تر این بود که در افکت `setResult(null)` بزنیم، ولی آن هم رندرِ
   * آبشاری می‌سازد و هم لحظه‌ای پاسخِ شماره‌ی قبلی را زیرِ شماره‌ی جدید نشان
   * می‌دهد. مشتق‌کردن هر دو را حل می‌کند.
   */
  const current = answer?.phone === phone ? answer : null
  const checking = complete && current === null

  useEffect(() => {
    if (!complete) return

    /*
     * مکثِ کوتاه پیش از پرسیدن: بدونِ آن هر کاراکتر یک درخواست می‌شد و
     * محدودیتِ نرخِ سرور کاربرِ کاملاً عادی را می‌بست.
     */
    const controller = new AbortController()
    const asked = phone

    const timer = setTimeout(() => {
      api<LookupResult>(`/join-requests/lookup?phone=${asked}`, { signal: controller.signal })
        .then((data) => setAnswer({ ...data, phone: asked }))
        .catch(() => setAnswer({ found: false, complexName: null, phone: asked }))
    }, 400)

    return () => {
      clearTimeout(timer)
      controller.abort()
    }
  }, [phone, complete])

  if (sent) {
    return (
      <div
        className="flex items-start gap-3 rounded-2xl border p-4"
        style={{ borderColor: 'var(--color-brand-500)', backgroundColor: 'var(--surface-base)' }}
        role="status"
      >
        <Check size={18} className="mt-0.5 shrink-0" style={{ color: 'var(--color-brand-500)' }} />
        <p className="text-[13px] leading-6" style={{ color: 'var(--text-secondary)' }}>
          درخواست شما ارسال شد. به‌محض تایید مدیر، به مجتمع اضافه می‌شوید.
        </p>
      </div>
    )
  }

  return (
    <section
      className="flex flex-col gap-3 rounded-2xl border p-4"
      style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
    >
      <div>
        <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
          شماره‌ی مدیر ساختمان را دارید؟
        </p>
        <p className="mt-1 text-[13px] leading-6" style={{ color: 'var(--text-secondary)' }}>
          شماره‌ی موبایل مدیر مجتمع را وارد کنید تا درخواست عضویت برایش فرستاده شود.
        </p>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="manager-phone" className="sr-only">
          شماره موبایل مدیر مجتمع
        </label>
        <input
          id="manager-phone"
          type="text"
          inputMode="numeric"
          maxLength={11}
          placeholder="۰۹xxxxxxxxx"
          value={phone}
          onChange={(event) => setPhone(toAsciiDigits(event.target.value).replace(/\D/g, ''))}
          className="rounded-xl border px-3.5 py-2.5 text-sm tabular-nums outline-none"
          style={{
            borderColor: 'var(--border-default)',
            backgroundColor: 'var(--surface-sunken)',
            color: 'var(--text-primary)',
          }}
          aria-describedby="manager-phone-status"
        />

        {/* وضعیتِ زیرِ اینپوت: همان چیزی که کاربر باید پیش از ارسال ببیند */}
        <p id="manager-phone-status" className="min-h-5 text-xs" aria-live="polite">
          {!complete ? (
            <span style={{ color: 'var(--text-tertiary)' }}>
              شماره‌ی ۱۱ رقمی را کامل وارد کنید.
            </span>
          ) : checking ? (
            <span
              className="inline-flex items-center gap-1.5"
              style={{ color: 'var(--text-tertiary)' }}
            >
              <Loader2 size={12} className="animate-spin" />
              در حال بررسی…
            </span>
          ) : current?.found ? (
            <span
              className="inline-flex items-center gap-1.5 font-semibold"
              style={{ color: 'var(--color-brand-600)' }}
            >
              <Building2 size={12} />
              {current.complexName}
            </span>
          ) : current ? (
            <span
              className="inline-flex items-center gap-1.5"
              style={{ color: 'var(--color-danger)' }}
            >
              <X size={12} />
              این شماره متعلق به هیچ مدیر مجتمعی نیست.
            </span>
          ) : null}
        </p>
      </div>

      <button
        type="button"
        // دکمه فقط وقتی باز می‌شود که سرور مجتمع را تایید کرده باشد
        disabled={!current?.found || pendingKey !== null}
        onClick={() =>
          void run(() => api('/join-requests', { method: 'POST', body: { phone } }), {
            key: 'join',
            onDone: () => setSent(true),
          })
        }
        className="self-start rounded-xl px-4 py-2 text-xs font-bold text-white transition-opacity disabled:cursor-not-allowed disabled:opacity-45"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        <span className="inline-flex items-center gap-1.5">
          <Send size={14} />
          ارسال درخواست
        </span>
      </button>
    </section>
  )
}
