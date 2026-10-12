import { useMemo } from 'react'
import { encodeQr } from '@/shared/lib/qrCode'

/**
 * QR به‌صورتِ SVG (مرحلهٔ ۱۰ · فاز ۵) — یک مسیر، با حاشیه‌ی آرامِ چهارماژولی که استاندارد می‌خواهد.
 *
 * ⚠️ رنگ‌ها عمداً ثابت‌اند (`black` روی `white`)، نه توکنِ تم: اسکنرِ گوشی QR ِ روشن‌روی‌تیره‌ی حالتِ تیره
 * را همیشه نمی‌خواند، و همین QR چاپ و در لابی نصب می‌شود.
 */
export function QrCode({
  value,
  label,
  size = 224,
}: {
  value: string
  label: string
  size?: number
}) {
  const { path, extent } = useMemo(() => {
    const qr = encodeQr(value)
    const quiet = 4
    let d = ''
    qr.modules.forEach((row, y) =>
      row.forEach((dark, x) => {
        if (dark) d += `M${x + quiet} ${y + quiet}h1v1h-1z`
      }),
    )
    return { path: d, extent: qr.size + quiet * 2 }
  }, [value])

  return (
    <svg
      role="img"
      aria-label={label}
      width={size}
      height={size}
      viewBox={`0 0 ${extent} ${extent}`}
      shapeRendering="crispEdges"
      className="rounded-xl"
    >
      <rect width={extent} height={extent} fill="white" />
      <path d={path} fill="black" />
    </svg>
  )
}
