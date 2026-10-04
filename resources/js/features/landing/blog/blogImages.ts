/**
 * تصویرِ بلاگ که بار نشد (مرحلهٔ ۸ · فاز ۳ · بندِ ۱۶).
 *
 * ⚠️ این **جایگزینِ اصلاحِ ریشه نیست**. ریشه‌ی ۴۰۴ ِ تصویرها روی سرورِ اصلی
 * دسترسیِ پوشه‌ی `storage/app/private/blog` برای php-fpm بود و در
 * `scripts/lib.sh` و `BlogImporter` بسته شد؛ سنجه‌ی `blog_media` ِ `/up` هم
 * از درونِ وب نگاهش می‌کند. این‌جا فقط دو کار:
 *
 *   ① به‌جای جعبه‌ی خالی یا نشانِ «تصویرِ شکسته»، همان متنی که تصویر
 *     می‌گفت — عنوانِ مقاله برای جلد، توضیحِ تصویر برای تصویرِ متن.
 *   ② **گزارش** به خطاهای سامانه (یک بار برای هر نشانی)، تا جایگزین ریشه را
 *     پنهان نکند: مدیرِ کل آن را در پایشِ خطا می‌بیند.
 *
 * متن با `textContent` گذاشته می‌شود — نه HTML.
 */

const HANDLED = 'blogImageFailed'

export function watchBlogImages(root: HTMLElement, report: (src: string) => void): () => void {
  const reported = new Set<string>()

  const fail = (img: HTMLImageElement) => {
    if (img.dataset[HANDLED] !== undefined) return
    img.dataset[HANDLED] = ''

    const label = (img.dataset.fallback ?? img.alt).trim()
    const fallback = document.createElement('span')
    fallback.className = 'blog-img-fallback'
    fallback.setAttribute('role', 'img')
    fallback.setAttribute('aria-label', img.alt || label)
    fallback.textContent = label
    img.classList.add('is-broken')
    img.after(fallback)

    const src = img.currentSrc || img.src
    if (src && !reported.has(src)) {
      reported.add(src)
      report(src)
    }
  }

  // ⚠️ رویدادِ error حباب نمی‌زند؛ در فازِ capture گرفته می‌شود
  const onError = (event: Event) => {
    if (event.target instanceof HTMLImageElement) fail(event.target)
  }

  // تصویری که پیش از رسیدنِ این اسکریپت شکسته بود
  const sweep = () => {
    root.querySelectorAll('img').forEach((img) => {
      if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) fail(img)
    })
  }

  root.addEventListener('error', onError, true)
  window.addEventListener('blog:navigated', sweep)
  sweep()

  return () => {
    root.removeEventListener('error', onError, true)
    window.removeEventListener('blog:navigated', sweep)
  }
}
