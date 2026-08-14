/**
 * تکه‌ای از ماشین‌حساب که از `CalculatorPage.tsx` بیرون کشیده شد (R39 · آیتم ⑤).
 *
 * ⚠️ این جابه‌جایی عمداً **هیچ منطقی را عوض نمی‌کند**: فایلِ اصلی ۹۶۸ خط بود
 * و همه‌ی زیرکامپوننت‌هایش را هم داخلِ خودش داشت. تجزیه از قبل انجام شده
 * بود؛ چیزی که نبود، مرزِ فایلی بود.
 */

import { motion } from 'framer-motion'
import { Copy } from 'lucide-react'
import { toPersianDigits } from '@/shared/lib/format'
import { formatResult, normalizeDigits, type AngleMode } from '@/shared/lib/calculator'

export function displayStatus(
  error: string | null,
  result: string,
  preview: string,
): { kind: 'error' | 'result' | 'preview'; text: string } | null {
  if (error) return { kind: 'error', text: error }
  if (result) return { kind: 'result', text: result }
  if (preview) return { kind: 'preview', text: preview }

  return null
}

/** نویسه‌های مجازِ تایپ با کیبورد: رقم (فارسی/لاتین)، ممیز، فاصله و نمادها.
    توابع (sin و…) عمداً نیستند؛ فقط با دکمه‌های ماشین حساب درج می‌شوند. */
const ALLOWED_INPUT = /[^0-9۰-۹٠-٩.+\-*/%^()\s]/

/**
 * متنِ چسبانده‌شده را پاک‌سازی می‌کند: ارقام فارسی به لاتین و هر نویسه‌ی
 * غیرمجاز حذف می‌شود. برخلاف تایپ (که نویسه‌به‌نویسه فیلتر می‌شود)، paste را
 * کامل رد نمی‌کنیم؛ چون «۱۲+۳» معتبر را هم رد می‌کرد و کاربر گیج می‌شد. حالا
 * بخش معتبرش درج می‌شود و بقیه دور ریخته می‌شود.
 */
function sanitizePaste(text: string): string {
  return normalizeDigits(text).replace(/[^0-9.+\-*/%^()\s]/g, '')
}

