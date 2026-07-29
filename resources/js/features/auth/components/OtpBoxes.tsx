import { useEffect, useRef } from 'react'
import { toAsciiDigits } from '@/shared/lib/inputFilters'

interface OtpBoxesProps {
  value: string
  onChange: (value: string) => void
  /** وقتی هر شش رقم پر شد صدا زده می‌شود (برای ارسال خودکار). */
  onComplete?: (value: string) => void
  length?: number
  disabled?: boolean
  hasError?: boolean
  autoFocus?: boolean
}

/**
 * ورودیِ کد تاییدِ چندخانه‌ای.
 *
 * هر خانه یک رقم؛ تایپ خودکار به خانه‌ی بعد می‌رود، بک‌اسپیس به قبل، و
 * چسباندنِ کل کد همه را پر می‌کند. با پرشدنِ آخرین رقم `onComplete` صدا زده
 * می‌شود تا فرم بدون فشار دکمه خودش ارسال شود.
 *
 * چون کد عددی و LTR است، خانه‌ها صریحاً چپ‌به‌راست چیده می‌شوند تا ترتیب
 * ارقام در محیط راست‌چین به‌هم نریزد.
 */
export function OtpBoxes({
  value,
  onChange,
  onComplete,
  length = 6,
  disabled = false,
  hasError = false,
  autoFocus = false,
}: OtpBoxesProps) {
  const refs = useRef<(HTMLInputElement | null)[]>([])

  // آخرین نسخه‌ی کال‌بک‌ها، تا افکتِ WebOTP که یک‌بار اجرا می‌شود بسته‌ی کهنه نگیرد
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange
  const onCompleteRef = useRef(onComplete)
  onCompleteRef.current = onComplete

  useEffect(() => {
    if (autoFocus) refs.current[0]?.focus()
  }, [autoFocus])

  /*
   * پرشدنِ خودکارِ کد از پیامک (فقط وقتی کاربر با گوشی وارد شده).
   *
   * WebOTP کدِ داخلِ پیامکی را که با خطِ «@دامنه #کد» تمام می‌شود می‌خواند و
   * بدون تایپِ دستی در خانه‌ها می‌گذارد. روی دسکتاپ یا مرورگرِ ناپشتیبان،
   * `OTPCredential` وجود ندارد و این بخش بی‌اثر است، پس کاربرِ سیستم خودش کد
   * را وارد می‌کند. (iOS جداگانه با autocomplete=one-time-code کار می‌کند.)
   */
  useEffect(() => {
    if (typeof window === 'undefined' || !('OTPCredential' in window)) return

    const controller = new AbortController()
    ;(navigator.credentials as unknown as {
      get: (o: unknown) => Promise<{ code?: string } | null>
    })
      .get({ otp: { transport: ['sms'] }, signal: controller.signal })
      .then((cred) => {
        const code = String(cred?.code ?? '').replace(/\D/g, '').slice(0, length)
        if (!code) return
        onChangeRef.current(code)
        if (code.length === length) onCompleteRef.current?.(code)
      })
      .catch(() => {
        // کاربر رد کرد یا پیامکی نرسید — بی‌صدا نادیده می‌گیریم
      })

    return () => controller.abort()
  }, [length])

  const digits = value.padEnd(length, ' ').slice(0, length).split('')

  function setDigit(index: number, raw: string) {
    const clean = toAsciiDigits(raw).replace(/\D/g, '')

    // چسباندن: از همین خانه به بعد پر می‌شود
    if (clean.length > 1) {
      const next = (value.slice(0, index) + clean).replace(/\s/g, '').slice(0, length)
      onChange(next)
      const focusAt = Math.min(next.length, length - 1)
      refs.current[focusAt]?.focus()
      if (next.length === length) onComplete?.(next)
      return
    }

    const chars = value.padEnd(length, ' ').split('')
    chars[index] = clean || ' '
    const next = chars.join('').replace(/\s+$/, '')
    onChange(next.trimEnd())

    if (clean && index < length - 1) refs.current[index + 1]?.focus()

    const filled = next.replace(/\s/g, '')
    if (filled.length === length) onComplete?.(filled)
  }

  function onKeyDown(index: number, e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && !digits[index]?.trim() && index > 0) {
      refs.current[index - 1]?.focus()
    }
  }

  return (
    <div className="flex justify-center gap-2" dir="ltr">
      {Array.from({ length }).map((_, i) => (
        <input
          key={i}
          ref={(el) => {
            refs.current[i] = el
          }}
          value={digits[i]?.trim() ?? ''}
          onChange={(e) => setDigit(i, e.target.value)}
          onKeyDown={(e) => onKeyDown(i, e)}
          onFocus={(e) => e.target.select()}
          inputMode="numeric"
          autoComplete={i === 0 ? 'one-time-code' : 'off'}
          maxLength={1}
          disabled={disabled}
          aria-label={`رقم ${i + 1} کد`}
          className="h-12 w-11 rounded-xl border text-center text-lg font-bold outline-none transition-all focus:ring-2 disabled:opacity-60"
          style={{
            backgroundColor: 'var(--surface-sunken)',
            borderColor: hasError ? 'var(--color-danger)' : 'var(--border-subtle)',
            color: 'var(--text-primary)',
            ['--tw-ring-color' as string]: hasError ? 'var(--color-danger)' : 'var(--ring-focus)',
          }}
        />
      ))}
    </div>
  )
}
