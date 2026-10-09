import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * مدرکِ ناپایداریِ E2E پنهان نمی‌شود (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶؛ تصمیمِ
 * کارفرما: «صرفاً برای سبز شدنِ CI retry اضافه نکن و خطای اجرای اول را پنهان نکن»).
 */
const config = readFileSync(resolve(__dirname, '../../playwright.config.ts'), 'utf8')
const workflow = readFileSync(resolve(__dirname, '../../.github/workflows/ci.yml'), 'utf8')

describe('مدرکِ شکستِ اول در E2E', () => {
  it('تستی که فقط در تلاشِ دوم سبز شد کارِ CI را قرمز می‌کند', () => {
    expect(config).toMatch(/failOnFlakyTests:\s*!!process\.env\.CI/)
    // بیش از یک تکرار نه
    expect(config).toMatch(/retries:\s*process\.env\.CI \? 1 : 0/)
  })

  it('ردِ همان اولین شکست ضبط و در هر اجرای لغونشده بالا برده می‌شود', () => {
    expect(config).toContain("trace: 'retain-on-first-failure'")
    expect(workflow).toContain('if: ${{ !cancelled() }}')
  })
})
