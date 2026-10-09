import { useState } from 'react'
import { Check, Copy, KeyRound } from 'lucide-react'
import { copyText } from '@/shared/lib/clipboard'

/**
 * پیوندِ یک‌بارمصرفِ دعوت — فقط **همین یک بار** (مرحلهٔ ۹ · فاز ۶).
 *
 * سرور خودِ توکن را نگه نمی‌دارد (فقط هشش)، پس این پنل بسته شود پیوند دیگر
 * دیده نمی‌شود؛ برای پیوندِ تازه دوباره دعوت می‌فرستند و قبلی باطل می‌شود.
 * پیامکِ سامانه فقط برای کدِ ورود و یادآورِ بدهی است، پس فرستادن با خودِ مدیر.
 */
export function InviteLinkPanel({
  link,
  phone,
  expiresAt,
  onDone,
}: {
  link: string
  phone: string | null
  expiresAt: string | null
  onDone: () => void
}) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    setCopied(await copyText(link))
  }

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm leading-7" style={{ color: 'var(--text-secondary)' }}>
        دعوت برای شماره‌ی{' '}
        <span className="font-bold tabular-nums" dir="ltr" style={{ color: 'var(--text-primary)' }}>
          {phone}
        </span>{' '}
        ساخته شد. این پیوند را خودتان برایش بفرستید؛ با همان شماره وارد یا ثبت‌نام می‌کند و با کدِ
        پیامکی می‌پذیرد.
      </p>

      <div className="flex items-stretch gap-2">
        <input
          readOnly
          value={link}
          dir="ltr"
          aria-label="پیوندِ دعوت"
          onFocus={(event) => event.currentTarget.select()}
          className="field min-w-0 flex-1 rounded-xl px-3 py-2.5 text-xs"
        />
        <button
          type="button"
          onClick={() => void copy()}
          className="flex shrink-0 items-center gap-1.5 rounded-xl px-3 text-xs font-bold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {copied ? <Check size={14} /> : <Copy size={14} />}
          {copied ? 'کپی شد' : 'کپی'}
        </button>
      </div>

      <p
        className="flex items-start gap-2 rounded-xl px-3 py-2.5 text-xs leading-6"
        style={{
          backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 10%, transparent)',
          color: 'var(--text-secondary)',
        }}
      >
        <KeyRound size={14} className="mt-1 shrink-0" style={{ color: 'var(--color-brand-500)' }} />
        <span>
          پیوند فقط همین حالا نشان داده می‌شود و یک بار کار می‌کند
          {expiresAt ? `؛ تا ${expiresAt} معتبر است` : ''}. به‌تنهایی دسترسی نمی‌دهد: پذیرش فقط از
          حسابِ همین شماره و با کدِ پیامکی است.
        </span>
      </p>

      <button
        type="button"
        onClick={onDone}
        className="rounded-xl border py-3 text-sm font-semibold"
        style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
      >
        تمام شد
      </button>
    </div>
  )
}
