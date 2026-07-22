import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { AnimatePresence, motion } from 'framer-motion'
import { ChevronDown, LifeBuoy } from 'lucide-react'
import { useDocumentTitle } from '@/hooks'
import { scrollToElement } from '@/lib/scroll'
import { HomeNavbar } from '../home/components/HomeNavbar'
import { HomeFooter } from '../home/components/HomeFooter'
import { SupportWheel } from './SupportWheel'
import { supportTopics, type SupportTopicId } from './supportContent'

/** ارتفاع نوار بالای صفحه، تا مقصدِ اسکرول زیرش پنهان نشود. */
const NAVBAR_OFFSET = 88

/**
 * صفحه‌ی پشتیبانی.
 *
 * چهار موضوع (سوالات متداول، قوانین، حریم خصوصی، تماس با ما) هم به‌صورت
 * ربع‌های یک دایره در هدر دیده می‌شوند و هم به‌صورت آکاردیون زیر هم.
 *
 * لینک‌های فوتر با `?topic=` به اینجا می‌آیند و همان بخش را باز می‌کنند.
 */
export function SupportPage() {
  const [params] = useSearchParams()
  const requested = params.get('topic') as SupportTopicId | null

  const [activeTopic, setActiveTopic] = useState<SupportTopicId | null>(requested)
  const [openEntry, setOpenEntry] = useState<string | null>(null)
  const sectionRefs = useRef<Record<string, HTMLElement | null>>({})

  useDocumentTitle('پشتیبانی و راهنما')

  function goToTopic(id: SupportTopicId) {
    setActiveTopic(id)

    const section = sectionRefs.current[id]
    if (section) scrollToElement(section, NAVBAR_OFFSET)
  }

  // ورود با ?topic= از فوتر: همان بخش باز و به آن اسکرول شود
  useEffect(() => {
    if (!requested) return

    // یک تیک صبر تا چیدمان کامل شود، وگرنه موقعیت اشتباه حساب می‌شود
    const timer = window.setTimeout(() => goToTopic(requested), 220)
    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requested])

  return (
    <div style={{ backgroundColor: 'var(--surface-canvas)' }}>
      <HomeNavbar />

      {/* ---------------- هدر با چرخِ چهارقسمتی ---------------- */}
      <header className="relative overflow-hidden pb-16 pt-28" dir="rtl">
        {/* نقش‌مایه‌ی پس‌زمینه */}
        <div
          className="pointer-events-none absolute inset-0 -z-10"
          style={{
            background:
              'radial-gradient(60% 55% at 50% 0%, color-mix(in srgb, var(--color-brand-500) 14%, transparent), transparent 70%)',
          }}
        />

        <div className="mx-auto grid max-w-5xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-[1fr_auto]">
          <motion.div
            initial={{ opacity: 0, x: 30 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.7, ease: [0.22, 1, 0.36, 1] }}
          >
            <span
              className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11.5px] font-bold"
              style={{
                backgroundColor: 'color-mix(in srgb, var(--color-brand-500) 12%, transparent)',
                color: 'var(--color-brand-600)',
              }}
            >
              <LifeBuoy size={13} />
              مرکز پشتیبانی
            </span>

            <h1 className="mt-4 text-3xl font-extrabold sm:text-4xl" style={{ color: 'var(--text-primary)' }}>
              چطور می‌توانیم کمک کنیم؟
            </h1>
            <p className="mt-4 max-w-md text-[15px] leading-8" style={{ color: 'var(--text-secondary)' }}>
              پاسخ پرسش‌های پرتکرار، شرایط استفاده، سیاست حریم خصوصی و راه‌های تماس — همه در
              یک صفحه. روی هر بخش از دایره بزنید تا مستقیم به همان قسمت بروید.
            </p>

            <div className="mt-7 flex flex-wrap gap-2">
              {supportTopics.map((topic, index) => (
                <motion.button
                  key={topic.id}
                  initial={{ opacity: 0, y: 12 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.4, delay: 0.35 + index * 0.08 }}
                  onClick={() => goToTopic(topic.id)}
                  className="rounded-xl border px-3.5 py-2 text-[12.5px] font-semibold transition-all duration-200 hover:-translate-y-0.5"
                  style={{
                    borderColor:
                      activeTopic === topic.id ? topic.color : 'var(--border-subtle)',
                    color: activeTopic === topic.id ? topic.color : 'var(--text-secondary)',
                    backgroundColor:
                      activeTopic === topic.id
                        ? `color-mix(in srgb, ${topic.color} 10%, transparent)`
                        : 'transparent',
                  }}
                >
                  {topic.title}
                </motion.button>
              ))}
            </div>
          </motion.div>

          <SupportWheel topics={supportTopics} activeId={activeTopic} onSelect={goToTopic} />
        </div>
      </header>

      {/* ---------------- آکاردیون‌ها ---------------- */}
      <main className="mx-auto max-w-3xl px-4 pb-24 sm:px-6" dir="rtl">
        {supportTopics.map((topic, topicIndex) => {
          const Icon = topic.icon
          const isActive = activeTopic === topic.id

          return (
            <motion.section
              key={topic.id}
              ref={(node) => {
                sectionRefs.current[topic.id] = node
              }}
              initial={{ opacity: 0, y: 28 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true, margin: '-80px' }}
              transition={{ duration: 0.5, delay: Math.min(topicIndex * 0.05, 0.2) }}
              className="mb-6 overflow-hidden rounded-3xl border"
              style={{
                borderColor: isActive ? topic.color : 'var(--border-subtle)',
                backgroundColor: 'var(--surface-base)',
                boxShadow: isActive ? `0 0 0 3px color-mix(in srgb, ${topic.color} 14%, transparent)` : undefined,
              }}
            >
              <div
                className="flex items-center gap-3 border-b p-5"
                style={{
                  borderColor: 'var(--border-subtle)',
                  background: `linear-gradient(120deg, color-mix(in srgb, ${topic.color} 9%, transparent), transparent 70%)`,
                }}
              >
                <span
                  className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-white"
                  style={{ backgroundColor: topic.color }}
                >
                  <Icon size={20} />
                </span>

                <div>
                  <h2 className="text-[17px] font-extrabold" style={{ color: 'var(--text-primary)' }}>
                    {topic.title}
                  </h2>
                  <p className="mt-0.5 text-[12px]" style={{ color: 'var(--text-tertiary)' }}>
                    {topic.short}
                  </p>
                </div>
              </div>

              <div className="p-2 sm:p-3">
                {topic.entries.map((entry, entryIndex) => {
                  const key = `${topic.id}-${entryIndex}`
                  const isOpen = openEntry === key

                  return (
                    <div
                      key={key}
                      className="border-b last:border-b-0"
                      style={{ borderColor: 'var(--border-subtle)' }}
                    >
                      <button
                        onClick={() => setOpenEntry(isOpen ? null : key)}
                        aria-expanded={isOpen}
                        className="flex w-full items-center gap-3 rounded-xl px-3 py-4 text-right transition-colors hover:bg-(--surface-sunken)"
                      >
                        <span
                          className="text-[13.5px] font-bold leading-7"
                          style={{ color: isOpen ? topic.color : 'var(--text-primary)' }}
                        >
                          {entry.question}
                        </span>

                        <motion.span
                          animate={{ rotate: isOpen ? 180 : 0 }}
                          transition={{ duration: 0.25 }}
                          className="mr-auto flex shrink-0 items-center"
                          style={{ color: isOpen ? topic.color : 'var(--text-tertiary)' }}
                        >
                          <ChevronDown size={17} />
                        </motion.span>
                      </button>

                      <AnimatePresence initial={false}>
                        {isOpen && (
                          <motion.div
                            initial={{ height: 0, opacity: 0 }}
                            animate={{ height: 'auto', opacity: 1 }}
                            exit={{ height: 0, opacity: 0 }}
                            transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
                            className="overflow-hidden"
                          >
                            <p
                              className="px-3 pb-5 text-[13.5px] leading-8"
                              style={{ color: 'var(--text-secondary)' }}
                            >
                              {entry.answer}
                            </p>
                          </motion.div>
                        )}
                      </AnimatePresence>
                    </div>
                  )
                })}
              </div>
            </motion.section>
          )
        })}
      </main>

      <HomeFooter />
    </div>
  )
}
