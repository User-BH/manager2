import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { DemoPage } from '@/features/landing/demo/DemoPage'

/** Entryِ صفحه‌ی دمو (island). تم store‌ی zustand است و Provider نمی‌خواهد. */
/*
 * این صفحه یک سندِ مستقل است، پس بازدیدش همین‌جا یک بار ثبت می‌شود
 * (برخلافِ SPA که ناوبریِ داخلی دارد و در روتر ثبت می‌کند).
 */
initObservability()
trackPageView(window.location.pathname)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <DemoPage />
  </StrictMode>,
)
