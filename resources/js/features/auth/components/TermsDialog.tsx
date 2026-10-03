/*
 * ⚠️ `?raw` عمدی است: با importِ عادی، همین JSON بینِ صفحه‌ی پشتیبانی (ایستا)
 * و این پنجره (تنبل) مشترک می‌شد و چانکِ جدای خودش را می‌گرفت — یک فایلِ
 * دیگر در بارگذاریِ اولِ پشتیبانی و بالای سقفِ فایل‌های نشست. نسخه‌ی خام فقط
 * در چانکِ تنبلِ همین پنجره می‌نشیند؛ منبع همان فایل است.
 */
import raw from '@/../content/support-topics.json?raw'
import { Fragment, type ReactNode } from 'react'
import { Modal } from '@/shared/ui/Modal'

/**
 * قوانین و مقررات، درونِ همان صفحه‌ی ثبت‌نام (مرحلهٔ ۷ · فاز ۶).
 *
 * ─── چرا پنجره و نه پیوند به صفحه‌ی پشتیبانی ───────────────────────────────
 * پیوندِ قبلی `target="_blank"` بود. وقتی ساکنا نصب شده باشد، کروم پیوندی
 * را که **ناوبریِ تازه** می‌سازد و داخلِ دامنه‌ی manifest است (`scope: "/"`)
 * به پنجره‌ی اپِ نصب‌شده می‌برد («link capturing»)؛ در خودِ اپِ نصب‌شده هم
 * همان پیوند پنجره‌ی اپیِ بی‌نوارِ مرورگر باز می‌کرد. یعنی قوانین در «وب‌اپ»
 * باز می‌شد، نه در وبِ عمومی. دامنه‌ی manifest را نمی‌شود تنگ کرد: داشبورد هم
 * در ریشه است.
 *
 * پنجره هیچ ناوبری‌ای نمی‌سازد، پس چیزی برای ربودن نمی‌ماند — در مرورگر، در
 * اپِ نصب‌شده، روی موبایل و دسکتاپ. فرمِ نیمه‌پرِ ثبت‌نام هم از دست نمی‌رود.
 *
 * متن از **همان** `support-topics.json` می‌آید که صفحه‌ی پشتیبانی و FAQِ گوگل
 * می‌خوانند — نسخه‌ی دوم ساخته نشد. این ماژول تنبل است و فقط با بازکردنِ
 * پنجره دانلود می‌شود.
 */
interface Entry {
  question: string
  answer: string
}

/**
 * بندهای قوانین از متنِ خام — با **بررسی**، نه `as`.
 *
 * فایل مالِ خودِ پروژه است، ولی شکلش در زمانِ ساخت سنجیده نمی‌شود؛ اگر روزی
 * ساختارش عوض شود، پنجره باید خالی بماند نه اینکه رندر بترکد
 * (`typeSafety.test.ts`: هیچ `JSON.parse`ِ بی‌بررسی).
 */
function readTerms(text: string): { title: string; entries: Entry[] } | null {
  const data: unknown = JSON.parse(text)
  if (typeof data !== 'object' || data === null || !('topics' in data)) return null
  const topics = data.topics
  if (!Array.isArray(topics)) return null

  for (const topic of topics as unknown[]) {
    if (typeof topic !== 'object' || topic === null) continue
    if (!('id' in topic) || topic.id !== 'terms') continue
    if (!('entries' in topic) || !Array.isArray(topic.entries)) return null

    const entries = (topic.entries as unknown[]).filter(
      (entry): entry is Entry =>
        typeof entry === 'object' &&
        entry !== null &&
        'question' in entry &&
        'answer' in entry &&
        typeof entry.question === 'string' &&
        typeof entry.answer === 'string',
    )
    const title = 'title' in topic && typeof topic.title === 'string' ? topic.title : ''

    return { title, entries }
  }

  return null
}

const terms = readTerms(raw)

/**
 * `**این**` → پررنگ؛ همان قاعده‌ی `emphasize`ِ صفحه‌ی پشتیبانی.
 *
 * ⚠️ عمداً نسخه‌ی خودش، نه importِ آن: هر ماژولِ مشترک میانِ بارگذاریِ اولِ
 * پشتیبانی و این چانکِ تنبل، فایلِ جدای خودش را می‌گرفت (‎۰٫۲۶KB) و از سقفِ
 * فایل‌های نشست می‌گذشت. هشت خط تکرار ارزان‌تر از یک درخواستِ اضافه برای همه
 * است. خروجی فقط گره‌ی متنیِ React است؛ هیچ HTMLی از متن ساخته نمی‌شود —
 * `tests/js/termsDialog.test.tsx` همین را می‌سنجد.
 */
function bold(text: string): ReactNode[] {
  return text.split(/\*\*(.+?)\*\*/g).map((part, index) =>
    index % 2 === 1 ? (
      <strong key={index} style={{ color: 'var(--text-primary)' }}>
        {part}
      </strong>
    ) : (
      <Fragment key={index}>{part}</Fragment>
    ),
  )
}

export default function TermsDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  return (
    <Modal open={open} title={terms?.title || 'قوانین و مقررات'} onClose={onClose}>
      <div dir="rtl" className="flex flex-col gap-4" data-testid="terms-dialog">
        {terms?.entries.map((entry) => (
          <section key={entry.question}>
            <h3 className="text-[13.5px] font-bold" style={{ color: 'var(--text-primary)' }}>
              {entry.question}
            </h3>
            <p className="mt-1.5 text-[13px] leading-7" style={{ color: 'var(--text-secondary)' }}>
              {bold(entry.answer)}
            </p>
          </section>
        ))}

        <button
          type="button"
          onClick={onClose}
          className="mt-1 self-start rounded-xl px-4 py-2 text-[13px] font-semibold text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          بازگشت به ثبت‌نام
        </button>
      </div>
    </Modal>
  )
}
