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
  buildingMain: '/images/hero-building.jpg',
  buildingNight: '/images/hero-building-night.jpg',
}

export const featureImages = {
  security: '/images/feature-security.jpg',
  payments: '/images/feature-payments.jpg',
  community: '/images/feature-community.jpg',
  maintenance: '/images/feature-maintenance.jpg',
}

// نسبت ابعاد ثابت ۴:۵ (عمودی) برای همه‌ی تصاویر گالری - تا در اسلایدر هیچ اختلاف ابعادی دیده نشود
export const galleryImages = [
  '/images/gallery-1.jpg',
  '/images/gallery-2.jpg',
  '/images/gallery-3.jpg',
  '/images/gallery-4.jpg',
  '/images/gallery-5.jpg',
  '/images/gallery-6.jpg',
  '/images/gallery-7.jpg',
  '/images/gallery-8.jpg',
]

export const testimonialAvatars = [
  '/images/avatar-1.jpg',
  '/images/avatar-2.jpg',
  '/images/avatar-3.jpg',
]

export const authBackgroundImage = '/images/auth-background.jpg'
