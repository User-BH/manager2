interface SocialIconProps {
  size?: number
  className?: string
}

export function InstagramIcon({ size = 18, className }: SocialIconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" className={className}>
      <rect x="3" y="3" width="18" height="18" rx="5" stroke="currentColor" strokeWidth="1.8" />
      <circle cx="12" cy="12" r="4" stroke="currentColor" strokeWidth="1.8" />
      <circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" />
    </svg>
  )
}

export function LinkedinIcon({ size = 18, className }: SocialIconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" className={className}>
      <rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" strokeWidth="1.8" />
      <line
        x1="7.5"
        y1="10"
        x2="7.5"
        y2="16.5"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
      />
      <circle cx="7.5" cy="7" r="0.6" fill="currentColor" stroke="currentColor" strokeWidth="1.2" />
      <path
        d="M11.5 16.5V10M11.5 12.5C11.5 11 12.5 10 14 10C15.5 10 16.5 11 16.5 12.5V16.5"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export function TelegramIcon({ size = 18, className }: SocialIconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" className={className}>
      <circle cx="12" cy="12" r="9.2" stroke="currentColor" strokeWidth="1.8" />
      <path
        d="M6.8 12.1L16.7 8.2C17.2 8 17.6 8.3 17.4 9L15.7 16.6C15.6 17.1 15.2 17.2 14.8 16.9L12 14.8L10.6 16.1C10.4 16.3 10.2 16.3 10.1 16.2L10.4 13.4L15.3 9.4C15.5 9.2 15.3 9.1 15 9.3L8.8 13.3L6.6 12.6C6.1 12.4 6.1 12.2 6.8 12.1Z"
        fill="currentColor"
      />
    </svg>
  )
}

export function WhatsappIcon({ size = 18, className }: SocialIconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" className={className}>
      <path
        d="M12 3.5C7.3 3.5 3.5 7.3 3.5 12C3.5 13.6 3.9 15.1 4.7 16.3L3.6 20.4L7.8 19.3C9 20 10.5 20.5 12 20.5C16.7 20.5 20.5 16.7 20.5 12C20.5 7.3 16.7 3.5 12 3.5Z"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinejoin="round"
      />
      <path
        d="M9 9.3C9 8.8 9.4 8.4 9.9 8.4H10.4C10.7 8.4 11 8.6 11.1 8.9L11.7 10.5C11.8 10.8 11.7 11.1 11.5 11.3L10.9 11.9C11.4 13 12.4 14 13.5 14.5L14.1 13.9C14.3 13.7 14.6 13.6 14.9 13.7L16.5 14.3C16.8 14.4 17 14.7 17 15V15.5C17 16 16.6 16.4 16.1 16.4C12.7 16.4 9 12.7 9 9.3Z"
        fill="currentColor"
      />
    </svg>
  )
}

/**
 * آیکونِ «بله» (پیام‌رسانِ ایرانی) — قطره‌ی گفت‌وگو با تیکِ برجسته، مطابقِ
 * لوگوی رسمی. تک‌رنگ (currentColor) تا با بقیه‌ی آیکون‌های فوتر هم‌سبک بماند.
 */
export function BaleIcon({ size = 18, className }: SocialIconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" className={className}>
      {/*
        قطره + تیک به‌صورتِ یک مسیر با fill-rule «evenodd»: تیک سوراخ می‌شود و
        پس‌زمینه از داخلش دیده می‌شود، درست مثلِ لوگوی بله (تیکِ سفید روی قطره).
      */}
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M4.6 4.6C3 6.3 2.5 8.9 2.5 11.9C2.5 17.2 6.8 21.5 12 21.5C17.2 21.5 21.5 17.2 21.5 11.9C21.5 6.7 17.2 2.4 12 2.4C9.2 2.4 6.5 2.7 4.6 4.6ZM10.6 16.4L6.7 12.5C6.2 12 6.2 11.2 6.7 10.7C7.2 10.2 8 10.2 8.5 10.7L10.9 13.1L15.5 8.5C16 8 16.8 8 17.3 8.5C17.8 9 17.8 9.8 17.3 10.3L11.4 16.2C11.2 16.4 10.9 16.5 10.6 16.4Z"
      />
    </svg>
  )
}

/**
 * آیکونِ «روبیکا» — شش‌ضلعی با مکعبِ سه‌بعدیِ وسط، مطابقِ لوگوی رسمی؛ تک‌رنگ.
 */
export function RubikaIcon({ size = 18, className }: SocialIconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" className={className}>
      {/* شش‌ضلعی */}
      <path
        d="M12 2.6L20 7.3V16.7L12 21.4L4 16.7V7.3L12 2.6Z"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinejoin="round"
      />
      {/* مکعبِ ایزومتریک: وجهِ بالا و دو وجهِ کناری */}
      <path
        d="M12 8.2L15.4 10.1L12 12L8.6 10.1L12 8.2Z"
        stroke="currentColor"
        strokeWidth="1.4"
        strokeLinejoin="round"
      />
      <path
        d="M8.6 10.1V13.9L12 15.8V12"
        stroke="currentColor"
        strokeWidth="1.4"
        strokeLinejoin="round"
      />
      <path
        d="M15.4 10.1V13.9L12 15.8"
        stroke="currentColor"
        strokeWidth="1.4"
        strokeLinejoin="round"
      />
    </svg>
  )
}
