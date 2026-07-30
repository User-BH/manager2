import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { HomePage } from '@/features/landing/home/HomePage'

/**
 * Entryِ صفحه‌ی خانه (island).
 *
 * این صفحه یک سندِ HTMLِ مستقل است که لاراول سرو می‌کند؛ اینجا فقط همان یک
 * صفحه mount می‌شود، بدون روتر و بدون احراز (خانه به وضعیتِ ورود وابسته
 * نیست). تم store‌ی zustand است و Provider نمی‌خواهد.
 */
/*
 * این صفحه یک سندِ مستقل است، پس بازدیدش همین‌جا یک بار ثبت می‌شود
 * (برخلافِ SPA که ناوبریِ داخلی دارد و در روتر ثبت می‌کند).
 */
initObservability()
trackPageView(window.location.pathname)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <HomePage />
  </StrictMode>,
)
