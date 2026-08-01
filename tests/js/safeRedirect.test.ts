import { describe, expect, it } from 'vitest'
import { safeInternalPath } from '@/shared/lib/safeRedirect'

describe('safeInternalPath', () => {
  it('مسیرِ داخلیِ ساده را می‌پذیرد', () => {
    expect(safeInternalPath({ pathname: '/dashboard' })).toBe('/dashboard')
  })

  it('پرس‌وجو را نگه می‌دارد', () => {
    expect(safeInternalPath({ pathname: '/bills', search: '?page=2' })).toBe('/bills?page=2')
  })

  it('نبودِ مقدار را null می‌کند تا فراخوان به پیش‌فرض برگردد', () => {
    expect(safeInternalPath(null)).toBeNull()
    expect(safeInternalPath(undefined)).toBeNull()
    expect(safeInternalPath({ pathname: '' })).toBeNull()
  })

  /*
   * تله‌ی اصلی: `//evil.com` یک pathname معتبر است ولی مرورگر آن را آدرسِ
   * نسبی‌به‌پروتکل می‌فهمد و به دامنه‌ی دیگری می‌رود. شرطِ «با / شروع می‌شود»
   * به‌تنهایی این را نمی‌گیرد.
   */
  it('آدرسِ نسبی‌به‌پروتکل را رد می‌کند', () => {
    expect(safeInternalPath({ pathname: '//evil.com' })).toBeNull()
    expect(safeInternalPath({ pathname: '//evil.com/login' })).toBeNull()
  })

  it('نسخه‌ی بک‌اسلشیِ همان حمله را هم رد می‌کند', () => {
    // بعضی مرورگرها `/\` را مثل `//` می‌فهمند
    expect(safeInternalPath({ pathname: '/\\evil.com' })).toBeNull()
  })

  it('آدرسِ مطلقِ بیرونی را رد می‌کند', () => {
    expect(safeInternalPath({ pathname: 'https://evil.com' })).toBeNull()
    expect(safeInternalPath({ pathname: 'javascript:alert(1)' })).toBeNull()
  })

  it('پرس‌وجوی بدشکل را رد می‌کند تا به مسیر نچسبد', () => {
    expect(safeInternalPath({ pathname: '/x', search: 'evil.com' })).toBeNull()
  })
})
