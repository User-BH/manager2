import { useRef, useState } from 'react'
import { Controller, useForm, useWatch, type Control } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { CheckCircle2, Loader2, MessageSquareHeart, RotateCcw, Send } from 'lucide-react'
import { TextAreaField, TextField } from '@/shared/ui/Field'
import { SelectMenu } from '@/shared/ui/SelectMenu'
import { api, ApiError } from '@/shared/lib/api'
import { formatNumber } from '@/shared/lib/format'
import {
  filterHints,
  filterMobile,
  toAsciiDigits,
  type FilterResult,
} from '@/shared/lib/inputFilters'
import {
  FEEDBACK_CATEGORIES,
  MESSAGE_MAX,
  NAME_MAX,
  PERSIAN_NAME_HINT,
  feedbackSchema,
  filterPersianName,
  type FeedbackValues,
} from './feedbackSchema'

const EMPTY: FeedbackValues = { category: '', message: '', name: '', phone: '', website: '' }

/** ترتیبِ فیلدها روی صفحه — برای فوکوس روی اولین خطا */
const FIELD_ORDER = ['category', 'message', 'name', 'phone'] as const

/** از این‌جا به پایین شمارنده هشدار می‌دهد */
const NEAR_LIMIT = 100

/**
 * فرمِ بازخورد، پیشنهاد و گزارشِ مشکل — انتهای صفحه‌ی پشتیبانی.
 *
 * پیوندِ «ارسال بازخورد» در فوترِ خانه با `#feedback` به همین‌جا می‌آید.
 *
 * ─── سازوکار (مرحلهٔ ۷ · فاز ۴) ───────────────────────────────────────────
 * • اعتبارسنجیِ فوری با `feedbackSchema`؛ همان قاعده‌ها در سرور دوباره.
 * • `submitting` دکمه را می‌بندد: دوبار-کلیک دو پیام نمی‌سازد (سرور هم
 *   همان متن را از همان فرستنده در ۲۴ ساعت دوباره ذخیره نمی‌کند).
 * • خطای فیلدیِ سرور زیرِ همان فیلد می‌نشیند؛ خطای کلی بالای دکمه.
 * • `website` تله‌ی ربات است: برای انسان نادیدنی و بیرون از ترتیبِ Tab.
 *
 * ─── مرحلهٔ ۸ · فاز ۵ ─────────────────────────────────────────────────────
 * • بخشِ جدا با پس‌زمینه‌ی برند تا انتهای عرضِ صفحه؛ فرم روی کارتی برجسته.
 * • «نوعِ پیام» فهرستِ انتخابیِ سفارشی (`SelectMenu`)، نه دکمه‌های کنارِ هم.
 * • نام: فقط حروفِ فارسی — هنگامِ تایپ **و چسباندن** پالایه می‌شود، نه فقط
 *   هنگامِ ارسال. موبایل: اجباری و فقط رقم.
 * • خطای شبکه/سرور: داده‌ها می‌مانند و «تلاش مجدد» همان را دوباره می‌فرستد.
 *   هیچ شکستی «رسید» نشان داده نمی‌شود.
 */
