import { useCallback, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'

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
 * ─── چرا اسمش `useAction` است و نه `useMutation`؟ ──────────────────────────
 * چون `useMutation` نامِ هوکِ خودِ TanStack Query است. دو چیزِ متفاوت با یک
 * نام، برای کسی که تازه وارد پروژه می‌شود تله است. این هوک لایه‌ی رویی است:
 * تاییدِ کاربر، توست، و ابطالِ کش.
 *
 * `pendingKey` برای فهرست‌ها لازم است: می‌گوید *کدام ردیف* در حال ارسال است تا
 * فقط همان یک دکمه اسپینر بگیرد، نه کلِ جدول. (`useMutation`ِ تنستک یک
 * `isPending` سراسری می‌دهد که برای جدول کافی نیست.)
 *
 * @example
 * const { call, isPending } = useApiAction()
 * void call(`/system/members/${m.id}`, { method: 'DELETE' }, {
 *   key: m.id,
 *   confirm: { title: 'حذف کاربر', danger: true },
 *   success: 'کاربر حذف شد.',
 *   invalidate: [queryKeys.members.all()],
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
  /**
   * کلیدهایی که پس از موفقیت باید باطل شوند.
   *
   * جای `reload()`ِ دستی را می‌گیرد: به‌جای اینکه صفحه خودش دوباره بگیرد،
   * می‌گوییم «این داده کهنه شد» و هر مصرف‌کننده‌ای در هر گوشه‌ی برنامه که آن
   * کلید را دارد تازه می‌شود — نه فقط همان صفحه‌ای که دکمه در آن بود.
   */
  invalidate?: readonly (readonly unknown[])[]
  /** پس از موفقیت صدا زده می‌شود. */
  onDone?: () => void
}

export function useAction() {
  const [pendingKey, setPendingKey] = useState<Key | null>(null)
  const queryClient = useQueryClient()

  const run = useCallback(
    async <T>(action: () => Promise<T>, options: RunOptions = {}): Promise<T | undefined> => {
      const { key = '__single__', confirm, success, errorFallback, invalidate, onDone } = options

      if (confirm && !(await confirmAction(confirm))) return undefined

      setPendingKey(key)
      try {
        const result = await action()
        if (success) toastSuccess(success)

        if (invalidate) {
          await Promise.all(
            invalidate.map((queryKey) => queryClient.invalidateQueries({ queryKey })),
          )
        }

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
    [queryClient],
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
export function useApiAction() {
  const action = useAction()

  const call = useCallback(
    (path: string, init: Parameters<typeof api>[1], options?: RunOptions) =>
      action.run(() => api(path, init), options),
    [action],
  )

  return { ...action, call }
}
