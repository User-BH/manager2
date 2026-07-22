import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import {
  Calculator as CalculatorIcon,
  Copy,
  Delete,
  History,
  Search,
  Trash2,
} from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { useDocumentTitle, useDebounce, useLocalStorage } from '@/hooks'
import { confirmAction, toastSuccess } from '@/lib/alert'
import {
  CalculationError,
  evaluate,
  formatResult,
  normalizeDigits,
  type AngleMode,
} from '@/lib/calculator'
import { formatJalaliDateTime, formatRelative, toPersianDigits } from '@/lib/format'
import { cn } from '@/lib/cn'

interface HistoryEntry {
  id: string
  expression: string
  result: string
  angleMode: AngleMode
  /** میلی‌ثانیه‌ی یونیکس؛ تاریخ شمسی هنگام نمایش ساخته می‌شود. */
  at: number
}

const HISTORY_KEY = 'app:calculator-history'
const MEMORY_KEY = 'app:calculator-memory'
const MAX_HISTORY = 200

export function CalculatorPage() {
  const [expression, setExpression] = useState('')
  const [result, setResult] = useState('')
  // عبارتی که نتیجه‌ی فعلی را ساخت؛ زیرِ نتیجه نشان داده می‌شود
  const [resultExpr, setResultExpr] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [angleMode, setAngleMode] = useState<AngleMode>('deg')
  const [secondFn, setSecondFn] = useState(false)
  const [historyQuery, setHistoryQuery] = useState('')

  const [history, setHistory] = useLocalStorage<HistoryEntry[]>(HISTORY_KEY, [])
  const [memory, setMemory] = useLocalStorage<number>(MEMORY_KEY, 0)

  const inputRef = useRef<HTMLInputElement>(null)

  useDocumentTitle('ماشین حساب')

  /*
   * پیش‌نمایش زنده: با هر تغییر عبارت، نتیجه‌ی موقت زیر آن نشان داده می‌شود
   * ولی در تاریخچه ثبت نمی‌شود. عبارت‌های نیمه‌تمام خطا می‌دهند که اینجا
   * بی‌صدا نادیده گرفته می‌شود — خطا فقط هنگام زدن «=» به کاربر گفته می‌شود.
   */
  const preview = useMemo(() => {
    const trimmed = expression.trim()
    if (!trimmed) return ''

    try {
      return formatResult(evaluate(trimmed, angleMode))
    } catch {
      return ''
    }
  }, [expression, angleMode])

  const compute = useCallback(() => {
    const trimmed = expression.trim()
    if (!trimmed) return

    try {
      const value = evaluate(trimmed, angleMode)
      const formatted = formatResult(value)

      setResult(formatted)
      setResultExpr(trimmed)
      setError(null)

      setHistory((prev) =>
        [
          {
            id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
            expression: trimmed,
            result: formatted,
            angleMode,
            at: Date.now(),
          },
          ...prev,
        ].slice(0, MAX_HISTORY),
      )
    } catch (err) {
      setResult('')
      setError(err instanceof CalculationError ? err.message : 'محاسبه ممکن نشد.')
    }
  }, [expression, angleMode, setHistory])

  /*
   * محل مکان‌نمای «هدف» بعد از درج.
   *
   * چرا ref و نه خواندن مستقیم از DOM: اگر دو کلید پشت سر هم زده شوند،
   * React هر دو به‌روزرسانی را در یک رندر جمع می‌کند و در آن لحظه
   * input.selectionStart هنوز مقدار قبل از درج اول را دارد — پس درج دوم
   * سرِ جای اشتباه می‌نشست. با نگه‌داشتن مکان‌نمای هدف، درج دوم از همان‌جا
   * ادامه می‌دهد. بعد از رندر که DOM به‌روز شد، دوباره DOM ملاک می‌شود.
   */
  const caretRef = useRef<number | null>(null)

  /** درج متن در محل مکان‌نما، نه فقط انتهای عبارت. */
  const insert = useCallback((text: string) => {
    setError(null)
    setResult('')
    setResultExpr('')

    setExpression((prev) => {
      const input = inputRef.current
      const start = caretRef.current ?? input?.selectionStart ?? prev.length
      const end = caretRef.current ?? input?.selectionEnd ?? prev.length

      caretRef.current = start + text.length

      return prev.slice(0, start) + text + prev.slice(end)
    })
  }, [])

  // مکان‌نما را بعد از رندر سر جای هدف می‌گذارد و اختیار را به DOM برمی‌گرداند
  useEffect(() => {
    if (caretRef.current === null) return

    const input = inputRef.current
    const position = caretRef.current
    caretRef.current = null

    input?.focus()
    input?.setSelectionRange(position, position)
  }, [expression])

  const backspace = useCallback(() => {
    setError(null)
    setResult('')
    setResultExpr('')
    setExpression((prev) => prev.slice(0, -1))
  }, [])

  const clearAll = useCallback(() => {
    setExpression('')
    setResult('')
    setResultExpr('')
    setError(null)
    inputRef.current?.focus()
  }, [])

  // ورود به صفحه: input بلافاصله فوکوس می‌گیرد تا کاربر بدون کلیک تایپ کند.
  // این افکت بعد از mount اجرا می‌شود، پس ref حتماً مقداردهی شده است.
  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  /*
   * با عوض شدن درجه/رادیان، نتیجه‌ای که روی صفحه هست باید در حالت تازه
   * دوباره حساب شود. بدون این، کاربر sin(30) را در درجه می‌دید (۰.۵)، حالت
   * را رادیان می‌کرد ولی همان ۰.۵ می‌ماند و فکر می‌کرد سوییچ کار نمی‌کند.
   */
  useEffect(() => {
    setResult((current) => {
      if (!current) return current
      try {
        return formatResult(evaluate(expression, angleMode))
      } catch {
        return ''
      }
    })
    // فقط با تغییر حالت زاویه، نه با هر تایپ
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [angleMode])

  // صفحه‌کلید فیزیکی: ماشین حساب بدون آن روی دسکتاپ کند است
  useEffect(() => {
    function handleKey(event: KeyboardEvent) {
      const target = event.target as HTMLElement | null
      // اگر کاربر داخل ورودی دیگری (مثلاً جستجوی تاریخچه) تایپ می‌کند، دخالت نکن
      if (target && target !== inputRef.current && /^(INPUT|TEXTAREA)$/.test(target.tagName)) return

      if (event.key === 'Enter' || event.key === '=') {
        event.preventDefault()
        compute()
        return
      }
      // کلید Delete کل عبارت را پاک می‌کند (نقش AC)، برخلاف Backspace که
      // فقط یک نویسه برمی‌دارد.
      if (event.key === 'Delete') {
        event.preventDefault()
        clearAll()
        return
      }
      if (event.key === 'Escape') {
        event.preventDefault()
        clearAll()
      }
    }

    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [compute, clearAll])

  async function clearHistory() {
    const ok = await confirmAction({
      title: 'کل تاریخچه پاک شود؟',
      text: 'محاسبه‌های ذخیره‌شده قابل بازگردانی نیستند.',
      confirmLabel: 'پاک کن',
      danger: true,
    })
    if (!ok) return

    setHistory([])
    toastSuccess('تاریخچه پاک شد.')
  }

  function copyResult() {
    const value = result || preview
    if (!value) return

    void navigator.clipboard?.writeText(value).then(
      () => toastSuccess('نتیجه کپی شد.'),
      () => undefined,
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1
            className="flex items-center gap-2 text-xl font-extrabold"
            style={{ color: 'var(--text-primary)' }}
          >
            <CalculatorIcon size={19} style={{ color: 'var(--color-brand-500)' }} />
            ماشین حساب مهندسی
          </h1>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
            محاسبه‌ها با تاریخ و ساعت ذخیره می‌شوند و روی همین مرورگر باقی می‌مانند.
          </p>
        </div>

        <div
          className="flex items-center gap-1 rounded-xl border p-1"
          style={{ borderColor: 'var(--border-subtle)' }}
        >
          {(['deg', 'rad'] as const).map((mode) => (
            <button
              key={mode}
              onClick={() => setAngleMode(mode)}
              className="rounded-lg px-3 py-1.5 text-[12px] font-bold transition-colors"
              style={{
                backgroundColor: angleMode === mode ? 'var(--color-brand-500)' : 'transparent',
                color: angleMode === mode ? '#fff' : 'var(--text-secondary)',
              }}
            >
              {mode === 'deg' ? 'درجه' : 'رادیان'}
            </button>
          ))}
        </div>
      </header>

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <Card>
          <Display
            expression={expression}
            onExpressionChange={(value) => {
              setError(null)
              // نتیجه‌ی محاسبه‌ی قبلی باید برود، وگرنه کاربر عبارت تازه را
              // می‌بیند ولی زیرش عددِ محاسبه‌ی قبلی مانده و گمراه می‌شود
              setResult('')
              setResultExpr('')
              setExpression(value)
            }}
            inputRef={inputRef}
            result={result}
            resultExpr={resultExpr}
            preview={preview}
            error={error}
            memory={memory}
            angleMode={angleMode}
            onCopy={copyResult}
            onInsert={insert}
          />

          <MemoryRow
            memory={memory}
            onRecall={() => insert(String(memory))}
            onClear={() => setMemory(0)}
            onAdd={() => setMemory((prev) => prev + Number(preview || result || 0))}
            onSubtract={() => setMemory((prev) => prev - Number(preview || result || 0))}
            onStore={() => setMemory(Number(preview || result || 0))}
          />

          <Keypad
            secondFn={secondFn}
            onToggleSecond={() => setSecondFn((prev) => !prev)}
            onInsert={insert}
            onBackspace={backspace}
            onClear={clearAll}
            onEquals={compute}
          />
        </Card>

        <HistoryPanel
          history={history}
          query={historyQuery}
          onQueryChange={setHistoryQuery}
          onReuse={(entry) => {
            setExpression(entry.expression)
            setResult(entry.result)
            setError(null)
            inputRef.current?.focus()
          }}
          onUseResult={(entry) => insert(entry.result)}
          onRemove={(id) => setHistory((prev) => prev.filter((item) => item.id !== id))}
          onClear={clearHistory}
        />
      </div>
    </div>
  )
}

/* ------------------------------- نمایشگر ------------------------------- */

/** چه چیزی زیر عبارت نشان داده شود: خطا مقدم بر نتیجه، نتیجه مقدم بر پیش‌نمایش. */
function displayStatus(
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

function Display({
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
    <div
      className="rounded-2xl border p-4"
      style={{ backgroundColor: 'var(--surface-sunken)', borderColor: 'var(--border-subtle)' }}
    >
      <div className="mb-2 flex items-center gap-2 text-[10.5px]" style={{ color: 'var(--text-tertiary)' }}>
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
          const data = (event.nativeEvent as InputEvent).data
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
  )
}

/* -------------------------------- حافظه -------------------------------- */

function MemoryRow({
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
        <span className="mr-auto font-mono text-[11px] tabular-nums" style={{ color: 'var(--color-brand-600)' }}>
          حافظه: {toPersianDigits(formatResult(memory))}
        </span>
      )}
    </div>
  )
}

/* ------------------------------- کلیدها -------------------------------- */

interface KeySpec {
  label: string
  /** متنی که به عبارت اضافه می‌شود؛ اگر نبود از label استفاده می‌شود. */
  insert?: string
  variant?: 'function' | 'operator' | 'digit' | 'danger' | 'equals'
  title?: string
}

/** کلیدهای علمی؛ ردیف اول با دکمه‌ی 2nd جای خود را به معکوس‌ها می‌دهد. */
const SCIENTIFIC: KeySpec[][] = [
  [
    { label: '2nd', variant: 'function', title: 'توابع معکوس' },
    { label: 'sin', insert: 'sin(', variant: 'function' },
    { label: 'cos', insert: 'cos(', variant: 'function' },
    { label: 'tan', insert: 'tan(', variant: 'function' },
    { label: 'π', insert: 'pi', variant: 'function' },
  ],
  [
    { label: 'x²', insert: 'sqr(', variant: 'function', title: 'مجذور' },
    { label: 'xʸ', insert: '^', variant: 'function', title: 'به توان' },
    { label: '√', insert: 'sqrt(', variant: 'function', title: 'جذر' },
    { label: '∛', insert: 'cbrt(', variant: 'function', title: 'ریشه سوم' },
    { label: 'e', insert: 'e', variant: 'function' },
  ],
  [
    { label: 'ln', insert: 'ln(', variant: 'function', title: 'لگاریتم طبیعی' },
    { label: 'log', insert: 'log(', variant: 'function', title: 'لگاریتم مبنای ۱۰' },
    { label: '1/x', insert: 'inv(', variant: 'function', title: 'معکوس' },
    { label: 'n!', insert: '!', variant: 'function', title: 'فاکتوریل' },
    { label: '|x|', insert: 'abs(', variant: 'function', title: 'قدرمطلق' },
  ],
]

const SECOND_ROW: KeySpec[] = [
  { label: '2nd', variant: 'function', title: 'بازگشت' },
  { label: 'sin⁻¹', insert: 'asin(', variant: 'function' },
  { label: 'cos⁻¹', insert: 'acos(', variant: 'function' },
  { label: 'tan⁻¹', insert: 'atan(', variant: 'function' },
  { label: 'mod', insert: ' mod ', variant: 'function', title: 'باقیمانده' },
]

const SECOND_ROWS: KeySpec[][] = [
  SECOND_ROW,
  [
    { label: 'sinh', insert: 'sinh(', variant: 'function' },
    { label: 'cosh', insert: 'cosh(', variant: 'function' },
    { label: 'tanh', insert: 'tanh(', variant: 'function' },
    { label: 'eˣ', insert: 'exp(', variant: 'function' },
    { label: 'log₂', insert: 'log2(', variant: 'function' },
  ],
  [
    { label: 'round', insert: 'round(', variant: 'function', title: 'گرد کردن' },
    { label: '⌊x⌋', insert: 'floor(', variant: 'function', title: 'کف' },
    { label: '⌈x⌉', insert: 'ceil(', variant: 'function', title: 'سقف' },
    { label: 'n!', insert: '!', variant: 'function', title: 'فاکتوریل' },
    { label: '|x|', insert: 'abs(', variant: 'function', title: 'قدرمطلق' },
  ],
]

const NUMPAD: KeySpec[][] = [
  [
    { label: 'AC', variant: 'danger', title: 'پاک کردن همه' },
    { label: '⌫', variant: 'danger', title: 'حذف آخرین نویسه' },
    { label: '%', variant: 'operator', title: 'درصد' },
    { label: '÷', insert: '/', variant: 'operator' },
  ],
  [
    { label: '7', variant: 'digit' },
    { label: '8', variant: 'digit' },
    { label: '9', variant: 'digit' },
    { label: '×', insert: '*', variant: 'operator' },
  ],
  [
    { label: '4', variant: 'digit' },
    { label: '5', variant: 'digit' },
    { label: '6', variant: 'digit' },
    { label: '−', insert: '-', variant: 'operator' },
  ],
  [
    { label: '1', variant: 'digit' },
    { label: '2', variant: 'digit' },
    { label: '3', variant: 'digit' },
    { label: '+', variant: 'operator' },
  ],
  [
    { label: '(', variant: 'operator' },
    { label: '0', variant: 'digit' },
    { label: '.', variant: 'digit' },
    { label: ')', variant: 'operator' },
  ],
]

function Keypad({
  secondFn,
  onToggleSecond,
  onInsert,
  onBackspace,
  onClear,
  onEquals,
}: {
  secondFn: boolean
  onToggleSecond: () => void
  onInsert: (text: string) => void
  onBackspace: () => void
  onClear: () => void
  onEquals: () => void
}) {
  const rows = secondFn ? SECOND_ROWS : SCIENTIFIC

  function press(key: KeySpec) {
    if (key.label === '2nd') return onToggleSecond()
    if (key.label === 'AC') return onClear()
    if (key.label === '⌫') return onBackspace()

    onInsert(key.insert ?? key.label)
  }

  return (
    <div className="mt-4 flex flex-col gap-2" dir="ltr">
      {rows.map((row, rowIndex) => (
        <div key={`sci-${rowIndex}`} className="grid grid-cols-5 gap-2">
          {row.map((key) => (
            <Key
              key={`${key.label}-${rowIndex}`}
              spec={key}
              active={key.label === '2nd' && secondFn}
              onPress={() => press(key)}
            />
          ))}
        </div>
      ))}

      <div className="mt-1 grid grid-cols-4 gap-2">
        {NUMPAD.flat().map((key, index) => (
          <Key key={`pad-${key.label}-${index}`} spec={key} onPress={() => press(key)} />
        ))}
      </div>

      <button
        onClick={onEquals}
        className="mt-1 rounded-xl py-3.5 text-[17px] font-extrabold text-white transition-transform hover:scale-[1.01] active:scale-[0.99]"
        style={{ backgroundColor: 'var(--color-brand-500)' }}
      >
        =
      </button>
    </div>
  )
}

/**
 * رنگ پایه، متن و رنگ هاورِ هر تنوعِ کلید.
 *
 * رنگ هاور عمداً یک ته‌رنگ سبزِ دیده‌شدنی است، نه سفیدِ محو. چون
 * background-color این‌لاین بر :hover در CSS غلبه می‌کند، این مقادیر به‌جای
 * style این‌لاین در متغیرهای CSS گذاشته می‌شوند و کلاس .calc-key در app.css
 * هم پایه و هم هاور را از همان متغیرها می‌خواند.
 */
function keyColors(variant: NonNullable<KeySpec['variant']>, active: boolean) {
  if (active) {
    return {
      bg: 'var(--color-brand-500)',
      fg: '#fff',
      hover: 'color-mix(in srgb, #fff 12%, var(--color-brand-500))',
    }
  }

  switch (variant) {
    case 'digit':
      return {
        bg: 'var(--surface-sunken)',
        fg: 'var(--text-primary)',
        hover: 'color-mix(in srgb, var(--color-brand-500) 14%, var(--surface-sunken))',
      }
    case 'operator':
      return {
        bg: 'var(--surface-base)',
        fg: 'var(--color-brand-600)',
        hover: 'color-mix(in srgb, var(--color-brand-500) 20%, var(--surface-base))',
      }
    case 'danger':
      return {
        bg: 'color-mix(in srgb, var(--state-danger) 10%, transparent)',
        fg: 'var(--state-danger)',
        hover: 'color-mix(in srgb, var(--state-danger) 22%, var(--surface-base))',
      }
    default: // function
      return {
        bg: 'var(--surface-base)',
        fg: 'var(--text-secondary)',
        hover: 'color-mix(in srgb, var(--color-brand-500) 13%, var(--surface-base))',
      }
  }
}

function Key({
  spec,
  active,
  onPress,
}: {
  spec: KeySpec
  active?: boolean
  onPress: () => void
}) {
  const variant = spec.variant ?? 'digit'
  const colors = keyColors(variant, Boolean(active))

  return (
    <button
      onClick={onPress}
      title={spec.title}
      type="button"
      className={cn(
        'calc-key rounded-xl border py-2.5 font-mono text-[14px] font-bold',
        variant === 'digit' && 'text-[16px]',
      )}
      style={{
        ['--key-bg' as string]: colors.bg,
        ['--key-fg' as string]: colors.fg,
        ['--key-hover' as string]: colors.hover,
      }}
    >
      {spec.label}
    </button>
  )
}

/* ------------------------------- تاریخچه ------------------------------- */

function HistoryPanel({
  history,
  query,
  onQueryChange,
  onReuse,
  onUseResult,
  onRemove,
  onClear,
}: {
  history: HistoryEntry[]
  query: string
  onQueryChange: (value: string) => void
  onReuse: (entry: HistoryEntry) => void
  onUseResult: (entry: HistoryEntry) => void
  onRemove: (id: string) => void
  onClear: () => void
}) {
  // فیلتر با debounce تا تایپ در فهرست بلند کند نشود
  const debounced = useDebounce(query, 250)

  const filtered = useMemo(() => {
    const term = normalizeDigits(debounced.trim().toLowerCase())
    if (!term) return history

    return history.filter(
      (entry) =>
        entry.expression.toLowerCase().includes(term) || entry.result.includes(term),
    )
  }, [history, debounced])

  return (
    <Card delay={0.08} className="flex max-h-[42rem] flex-col">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-1.5 text-[14px] font-bold" style={{ color: 'var(--text-primary)' }}>
          <History size={15} style={{ color: 'var(--color-brand-500)' }} />
          تاریخچه
          <span className="text-[11px] font-medium" style={{ color: 'var(--text-tertiary)' }}>
            ({toPersianDigits(history.length)})
          </span>
        </h2>

        {history.length > 0 && (
          <button
            onClick={onClear}
            className="flex items-center gap-1 text-[11px] font-semibold transition-opacity hover:opacity-75"
            style={{ color: 'var(--color-danger)' }}
          >
            <Trash2 size={12} />
            پاک کردن
          </button>
        )}
      </div>

      {history.length > 3 && (
        <div className="relative mb-3">
          <Search
            size={14}
            className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2"
            style={{ color: 'var(--text-tertiary)' }}
          />
          <input
            value={query}
            onChange={(event) => onQueryChange(event.target.value)}
            placeholder="جستجو در محاسبه‌ها…"
            className="w-full rounded-xl border py-1.5 pr-8 pl-2.5 text-[12px] outline-none focus:ring-2"
            style={{
              backgroundColor: 'var(--surface-sunken)',
              borderColor: 'var(--border-subtle)',
              color: 'var(--text-primary)',
              ['--tw-ring-color' as string]: 'var(--ring-focus)',
            }}
          />
        </div>
      )}

      {filtered.length === 0 ? (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 py-10 text-center">
          <History size={24} style={{ color: 'var(--text-tertiary)' }} />
          <p className="text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
            {history.length === 0 ? 'هنوز محاسبه‌ای ثبت نشده است.' : 'موردی با این عبارت پیدا نشد.'}
          </p>
        </div>
      ) : (
        <ul className="scrollbar-thin -mx-1 flex-1 overflow-y-auto px-1">
          <AnimatePresence initial={false}>
            {filtered.map((entry) => (
              <motion.li
                key={entry.id}
                layout
                initial={{ opacity: 0, y: -6 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: 0.18 }}
                className="group relative border-b last:border-b-0"
                style={{ borderColor: 'var(--border-subtle)' }}
              >
                <button
                  onClick={() => onReuse(entry)}
                  title="بازگرداندن این عبارت به ماشین حساب"
                  className="w-full rounded-lg px-2 py-2.5 pl-16 text-right transition-colors hover:bg-(--surface-sunken)"
                >
                  <span
                    dir="ltr"
                    className="block truncate text-left font-mono text-[12px]"
                    style={{ color: 'var(--text-tertiary)' }}
                  >
                    {entry.expression}
                  </span>

                  <span
                    dir="ltr"
                    className="mt-0.5 block truncate text-left font-mono text-[15px] font-extrabold"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    = {toPersianDigits(entry.result)}
                  </span>

                  <span
                    className="mt-1 flex items-center gap-1.5 text-[10px]"
                    style={{ color: 'var(--text-tertiary)' }}
                    title={formatJalaliDateTime(entry.at)}
                  >
                    <span>{formatJalaliDateTime(entry.at)}</span>
                    <span>·</span>
                    <span>{formatRelative(entry.at)}</span>
                    <span
                      className="rounded px-1 font-bold"
                      style={{ backgroundColor: 'var(--surface-sunken)' }}
                    >
                      {entry.angleMode === 'deg' ? 'DEG' : 'RAD'}
                    </span>
                  </span>
                </button>

                {/*
                  سطل زباله‌ی هر ردیف همیشه دیده می‌شود (نه فقط با هاور)، چون
                  کاربر ممکن است بخواهد فقط چند مورد را پاک کند نه کل تاریخچه.
                  «درج نتیجه» چون کم‌کاربردتر است فقط با هاور ظاهر می‌شود.
                */}
                <div className="absolute left-1.5 top-2.5 flex items-center gap-0.5">
                  <button
                    onClick={() => onUseResult(entry)}
                    aria-label="درج نتیجه در عبارت"
                    title="درج نتیجه در عبارت"
                    className="flex h-7 w-7 items-center justify-center rounded-lg opacity-0 transition-opacity hover:bg-(--border-subtle) focus-visible:opacity-100 group-hover:opacity-100"
                    style={{ color: 'var(--color-brand-600)' }}
                  >
                    <Delete size={13} className="rotate-180" />
                  </button>
                  <button
                    onClick={() => onRemove(entry.id)}
                    aria-label="حذف این مورد از تاریخچه"
                    title="حذف این مورد"
                    className="flex h-7 w-7 items-center justify-center rounded-lg transition-colors hover:bg-(--surface-sunken)"
                    style={{ color: 'var(--color-danger)' }}
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              </motion.li>
            ))}
          </AnimatePresence>
        </ul>
      )}
    </Card>
  )
}
