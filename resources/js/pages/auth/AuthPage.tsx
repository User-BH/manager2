import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowRight, ShieldCheck } from 'lucide-react'
import { LoginForm } from './components/LoginForm'
import { RegisterForm } from './components/RegisterForm'
import { ThemeToggle } from '@/components/layout/ThemeToggle'
import { LogoMark } from '@/components/common/LogoMark'
import { authBackgroundImage } from '@/data/images'
import { useAuth } from '@/context/AuthContext'
import { useDocumentTitle } from '@/hooks'
import { BRAND_NAME } from '@/config/brand'

type AuthTab = 'login' | 'register'

const SLIDE = { type: 'spring', stiffness: 260, damping: 30 } as const

/**
 * صفحه‌ی ورود/ثبت‌نام با انیمیشنِ کشویی.
 *
 * روی دسکتاپ دو پنل کنار هم‌اند: تصویرِ برند و فرم. با تعویض بین ورود و
 * ثبت‌نام، تصویر و فرم هم‌زمان به سمت مخالف سُر می‌خورند و محتوای فرم عوض
 * می‌شود. روی موبایل، جایی برای دو پنل نیست، پس فقط فرم با یک تعویضِ ملایم
 * نشان داده می‌شود.
 */
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

  if (!isLoading && isAuthenticated) {
    const from = (location.state as { from?: { pathname: string; search?: string } } | null)?.from
    return <Navigate to={from ? `${from.pathname}${from.search ?? ''}` : '/dashboard'} replace />
  }

  const isLogin = tab === 'login'
  const reason = searchParams.get('reason')

  const heading = (
    <div className="mb-6 text-center">
      <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
        {isLogin ? 'ورود به پنل مدیریت' : 'ساخت حساب مجتمع جدید'}
      </h1>
      <p className="mt-2 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
        {isLogin
          ? 'با شماره موبایل و رمز عبور خود وارد شوید'
          : 'در کمتر از ۵ دقیقه پنل مجتمع خود را راه‌اندازی کنید'}
      </p>
    </div>
  )

  const reasonBanner = reason && (
    <div
      className="mb-5 flex items-start gap-2 rounded-xl border px-3.5 py-3 text-[12.5px] leading-6"
      style={{
        borderColor: 'var(--color-danger)',
        backgroundColor: 'color-mix(in srgb, var(--color-danger) 10%, transparent)',
        color: 'var(--text-primary)',
      }}
    >
      <ShieldCheck size={16} className="mt-0.5 shrink-0" style={{ color: 'var(--color-danger)' }} />
      <span>{reason}</span>
    </div>
  )

  const switcher = (
    <p className="mt-6 text-center text-xs" style={{ color: 'var(--text-tertiary)' }}>
      {isLogin ? 'هنوز حساب مجتمع نساخته‌اید؟ ' : 'حساب مجتمع دارید؟ '}
      <button
        onClick={() => setTab(isLogin ? 'register' : 'login')}
        className="font-semibold"
        style={{ color: 'var(--color-brand-600)' }}
      >
        {isLogin ? 'ثبت‌نام کنید' : 'وارد شوید'}
      </button>
    </p>
  )

  const formArea = (
    <div className="w-full max-w-sm" dir="rtl">
      {heading}
      {reasonBanner}
      {/*
        بدون AnimatePresence با mode="wait": آن حالت منتظر پایانِ انیمیشنِ
        خروجِ فرم قبلی می‌ماند و اگر انیمیشن اجرا نشود (مثلاً تبِ پس‌زمینه که
        requestAnimationFrame در آن معلق است) فرمِ تازه هرگز سوار نمی‌شود.
        هر فرم خودش انیمیشن ورود دارد؛ تعویض مستقیم امن‌تر است.
      */}
      {isLogin ? (
        <LoginForm key="login" />
      ) : (
        <RegisterForm key="register" onRegistered={() => setTab('login')} />
      )}
      {switcher}
    </div>
  )

  const brandPanel = (
    <>
      <img
        src={authBackgroundImage}
        alt="نمای مجتمع مسکونی"
        className="h-full w-full object-cover"
        draggable={false}
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

        <AnimatePresence mode="wait">
          <motion.div
            key={tab}
            initial={{ opacity: 0, y: 18 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -18 }}
            transition={{ duration: 0.4 }}
          >
            <h2 className="text-2xl font-extrabold leading-relaxed">
              {isLogin
                ? `با ${BRAND_NAME}، مدیریت مجتمع را به ساده‌ترین شکل ممکن تجربه کنید`
                : 'همین حالا مجتمع خود را بسازید و مدیریتش را ساده کنید'}
            </h2>
            <div className="mt-5 flex items-center gap-2 text-sm text-white/85">
              <ShieldCheck size={16} />
              {isLogin
                ? 'ورود دومرحله‌ای با پیامک، برای امنیت بیشتر'
                : 'اطلاعات شما با بالاترین استاندارد امنیتی محافظت می‌شود'}
            </div>
          </motion.div>
        </AnimatePresence>
      </div>
    </>
  )

  return (
    <div className="min-h-screen" style={{ backgroundColor: 'var(--surface-canvas)' }}>
      {/* نوار بالا */}
      <div className="flex items-center justify-between px-5 py-4 sm:px-8" dir="rtl">
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

      {/* --- دسکتاپ: دو پنلِ کشویی --- */}
      <div
        className="relative mx-4 mb-6 hidden overflow-hidden rounded-3xl border lg:block"
        style={{ height: 'calc(100vh - 6rem)', borderColor: 'var(--border-subtle)' }}
      >
        {/* پنل فرم — زیرِ پنل تصویر */}
        <motion.div
          className="absolute top-0 flex h-full w-1/2 items-center justify-center overflow-y-auto px-10 py-8"
          style={{ left: 0, zIndex: 10, backgroundColor: 'var(--surface-canvas)' }}
          animate={{ x: isLogin ? '100%' : '0%' }}
          transition={SLIDE}
        >
          {formArea}
        </motion.div>

        {/* پنل تصویر — رویِ پنل فرم می‌لغزد */}
        <motion.div
          className="absolute top-0 h-full w-1/2 overflow-hidden"
          style={{ left: 0, zIndex: 20 }}
          animate={{ x: isLogin ? '0%' : '100%' }}
          transition={SLIDE}
        >
          {brandPanel}
        </motion.div>
      </div>

      {/* --- موبایل: فقط فرم --- */}
      <div className="flex items-start justify-center px-5 pb-12 pt-4 lg:hidden">
        {formArea}
      </div>
    </div>
  )
}
