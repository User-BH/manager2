import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { ArrowRight, History } from 'lucide-react'
import { api } from '@/shared/lib/api'
import { queryKeys } from '@/shared/lib/queryKeys'
import { alertInfo } from '@/shared/lib/alert'
import { useDocumentTitle } from '@/shared/hooks'
import { useAuth } from '@/shared/stores/authStore'
import { CardListSkeleton } from '@/shared/ui/Skeleton'
import { ErrorState } from '@/shared/ui/PageState'
import { PAYMENT_HISTORY_PATH, PaymentHistory, PlanWizard } from '@/shared/ui/plans/PlanWizard'
import type { AccountSettingsResponse } from '@/shared/ui/plans/types'

/**
 * ساختِ مجتمع و خریدِ اشتراک — «حساب و اشتراک» ِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۵؛ از ۱۳/۶ جادوگرِ ایرادات ۱۰).
 *
 *   نوعِ مجتمع ← پلن ← مدتِ اشتراک ← تأیید (نام و نشانیِ مجتمع) ← پرداختِ آنلاین ←
 *   تأییدِ سرور ← مجتمع + اشتراک + مدیر در یک تراکنش ← داشبوردِ مدیر
 *
 * بی «تمدیدِ پلنِ فعلی» (بندِ ۳): حسابِ اولیه پلنی ندارد. مبلغی که نشان داده می‌شود فقط نمایش است؛ سرور پلن، مدت و
 * مبلغ را از جدولِ قیمت می‌خواند (`ComplexPurchase`).
 */
export function RegistrantPurchasePage() {
  const historyView = useLocation().pathname === PAYMENT_HISTORY_PATH
  useDocumentTitle(historyView ? 'تاریخچه پرداخت‌ها' : 'ساختِ مجتمع و اشتراک')
  const [params, setParams] = useSearchParams()
  // عضوِ مجتمع‌های دیگر هم از همین صفحه می‌خرد (مرحلهٔ ۱۰ · فاز ۵)
  const isMember = useAuth().user?.accountState === 'member'

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: queryKeys.subscription.all(),
    queryFn: ({ signal }) => api<AccountSettingsResponse>('/subscription', { signal }),
  })

  /* بازگشت از درگاهِ ناموفق با پارامتر در آدرس اعلام می‌شود؛ موفق به داشبوردِ مدیر می‌رود */
  useEffect(() => {
    const checkout = params.get('checkout')
    if (!checkout) return

    if (checkout === 'failed') {
      void alertInfo('پرداخت انجام نشد.', 'مجتمعی ساخته نشد. می‌توانید دوباره تلاش کنید.')
    } else if (checkout === 'unknown') {
      void alertInfo(
        'نتیجه‌ی پرداخت مشخص نشد.',
        'ارتباط با درگاه قطع شد و وضعیت روشن نیست. تا بررسی دوباره پرداخت نکنید؛ اگر مبلغی کسر شده باشد پیگیری می‌شود.',
      )
    } else if (checkout === 'review') {
      void alertInfo(
        'پرداخت تأیید شد ولی مجتمع ساخته نشد.',
        'دوباره پرداخت نکنید: اگر حساب‌تان دوباره فعال شود مجتمع خودکار ساخته می‌شود، وگرنه مبلغ بازگردانده می‌شود.',
      )
    } else if (checkout === 'error') {
      void alertInfo('خرید انجام نشد.', params.get('message') ?? undefined)
    }

    const next = new URLSearchParams(params)
    next.delete('checkout')
    next.delete('message')
    setParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  if (isLoading) return <CardListSkeleton items={2} />
  if (error) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return null

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
            {historyView ? 'تاریخچه پرداخت‌ها' : 'ساختِ مجتمع و خریدِ اشتراک'}
          </h1>
          {!historyView && (
            <p className="mt-1 text-sm leading-6" style={{ color: 'var(--text-secondary)' }}>
              {isMember
                ? 'پس از پرداختِ موفق، مجتمعِ تازه ساخته می‌شود و مدیرِ آن می‌شوید. عضویت و نقشتان در مجتمع‌های فعلی دست نمی‌خورد؛ پس از پرداخت به مجتمعِ تازه می‌روید و از «مجتمع‌های من» برمی‌گردید.'
                : 'پس از پرداختِ موفق، مجتمعتان ساخته می‌شود و مدیرِ آن می‌شوید. اگر مالک یا مستأجرِ یک واحد هستید، اشتراک لازم نیست؛ کافی است مدیرِ ساختمان شما را اضافه کند.'}
            </p>
          )}
        </div>
        <Link
          to={historyView ? '/account' : PAYMENT_HISTORY_PATH}
          className="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-body font-semibold"
          style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
        >
          {historyView ? <ArrowRight size={15} /> : <History size={15} />}
          {historyView ? 'بازگشت به خرید' : 'تاریخچه پرداخت‌ها'}
        </Link>
      </div>

      {historyView ? <PaymentHistory /> : <PlanWizard data={data} />}
    </div>
  )
}
