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

export function HomePage() {
  useDocumentTitle('صفحه اصلی')

  return (
    <div style={{ backgroundColor: 'var(--surface-canvas)' }}>
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
    </div>
  )
}
