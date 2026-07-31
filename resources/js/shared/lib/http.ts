/**
 * نمونه‌ی axios و نگه‌داریِ توکن CSRF — لایه‌ی حمل‌ونقل، بدونِ هیچ منطقِ اپ.
 *
 * مرزِ این فایل عمدی است: هرچه به **نحوه‌ی رفتنِ بایت‌ها روی سیم** مربوط است
 * اینجاست (آدرس پایه، کوکی، هدر، timeout، CSRF)، و هرچه به معنیِ پاسخ مربوط
 * است در `apiError.ts` و `api.ts`. لایه‌ی کش (R6) هرگز نباید مستقیم با این
 * فایل کار کند.
 */

import axios, { type AxiosInstance } from 'axios'

/*
 * توکن CSRF در متغیر نگه داشته می‌شود، نه اینکه هر بار از متاتگ خوانده شود.
 *
 * دلیلش مهم است: هنگام ورود، لاراول نشست را regenerate می‌کند و توکن CSRF هم
 * عوض می‌شود. چون این یک SPA است و صفحه رفرش نمی‌شود، متاتگ همان توکن قدیمی
 * را نگه می‌دارد و اولین درخواست نوشتنیِ بعد از ورود با ۴۱۹ رد می‌شد.
 */
let csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

export function setCsrfToken(token: string | undefined | null): void {
  if (token) csrfToken = token
}

/**
 * سقفِ زمانیِ هر درخواست.
 *
 * بدونِ این، یک اتصالِ نیمه‌باز (موبایل که از آنتن می‌افتد) می‌تواند برای همیشه
 * معلق بماند و کاربر یک اسپینرِ ابدی ببیند. آپلود سقفِ بلندتری دارد چون رسیدِ
 * چندمگابایتی روی اینترنتِ ضعیف واقعاً طول می‌کشد.
 */
export const TIMEOUT_MS = 20_000
export const UPLOAD_TIMEOUT_MS = 120_000

export const http: AxiosInstance = axios.create({
  /*
   * نسخه‌ی رسمیِ API (R10). مسیرِ بدونِ نسخه (`/api/...`) هنوز کار می‌کند
   * ولی فقط برای سازگاری است؛ کدِ تازه باید همیشه نسخه‌دار باشد.
   *
   * چون همه‌ی درخواست‌ها از همین یک نمونه می‌گذرند، ارتقا به `v2` روزی که
   * لازم شود، تغییرِ همین یک خط است.
   */
  baseURL: '/api/v1',
  // کوکی نشست باید همراه هر درخواست برود
  withCredentials: true,
  timeout: TIMEOUT_MS,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  // ما خودمان بر اساس بدنه‌ی پاسخ تصمیم می‌گیریم، پس همه‌ی وضعیت‌ها را می‌گیریم
  // و در catch نگاشتشان می‌کنیم به ApiError.
})

// توکن CSRF فقط روی درخواست‌های تغییردهنده لازم است
http.interceptors.request.use((config) => {
  const method = (config.method ?? 'get').toUpperCase()
  if (method !== 'GET') {
    config.headers.set('X-CSRF-TOKEN', csrfToken)
  }
  return config
})

/**
 * گرفتنِ توکنِ تازه پس از ۴۱۹.
 *
 * `skipRetry` می‌گذاریم تا اگر خودِ این درخواست ۴۱۹ گرفت، حلقه‌ی بی‌پایانِ
 * «۴۱۹ → توکن بگیر → ۴۱۹» راه نیفتد.
 */
export async function refreshCsrfToken(): Promise<void> {
  try {
    const { data } = await http.get<{ csrfToken?: string }>('/csrf-token')
    setCsrfToken(data?.csrfToken)
  } catch {
    // اگر شبکه هم قطع باشد، خطای اصلی به تماس‌گیرنده برمی‌گردد
  }
}