export function FeedbackSection() {
  const [submitting, setSubmitting] = useState(false)
  const [sent, setSent] = useState<string | null>(null)
  const [failure, setFailure] = useState<{ text: string; retry: boolean } | null>(null)

  const {
    control,
    register,
    handleSubmit,
    reset,
    setError,
    setFocus,
    formState: { errors, isSubmitted },
  } = useForm<FeedbackValues>({
    resolver: zodResolver(feedbackSchema),
    defaultValues: EMPTY,
    /*
     * ⚠️ فوکوس روی اولین خطا به ترتیبِ **دیدنی**، نه ترتیبِ ثبت: `register('message')`
     * در رندرِ همین تابع پیش از Controller ِ «نوعِ پیام» ثبت می‌شود و react-hook-form
     * فوکوس را به متن می‌برد و فهرستِ بالای آن را جا می‌انداخت (تست گرفت).
     */
    shouldFocusError: false,
  })

  const message = useWatch({ control, name: 'message' }) ?? ''

  async function onSubmit(values: FeedbackValues) {
    if (submitting) return
    setSubmitting(true)
    setFailure(null)

    try {
      const data = await api<{ message: string }>('/feedback', {
        method: 'POST',
        body: {
          category: values.category,
          message: values.message,
          name: values.name.trim() || null,
          phone: toAsciiDigits(values.phone),
          website: values.website,
        },
      })
      setSent(data.message)
      reset(EMPTY)
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        let placed = false
        for (const field of ['category', 'message', 'name', 'phone'] as const) {
          const text = error.fieldError(field)
          if (text) {
            setError(field, { message: text }, { shouldFocus: !placed })
            placed = true
          }
        }
        if (!placed) setFailure({ text: error.message, retry: false })
      } else if (error instanceof ApiError && error.status === 429) {
        setFailure({
          text: 'چند پیام پشتِ سرِ هم فرستاده‌اید؛ چند دقیقه‌ی دیگر دوباره امتحان کنید.',
          retry: true,
        })
      } else {
        setFailure({
          text:
            error instanceof ApiError && error.status < 500
              ? error.message
              : 'پیام فرستاده نشد — ارتباط با سرور برقرار نشد. نوشته‌تان سرِ جایش است؛ دوباره امتحان کنید.',
          retry: true,
        })
      }
    } finally {
      setSubmitting(false)
    }
  }

  const submit = handleSubmit(onSubmit, (invalid) => {
    const first = FIELD_ORDER.find((field) => invalid[field])
    if (first) setFocus(first)
  })
  const remaining = MESSAGE_MAX - message.length

  return (
    <section aria-labelledby="feedback-title" className="feedback-band" dir="rtl">
      <div className="mx-auto max-w-3xl px-4 sm:px-6">
        <div className="mb-6 flex flex-col items-center gap-3 text-center">
          <span
            className="flex h-12 w-12 items-center justify-center rounded-2xl text-white shadow-lg"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            <MessageSquareHeart size={22} aria-hidden="true" />
          </span>
          <h2
            id="feedback-title"
            className="text-xl font-extrabold sm:text-2xl"
            style={{ color: 'var(--text-primary)' }}
          >
            حرفتان را بشنویم
          </h2>
          <p className="max-w-md text-small leading-7" style={{ color: 'var(--text-secondary)' }}>
            پیشنهاد، انتقاد، گزارشِ مشکل یا هر بازخوردی — مستقیم به تیمِ ساکنا می‌رسد.
          </p>
        </div>

        <div className="feedback-card">
          {sent ? (
            <div className="flex flex-col items-center gap-3 p-8 text-center" role="status">
              <CheckCircle2 size={36} style={{ color: 'var(--color-success)' }} />
              <p className="text-body font-bold" style={{ color: 'var(--text-primary)' }}>
                {sent}
              </p>
              <button
                type="button"
                onClick={() => setSent(null)}
                className="mt-1 text-small font-semibold underline"
                style={{ color: 'var(--color-brand-600)' }}
              >
                ارسالِ پیامِ دیگر
              </button>
            </div>
          ) : (
            <form
              onSubmit={(event) => void submit(event)}
              noValidate
              className="flex flex-col gap-4 p-5 sm:p-7"
            >
              <Controller
                control={control}
                name="category"
                render={({ field }) => (
                  <SelectMenu
                    ref={field.ref}
                    label="نوعِ پیام"
                    placeholder="یکی را انتخاب کنید"
                    options={FEEDBACK_CATEGORIES}
                    value={field.value}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                    error={errors.category?.message}
                  />
                )}
              />

              <div>
                <TextAreaField
                  label="متنِ پیام"
                  rows={5}
                  // سقف هم هنگامِ تایپ و هم چسباندن؛ سرور هم همین را می‌سنجد
                  maxLength={MESSAGE_MAX}
                  placeholder="هرچه دقیق‌تر بنویسید، زودتر به نتیجه می‌رسد."
                  error={errors.message?.message}
                  valid={isSubmitted && !errors.message && message.trim().length >= 10}
                  {...register('message')}
                />
                <p
                  className="mt-1 flex justify-between text-tiny tabular-nums"
                  style={{
                    color:
                      remaining === 0
                        ? 'var(--color-danger)'
                        : remaining <= NEAR_LIMIT
                          ? 'var(--state-warning)'
                          : 'var(--text-tertiary)',
                  }}
                  aria-live={remaining <= NEAR_LIMIT ? 'polite' : 'off'}
                  data-testid="feedback-counter"
                >
                  <span>
                    {remaining === 0
                      ? 'به سقفِ متن رسیدید'
                      : remaining <= NEAR_LIMIT
                        ? `${formatNumber(remaining)} نویسه مانده`
                        : ''}
                  </span>
                  <span dir="ltr">
                    {formatNumber(message.length)} / {formatNumber(MESSAGE_MAX)}
                  </span>
                </p>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <FilteredField
                  control={control}
                  name="name"
                  label="نام (اختیاری)"
                  autoComplete="name"
                  maxLength={NAME_MAX}
                  filter={filterPersianName}
                  hint={PERSIAN_NAME_HINT}
                  error={errors.name?.message}
                />
                <FilteredField
                  control={control}
                  name="phone"
                  label="شماره موبایل"
                  inputMode="numeric"
                  autoComplete="tel"
                  dir="ltr"
                  placeholder="۰۹xxxxxxxxx"
                  // ⚠️ بی maxLength: مرورگر متنِ چسبانده («۰۹۱۲-۱۲۳ ۴۵۶۷») را پیش از
                  // پالایه با جداکننده‌ها می‌بُرد و رقم‌ها گم می‌شدند؛ filterMobile خودش ۱۱ رقم نگه می‌دارد
                  required
                  filter={filterMobile}
                  hint={filterHints.mobile}
                  error={errors.phone?.message}
                />
              </div>

              {/*
                تله‌ی ربات: بیرون از دید و ترتیبِ Tab؛ صفحه‌خوان هم آن را نمی‌خواند.

                ⚠️ `sr-only` و نه `left: -10000px`: در صفحه‌ی راست‌به‌چپ، عنصری که به
                چپ رانده شود ناحیه‌ی اسکرول را باز می‌کند — در مرورگر سنجیده شد:
                پهنای صفحه ‎۱۰٬۵۶۷px و اسکرولِ افقیِ بزرگ.
              */}
              <div aria-hidden="true" className="sr-only">
                <label>
                  وب‌سایت
                  <input
                    type="text"
                    className="field"
                    tabIndex={-1}
                    autoComplete="off"
                    {...register('website')}
                  />
                </label>
              </div>

              <p className="text-tiny leading-6" style={{ color: 'var(--text-tertiary)' }}>
                شماره فقط برای پاسخ به همین پیام به کار می‌رود و جایی نمایش داده نمی‌شود.
              </p>

              {failure && (
                <div
                  role="alert"
                  className="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-small"
                  style={{
                    borderColor: 'color-mix(in srgb, var(--color-danger) 35%, transparent)',
                    backgroundColor: 'color-mix(in srgb, var(--color-danger) 7%, transparent)',
                    color: 'var(--color-danger)',
                  }}
                >
                  <span>{failure.text}</span>
                  {failure.retry && (
                    <button
                      type="button"
                      onClick={() => void submit()}
                      disabled={submitting}
                      className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-bold disabled:opacity-60"
                      style={{ borderColor: 'currentColor' }}
                    >
                      <RotateCcw size={14} aria-hidden="true" />
                      تلاش مجدد
                    </button>
                  )}
                </div>
              )}

              <button
                type="submit"
                disabled={submitting}
                aria-busy={submitting || undefined}
                className="flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-body font-bold text-white transition-transform hover:scale-[1.02] disabled:hover:scale-100 sm:self-start"
                style={{ backgroundColor: 'var(--color-brand-500)' }}
              >
                {submitting ? (
                  <Loader2 size={16} className="animate-spin" aria-hidden="true" />
                ) : (
                  <Send size={16} aria-hidden="true" />
                )}
                {submitting ? 'در حالِ ارسال…' : 'ارسالِ پیام'}
              </button>
            </form>
          )}
        </div>
      </div>
    </section>
  )
}

