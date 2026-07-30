import { useCallback, useState } from 'react'
import { api } from '@/shared/lib/api'
import { alertError, confirmAction, toastSuccess } from '@/shared/lib/alert'

/**
 * اجرای یک عملیاتِ تغییردهنده (POST/PUT/PATCH/DELETE) با همه‌ی کارهای تکراری‌اش.
 *
 * پیش از این، این الگو در هشت صفحه کلمه‌به‌کلمه تکرار شده بود:
 *   ‏state «در حال ارسال» → گرفتنِ تایید → صدا زدنِ api → توست موفقیت →
 *   ‏نمایش خطا → پاک‌کردن state در finally
 * هر تکرار یک جای خطای احتمالی بود (مثلاً یادرفتنِ `finally` که دکمه را برای
 * همیشه غیرفعال می‌گذاشت). حالا منطق یک‌جاست.
 *
 * `pendingKey` برای فهرست‌ها لازم است: می‌گوید *کدام ردیف* در حال ارسال است تا
 * فقط همان یک دکمه اسپینر بگیرد، نه کلِ جدول.
 *
 * @example
 * const { run, isPending } = useMutation()
 * run(() => api(`/system/members/${m.id}`, { method: 'DELETE' }), {
 *   key: m.id,
 *   confirm: { title: 'حذف کاربر', danger: true },
 *   success: 'کاربر حذف شد.',
 *   onDone: reload,
 * })
 */

type Key = string | number

interface RunOptions {
  /** شناسه‌ی ردیف؛ برای نشان‌دادنِ وضعیتِ ارسال روی همان یک ردیف. */
  key?: Key
  /** اگر بدهید، پیش از اجرا از کاربر تایید گرفته می‌شود. */
  confirm?: Parameters<typeof confirmAction>[0]
  /** پیامِ توستِ موفقیت. */
  success?: string
  /** پیامِ جایگزین وقتی خطا شناخته نشد. */
  errorFallback?: string
  /** پس از موفقیت صدا زده می‌شود (معمولاً `reload`). */
  onDone?: () => void
}

export function useMutation() {
  const [pendingKey, setPendingKey] = useState<Key | null>(null)

  const run = useCallback(
    async <T>(action: () => Promise<T>, options: RunOptions = {}): Promise<T | undefined> => {
      const { key = '__single__', confirm, success, errorFallback, onDone } = options

      if (confirm && !(await confirmAction(confirm))) return undefined

      setPendingKey(key)
      try {
        const result = await action()
        if (success) toastSuccess(success)
        onDone?.()
        return result
      } catch (error) {
        alertError(error, errorFallback)
        return undefined
      } finally {
        // همیشه پاک می‌شود، حتی وقتی خطا رخ داده — وگرنه دکمه قفل می‌ماند
        setPendingKey(null)
      }
    },
    [],
  )

  return {
    run,
    /** آیا این ردیفِ مشخص در حال ارسال است؟ */
    isPending: useCallback((key?: Key) => pendingKey === (key ?? '__single__'), [pendingKey]),
    /** آیا هر عملیاتی در جریان است؟ */
    isBusy: pendingKey !== null,
    pendingKey,
  }
}

/** نمونه‌ی آماده برای حالتِ رایج: فقط صدا زدنِ یک endpoint. */
export function useApiMutation() {
  const mutation = useMutation()

  const call = useCallback(
    (path: string, init: Parameters<typeof api>[1], options?: RunOptions) =>
      mutation.run(() => api(path, init), options),
    [mutation],
  )

  return { ...mutation, call }
}
