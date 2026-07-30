/**
 * یکی‌کردنِ درخواست‌های هم‌زمانِ یکسان (request deduplication).
 *
 * مسئله‌ی واقعی: در داشبورد چند کامپوننت مستقل ممکن است هم‌زمان یک منبع را
 * بخواهند — مثلاً زنگوله‌ی اعلان در هدر و کارتِ اعلان در صفحه. بدون این لایه،
 * دو درخواستِ شبکه‌ی یکسان می‌رفت.
 *
 * ─── نکته‌ی ظریفی که این فایل را از یک Map ساده جدا می‌کند ─────────────────
 * اگر فقط promise را به اشتراک بگذاریم، وقتی **یکی** از مصرف‌کننده‌ها unmount
 * شود و درخواست را abort کند، درخواستِ مشترک می‌میرد و بقیه‌ی مصرف‌کننده‌ها که
 * هنوز روی صفحه‌اند خطای لغو می‌گیرند — یک باگِ گیج‌کننده که فقط در شرایط مسابقه
 * دیده می‌شود.
 *
 * پس مرجع‌شماری (refcount) داریم: درخواستِ زیرین با AbortControllerِ **داخلیِ**
 * خودش اجرا می‌شود و فقط وقتی abort می‌شود که **آخرین** مصرف‌کننده هم رفته
 * باشد. abortِ هر مصرف‌کننده صرفاً نمای خودش را رد می‌کند.
 */

import { abortError } from './retry'

interface Entry<T> {
  promise: Promise<T>
  controller: AbortController
  subscribers: number
}

const inflight = new Map<string, Entry<unknown>>()

/**
 * اگر درخواستی با همین کلید در جریان باشد، به همان می‌چسبد؛ وگرنه یکی
 * راه می‌اندازد.
 *
 * فقط باید برای خواندن (GET) استفاده شود. یکی‌کردنِ دو POSTِ یکسان **غلط**
 * است: ممکن است کاربر واقعاً قصد داشته دو بار پرداخت کند.
 */
export function dedupe<T>(
  key: string,
  run: (signal: AbortSignal) => Promise<T>,
  signal?: AbortSignal,
): Promise<T> {
  let entry = inflight.get(key) as Entry<T> | undefined

  if (!entry) {
    const controller = new AbortController()
    const promise = run(controller.signal)
    const created: Entry<T> = { promise, controller, subscribers: 0 }

    /*
     * پاک‌کردنِ کلید پس از پایان — با هر دو هندلر، تا promiseِ مشتق‌شده هرگز
     * به‌صورت «rejection مدیریت‌نشده» بیرون نزند. شرطِ برابری لازم است تا اگر
     * درخواستِ تازه‌تری با همین کلید ثبت شده باشد، اشتباهی آن را حذف نکنیم.
     */
    const forget = () => {
      if (inflight.get(key) === created) inflight.delete(key)
    }
    promise.then(forget, forget)

    inflight.set(key, created)
    entry = created
  }

  const shared = entry
  shared.subscribers += 1

  return new Promise<T>((resolve, reject) => {
    let settled = false

    const detach = () => {
      settled = true
      signal?.removeEventListener('abort', onAbort)
      shared.subscribers -= 1
    }

    function onAbort() {
      if (settled) return
      detach()
      // آخرین نفر که رفت، چراغ را خاموش کند
      if (shared.subscribers <= 0) shared.controller.abort()
      reject(abortError(signal))
    }

    if (signal?.aborted) {
      onAbort()
      return
    }
    signal?.addEventListener('abort', onAbort, { once: true })

    shared.promise.then(
      (value) => {
        if (settled) return
        detach()
        resolve(value)
      },
      (error: unknown) => {
        if (settled) return
        detach()
        /*
         * خطا را عیناً رد می‌کنیم و در Error نمی‌پیچیم. قاعده‌ی لینت درست است
         * برای جایی که خطا **ساخته** می‌شود؛ اینجا فقط پاس داده می‌شود و
         * پیچیدنش `ApiError` را نابود می‌کند — یعنی فرم‌ها دیگر به
         * `fieldError()` دسترسی نداشتند.
         */
        // eslint-disable-next-line @typescript-eslint/prefer-promise-reject-errors
        reject(error)
      },
    )
  })
}

/** تعدادِ درخواست‌های در جریان — فقط برای تست و اشکال‌زدایی. */
export function inflightCount(): number {
  return inflight.size
}

/** خالی‌کردنِ وضعیت بین تست‌ها. در کدِ محصول لازم نیست. */
export function resetInflight(): void {
  inflight.clear()
}
