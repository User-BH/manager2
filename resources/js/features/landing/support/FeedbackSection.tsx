import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { CheckCircle2, Loader2, MessageSquareHeart, Send } from 'lucide-react'
import { TextAreaField, TextField } from '@/shared/ui/Field'
import { api, ApiError } from '@/shared/lib/api'
import { formatNumber } from '@/shared/lib/format'
import { toAsciiDigits } from '@/shared/lib/inputFilters'
import {
  FEEDBACK_CATEGORIES,
  MESSAGE_MAX,
  feedbackSchema,
  type FeedbackValues,
} from './feedbackSchema'

const EMPTY: FeedbackValues = { category: '', message: '', name: '', phone: '', website: '' }

/**
 * فرمِ بازخورد، پیشنهاد و گزارشِ مشکل — انتهای صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴).
 *
 * پیوندِ «ارسال بازخورد» در فوترِ خانه با `#feedback` به همین‌جا می‌آید.
 *
 * ─── سازوکار ──────────────────────────────────────────────────────────────
 * • اعتبارسنجیِ فوری با `feedbackSchema`؛ همان قاعده‌ها در سرور دوباره.
 * • `submitting` دکمه را می‌بندد: دوبار-کلیک دو پیام نمی‌سازد (سرور هم
 *   همان متن را از همان فرستنده در ۲۴ ساعت دوباره ذخیره نمی‌کند).
 * • خطای فیلدیِ سرور زیرِ همان فیلد می‌نشیند؛ خطای کلی (سقفِ نرخ، شبکه)
 *   بالای دکمه.
 * • `website` تله‌ی ربات است: برای انسان نادیدنی و بیرون از ترتیبِ Tab.
 */
export function FeedbackSection() {
  const [submitting, setSubmitting] = useState(false)
  const [sent, setSent] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    control,
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitted },
  } = useForm<FeedbackValues>({
    resolver: zodResolver(feedbackSchema),
    defaultValues: EMPTY,
  })

  const message = useWatch({ control, name: 'message' }) ?? ''
  const category = useWatch({ control, name: 'category' })

  async function onSubmit(values: FeedbackValues) {
    if (submitting) return
    setSubmitting(true)
    setFormError(null)

    try {
      const data = await api<{ message: string }>('/feedback', {
        method: 'POST',
        body: {
          category: values.category,
          message: values.message,
          name: values.name || null,
          phone: values.phone ? toAsciiDigits(values.phone) : null,
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
            setError(field, { message: text })
            placed = true
          }
        }
        if (!placed) setFormError(error.message)
      } else if (error instanceof ApiError && error.status === 429) {
        setFormError('چند پیام پشتِ سرِ هم فرستاده‌اید؛ چند دقیقه‌ی دیگر دوباره امتحان کنید.')
      } else {
        setFormError(
          error instanceof ApiError
            ? error.message
            : 'ارتباط با سرور برقرار نشد. دوباره امتحان کنید.',
        )
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section
      aria-labelledby="feedback-title"
      className="mt-10 overflow-hidden rounded-3xl border"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-base)' }}
    >
      <div
        className="flex items-start gap-3 border-b p-5 sm:p-6"
        style={{
          borderColor: 'var(--border-subtle)',
          background:
            'linear-gradient(120deg, color-mix(in srgb, var(--color-brand-500) 12%, transparent), transparent 75%)',
        }}
      >
        <span
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-white"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          <MessageSquareHeart size={20} />
        </span>
        <div>
          <h2
            id="feedback-title"
            className="text-lg font-extrabold"
            style={{ color: 'var(--text-primary)' }}
          >
            حرفتان را بشنویم
          </h2>
          <p className="mt-1 text-small leading-6" style={{ color: 'var(--text-tertiary)' }}>
            بازخورد، پیشنهاد، انتقاد یا گزارشِ مشکل — مستقیم به تیمِ ساکنا می‌رسد.
          </p>
        </div>
      </div>

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
          onSubmit={handleSubmit(onSubmit)}
          noValidate
          className="flex flex-col gap-4 p-5 sm:p-6"
        >
          <fieldset>
            <legend
              className="mb-2 text-body font-medium"
              style={{ color: 'var(--text-secondary)' }}
            >
              نوعِ پیام
            </legend>
            <div
              className="flex flex-wrap gap-2"
              role="radiogroup"
              aria-invalid={Boolean(errors.category) || undefined}
            >
              {FEEDBACK_CATEGORIES.map((option) => {
                const checked = category === option.value
                return (
                  <label
                    key={option.value}
                    className="cursor-pointer rounded-xl border px-3.5 py-2 text-small font-semibold transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2"
                    style={{
                      borderColor: checked ? 'var(--color-brand-500)' : 'var(--border-default)',
                      backgroundColor: checked
                        ? 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)'
                        : 'transparent',
                      color: checked ? 'var(--color-brand-600)' : 'var(--text-secondary)',
                      outlineColor: 'var(--ring-focus)',
                    }}
                  >
                    <input
                      type="radio"
                      value={option.value}
                      className="sr-only"
                      {...register('category')}
                    />
                    {option.label}
                  </label>
                )
              })}
            </div>
            {errors.category && (
              <p role="alert" className="mt-1.5 text-xs" style={{ color: 'var(--color-danger)' }}>
                {errors.category.message}
              </p>
            )}
          </fieldset>

          <div>
            <TextAreaField
              label="متنِ پیام"
              rows={5}
              maxLength={MESSAGE_MAX}
              placeholder="هرچه دقیق‌تر بنویسید، زودتر به نتیجه می‌رسد."
              error={errors.message?.message}
              valid={isSubmitted && !errors.message && message.trim().length >= 10}
              {...register('message')}
            />
            <p
              className="mt-1 text-left text-tiny tabular-nums"
              style={{ color: 'var(--text-tertiary)' }}
              dir="ltr"
            >
              {formatNumber(message.length)} / {formatNumber(MESSAGE_MAX)}
            </p>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <TextField
              label="نام (اختیاری)"
              autoComplete="name"
              maxLength={80}
              error={errors.name?.message}
              {...register('name')}
            />
            <TextField
              label="شماره موبایل برای پاسخ (اختیاری)"
              inputMode="numeric"
              autoComplete="tel"
              dir="ltr"
              placeholder="۰۹xxxxxxxxx"
              maxLength={11}
              error={errors.phone?.message}
              {...register('phone')}
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

          {formError && (
            <p role="alert" className="text-small" style={{ color: 'var(--color-danger)' }}>
              {formError}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="flex items-center justify-center gap-2 self-start rounded-xl px-6 py-3 text-body font-bold text-white transition-transform hover:scale-[1.02] disabled:hover:scale-100"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            {submitting ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
            {submitting ? 'در حالِ ارسال…' : 'ارسالِ پیام'}
          </button>
        </form>
      )}
    </section>
  )
}

// بارگذاریِ تنبل از `SupportPage` با `LazyVisible` (همان الگوی بخش‌های صفحه‌ی اصلی)
export default FeedbackSection
