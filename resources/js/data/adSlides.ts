export interface AdSlide {
  id: string
  image: string
  title: string
  subtitle: string
  href: string
}

/**
 * اسلایدهای تبلیغاتی بنری - هر اسلاید با کلیک به لینک مقصد (href) باز می‌شود.
 * TODO: در آینده این داده باید از پنل مدیریت تبلیغات یا API واقعی خوانده شود.
 */
export const adSlides: AdSlide[] = [
  {
    id: 'ad-1',
    image: '/images/ad-insurance.jpg',
    title: 'بیمه آتش‌سوزی مجتمع‌های مسکونی',
    subtitle: 'پوشش کامل واحدها و فضای مشترک با تخفیف ویژه مدیران ساختمان',
    href: 'https://www.centralinsurance.ir',
  },
  {
    id: 'ad-2',
    image: '/images/ad-elevator.jpg',
    title: 'سرویس آسانسور و نگهداری دوره‌ای',
    subtitle: 'قرارداد سالانه با اعزام تکنسین در کمتر از ۲۴ ساعت',
    href: 'https://www.shahabelevator.com',
  },
  {
    id: 'ad-3',
    image: '/images/ad-security.jpg',
    title: 'نصب دوربین مداربسته و درب هوشمند',
    subtitle: 'افزایش امنیت مجتمع با سیستم‌های کنترل تردد یکپارچه',
    href: 'https://www.hikvision.com',
  },
]
