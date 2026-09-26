import { useState } from 'react'
import { Link2, Share2 } from 'lucide-react'
import { copyText } from '@/shared/lib/clipboard'

/**
 * اشتراکِ مقاله (مرحلهٔ ۶ · فاز ۱۱).
 *
 * پیوندهای تلگرام و واتساپ و… در خودِ HTMLِ سرورند و بدونِ JS کار می‌کنند؛
 * این فقط دو چیزِ جاوااسکریپتی را اضافه می‌کند: برگه‌ی اشتراکِ خودِ گوشی
 * (`navigator.share`) و «کپیِ پیوند».
 *
 * ⚠️ نتیجه‌ی کپی **گفته می‌شود** (همان درسِ فاز ۸): شکستِ بی‌صدا یعنی
 * خواننده فکر کند پیوند را دارد.
 */
export function ShareButton({ url, title }: { url: string; title: string }) {
  const [status, setStatus] = useState<string | null>(null)
  const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function'

  async function copy() {
    setStatus((await copyText(url)) ? 'پیوند کپی شد.' : 'کپی نشد؛ پیوند را از نوارِ آدرس بردارید.')
  }

  async function share() {
    try {
      await navigator.share({ title, url })
    } catch {
      // کاربر برگه را بست — خطا نیست
    }
  }

  return (
    <span className="blog-share-actions">
      {canShare && (
        <button type="button" onClick={() => void share()}>
          <Share2 size={14} aria-hidden="true" /> اشتراک
        </button>
      )}
      <button type="button" onClick={() => void copy()}>
        <Link2 size={14} aria-hidden="true" /> کپیِ پیوند
      </button>
      <span role="status" className="blog-share-status">
        {status}
      </span>
    </span>
  )
}
