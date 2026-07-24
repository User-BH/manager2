import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { ThemeProvider } from '@/context/ThemeContext'
import { SupportPage } from '@/pages/support/SupportPage'

/** Entryِ صفحه‌ی پشتیبانی (island). */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      <SupportPage />
    </ThemeProvider>
  </StrictMode>,
)
