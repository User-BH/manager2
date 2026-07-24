import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { ThemeProvider } from '@/context/ThemeContext'
import { DemoPage } from '@/pages/demo/DemoPage'

/** Entryِ صفحه‌ی دمو (island). */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      <DemoPage />
    </ThemeProvider>
  </StrictMode>,
)
