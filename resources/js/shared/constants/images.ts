/**
 * مرجعِ یکتای آدرسِ تصاویر.
 *
 * تصاویر از Unsplash و تحت Unsplash License‌اند (رایگان برای استفاده‌ی تجاری).
 * همه self-host هستند و هیچ وابستگی به سرویس بیرونی وجود ندارد.
 *
 * ---------------------------------------------------------------------------
 * قاعده‌ی جای‌گذاری مدیا — کدام تصویر از کجا می‌آید؟
 * ---------------------------------------------------------------------------
 *
 * ۱) `resources/images/*` → **از Vite عبور می‌کند** (با `import`)
 *    مناسبِ دارایی‌هایی که جزءِ خودِ ظاهرِ برنامه‌اند و کاربر عوضشان نمی‌کند.
 *    سودش: Vite نامِ فایل را هش می‌کند، پس می‌توان کشِ همیشگی (immutable)
 *    گذاشت و با هر تغییر، نامِ فایل خودبه‌خود عوض می‌شود؛ دیگر لازم نیست
 *    دستی cache-bust کنیم.
 *
 * ۲) `public/*` → **مستقیم با آدرسِ ثابت** (رشته‌ی مسیر)
 *    برای سه دسته که *نباید* هش شوند:
 *      • آدرسشان از بیرونِ ری‌اکت خوانده می‌شود: `manifest.webmanifest`،
 *        متاتگ‌های `og:image`، و Bladeهای عمومی (مثل `demo.blade.php` که
 *        `hero-building-night.webp` را نام می‌برد). این‌ها نامِ ثابت می‌خواهند.
 *      • محتوایی که ادمین از پنل آپلود/عوض می‌کند (بنرهای تبلیغاتی) — مسیرشان
 *        در دیتابیس ذخیره است.
 *      • محتوایی که کارفرما با جایگزینیِ فایل عوض می‌کند (تصاویر گالری،
 *        آواتارها). این‌ها نباید برای تعویض، تغییرِ کد لازم داشته باشند.
 *
 * برای بنرهای تبلیغاتی، کش‌شکنی در `Advertisement::displayImageUrl()` با
 * `?v=filemtime` انجام می‌شود.
 */

// —— دارایی‌های عبوری از Vite (هش‌دار) ——
import authBackground from '@/../images/auth-background.webp'
import featureCommunity from '@/../images/feature-community.webp'
import featureMaintenance from '@/../images/feature-maintenance.webp'
import featurePayments from '@/../images/feature-payments.webp'
import featureSecurity from '@/../images/feature-security.webp'
import heroBuilding from '@/../images/hero-building.webp'

export const heroImages = {
  buildingMain: heroBuilding,
  /** آدرسِ ثابت: `demo.blade.php` همین نام را به‌عنوان poster صدا می‌زند. */
  buildingNight: '/images/hero-building-night.webp',
}

export const featureImages = {
  security: featureSecurity,
  payments: featurePayments,
  community: featureCommunity,
  maintenance: featureMaintenance,
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

/** آدرسِ ثابت: قرار است با آواتارِ SVG جایگزین شوند (R33). */
export const testimonialAvatars = [
  '/images/avatar-1.webp',
  '/images/avatar-2.webp',
  '/images/avatar-3.webp',
]

export const authBackgroundImage = authBackground
