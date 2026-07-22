export interface AdSlide {
  id: string
  image: string
  title: string
  subtitle: string
  href: string
}

/**
 * اسلایدهای تبلیغاتی بنری - هر اسلاید با کلیک به لینک مقصد (href) باز می‌شود.
 *
 * تصاویر گرادیانِ ساخته‌شده‌ی خودمان (webp) هستند، نه عکسِ برداشته‌شده از آن
 * سایت‌ها؛ چون هم حق استفاده از لوگو/اسکرین‌شاتشان روشن نیست و هم لینک‌دادن
 * به فایلِ سرورِ دیگر، بارگذاری صفحه را به آن سرور وابسته می‌کرد.
 *
 * TODO: در آینده این داده باید از پنل مدیریت تبلیغات یا API واقعی خوانده شود.
 */
export const adSlides: AdSlide[] = [
  {
    id: 'ad-nitropanel',
    image: '/images/ad-nitropanel.webp',
    title: 'نیترو پنل — میزبانی و سرور ابری',
    subtitle: 'سرور مجازی و هاست پرسرعت با پشتیبانی شبانه‌روزی برای کسب‌وکار شما',
    href: 'https://nitropanel.ir/',
  },
  {
    id: 'ad-sendnetwork',
    image: '/images/ad-sendnetwork.webp',
    title: 'کانال تلگرام Send Network',
    subtitle: 'آخرین اخبار، آموزش‌ها و پیشنهادهای ویژه را در تلگرام دنبال کنید',
    href: 'https://t.me/SendNetwork',
  },
  {
    id: 'ad-qxbroker',
    image: '/images/ad-qxbroker.webp',
    title: 'Quotex — پلتفرم معاملات آنلاین',
    subtitle: 'معامله روی بازارهای جهانی با حساب آزمایشی رایگان و اجرای سریع',
    href: 'https://qxbroker.com',
  },
]
