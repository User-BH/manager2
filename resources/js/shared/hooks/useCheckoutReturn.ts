import { useEffect, useRef } from 'react'
import { useSearchParams } from 'react-router-dom'
import { switchContext } from '@/shared/lib/accountContext'
import { toastSuccess } from '@/shared/lib/alert'
import { useAuth } from '@/shared/stores/authStore'

/**
 * بازگشت از درگاه پس از خریدِ مجتمعِ تازه (مرحلهٔ ۱۰ · فاز ۵؛ تصمیمِ کارفرما).
 *
 * سرور (`/dashboard?checkout=success&complex=…`) فقط حسابِ **اولیه** را همان‌جا به مجتمعِ تازه می‌برد؛ عضوِ
 * مجتمع‌های دیگر در زمینه‌ی قبلی‌اش می‌ماند، چون بازگشتِ بانک تب‌های بازش را خبر نمی‌کند. اینجا خودِ کاربر
 * — در همین تب — به مجتمعِ تازه می‌رود: `switchContext` عضویت را در سرور می‌سنجد و با `enterNewContext`
 * کش را پاک و تب‌های دیگر را خبر می‌کند (همان مسیرِ ۱۰/۴).
 *
 * ⚠️ `complex` فقط **نشانی** است، نه مجوز: اگر حساب عضوِ آن نیست، سرور تعویض را رد می‌کند و اینجا هم
 * بی عضویتِ فعالِ آن مجتمع اصلاً تلاش نمی‌شود.
 */
export function useCheckoutReturn(): void {
  const [params, setParams] = useSearchParams()
  const { user } = useAuth()
  const handled = useRef(false)

  useEffect(() => {
    if (handled.current || !user || params.get('checkout') !== 'success') return
    handled.current = true

    const target = Number(params.get('complex'))
    const owns = (user.memberships ?? []).some(
      (m) => m.complexId === target && m.status !== 'suspended',
    )

    if (target && owns && user.complex?.id !== target) {
      void switchContext(target, '/dashboard?checkout=success')
      return
    }

    toastSuccess('اشتراک فعال شد و مجتمع آماده است.')
    const next = new URLSearchParams(params)
    for (const key of ['checkout', 'tracking', 'complex']) next.delete(key)
    setParams(next, { replace: true })
  }, [params, setParams, user])
}
