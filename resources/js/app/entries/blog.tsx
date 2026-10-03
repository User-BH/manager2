import { StrictMode, useEffect, useState } from 'react'
import { MotionConfig } from 'framer-motion'
import { createRoot, type Root } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { HomeNavbar } from '@/features/landing/home/components/HomeNavbar'
import { InstallPrompt } from '@/shared/pwa/InstallPrompt'
import { HomeFooter } from '@/features/landing/home/components/HomeFooter'
import { ScrollProgressBar } from '@/features/landing/home/components/ScrollProgressBar'
import { ShareButton } from '@/features/landing/blog/ShareButton'
import { initBlogNavigation } from '@/features/landing/blog/blogNavigation'

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

const isArticle = () => document.querySelector('[data-reading-progress]') !== null

/**
 * نوارِ پیشرفتِ خواندن فقط روی مقاله — و حالا با ناوبریِ بی‌بارگذاری (فاز ۷)
 * صفحه می‌تواند بی‌آنکه سند عوض شود از فهرست به مقاله برود و برگردد.
 */
function ArticleProgress() {
  const [show, setShow] = useState(isArticle)

  useEffect(() => {
    const update = () => setShow(isArticle())
    window.addEventListener('blog:navigated', update)
    return () => window.removeEventListener('blog:navigated', update)
  }, [])

  return show ? <ScrollProgressBar /> : null
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <MotionConfig reducedMotion="user">
      <ArticleProgress />
      <HomeNavbar page="blog" />
      {/* بلاگ دکمه‌ی شناور ندارد؛ پیشنهادِ نصب خودِ گوشه را می‌گیرد (فاز ۵) */}
      <InstallPrompt placement="public-bare" />
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

/*
 * دکمه‌ی «کپیِ پیوند» درونِ بدنه‌ی مقاله است؛ با هر جابه‌جاییِ بی‌بارگذاری
 * بدنه عوض می‌شود، پس ریشه‌ی React ِ قبلی برداشته و روی گره‌ی تازه سوار می‌شود.
 */
let shareRoot: Root | null = null

function mountShare() {
  const share = document.getElementById('blog-copy')
  if (!share?.dataset.url) return

  shareRoot = createRoot(share)
  shareRoot.render(
    <StrictMode>
      <ShareButton url={share.dataset.url} title={share.dataset.title ?? document.title} />
    </StrictMode>,
  )
}

mountShare()

const main = document.querySelector<HTMLElement>('main[data-blog-view]')
if (main) {
  initBlogNavigation(main, {
    beforeSwap: () => {
      shareRoot?.unmount()
      shareRoot = null
    },
    afterSwap: () => {
      mountShare()
      trackPageView(window.location.pathname)
    },
  })
}
