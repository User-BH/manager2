import { ScrollProgressBar } from './components/ScrollProgressBar'
import { HomeNavbar } from './components/HomeNavbar'
import { HeroSection } from './components/HeroSection'
import { StatsSection } from './components/StatsSection'
import { FeaturesSection } from './components/FeaturesSection'
import { CtaSection } from './components/CtaSection'
import { HomeFooter } from './components/HomeFooter'
import { FloatingActions } from './components/FloatingActions'
import { LazyVisible } from '@/shared/ui/LazyVisible'

export function HomePage() {
  // عنوان و متادیتا سمتِ سرور تنظیم می‌شوند (SEO)، پس اینجا document.title را
  // بازنویسی نمی‌کنیم.
  return (
    // overflow-x-clip تورِ ایمنی است: اگر عنصری (مثلاً انیمیشنِ ورودِ یک بخش
    // که هنوز اجرا نشده) کمی از لبه بیرون بزند، صفحه اسکرول افقی و قابلیت
    // zoom out پیدا نکند. عمداً clip است نه hidden، چون hidden یک کانتینر
    // اسکرول می‌سازد و position: sticky داخلش را خراب می‌کند.
    <div className="overflow-x-clip" style={{ backgroundColor: 'var(--surface-canvas)' }}>
      <ScrollProgressBar />
      <HomeNavbar />

      <main id="main-content" tabIndex={-1}>
        <HeroSection />
        <StatsSection />
        {/*
          ⚠️ هر دو بخشِ اسلایدری تنبل‌اند (R36).

          Swiper ‎۲۷٫۵KB فشرده است و پیش از این در بارگذاریِ **اولِ** صفحه‌ی
          فرود می‌آمد — همان صفحه‌ای که سرعتش برای SEO مهم است — در حالی که
          هر دو اسلایدر پایین‌تر از تا هستند و بازدیدکننده ممکن است اصلاً
          تا آن‌ها نرسد.
        */}
        <LazyVisible load={() => import('./components/AdBannerSection')} height={224} />
        <FeaturesSection />
        <LazyVisible load={() => import('./components/GallerySwiperSection')} height={520} />
        <LazyVisible load={() => import('./components/TestimonialsSection')} height={420} />
        <CtaSection />
      </main>

      <HomeFooter />

      <FloatingActions />
    </div>
  )
}
