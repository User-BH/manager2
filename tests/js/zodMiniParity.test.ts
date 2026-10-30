import { describe, expect, it } from 'vitest'
import { z } from 'zod'
import { zodResolver } from '@hookform/resolvers/zod'
import type { FieldValues, Resolver } from 'react-hook-form'
import { loginSchema } from '@/features/auth/schemas/loginSchema'
import { registerSchema } from '@/features/auth/schemas/registerSchema'
import { NAME_PART_MAX, PERSIAN_NAME_PATTERN, personNamePart } from '@/shared/lib/personName'

/**
 * هم‌ارزیِ رفتاریِ `zod/mini` با `zod` ِ کامل (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۷).
 *
 * چهار اسکیمای بارِ اول در کامیتِ `de01025` از `zod` به `zod/mini` رفتند. ادعای «قاعده‌ها و پیام‌ها عیناً همان‌اند» این‌جا
 * از روی کد پذیرفته نمی‌شود: نسخه‌ی پیش از تبدیل **کلمه‌به‌کلمه** از `git show 7de1bca:<فایل>` با `zod` ِ کامل بازسازی
 * شده و هر دو روی یک پیکره‌ی ورودی اجرا می‌شوند — معتبر، نامعتبر، خالی، نوعِ نادرست، مرزهای طول و قالب، فاصله‌ی
 * پیرامونی، رقمِ فارسی، نیم‌فاصله. برای هر ورودی باید یکی باشند: موفقیت، داده‌ی خروجی، و فهرستِ خطاها (مسیر + پیام).
 * سپس همان مقایسه از راهِ مصرف‌کننده‌ی واقعی، `zodResolver` ِ react-hook-form.
 */

/* ── نسخه‌ی پیش از تبدیل (7de1bca)، با zod ِ کامل ───────────────────────── */

function legacyNamePart(label: 'نام' | 'نام خانوادگی') {
  return z
    .string()
    .trim()
    .min(1, `${label} را وارد کنید.`)
    .min(2, `${label} دست‌کم ۲ حرف است.`)
    .max(NAME_PART_MAX, `${label} بیش از ${NAME_PART_MAX} حرف است.`)
    .regex(PERSIAN_NAME_PATTERN, `${label} فقط با حروف فارسی نوشته شود.`)
}

const legacyPhone = z
  .string()
  .min(1, 'شماره موبایل را وارد کنید')
  .regex(/^09\d{9}$/, 'شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد')

const legacy = {
  // بی رمز (۲۰۲۶-۱۰-۳۰): ورود فقط شماره، ثبت‌نام بی رمز و تکرارش
  login: z.object({
    phone: legacyPhone,
    remember: z.boolean().optional(),
  }),
  register: z.object({
    firstName: legacyNamePart('نام'),
    lastName: legacyNamePart('نام خانوادگی'),
    phone: legacyPhone,
    acceptTerms: z.boolean().refine((val) => val === true, {
      message: 'برای ادامه باید قوانین را بپذیرید',
    }),
  }),
}

/* ── پیکره‌ی ورودی ─────────────────────────────────────────────────────── */

const PHONES: unknown[] = [
  '09123456789',
  '',
  '0912345678',
  '091234567890',
  '9123456789',
  ' 09123456789',
  '09123456789 ',
  '۰۹۱۲۳۴۵۶۷۸۹',
  '0912345678a',
  '+989123456789',
  null,
  undefined,
  9123456789,
]
const NAMES: unknown[] = [
  'علی',
  '',
  ' ',
  'ع',
  ' علی ',
  'Ali',
  'علی۲',
  'علی  رضا',
  'محمد‌رضا',
  'كريم',
  'ب'.repeat(NAME_PART_MAX),
  'ب'.repeat(NAME_PART_MAX + 1),
  ` ${'ب'.repeat(NAME_PART_MAX)} `,
  null,
  42,
]
const BOOLS: unknown[] = [true, false, undefined, 'true', 1, null]

interface Shape {
  [field: string]: unknown[]
}

/** پایه‌ی معتبر + هر فیلد جداگانه با همه‌ی مقدارهایش + ترکیب‌های قطعیِ چندفیلدی */
function corpus(shape: Shape, extra: Record<string, unknown>[] = []): Record<string, unknown>[] {
  const fields = Object.keys(shape)
  const base = Object.fromEntries(fields.map((f) => [f, shape[f][0]]))
  const out: Record<string, unknown>[] = [base, {}, ...extra]

  for (const field of fields) {
    for (const value of shape[field]) {
      const row: Record<string, unknown> = { ...base }
      if (value === undefined) delete row[field]
      else row[field] = value
      out.push(row)
    }
  }

  // ترکیب‌های چندفیلدی با مولدِ شبه‌تصادفیِ ثابت (هر اجرا همان)
  let seed = 7
  const next = () => (seed = (seed * 48271) % 2147483647)
  for (let i = 0; i < 300; i++) {
    out.push(Object.fromEntries(fields.map((f) => [f, shape[f][next() % shape[f].length]])))
  }

  return out
}

