import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { ThemeProvider } from '@/context/ThemeContext'
import { HomePage } from '@/pages/home/HomePage'

/**
 * Entryِ صفحه‌ی خانه (island).
 *
 * این صفحه یک سندِ HTMLِ مستقل است که لاراول سرو می‌کند؛ اینجا فقط همان یک
 * صفحه mount می‌شود، بدون روتر و بدون AuthProvider (خانه به وضعیتِ ورود
 * وابسته نیست). ناوبری به صفحه‌های دیگر، ناوبریِ واقعیِ مرورگر است.
 */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      <HomePage />
    </ThemeProvider>
  </StrictMode>,
)
