import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * کنتراستِ رنگ‌های پیام‌رسان — مرحلهٔ ۱۲ · فاز ۷ (بندِ ۴۶ ِ سندِ هسته).
 *
 * jsdom CSS را اعمال نمی‌کند، پس رنگ‌ها از خودِ پرونده‌ها خوانده و نسبتِ کنتراستِ WCAG حساب می‌شود. 🔴 سنجشِ مرورگر
 * در ۱۲/۷ برچسبِ «مدیریت» را در حالتِ تیره ۱٫۵ به ۱ یافت (سبزِ تیره روی سبزِ تیره)؛ این آزمون همان را در هر دو حالت
 * برای هر متنِ روی هر زمینه‌ی پیام‌رسان نگه می‌دارد. کف: ۴٫۵ به ۱ (متنِ کوچک).
 */

const app = readFileSync('resources/css/app.css', 'utf8')
const messenger = readFileSync('resources/js/features/messaging/messenger/messenger.css', 'utf8')

/** مقدارِ یک متغیر در نخستین بلوکِ گزینشگرِ داده‌شده */
function token(css: string, selector: string, name: string): string {
  const start = css.indexOf(`${selector} {`)
  expect(start, `بلوکِ ${selector}`).toBeGreaterThanOrEqual(0)
  const block = css.slice(start, css.indexOf('}', start))
  const match = block.match(new RegExp(`${name}:\\s*(#[0-9a-fA-F]{6})`))
  expect(match, `${name} در ${selector}`).not.toBeNull()

  return match![1]
}

/** رنگِ یک ویژگی در بلوکِ گزینشگر (متغیرِ `var(--x)` از `app.css` ِ همان حالت خوانده می‌شود) */
function property(selector: string, prop: string, theme: ':root' | '.dark'): string {
  const start = messenger.indexOf(`${selector} {`)
  expect(start, `بلوکِ ${selector}`).toBeGreaterThanOrEqual(0)
  const block = messenger.slice(start, messenger.indexOf('}', start))
  const value = block.match(new RegExp(`(?<![\\w-])${prop}:\\s*([^;]+);`))![1].trim()
  const ref = value.match(/^var\((--[\w-]+)\)$/)

  return ref ? resolve(ref[1], theme) : value
}

function resolve(name: string, theme: ':root' | '.dark'): string {
  if (theme === '.dark') {
    const dark = app.slice(app.indexOf('.dark {'), app.indexOf('}', app.indexOf('.dark {')))
    const hit = dark.match(new RegExp(`${name}:\\s*(#[0-9a-fA-F]{6})`))
    if (hit) return hit[1]
  }

  return app.match(new RegExp(`${name}:\\s*(#[0-9a-fA-F]{6})`))![1]
}

function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const v = parseInt(hex.slice(i, i + 2), 16) / 255
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  })

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function contrast(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p)

  return (x + 0.05) / (y + 0.05)
}

describe('کنتراستِ پیام‌رسان', () => {
  it('متن و زمانِ پیامِ خود روی حبابِ سبز، در هر دو حالت', () => {
    const lightBg = resolve('--color-brand-500', ':root')
    expect(
      contrast(token(messenger, '.chat-surface', '--msg-mine-fg'), lightBg),
    ).toBeGreaterThanOrEqual(4.5)
    expect(
      contrast(token(messenger, '.chat-surface', '--msg-mine-meta'), lightBg),
    ).toBeGreaterThanOrEqual(4.5)

    const darkBg = token(messenger, '.dark .chat-surface', '--msg-mine')
    expect(
      contrast(token(messenger, '.dark .chat-surface', '--msg-mine-fg'), darkBg),
    ).toBeGreaterThanOrEqual(4.5)
    expect(
      contrast(token(messenger, '.dark .chat-surface', '--msg-mine-meta'), darkBg),
    ).toBeGreaterThanOrEqual(4.5)
  })

  it('نامِ هر فرستنده روی حبابِ دیگران، در هر دو حالت', () => {
    for (const n of [0, 1, 2, 3]) {
      expect(
        contrast(
          token(messenger, '.chat-surface', `--msg-name-${n}`),
          resolve('--surface-raised', ':root'),
        ),
        `نامِ ${n} روشن`,
      ).toBeGreaterThanOrEqual(4.5)
      expect(
        contrast(
          token(messenger, '.dark .chat-surface', `--msg-name-${n}`),
          resolve('--surface-raised', '.dark'),
        ),
        `نامِ ${n} تیره`,
      ).toBeGreaterThanOrEqual(4.5)
    }
  })

  it('🔴 برچسبِ «مدیریت» و نوارِ «خوانده‌نشده»، در هر دو حالت', () => {
    const lightFg = property('.msg-badge,\n.msg-unread', 'color', ':root')
    const lightBg = property('.msg-badge,\n.msg-unread', 'background-color', ':root')
    expect(contrast(lightFg, lightBg)).toBeGreaterThanOrEqual(4.5)

    const darkFg = property('.dark .msg-badge,\n.dark .msg-unread', 'color', '.dark')
    expect(contrast(darkFg, resolve('--tone-brand-bg', '.dark'))).toBeGreaterThanOrEqual(4.5)
  })
})
