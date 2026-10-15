import { useEffect, useState } from 'react'
import { formatNumber } from '@/shared/lib/format'

/**
 * حضورِ آنلاین — سمتِ کلاینت («چند نکته»، بندِ ۳).
 *
 * سرور نمی‌گوید «آنلاین است»، می‌گوید «آنلاین تا» (`onlineUntil` / `peerOnlineUntil`، ثانیه‌ی یونیکس) و ساعتِ خودش
 * (`serverTime`). این‌جا همان لحظه، **بی هیچ درخواستِ تازه**، نشان خاموش می‌شود — پس پاسخِ کهنه‌ای که دیگر تازه
 * نمی‌شود (تبِ پنهان، قطعیِ شبکه) هرگز «آنلاینِ کهنه» نشان نمی‌دهد. اختلافِ ساعتِ دستگاه با سرور جبران می‌شود.
 * تعریف و مرزِ دیدن سمتِ سرور است (`App\Services\Messenger\Presence`)؛ حضور هیچ دکمه و دسترسی‌ای را عوض نمی‌کند.
 */

/** اختلافِ ساعتِ سرور با این دستگاه (میلی‌ثانیه) — همان لحظه‌ی رسیدنِ پاسخ، نه هنگامِ رندر */
export function clockOffset(serverTime: number | undefined): number {
  return serverTime ? serverTime * 1000 - Date.now() : 0
}

/** «آنلاین تا» هنوز نگذشته؟ با یک زمان‌سنج سرِ همان لحظه خاموش می‌شود. */
export function useOnline(until: number | null | undefined, offsetMs: number): boolean {
  const [expired, setExpired] = useState<number | null>(null)

  useEffect(() => {
    if (!until) return
    const remaining = until * 1000 - (Date.now() + offsetMs)
    const timer = window.setTimeout(() => setExpired(until), Math.max(0, remaining))
    return () => window.clearTimeout(timer)
  }, [until, offsetMs])

  return Boolean(until) && expired !== until
}

/** نقطه‌ی سبزِ «آنلاین» کنارِ نام؛ آفلاین هیچ — نه نقطه‌ی خاکستری که «آخرین بازدید» را حدس بزند. */
export function OnlineDot({
  until,
  offsetMs,
  className = '',
}: {
  until: number | null | undefined
  offsetMs: number
  className?: string
}) {
  const online = useOnline(until, offsetMs)
  if (!online) return null

  return (
    <span
      role="img"
      aria-label="آنلاین"
      title="آنلاین"
      data-presence="online"
      className={`inline-block size-2 shrink-0 rounded-full ${className}`}
      style={{ backgroundColor: 'var(--color-success)' }}
    />
  )
}

/** «آنلاین» زیرِ نامِ طرفِ گفت‌وگوی یک‌به‌یک */
export function OnlineLabel({
  until,
  offsetMs,
}: {
  until: number | null | undefined
  offsetMs: number
}) {
  const online = useOnline(until, offsetMs)
  if (!online) return null

  return (
    <span
      data-presence="online"
      className="flex items-center gap-1 text-tiny"
      style={{ color: 'var(--color-success)' }}
    >
      <span
        aria-hidden="true"
        className="inline-block size-1.5 rounded-full"
        style={{ backgroundColor: 'var(--color-success)' }}
      />
      آنلاین
    </span>
  )
}

/**
 * شمارِ آنلاینِ گروه تا کی معتبر است (ثانیه پس از `serverTime`). رشته‌ی باز هر ۸ ثانیه و پنلِ اعضا هر ۳۰ ثانیه
 * تازه‌اش می‌کنند؛ اگر پرسش‌ها بایستند (قطعیِ شبکه)، عددِ کهنه به‌جای «آنلاین» نمی‌ماند.
 */
export const COUNT_FRESH_SECONDS = 45

/** «۳ آنلاین» ِ گروه — از `onlineCount` ِ سرور؛ این‌جا چیزی شمرده نمی‌شود */
export function OnlineCount({
  count,
  serverTime,
  offsetMs,
}: {
  count: number | null | undefined
  serverTime: number | undefined
  offsetMs: number
}) {
  const fresh = useOnline(serverTime ? serverTime + COUNT_FRESH_SECONDS : null, offsetMs)
  if (!fresh || !count) return null

  return (
    <span data-presence="count" className="text-tiny" style={{ color: 'var(--color-success)' }}>
      {formatNumber(count)} آنلاین
    </span>
  )
}
