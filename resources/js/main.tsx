import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
// تم و احراز حالا store‌های zustand‌اند و به Provider نیاز ندارند؛
// خودِ import‌شدنِ useAuth، وضعیتِ نشست را از سرور می‌گیرد.

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
)
