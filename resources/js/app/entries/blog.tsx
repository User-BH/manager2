import { StrictMode } from 'react'
import { MotionConfig } from 'framer-motion'
import { createRoot } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { HomeNavbar } from '@/features/landing/home/components/HomeNavbar'
import { HomeFooter } from '@/features/landing/home/components/HomeFooter'
import { ScrollProgressBar } from '@/features/landing/home/components/ScrollProgressBar'
import { ShareButton } from '@/features/landing/blog/ShareButton'

/*
 * Entryِ بلاگ (مرحلهٔ ۶ · فاز ۱۱).
 *
 * ⚠️ برخلافِ بقیه‌ی جزیره‌ها، **محتوا را React نمی‌سازد**: فهرست و متنِ
 * مقاله در HTMLِ سرورند (`public/blog/*.blade.php`). این‌جا فقط چیزهایی که
 * بدونشان هم صفحه کامل است، روی همان HTML سوار می‌شوند:
 *
 * - سرصفحه و پاصفحه‌ی مشترکِ سایت
 * - نوارِ پیشرفتِ خواندن — فقط روی صفحه‌ی مقاله
 * - دکمه‌ی اشتراک و کپیِ پیوند
 */
// ⚠️ بدونِ تگ‌های بازاریابی (ضبطِ جلسه، GTM): قاعده‌ی فاز ۷ فقط خانه، دمو و
// پشتیبانی را مجاز می‌داند و خواندنِ مقاله دلیلی برای ضبطِ جلسه نیست
initObservability()
trackPageView(window.location.pathname)

const isArticle = document.querySelector('[data-reading-progress]') !== null

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <MotionConfig reducedMotion="user">
      {isArticle && <ScrollProgressBar />}
      <HomeNavbar page="blog" />
    </MotionConfig>
  </StrictMode>,
)

const footer = document.getElementById('blog-footer')
if (footer) {
  createRoot(footer).render(
    <StrictMode>
      <HomeFooter page="blog" />
    </StrictMode>,
  )
}

const share = document.getElementById('blog-copy')
if (share?.dataset.url) {
  createRoot(share).render(
    <StrictMode>
      <ShareButton url={share.dataset.url} title={share.dataset.title ?? document.title} />
    </StrictMode>,
  )
}
