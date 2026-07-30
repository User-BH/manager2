import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthPage } from '@/features/auth/AuthPage'
import { VerifyOtpPage } from '@/features/auth/VerifyOtpPage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'

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
initObservability()
trackPageView(window.location.pathname)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path="/auth" element={<AuthPage />} />
        <Route path="/auth/verify" element={<VerifyOtpPage />} />
        <Route path="/auth/forgot" element={<ForgotPasswordPage />} />
      </Routes>
    </BrowserRouter>
  </StrictMode>,
)
