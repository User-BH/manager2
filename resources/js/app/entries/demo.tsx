import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { DemoPage } from '@/features/landing/demo/DemoPage'

/** Entryِ صفحه‌ی دمو (island). تم store‌ی zustand است و Provider نمی‌خواهد. */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <DemoPage />
  </StrictMode>,
)
