import { Suspense, lazy } from 'react'
import { useReauthStore } from '@/shared/stores/reauthStore'

/**
 * جای پنجره‌ی رمز در داشبورد — خودِ پنجره تنبل بار می‌شود (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ⚠️ `bundleBudget` نشان داد پنجره با فرم و اعتبارسنجی‌اش ۲۰ کیلوبایت به
 * باندلِ اولِ داشبورد می‌افزود — برای چیزی که بیشترِ کاربرها هرگز نمی‌بینند.
 * حالا فقط وقتی سرور تأیید خواست بار می‌شود.
 */
const ReauthDialog = lazy(() =>
  import('./ReauthDialog').then((module) => ({ default: module.ReauthDialog })),
)

export function ReauthHost() {
  const open = useReauthStore((state) => state.open)

  if (!open) return null

  return (
    <Suspense fallback={null}>
      <ReauthDialog />
    </Suspense>
  )
}
