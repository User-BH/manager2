import { StrictMode, Suspense, lazy } from 'react'
import { MotionConfig } from 'framer-motion'
import { createRoot } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthPage } from '@/features/auth/AuthPage'
import { VerifyOtpPage } from '@/features/auth/VerifyOtpPage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { InvitePage } from '@/features/auth/InvitePage'
import { takeInviteTokenFromUrl } from '@/features/auth/inviteToken'
import { JoinPage } from '@/features/auth/JoinPage'
import { takeJoinCodeFromUrl } from '@/features/auth/joinCode'

/*
 * بازیابیِ حساب بی شماره‌ی قبلی (مرحلهٔ ۹) و ورود با نام کاربری (مرحلهٔ ۱۳ · فاز ۱؛ فقط سوپر ادمین و حساب‌های
 * آزمایشیِ Preview) نادرند: تنبل، و هر دو از **یک** ماژول، تا وزن و چانک‌هایشان به هر ورودِ معمولی تحمیل نشود
 * (بودجه‌ی باندلِ نشست).
 */
const loadRare = () => import('@/features/auth/rarePages')
const RecoverAccountPage = lazy(() => loadRare().then((m) => ({ default: m.RecoverAccountPage })))
const UsernameLoginPage = lazy(() => loadRare().then((m) => ({ default: m.UsernameLoginPage })))
const TwoFactorLoginPage = lazy(() => loadRare().then((m) => ({ default: m.TwoFactorLoginPage })))

/**
 * Entryِ بخشِ ورود/ثبت‌نام (island).
 *
 * برخلاف خانه/دمو/پشتیبانی، این island یک روترِ کوچک دارد چون جریانِ
 * دومرحله‌ای سه گام است (/auth ، /auth/verify ، /auth/forgot) و باید بینشان
 * حالت (شماره و کد) رد شود؛ این ناوبریِ داخلی سمتِ کلاینت است. رفتن به
 * داشبورد یا خانه اما ناوبریِ واقعیِ مرورگر است (سندِ جدا).
 */
/*
 * این صفحه یک سندِ مستقل است، پس بازدیدش همین‌جا یک بار ثبت می‌شود
 * (برخلافِ SPA که ناوبریِ داخلی دارد و در روتر ثبت می‌کند).
 */
// توکنِ پیوندِ دعوت پیش از هر پایش و تحلیلی از نشانی برداشته می‌شود (مرحلهٔ ۹)
takeInviteTokenFromUrl()
// کدِ QR ِ ورودِ عمومی هم (مرحلهٔ ۱۰ · فاز ۵)
takeJoinCodeFromUrl()
initObservability()
trackPageView(window.location.pathname)

/*
 * ⚠️ بازگشت از bfcache (مرحلهٔ ۷ · فاز ۱).
 *
 * سرور این صفحه را `no-store` می‌فرستد تا مرورگر نسخه‌ی کهنه‌اش را نگه
 * ندارد، ولی همه‌ی مرورگرها به آن پایبند نیستند. نسخه‌ای که از bfcache
 * برمی‌گردد هنوز فکر می‌کند کاربر مهمان است و فرمِ ورود را نشان می‌دهد؛
 * بارگذاریِ دوباره از سرور می‌پرسد، و سرور کاربرِ واردشده را به داشبورد
 * می‌فرستد.
 */
window.addEventListener('pageshow', (event) => {
  if (event.persisted) window.location.reload()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {/*
        ⚠️ `reducedMotion="user"` تنها راهی است که انیمیشن‌های framer-motion
        به تنظیمِ سیستمیِ «کاهش حرکت» احترام بگذارند. سه بلوکِ
        `prefers-reduced-motion` در CSS هرکدام فقط یک انیمیشنِ خاص را
        می‌گرفتند؛ `motion.div`ها از همه‌شان رد می‌شدند.
      */}
    <MotionConfig reducedMotion="user">
      <BrowserRouter>
        {/* یک Suspense برای صفحه‌های تنبل؛ بقیه‌ی مسیرها هرگز معلق نمی‌شوند */}
        <Suspense fallback={null}>
          <Routes>
            <Route path="/auth" element={<AuthPage />} />
            <Route path="/auth/verify" element={<VerifyOtpPage />} />
            <Route path="/auth/forgot" element={<ForgotPasswordPage />} />
            {/* بازیابیِ حساب بی شماره‌ی قبلی (مرحلهٔ ۹): فقط ثبتِ درخواست برای ادمینِ کل */}
            <Route path="/auth/recover" element={<RecoverAccountPage />} />
            {/* ورود با نام کاربری (۱۳/۱): فقط سوپر ادمین و حساب‌های آزمایشیِ Preview */}
            <Route path="/auth/username" element={<UsernameLoginPage />} />
            {/* گامِ دومِ سوپر ادمین: کدِ Authenticator یا کدِ بازیابی (۲۰۲۶-۱۰-۲۵) */}
            <Route path="/auth/two-factor" element={<TwoFactorLoginPage />} />
            {/* پیوندِ دعوت (مرحلهٔ ۹ · فاز ۶): فقط پیش‌نمایش؛ توکن پس از # */}
            <Route path="/invite" element={<InvitePage />} />
            {/* QR ِ ورودِ عمومیِ مجتمع (مرحلهٔ ۱۰ · فاز ۵): نامِ مجتمع و فرستادنِ درخواست؛ کد پس از # */}
            <Route path="/join" element={<JoinPage />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </MotionConfig>
  </StrictMode>,
)
