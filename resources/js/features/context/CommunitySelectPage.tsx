import { Building2, ChevronLeft, Loader2, LogOut } from 'lucide-react'
import { LogoMark } from '@/shared/common/LogoMark'
import { useDocumentTitle } from '@/shared/hooks'
import { useAction } from '@/shared/hooks/useAction'
import { switchContext } from '@/shared/lib/accountContext'
import { leaveToAuth } from '@/shared/lib/leaveToAuth'
import { useAuth } from '@/shared/stores/authStore'

/**
 * صفحه‌ی انتخابِ مجتمع پس از ورودِ حسابِ چندمجتمعی (مرحلهٔ ۱۳ · فاز ۳؛ ایرادات ۱۰، بندهای ۳۳ تا ۳۵؛ ایرادات ۱۱،
 * بندِ ۵.۵).
 *
 * یک کادرِ مرکزیِ ساده روی پس‌زمینه‌ی نرم؛ هر مجتمعِ معتبر یک کارت. کلیک همان تعویضِ واقعیِ زمینه است
 * (`switchContext` ← `POST account/context`)، و سرور تا مجتمعی برگزیده نشده هیچ داده‌ی مجتمعی نمی‌دهد و جز همین
 * گزینش و خروج هیچ نوشتنی را نمی‌پذیرد (`LockInitialAccount`). بستنِ صفحه چیزی برنمی‌گزیند: ورودِ بعدی دوباره
 * همین‌جاست.
 *
 * ⚠️ «ایجادِ حسابِ جدید» عمداً این‌جا نیست (بندِ ۵.۵): آن جریان فقط از منوی آدمکِ داشبوردِ مجتمع آغاز می‌شود.
 */
export function CommunitySelectPage() {
  useDocumentTitle('انتخاب مجتمع')
  const { user, logout } = useAuth()
  const { run, pendingKey } = useAction()

  // فقط مجتمع‌هایی که واقعاً می‌شود واردشان شد؛ معلق‌ها را سرور هم رد می‌کند (ایرادات ۱۱، بندِ ۵.۳)
  const communities = (user?.memberships ?? []).filter((m) => m.status === 'active')

  async function signOut() {
    try {
      await logout()
    } catch {
      // حالتِ محلی و کش از قبل پاک شده‌اند؛ بیرون بردنِ کاربر مهم‌تر است (همان قاعده‌ی منوی کاربر)
    }
    leaveToAuth()
  }

  return (
    <div
      dir="rtl"
      className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10"
      style={{ backgroundColor: 'var(--surface-canvas)' }}
    >
      {/* پس‌زمینه‌ی نرم: دو هاله‌ی محوِ رنگِ برند — بی تصویر و بی بارِ اضافه */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0"
        style={{
          background:
            'radial-gradient(40rem 28rem at 85% 10%, color-mix(in srgb, var(--color-brand-500) 18%, transparent), transparent 70%), radial-gradient(36rem 26rem at 10% 95%, color-mix(in srgb, var(--color-brand-400, var(--color-brand-500)) 14%, transparent), transparent 70%)',
        }}
      />

      <main
        id="main-content"
        className="relative w-full max-w-md rounded-3xl border p-6 shadow-xl backdrop-blur-md sm:p-8"
        style={{
          backgroundColor: 'color-mix(in srgb, var(--surface-raised) 84%, transparent)',
          borderColor: 'var(--border-subtle)',
        }}
      >
        <div className="flex flex-col items-center gap-3 text-center">
          <LogoMark size={44} />
          <h1 className="text-lg font-extrabold" style={{ color: 'var(--text-primary)' }}>
            انتخاب مجتمع
          </h1>
          <p className="text-body leading-6" style={{ color: 'var(--text-secondary)' }}>
            {user?.name ? `${user.name}، ` : ''}با همین حساب در چند مجتمع عضو هستید. به کدام
            می‌روید؟
          </p>
        </div>

        <ul className="mt-6 flex flex-col gap-2.5" aria-label="مجتمع‌های شما">
          {communities.map((m) => {
            const pending = pendingKey === m.complexId

            return (
              <li key={m.complexId}>
                <button
                  type="button"
                  onClick={() => void run(() => switchContext(m.complexId), { key: m.complexId })}
                  disabled={pendingKey !== null}
                  aria-busy={pending || undefined}
                  className="flex min-h-14 w-full items-center gap-3 rounded-2xl border px-4 py-3 text-right transition-colors duration-150 hover:bg-(--surface-sunken) focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-60"
                  style={{
                    borderColor: 'var(--border-default)',
                    backgroundColor: 'var(--surface-base)',
                  }}
                >
                  <span
                    className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                    style={{
                      backgroundColor:
                        'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
                      color: 'var(--color-brand-600)',
                    }}
                  >
                    <Building2 size={19} aria-hidden="true" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span
                      className="block truncate text-sm font-bold"
                      style={{ color: 'var(--text-primary)' }}
                    >
                      {m.complexName}
                    </span>
                    <span className="block text-xs" style={{ color: 'var(--text-tertiary)' }}>
                      {[m.complexTypeLabel, m.roleLabel].filter(Boolean).join(' · ')}
                    </span>
                  </span>
                  {pending ? (
                    <Loader2
                      size={16}
                      className="shrink-0 animate-spin"
                      aria-hidden="true"
                      style={{ color: 'var(--color-brand-500)' }}
                    />
                  ) : (
                    <ChevronLeft
                      size={16}
                      className="shrink-0"
                      aria-hidden="true"
                      style={{ color: 'var(--text-tertiary)' }}
                    />
                  )}
                </button>
              </li>
            )
          })}
        </ul>

        <button
          type="button"
          onClick={() => void signOut()}
          className="mx-auto mt-6 flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold transition-colors hover:bg-(--surface-sunken)"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <LogOut size={14} aria-hidden="true" />
          خروج از حساب
        </button>
      </main>
    </div>
  )
}
