import { z } from 'zod'

/**
 * فرمِ مقاله (مرحلهٔ ۶ · فاز ۱۱) — همان سقف‌های `SaveBlogPostRequest`.
 *
 * ⚠️ نامک اختیاری است: خالی یعنی «از عنوان بساز». اگر پر شود، فقط حرف و
 * عدد و خط‌تیره — همان قاعده‌ی سرور.
 */
export const blogPostSchema = z.object({
  title: z.string().trim().min(1, 'عنوان را وارد کنید.').max(200, 'عنوان حداکثر ۲۰۰ نویسه است.'),
  slug: z
    .string()
    .trim()
    .max(180)
    .regex(/^[\p{L}\p{N}-]*$/u, 'نامک فقط حرف، عدد و خط‌تیره می‌پذیرد.'),
  excerpt: z.string().trim().max(400, 'خلاصه حداکثر ۴۰۰ نویسه است.'),
  body: z.string().trim().min(1, 'متنِ مقاله را بنویسید.').max(200_000),
  categoryId: z.string(),
  tags: z.string().max(600),
  status: z.enum(['draft', 'published']),
  /** مقدارِ `datetime-local` (وقتِ محلیِ مرورگر)؛ خالی یعنی «همین حالا» */
  publishedAt: z.string(),
  cover: z.string(),
  metaTitle: z.string().trim().max(200),
  metaDescription: z.string().trim().max(300, 'توضیحِ سئو حداکثر ۳۰۰ نویسه است.'),
})

export type BlogPostValues = z.infer<typeof blogPostSchema>

/** «مالی، شارژ ،  آسانسور» → ['مالی', 'شارژ', 'آسانسور'] */
export function parseTags(value: string): string[] {
  return [
    ...new Set(
      value
        .split(/[,،]/)
        .map((tag) => tag.trim())
        .filter(Boolean),
    ),
  ].slice(0, 10)
}

/** ISO از سرور → مقدارِ `datetime-local` به وقتِ همین مرورگر. */
export function toLocalInput(iso: string | null): string {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/**
 * مقدارِ `datetime-local` → ISOِ UTC.
 *
 * 🔴 بدونِ این، «ساعتِ ۱۰» که مدیر در تهران می‌زند، روی سرورِ UTC «ساعتِ ۱۰ِ
 * UTC» خوانده می‌شد — سه ساعت و نیم دیرتر از خواسته‌اش منتشر می‌شد.
 */
export function fromLocalInput(value: string): string | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date.toISOString()
}
