import { useDocumentTitle } from '@/hooks'
import { ScrollProgressBar } from './components/ScrollProgressBar'
import { HomeNavbar } from './components/HomeNavbar'
import { HeroSection } from './components/HeroSection'
import { StatsSection } from './components/StatsSection'
import { AdBannerSection } from './components/AdBannerSection'
import { FeaturesSection } from './components/FeaturesSection'
import { GallerySwiperSection } from './components/GallerySwiperSection'
import { TestimonialsSection } from './components/TestimonialsSection'
import { CtaSection } from './components/CtaSection'
import { HomeFooter } from './components/HomeFooter'
import { FloatingActions } from './components/FloatingActions'

export function HomePage() {
  useDocumentTitle('صفحه اصلی')

  return (
    // overflow-x-clip تورِ ایمنی است: اگر عنصری (مثلاً انیمیشنِ ورودِ یک بخش
    // که هنوز اجرا نشده) کمی از لبه بیرون بزند، صفحه اسکرول افقی و قابلیت
    // zoom out پیدا نکند. عمداً clip است نه hidden، چون hidden یک کانتینر
    // اسکرول می‌سازد و position: sticky داخلش را خراب می‌کند.
    <div className="overflow-x-clip" style={{ backgroundColor: 'var(--surface-canvas)' }}>
      <ScrollProgressBar />
      <HomeNavbar />

      <main>
        <HeroSection />
        <StatsSection />
        <AdBannerSection />
        <FeaturesSection />
        <GallerySwiperSection />
        <TestimonialsSection />
        <CtaSection />
      </main>

      <HomeFooter />

      <FloatingActions />
    </div>
  )
}
