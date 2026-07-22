/**
 * تصاویر از Unsplash و تحت Unsplash License هستند
 * (رایگان برای استفاده‌ی تجاری، بدون نیاز اجباری به ذکر منبع).
 *
 * برخلاف نسخه‌ی اولیه که مستقیم به images.unsplash.com لینک می‌داد، فایل‌ها
 * دانلود شده و در public/images قرار دارند. دلیلش دو چیز است: این سامانه
 * نباید به سرویس خارجی وابسته باشد، و نمایش صفحه نباید به دسترسی
 * بازدیدکننده به Unsplash گره بخورد.
 *
 * برای جایگزینی با عکس واقعیِ مجتمع کافی است فایل داخل public/images عوض
 * شود؛ هیچ کد دیگری نیاز به تغییر ندارد.
 */

export const heroImages = {
  buildingMain: '/images/hero-building.webp',
  buildingNight: '/images/hero-building-night.webp',
}

export const featureImages = {
  security: '/images/feature-security.webp',
  payments: '/images/feature-payments.webp',
  community: '/images/feature-community.webp',
  maintenance: '/images/feature-maintenance.webp',
}

export interface GalleryItem {
  src: string
  title: string
  description: string
  /** برچسب‌های کوتاه که در پنل کناریِ لایت‌باکس نشان داده می‌شوند. */
  tags: string[]
}

/**
 * تصاویر گالری با توضیح.
 *
 * نسبت ابعاد همه ۴:۵ (عمودی) است تا در نوار متحرک هیچ اختلاف ابعادی دیده
 * نشود. توضیح‌ها در لایت‌باکس کنار تصویر نمایش داده می‌شوند.
 */
export const galleryItems: GalleryItem[] = [
  {
    src: '/images/gallery-1.webp',
    title: 'لابی و ورودی اصلی',
    description:
      'ورودی مجتمع اولین چیزی است که ساکن و مهمان می‌بینند. با کنترل تردد هوشمند، ورود و خروج ثبت می‌شود و مدیر می‌تواند گزارش دقیق مراجعات را در پنل ببیند.',
    tags: ['کنترل تردد', 'امنیت', 'مشاعات'],
  },
  {
    src: '/images/gallery-2.webp',
    title: 'نمای بیرونی مجتمع',
    description:
      'نگهداری نما و محوطه یکی از سرفصل‌های ثابت هزینه‌های مشترک است. در بخش هزینه‌ها می‌توانید این مخارج را ثبت و بین واحدها بر اساس متراژ یا نفرات تقسیم کنید.',
    tags: ['نگهداری', 'هزینه مشترک'],
  },
  {
    src: '/images/gallery-3.webp',
    title: 'راهروها و مشاعات',
    description:
      'روشنایی، نظافت و سرویس دوره‌ای مشاعات با یادآورهای خودکار پیگیری می‌شود تا هیچ سرویسی از قلم نیفتد و سابقه‌ی هر کدام ثبت بماند.',
    tags: ['نظافت', 'یادآور دوره‌ای'],
  },
  {
    src: '/images/gallery-4.webp',
    title: 'پارکینگ و محوطه',
    description:
      'هر واحد سهم پارکینگ مشخصی دارد. تعداد پارکینگ در اطلاعات واحد ثبت می‌شود و می‌تواند در محاسبه‌ی شارژ ماهانه هم لحاظ شود.',
    tags: ['پارکینگ', 'شارژ'],
  },
  {
    src: '/images/gallery-5.webp',
    title: 'فضای سبز و حیاط',
    description:
      'هزینه‌ی باغبانی و آبیاری فضای سبز به‌صورت دوره‌ای ثبت و بین واحدها تقسیم می‌شود؛ ساکنین هم ریز این هزینه را در صورت‌حساب خود می‌بینند.',
    tags: ['فضای سبز', 'شفافیت مالی'],
  },
  {
    src: '/images/gallery-6.webp',
    title: 'سالن اجتماعات',
    description:
      'برای جلسات هیئت مدیره و مجامع، اطلاعیه‌ی جلسه را از پنل برای همه یا فقط مالکین بفرستید و مطمئن شوید که همه باخبر شده‌اند.',
    tags: ['اطلاعیه', 'هیئت مدیره'],
  },
  {
    src: '/images/gallery-7.webp',
    title: 'آسانسور و تاسیسات',
    description:
      'سرویس دوره‌ای آسانسور، موتورخانه و تاسیسات با قرارداد و تاریخ سررسید ثبت می‌شود؛ ضریب استفاده از آسانسور هم در محاسبه‌ی شارژ قابل اعمال است.',
    tags: ['آسانسور', 'تاسیسات', 'قرارداد'],
  },
  {
    src: '/images/gallery-8.webp',
    title: 'بام و مشرف به شهر',
    description:
      'فضاهای مشترکِ بام و تراس هم بخشی از دارایی مجتمع‌اند. نگهداری و ایمن‌سازی آن‌ها را می‌توانید در برنامه‌ی هزینه‌های سالانه دیده و بودجه‌بندی کنید.',
    tags: ['مشاعات', 'بودجه سالانه'],
  },
]

/** فقط آدرس تصاویر — جایی که به توضیح نیاز نیست. */
export const galleryImages = galleryItems.map((item) => item.src)

export const testimonialAvatars = [
  '/images/avatar-1.webp',
  '/images/avatar-2.webp',
  '/images/avatar-3.webp',
]

export const authBackgroundImage = '/images/auth-background.webp'
