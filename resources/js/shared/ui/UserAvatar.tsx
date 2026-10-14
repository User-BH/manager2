import { useState } from 'react'

/**
 * تصویرِ پروفایلِ یک حساب (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۵): همان تصویرِ canonical در منوی کاربر، پروفایل و پیام‌رسان.
 *
 * بی تصویر — یا اگر بار نشد (مثلاً دیگر حقِ دیدنش را ندارید) — حرفِ اولِ نام روی رنگِ برند، بی هیچ درخواستِ دیگری.
 * نشانیِ تصویر نسخه دارد (`?v=`)، پس تعویضِ تصویر همان لحظه همه‌جا دیده می‌شود.
 *
 * ⚠️ عمداً به `ContactAvatar` ِ صفحه‌ی فرود وابسته نیست: آن وابستگی همان ماژول را میانِ صفحه‌ی فرود و داشبورد مشترک
 * و به چانکِ ریزِ جدایی تبدیل می‌کرد (بودجه‌ی «فایلِ ریز» در `bundleBudget`).
 */
export function UserAvatar({
  name,
  url,
  size = 32,
  className,
}: {
  name: string
  url: string | null | undefined
  size?: number
  className?: string
}) {
  const [failed, setFailed] = useState<string | null>(null)
  const classes = ['shrink-0 rounded-full', className].filter(Boolean).join(' ')

  if (!url || failed === url) {
    return (
      <span
        role="img"
        aria-label={name}
        className={`${classes} inline-flex items-center justify-center font-bold text-white`}
        style={{
          width: size,
          height: size,
          fontSize: Math.round(size * 0.45),
          backgroundColor: 'var(--color-brand-500)',
        }}
      >
        {name.trim().charAt(0) || '؟'}
      </span>
    )
  }

  return (
    <img
      src={url}
      alt={name}
      width={size}
      height={size}
      loading="lazy"
      decoding="async"
      onError={() => setFailed(url)}
      className={`${classes} object-cover`}
      style={{ width: size, height: size }}
    />
  )
}
