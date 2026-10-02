export const BRAND_NAME = 'ساکنا'
export const BRAND_TAGLINE = 'پنل مدیریت مجتمع‌های مسکونی و تجاری'

/**
 * راهِ تماسِ عمومی: فقط تلفن (مرحلهٔ ۷ · فاز ۴، تصمیمِ کارفرما).
 *
 * ایمیلِ پشتیبانی در این پروژه وجود ندارد و نشانی و نقشه مقدارِ نمونه بودند.
 * مقدارِ واقعی از `/site-settings` می‌آید (ادمینِ کل ویرایشش می‌کند)؛ این فقط
 * پیش‌فرضِ پیش از رسیدنِ پاسخ است.
 */
export const contactInfo = {
  phone: '۰۲۱-۸۸۷۷۶۶۵۵',
}

export const socialLinks = [
  {
    id: 'instagram',
    label: 'اینستاگرام',
    href: 'https://instagram.com/sakena.app',
    // گرادینت رسمی اینستاگرام
    hoverBackground: 'linear-gradient(45deg, #f9ce34, #ee2a7b, #6228d7)',
  },
  {
    id: 'telegram',
    label: 'تلگرام',
    href: 'https://t.me/sakena_app',
    hoverBackground: '#26A5E4',
  },
  {
    id: 'whatsapp',
    label: 'واتساپ',
    href: 'https://wa.me/982188776655',
    hoverBackground: '#25D366',
  },
  {
    id: 'rubika',
    label: 'روبیکا',
    href: 'https://rubika.ir/sakena_app',
    hoverBackground: '#F2461F',
  },
  {
    id: 'bale',
    label: 'بله',
    href: 'https://ble.ir/sakena_app',
    hoverBackground: '#1BA3E8',
  },
] as const

/**
 * رنگِ هاورِ هر شبکه بر پایه‌ی شناسه — برای وقتی که فهرستِ شبکه‌ها از پنلِ
 * مدیر می‌آید و فقط شناسه/برچسب/لینک دارد (نه رنگ، که جزء ظاهر است).
 */
export const socialHover: Record<string, string> = Object.fromEntries(
  socialLinks.map((s) => [s.id, s.hoverBackground]),
)
