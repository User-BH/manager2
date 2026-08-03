import { useEffect, useRef, useState } from 'react'
import { Smile } from 'lucide-react'

/**
 * انتخابگرِ اموجی (R23b).
 *
 * ─── چرا پکیج نصب نشد ──────────────────────────────────────────────────────
 * کتابخانه‌های آماده‌ی اموجی چند صد کیلوبایت داده‌ی یونیکد و معمولاً یک
 * تصویرِ sprite با خودشان می‌آورند. برای یک چتِ ساختمانی که کاربرش چند
 * اموجیِ متداول می‌خواهد، این هزینه توجیه ندارد — به‌ویژه که خودِ کیبوردِ
 * موبایل اموجی دارد و این دکمه بیشتر برای دسکتاپ است.
 *
 * پس یک شبکه‌ی دست‌چین‌شده: کاراکترهای خامِ یونیکد، بدونِ داده و بدونِ تصویر.
 */
const EMOJIS = [
  '🙂',
  '😊',
  '😀',
  '😅',
  '😍',
  '😉',
  '🤔',
  '😐',
  '😕',
  '😢',
  '😡',
  '👍',
  '👎',
  '🙏',
  '👌',
  '👏',
  '💪',
  '❤️',
  '🔥',
  '✅',
  '❌',
  '⚠️',
  '📌',
  '📎',
  '🏠',
  '🔑',
  '🚗',
  '🛗',
  '💡',
  '🔧',
  '🧹',
  '🗑️',
  '💧',
  '🌡️',
  '📅',
  '⏰',
  '💰',
  '🧾',
  '📞',
  '✉️',
]

export function EmojiPicker({ onPick }: { onPick: (emoji: string) => void }) {
  const [isOpen, setIsOpen] = useState(false)
  const wrapperRef = useRef<HTMLDivElement>(null)

  /*
   * بستن با کلیکِ بیرون و با Escape. بدونِ این، پنل روی صفحه می‌ماند و
   * روی فرمِ ارسال می‌افتد — که در موبایل یعنی کاربر دیگر نمی‌تواند بفرستد.
   */
  useEffect(() => {
    if (!isOpen) return

    const onPointerDown = (event: PointerEvent) => {
      if (!wrapperRef.current?.contains(event.target as Node)) setIsOpen(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsOpen(false)
    }

    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [isOpen])

  return (
    <div ref={wrapperRef} className="relative">
      <button
        type="button"
        onClick={() => setIsOpen((open) => !open)}
        aria-label="افزودن اموجی"
        aria-expanded={isOpen}
        className="flex h-9 w-9 items-center justify-center rounded-lg transition-colors"
        style={{ color: 'var(--text-tertiary)' }}
      >
        <Smile size={17} />
      </button>

      {isOpen && (
        <div
          role="listbox"
          aria-label="اموجی‌ها"
          className="absolute bottom-11 right-0 z-20 grid w-[268px] grid-cols-8 gap-1 rounded-xl border p-2 shadow-lg"
          style={{
            backgroundColor: 'var(--surface-raised)',
            borderColor: 'var(--border-subtle)',
          }}
        >
          {EMOJIS.map((emoji) => (
            <button
              key={emoji}
              type="button"
              role="option"
              aria-selected={false}
              onClick={() => {
                onPick(emoji)
                setIsOpen(false)
              }}
              className="flex h-7 w-7 items-center justify-center rounded-md text-[17px] leading-none transition-transform hover:scale-125"
            >
              {emoji}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