/** عبارت را برای نمایش زیر نتیجه زیباتر می‌کند: × ÷ − و ارقام فارسی. */
function prettyExpr(expr: string): string {
  return toPersianDigits(expr.replace(/\*/g, '×').replace(/\//g, '÷').replace(/-/g, '−'))
}

export function Display({
  expression,
  onExpressionChange,
  inputRef,
  result,
  resultExpr,
  preview,
  error,
  memory,
  angleMode,
  onCopy,
  onInsert,
}: {
  expression: string
  onExpressionChange: (value: string) => void
  inputRef: React.RefObject<HTMLInputElement | null>
  result: string
  resultExpr: string
  preview: string
  error: string | null
  memory: number
  angleMode: AngleMode
  onCopy: () => void
  onInsert: (text: string) => void
}) {
  const status = displayStatus(error, result, preview)

  return (
    /*
     * مانیتور چسبان: با اسکرولِ صفحه سرِ جای خود بالای صفحه می‌ماند تا عبارت
     * و نتیجه همیشه دیده شوند.
     *
     * دو نکته‌ی چیدمانی:
     * ۱) margin منفی افقی، لفافه را تا لبه‌های کارت پهن می‌کند تا محتوای
     *    پشتش از کنارها بیرون نزند.
     * ۲) `box-shadow` با آفستِ منفی و بدون بلور، نواری هم‌رنگِ کارت بالای
     *    لفافه می‌کشد. بدون آن، بینِ نوارِ بالای صفحه و مانیتور یک شکاف شفاف
     *    می‌ماند (به اندازه‌ی padding خودِ ناحیه‌ی اسکرول) و دکمه‌هایی مثل
     *    sin و cos هنگام اسکرول از داخل همان شکاف دیده می‌شدند. ارتفاع ۲rem
     *    هر دو حالت p-4 موبایل و p-6 دسکتاپ را می‌پوشاند.
     */
    <div
      className="sticky top-0 z-20 -mx-5 px-5 pb-2 pt-1"
      style={{
        backgroundColor: 'var(--surface-base)',
        boxShadow: '0 -2rem 0 var(--surface-base)',
      }}
    >
      <div
        className="rounded-2xl border p-4 shadow-sm"
        style={{ backgroundColor: 'var(--surface-sunken)', borderColor: 'var(--border-subtle)' }}
      >
        <div
          className="mb-2 flex items-center gap-2 text-[10.5px]"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <span
            className="rounded px-1.5 py-0.5 font-bold"
            style={{ backgroundColor: 'var(--surface-base)' }}
          >
            {angleMode === 'deg' ? 'DEG' : 'RAD'}
          </span>
          {memory !== 0 && (
            <span
              className="rounded px-1.5 py-0.5 font-bold"
              style={{ backgroundColor: 'var(--surface-base)', color: 'var(--color-brand-600)' }}
            >
              M
            </span>
          )}

          <button
            onClick={onCopy}
            disabled={!result && !preview}
            className="mr-auto flex items-center gap-1 rounded px-1.5 py-0.5 transition-colors enabled:hover:bg-(--surface-base) disabled:opacity-40"
            title="کپی نتیجه"
          >
            <Copy size={11} />
            کپی
          </button>
        </div>

        {/*
        عبارت در یک input واقعی است نه فقط متن: کاربر باید بتواند وسط عبارت
        کلیک کند، تکه‌ای را انتخاب کند و مستقیم تایپ کند. dir=ltr چون فرمول
        ریاضی چپ‌به‌راست خوانده می‌شود حتی در صفحه‌ی راست‌به‌چپ.
      */}
        <input
          ref={inputRef}
          dir="ltr"
          value={expression}
          onChange={(event) => onExpressionChange(normalizeDigits(event.target.value))}
          onBeforeInput={(event) => {
            // جلوی تایپِ نویسه‌به‌نویسه‌ی حرف (مثل sin) را می‌گیرد؛ Enter و = را
            // شنونده‌ی سراسری صفحه‌کلید مدیریت می‌کند، نه اینجا. (paste را
            // onPaste جدا و با پاک‌سازی مدیریت می‌کند.)
            const data = event.nativeEvent.data
            if (data && ALLOWED_INPUT.test(data)) {
              event.preventDefault()
            }
          }}
          onPaste={(event) => {
            // به‌جای رد کردنِ کلِ paste، فقط بخش معتبرش درج می‌شود
            event.preventDefault()
            const pasted = event.clipboardData.getData('text')
            const clean = sanitizePaste(pasted)
            if (clean) onInsert(clean)
          }}
          placeholder="0"
          inputMode="numeric"
          aria-label="عبارت ریاضی"
          spellCheck={false}
          className="w-full bg-transparent text-left font-mono text-[26px] font-bold outline-none"
          style={{ color: 'var(--text-primary)' }}
        />

        {/*
        یک عنصر واحد که با key عوض می‌شود، نه سه شاخهٔ جدا داخل
        AnimatePresence با mode="wait".
        دلیل: پیام خطا باید فوراً دیده شود. با mode="wait" نمایش خطا تا پایان
        انیمیشن خروجِ نتیجه‌ی قبلی عقب می‌افتاد، و اگر انیمیشن به هر دلیلی
        تمام نمی‌شد اصلاً نمایش داده نمی‌شد. این شکل، وابسته به تمام شدن
        انیمیشن نیست.
      */}
        <div className="mt-1 flex min-h-[26px] flex-col items-end justify-center">
          {status && (
            <motion.p
              key={`${status.kind}-${status.text}`}
              initial={{ opacity: 0, y: -3 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.18 }}
              dir={status.kind === 'error' ? undefined : 'ltr'}
              className={
                status.kind === 'error'
                  ? 'text-[12.5px] font-semibold'
                  : status.kind === 'result'
                    ? 'font-mono text-[22px] font-extrabold'
                    : 'font-mono text-[15px]'
              }
              style={{
                color:
                  status.kind === 'error'
                    ? 'var(--color-danger)'
                    : status.kind === 'result'
                      ? 'var(--color-brand-600)'
                      : 'var(--text-tertiary)',
              }}
            >
              {status.kind === 'error' ? status.text : `= ${toPersianDigits(status.text)}`}
            </motion.p>
          )}

          {/* محاسبه‌ای که به این نتیجه رسید، درست زیرِ خودِ نتیجه */}
          {status?.kind === 'result' && resultExpr && (
            <motion.p
              key={`src-${resultExpr}`}
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              dir="ltr"
              className="mt-0.5 font-mono text-[12.5px]"
              style={{ color: 'var(--text-tertiary)' }}
            >
              {prettyExpr(resultExpr)}
            </motion.p>
          )}
        </div>
      </div>
    </div>
  )
}

/* -------------------------------- حافظه -------------------------------- */

export function MemoryRow({
  memory,
  onRecall,
  onClear,
  onAdd,
  onSubtract,
  onStore,
}: {
  memory: number
  onRecall: () => void
  onClear: () => void
  onAdd: () => void
  onSubtract: () => void
  onStore: () => void
}) {
  const buttons: [string, () => void, string][] = [
    ['MC', onClear, 'پاک کردن حافظه'],
    ['MR', onRecall, 'فراخوانی حافظه'],
    ['M+', onAdd, 'افزودن به حافظه'],
    ['M−', onSubtract, 'کم کردن از حافظه'],
    ['MS', onStore, 'ذخیره در حافظه'],
  ]

  return (
    <div className="mt-3 flex flex-wrap items-center gap-1.5">
      {buttons.map(([label, handler, title]) => (
        <button
          key={label}
          onClick={handler}
          title={title}
          className="rounded-lg border px-2.5 py-1.5 font-mono text-[11.5px] font-bold transition-colors hover:bg-(--surface-sunken)"
          style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
        >
          {label}
        </button>
      ))}

      {/* فقط وقتی حافظه مقدار دارد نشان داده می‌شود؛ «حافظه: ۰» همیشگی نویز بود. */}
      {memory !== 0 && (
        <span
          className="mr-auto font-mono text-[11px] tabular-nums"
          style={{ color: 'var(--color-brand-600)' }}
        >
          حافظه: {toPersianDigits(formatResult(memory))}
        </span>
      )}
    </div>
  )
}

/* ------------------------------- کلیدها -------------------------------- */
