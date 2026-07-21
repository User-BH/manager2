import { useNavigate } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { RevealOnScroll } from './RevealOnScroll'

export function CtaSection() {
  const navigate = useNavigate()

  return (
    <section className="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
      <RevealOnScroll>
        <div
          className="relative overflow-hidden rounded-[2rem] px-6 py-14 text-center sm:px-12"
          style={{ background: 'linear-gradient(135deg, var(--color-brand-600), var(--color-brand-400))' }}
        >
          <div className="pointer-events-none absolute -left-10 -top-10 h-56 w-56 rounded-full bg-white/10" />
          <div className="pointer-events-none absolute -bottom-16 -right-16 h-64 w-64 rounded-full bg-white/10" />

          <h2 className="relative text-2xl font-extrabold text-white sm:text-3xl">
            همین امروز مدیریت مجتمع را ساده کنید
          </h2>
          <p className="relative mx-auto mt-3 max-w-md text-[14.5px] leading-7 text-white/85">
            ثبت‌نام رایگان است و در کمتر از ۵ دقیقه پنل مجتمع شما آماده می‌شود.
          </p>

          <button
            onClick={() => navigate('/auth?tab=register')}
            className="group relative mt-8 inline-flex items-center gap-2 rounded-2xl bg-white px-7 py-3.5 text-sm font-bold transition-transform duration-200 hover:scale-105"
            style={{ color: 'var(--color-brand-600)' }}
          >
            شروع رایگان
            <ArrowLeft size={16} className="transition-transform group-hover:-translate-x-1" />
          </button>
        </div>
      </RevealOnScroll>
    </section>
  )
}