/**
 * ورودیِ پالایه‌شده: نویسه‌ی نامجاز هنگامِ تایپ **و چسباندن** اصلاً وارد
 * نمی‌شود و یک پیامِ کوتاه علتش را می‌گوید. همان الگوی `RestrictedField` ِ
 * ورود و ثبت‌نام، روی `TextField` ِ مشترک (مرزِ ویژگی‌ها import از auth را
 * نمی‌گذارد).
 */
function FilteredField({
  control,
  name,
  filter,
  hint,
  error,
  ...props
}: {
  control: Control<FeedbackValues>
  name: 'name' | 'phone'
  label: string
  filter: (value: string) => FilterResult
  hint: string
  error?: string
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'name'>) {
  const [flash, setFlash] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState, formState }) => (
        <TextField
          {...props}
          ref={field.ref}
          name={field.name}
          value={field.value}
          onBlur={field.onBlur}
          onChange={(event) => {
            const result = filter(event.target.value)
            if (result.changed) {
              setFlash(result.hint ?? hint)
              if (timer.current) clearTimeout(timer.current)
              timer.current = setTimeout(() => setFlash(null), 2400)
            }
            field.onChange(result.value)
          }}
          error={error ?? flash ?? undefined}
          valid={formState.isSubmitted && !fieldState.invalid && field.value !== ''}
        />
      )}
    />
  )
}

// بارگذاریِ تنبل از `SupportPage` با `LazyVisible` (همان الگوی بخش‌های صفحه‌ی اصلی)
export default FeedbackSection
