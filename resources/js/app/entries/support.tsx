import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { SupportPage } from '@/features/landing/support/SupportPage'

/** Entryِ صفحه‌ی پشتیبانی (island). تم store‌ی zustand است و Provider نمی‌خواهد. */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <SupportPage />
  </StrictMode>,
)