/** آنچه کاربر و فرم می‌بینند: موفقیت، داده، و خطاها با مسیر و پیام (ترتیبِ ثابت) */
function outcome(schema: { safeParse: (v: unknown) => unknown }, value: unknown) {
  const result = schema.safeParse(value) as
    | { success: true; data: unknown }
    | { success: false; error: { issues: { path: PropertyKey[]; message: string }[] } }

  if (result.success) return { success: true, data: result.data }

  return {
    success: false,
    issues: result.error.issues
      .map((issue) => `${issue.path.map(String).join('.')}: ${issue.message}`)
      .sort(),
  }
}

const PAIRS = [
  {
    name: 'ورود (loginSchema)',
    current: loginSchema,
    legacy: legacy.login,
    rows: corpus({ phone: PHONES, remember: BOOLS }),
  },
  {
    name: 'ثبت‌نام (registerSchema)',
    current: registerSchema,
    legacy: legacy.register,
    rows: corpus({
      firstName: NAMES,
      lastName: NAMES,
      phone: PHONES,
      acceptTerms: BOOLS,
    }),
  },
]

describe('zod/mini همان رفتارِ zod ِ کامل را دارد', () => {
  it.each(PAIRS)(
    '$name: موفقیت، داده و هر پیامِ خطا برای کلِ پیکره یکی است',
    ({ current, legacy: old, rows }) => {
      expect(rows.length).toBeGreaterThan(300)
      for (const row of rows) {
        expect(outcome(current, row), JSON.stringify(row)).toEqual(outcome(old, row))
      }
    },
  )

  it.each(['نام', 'نام خانوادگی'] as const)(
    'personNamePart(«%s»): هر ورودی همان نتیجه',
    (label) => {
      const current = personNamePart(label)
      const old = legacyNamePart(label)
      for (const value of NAMES) {
        expect(outcome(current, value), String(value)).toEqual(outcome(old, value))
      }
    },
  )

  it('پیکره واقعاً هر دو حالت را دارد (آزمون با ورودیِ همه‌معتبر یا همه‌نامعتبر پوچ است)', () => {
    for (const { current, rows } of PAIRS) {
      const results = rows.map((row) => outcome(current, row).success)
      expect(results).toContain(true)
      expect(results).toContain(false)
    }
  })

  it('پیام‌ها و مرزهای کلیدی همان‌اند که سرور و رابط انتظار دارند', () => {
    const name = personNamePart('نام')
    expect(outcome(name, ' ')).toEqual({
      success: false,
      // پس از trim رشته‌ی خالی است: هر سه قاعده (همان رفتارِ نسخه‌ی پیشین)
      issues: [
        ': نام دست‌کم ۲ حرف است.',
        ': نام را وارد کنید.',
        ': نام فقط با حروف فارسی نوشته شود.',
      ],
    })
    expect(outcome(name, ' علی ')).toEqual({ success: true, data: 'علی' })
    expect(outcome(name, 'ب'.repeat(NAME_PART_MAX)).success).toBe(true)
    expect(outcome(name, 'ب'.repeat(NAME_PART_MAX + 1))).toEqual({
      success: false,
      issues: [': نام بیش از 60 حرف است.'],
    })

    expect(outcome(loginSchema, { phone: '' })).toEqual({
      success: false,
      issues: [
        'phone: شماره موبایل باید به‌فرمت ۰۹xxxxxxxxx باشد',
        'phone: شماره موبایل را وارد کنید',
      ],
    })
  })
})

describe('مصرف‌کننده‌ی واقعی: zodResolver ِ react-hook-form', () => {
  const options = { fields: {}, shouldUseNativeValidation: false } as never

  it.each(PAIRS)('$name: خطاها و مقدارهای فرم یکی است', async ({ current, legacy: old, rows }) => {
    const currentResolver = zodResolver(current as never) as Resolver<FieldValues>
    const legacyResolver = zodResolver(old as never) as Resolver<FieldValues>

    for (const row of rows.slice(0, 120)) {
      const [a, b] = await Promise.all([
        currentResolver(row, undefined, options),
        legacyResolver(row, undefined, options),
      ])
      const shape = (r: Awaited<ReturnType<typeof currentResolver>>) => ({
        values: r.values,
        errors: Object.fromEntries(
          Object.entries(r.errors as Record<string, { message?: string; type?: string }>).map(
            ([k, v]) => [k, v.message],
          ),
        ),
      })
      expect(shape(a), JSON.stringify(row)).toEqual(shape(b))
    }
  })
})
