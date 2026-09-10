/**
 * تایپِ WebOTP — APIای که مرورگرها دارند و TypeScript ندارد.
 *
 * ─── 🔴 چرا این فایل ساخته شد (مرحلهٔ ۵، فاز ۳ — بند ۱۴) ───────────────────
 * `OtpBoxes` می‌نوشت:
 *
 *     (navigator.credentials as unknown as { get: (o: unknown) => ... })
 *
 * ⚠️ ادعای دولایه بود، ولی برخلاف موردِ `ReceiptUploadForm` **دروغ نبود**:
 * آن API واقعاً وجود دارد و پشتِ `'OTPCredential' in window` هم بررسی
 * می‌شود. مشکل این بود که TypeScript شکلش را نمی‌شناخت.
 *
 * 🔴 راهِ درست، اعلامِ نوع است نه دور زدنِ کامپایلر: حالا امضا **سنجیده**
 * می‌شود. اگر روزی آرگومان‌ها را اشتباه بدهیم، کامپایلر می‌گیرد — چیزی که
 * با `as unknown as` هرگز ممکن نبود.
 *
 * @see https://developer.mozilla.org/docs/Web/API/WebOTP_API
 */

interface OTPCredential extends Credential {
  readonly code: string
}

interface OTPCredentialRequestOptions {
  transport: string[]
}

interface CredentialRequestOptions {
  /** ⚠️ فقط روی مرورگرهایی که `OTPCredential` دارند معنا پیدا می‌کند. */
  otp?: OTPCredentialRequestOptions
}
