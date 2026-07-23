import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowRight, ShieldCheck } from 'lucide-react'
import { AuthTabs, type AuthTab } from './components/AuthTabs'
import { LoginForm } from './components/LoginForm'
import { RegisterForm } from './components/RegisterForm'
import { ThemeToggle } from '@/components/layout/ThemeToggle'
import { LogoMark } from '@/components/common/LogoMark'
import { authBackgroundImage } from '@/data/images'
import { useAuth } from '@/context/AuthContext'
import { useDocumentTitle } from '@/hooks'
import { BRAND_NAME } from '@/config/brand'

export function AuthPage() {
  const [searchParams] = useSearchParams()
  const initialTab: AuthTab = searchParams.get('tab') === 'register' ? 'register' : 'login'
  const [tab, setTab] = useState<AuthTab>(initialTab)
  const { isAuthenticated, isLoading } = useAuth()
  const location = useLocation()

  useDocumentTitle(tab === 'login' ? 'ورود به پنل' : 'ثبت‌نام')

  useEffect(() => {
    setTab(searchParams.get('tab') === 'register' ? 'register' : 'login')
  }, [searchParams])

  /*
   * کاربری که از قبل وارد شده نباید صفحه‌ی ورود را ببیند.
   *
   * `state.from` را ProtectedRoute می‌گذارد و تا حالا نادیده گرفته می‌شد.
   * اهمیتش وقتی روشن می‌شود که نشست کاربر وسط پرداخت منقضی شده باشد: بازگشت
   * از بانک او را به اینجا می‌آورد و بعد از ورود باید به همان
   * `/my-bills?payment=success&tracking=…` برگردد تا نتیجه‌ی پرداخت و کد
   * رهگیری‌اش را ببیند، نه به داشبورد.
   */
  if (!isLoading && isAuthenticated) {
    const from = (location.state as { from?: { pathname: string; search?: string } } | null)?.from

    return <Navigate to={from ? `${from.pathname}${from.search ?? ''}` : '/dashboard'} replace />
  }

  return (
    <div className="flex min-h-screen" style={{ backgroundColor: 'var(--surface-canvas)' }}>
      {/* پنل تصویری سمت چپ - فقط در دسکتاپ */}
      <div className="relative hidden w-1/2 overflow-hidden lg:block">
        <img
          src={authBackgroundImage}
          alt="نمای مجتمع مسکونی"
          width={1100}
          height={733}
          decoding="async"
          className="h-full w-full object-cover"
        />
        <div
          className="absolute inset-0"
          style={{
            background:
              'linear-gradient(135deg, color-mix(in srgb, var(--color-brand-700) 88%, transparent), color-mix(in srgb, var(--color-brand-400) 70%, transparent))',
          }}
        />

        <div className="relative flex h-full flex-col justify-between p-10 text-white" dir="rtl">
          <div className="flex items-center gap-2.5">
            <LogoMark size={34} monochrome />
            <span className="text-sm font-bold">{BRAND_NAME}</span>
          </div>

          <motion.div
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6, delay: 0.15 }}
          >
            <h2 className="text-2xl font-extrabold leading-relaxed">
              با {BRAND_NAME}، مدیریت مجتمع را به ساده‌ترین شکل ممکن تجربه کنید
            </h2>
            <div className="mt-5 flex items-center gap-2 text-sm text-white/85">
              <ShieldCheck size={16} />
              اطلاعات شما با بالاترین استاندارد امنیتی محافظت می‌شود
            </div>
          </motion.div>
        </div>
      </div>

      {/* پنل فرم */}
      <div className="flex w-full flex-col px-5 py-6 sm:px-10 lg:w-1/2 lg:px-16">
        <div className="flex items-center justify-between" dir="rtl">
          <Link
            to="/"
            className="flex items-center gap-1.5 text-sm font-medium"
            style={{ color: 'var(--text-secondary)' }}
          >
            <ArrowRight size={16} />
            بازگشت به صفحه اصلی
          </Link>
          <ThemeToggle />
        </div>

        <div className="flex flex-1 flex-col items-center justify-center py-10">
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5 }}
            className="w-full max-w-sm"
            dir="rtl"
          >
            <div className="mb-7 text-center">
              <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
                {tab === 'login' ? 'ورود به پنل مدیریت' : 'ساخت حساب مجتمع جدید'}
              </h1>
              <p className="mt-2 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
                {tab === 'login'
                  ? 'با شماره موبایل و رمز عبور خود وارد شوید'
                  : 'در کمتر از ۵ دقیقه پنل مجتمع خود را راه‌اندازی کنید'}
              </p>
            </div>

            {/*
              * دلیلِ بیرون‌انداخته‌شدن، اگر کاربر با نشستِ بسته‌شده به اینجا
              * رسیده باشد (مثلاً حسابش وسط کار غیرفعال شده). بدون این، فقط
              * ناگهان روی صفحه‌ی ورود می‌افتاد بی‌هیچ توضیحی.
              */}
            {searchParams.get('reason') && (
              <div
                className="mb-5 flex items-start gap-2 rounded-xl border px-3.5 py-3 text-[12.5px] leading-6"
                style={{
                  borderColor: 'var(--color-danger)',
                  backgroundColor: 'color-mix(in srgb, var(--color-danger) 10%, transparent)',
                  color: 'var(--text-primary)',
                }}
              >
                <ShieldCheck size={16} className="mt-0.5 shrink-0" style={{ color: 'var(--color-danger)' }} />
                <span>{searchParams.get('reason')}</span>
              </div>
            )}

            <AuthTabs active={tab} onChange={setTab} />

            <div className="mt-7">
              <AnimatePresence mode="wait">
                {tab === 'login' ? (
                  <LoginForm key="login" />
                ) : (
                  <RegisterForm key="register" onRegistered={() => setTab('login')} />
                )}
              </AnimatePresence>
            </div>

            <p className="mt-6 text-center text-xs" style={{ color: 'var(--text-tertiary)' }}>
              {tab === 'login' ? 'هنوز حساب مجتمع نساخته‌اید؟ ' : 'حساب مجتمع دارید؟ '}
              <button
                onClick={() => setTab(tab === 'login' ? 'register' : 'login')}
                className="font-semibold"
                style={{ color: 'var(--color-brand-600)' }}
              >
                {tab === 'login' ? 'ثبت‌نام کنید' : 'وارد شوید'}
              </button>
            </p>
          </motion.div>
        </div>
      </div>
    </div>
  )
}
