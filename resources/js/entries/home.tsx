import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { HomePage } from '@/pages/home/HomePage'

/**
 * Entryِ صفحه‌ی خانه (island).
 *
 * این صفحه یک سندِ HTMLِ مستقل است که لاراول سرو می‌کند؛ اینجا فقط همان یک
 * صفحه mount می‌شود، بدون روتر و بدون احراز (خانه به وضعیتِ ورود وابسته
 * نیست). تم store‌ی zustand است و Provider نمی‌خواهد.
 */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <HomePage />
  </StrictMode>,
)
