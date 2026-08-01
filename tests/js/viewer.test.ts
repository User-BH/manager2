import { afterEach, describe, expect, it } from 'vitest'
import { isViewerAuthenticated } from '@/shared/lib/viewer'

function setTag(content: string) {
  const el = document.createElement('script')
  el.type = 'application/json'
  el.id = 'viewer-state'
  el.textContent = content
  document.head.appendChild(el)
}

afterEach(() => {
  document.getElementById('viewer-state')?.remove()
})

describe('isViewerAuthenticated', () => {
  it('بدونِ تگ، مهمان فرض می‌شود', () => {
    // پاسخِ مهمان اصلاً این تگ را ندارد
    expect(isViewerAuthenticated()).toBe(false)
  })

  it('با تگِ کاربرِ واردشده true می‌دهد', () => {
    setTag('{"authenticated":true}')
    expect(isViewerAuthenticated()).toBe(true)
  })

  it('مقدارِ false را درست می‌فهمد', () => {
    setTag('{"authenticated":false}')
    expect(isViewerAuthenticated()).toBe(false)
  })

  it('تگِ خراب صفحه را نمی‌شکند', () => {
    /*
     * اگر JSON خراب باشد، بدترین حالتِ قابل قبول این است که دکمه‌ی «ورود»
     * بماند — نه اینکه کلِ ناوبری با استثنا از کار بیفتد.
     */
    setTag('{ not json')
    expect(isViewerAuthenticated()).toBe(false)
  })

  it('مقدارهای غیرمنتظره را true حساب نمی‌کند', () => {
    // فقط بولینِ true قبول است، نه هر چیزِ truthy
    setTag('{"authenticated":"yes"}')
    expect(isViewerAuthenticated()).toBe(false)
  })
})
