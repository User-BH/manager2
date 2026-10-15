import { useCallback, useEffect, useRef, useState } from 'react'
import { Calculator as CalculatorIcon } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { useDocumentTitle, useLocalStorage } from '@/shared/hooks'
import { confirmAction, toastError, toastSuccess } from '@/shared/lib/alert'
import { copyText } from '@/shared/lib/clipboard'
import { CalculationError, evaluate, formatResult, type AngleMode } from '@/shared/lib/calculator'
import { Display, MemoryRow } from './Display'
import { Keypad } from './Keypad'
import { HistoryPanel } from './HistoryPanel'
import { isHistory, isMemory, type HistoryEntry } from './types'

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

  // ⚠️ نگهبانِ زمانِ اجرا اجباری است: دادهٔ `localStorage` خارجی است (فاز ۳)
  const [history, setHistory] = useLocalStorage<HistoryEntry[]>(HISTORY_KEY, [], isHistory)
  const [memory, setMemory] = useLocalStorage<number>(MEMORY_KEY, 0, isMemory)

  const inputRef = useRef<HTMLInputElement>(null)

  useDocumentTitle('ماشین حساب')

  /*
   * محاسبه فقط با فرمانِ کاربر — «=» یا Enter («چند نکته»، بندِ ۶).
   *
   * پیش‌تر یک «پیش‌نمایشِ زنده» با هر تغییرِ عبارت `evaluate` را اجرا می‌کرد و نتیجه را زیرِ عبارت نشان می‌داد؛ یعنی
   * محاسبه واقعاً انجام می‌شد، نه فقط پنهان نبود. آن پیش‌نمایش برداشته شد: تا فرمان نیاید هیچ محاسبه‌ای اجرا نمی‌شود.
   */
  const compute = useCallback(() => {
    const trimmed = expression.trim()
    if (!trimmed) return
    // همان عبارت که همین حالا حساب شده: تکرارِ ناخواسته (دو بار Enter، کلیک و Enter) ردیفِ تکراری نمی‌سازد
    if (result && resultExpr === trimmed) return

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
  }, [expression, angleMode, result, resultExpr, setHistory])

  /**
   * مقدار برای دکمه‌های حافظه: نتیجه‌ی حساب‌شده، یا — اگر هنوز نیست — همان لحظه به فرمانِ خودِ کاربر حساب می‌شود
   * (بی ثبت در تاریخچه). عبارتِ نامعتبر خطا می‌دهد و حافظه دست نمی‌خورد.
   */
  function valueForMemory(): number | null {
    if (result) return Number(result)
    const trimmed = expression.trim()
    if (!trimmed) return 0
    try {
      return evaluate(trimmed, angleMode)
    } catch (err) {
      setError(err instanceof CalculationError ? err.message : 'محاسبه ممکن نشد.')
      return null
    }
  }

  function updateMemory(apply: (value: number) => void) {
    const value = valueForMemory()
    if (value !== null) apply(value)
  }

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
   *
   * ⚠️ این کار در **همان رویدادِ کلیک** انجام می‌شود، نه در افکت.
   *
   * علتش یک کنشِ کاربر است، نه همگام‌سازی با سیستمِ بیرونی. با افکت،
   * کلیک یک رندر با نتیجه‌ی **حالتِ قبلی** می‌داد و رندرِ دوم اصلاحش
   * می‌کرد — یعنی همان عددی که کاربر شک داشت درست است، یک فریم بیشتر
   * روی صفحه می‌ماند.
   *
   * و مهم‌تر: اینجا `next` استفاده می‌شود نه `angleMode`. افکت به
   * وابستگیِ دست‌چین‌شده تکیه داشت (با `eslint-disable`) و اگر روزی
   * چیزِ دیگری هم به آن آرایه اضافه می‌شد، بی‌صدا اشتباه حساب می‌کرد.
   */
  function changeAngleMode(next: AngleMode) {
    setAngleMode(next)

    setResult((current) => {
      if (!current) return current
      try {
        return formatResult(evaluate(expression, next))
      } catch {
        return ''
      }
    })
  }

  // صفحه‌کلید فیزیکی: ماشین حساب بدون آن روی دسکتاپ کند است
  useEffect(() => {
    function handleKey(event: KeyboardEvent) {
      const target = event.target as HTMLElement | null
      // اگر کاربر داخل ورودی دیگری (مثلاً جستجوی تاریخچه) تایپ می‌کند، دخالت نکن
      if (target && target !== inputRef.current && /^(INPUT|TEXTAREA)$/.test(target.tagName)) return

      if (event.key === 'Enter' || event.key === '=') {
        // Enter روی دکمه‌ی متمرکز کارِ همان دکمه است (درج، حذف از تاریخچه، «=»)، نه یک محاسبه‌ی اضافه.
        // نگه داشتنِ Enter و Enter ِ دوباره را خودِ `compute` بی‌اثر می‌کند (همان عبارتِ حساب‌شده).
        if (event.key === 'Enter' && target && /^(BUTTON|A)$/.test(target.tagName)) return
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
    const value = result
    if (!value) return

    void copyText(value).then((copied) =>
      copied ? toastSuccess('نتیجه کپی شد.') : toastError('کپی ممکن نشد؛ نتیجه را دستی کپی کنید.'),
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
              onClick={() => changeAngleMode(mode)}
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
            onAdd={() => updateMemory((value) => setMemory((prev) => prev + value))}
            onSubtract={() => updateMemory((value) => setMemory((prev) => prev - value))}
            onStore={() => updateMemory((value) => setMemory(value))}
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
            // نتیجه‌ی ذخیره‌شده با همان حالتِ زاویه‌ای که با آن حساب شد — نه نتیجه‌ی درجه زیرِ حالتِ رادیان
            setAngleMode(entry.angleMode)
            setExpression(entry.expression)
            setResult(entry.result)
            setResultExpr(entry.expression)
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
