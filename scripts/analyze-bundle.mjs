/**
 * تحلیلگرِ باندل (R36).
 *
 * ─── چرا دست‌نویس و نه `rollup-plugin-visualizer` ───────────────────────────
 * آن پلاگین یک HTMLِ زیبا می‌دهد که باید با چشم نگاهش کرد. چیزی که اینجا
 * لازم است **عددِ قابلِ مقایسه** است: چقدر در بارگذاریِ اول دانلود می‌شود،
 * و آیا از دفعه‌ی قبل بدتر شده یا نه. آن را می‌شود در تست قفل کرد؛ یک
 * تصویر را نه. ضمناً پروژه قیدِ «پکیجِ بی‌دلیل نصب نکن» دارد.
 *
 * ─── تعریفِ «بارگذاریِ اول» ────────────────────────────────────────────────
 * از هر ورودی شروع می‌کنیم و فقط `imports`ِ **ایستا** را دنبال می‌کنیم.
 * `dynamicImports` عمداً شمرده نمی‌شود، چون دقیقاً همان چیزی است که برای
 * نیامدنش تلاش می‌کنیم — اگر آن‌ها را هم بشماریم، هر بار که چیزی را تنبل
 * می‌کنیم عدد ثابت می‌ماند و کارِ درست بی‌پاداش می‌شود.
 *
 * اجرا: `node scripts/analyze-bundle.mjs [--json]`
 */

import { gzipSync } from 'node:zlib'
import { readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const BUILD = 'public/build'
const manifest = JSON.parse(readFileSync(join(BUILD, 'manifest.json'), 'utf8'))

/** بایتِ فشرده‌شده — همان چیزی که کاربر واقعاً از شبکه می‌گیرد. */
function sizes(file) {
  const path = join(BUILD, file)
  const raw = statSync(path).size
  const gzip = gzipSync(readFileSync(path), { level: 9 }).length

  return { raw, gzip }
}

/** بستارِ ایستای یک ورودی: خودش + هر چه بدونِ شرط وارد می‌کند. */
function staticClosure(entryKey) {
  const seen = new Set()
  const queue = [entryKey]

  while (queue.length > 0) {
    const key = queue.pop()

    if (seen.has(key)) continue

    const node = manifest[key]

    if (!node) continue

    seen.add(key)
    queue.push(...(node.imports ?? []))
  }

  return [...seen]
}

const entries = Object.entries(manifest).filter(([, node]) => node.isEntry)

const report = entries.map(([key, node]) => {
  const chunks = staticClosure(key)

  let raw = 0
  let gzip = 0
  const files = []

  for (const chunkKey of chunks) {
    const chunk = manifest[chunkKey]

    for (const file of [chunk.file, ...(chunk.css ?? [])]) {
      const size = sizes(file)

      raw += size.raw
      gzip += size.gzip
      files.push({ file, ...size })
    }
  }

  return {
    entry: key,
    name: node.file,
    chunkCount: chunks.length,
    raw,
    gzip,
    files: files.sort((a, b) => b.gzip - a.gzip),
  }
})

report.sort((a, b) => b.gzip - a.gzip)

/**
 * بایتِ **یکتا** در کلِ نشست.
 *
 * ─── چرا این عدد لازم شد ───────────────────────────────────────────────────
 * عددِ «بارگذاریِ اولِ هر ورودی» به‌تنهایی گمراه‌کننده است. وقتی چانکِ
 * مشترکی می‌سازیم، عددِ تک‌تکِ صفحه‌ها بالا می‌رود ولی کاربری که چند صفحه
 * می‌بیند **کمتر** دانلود می‌کند، چون همان یک فایل کش شده است. کاربرِ
 * واقعی هم دقیقاً همین است: از صفحه‌ی فرود می‌آید، وارد می‌شود، به داشبورد
 * می‌رود. پس تصمیمِ چانک‌بندی باید با این عدد گرفته شود، نه با آن یکی.
 */
const union = new Set()

for (const [key, node] of entries) {
  for (const chunkKey of staticClosure(key)) {
    const chunk = manifest[chunkKey]

    for (const file of [chunk.file, ...(chunk.css ?? [])]) union.add(file)
  }
}

const sessionTotal = [...union].reduce(
  (acc, file) => {
    const size = sizes(file)

    return { raw: acc.raw + size.raw, gzip: acc.gzip + size.gzip }
  },
  { raw: 0, gzip: 0 },
)

const jsFiles = Object.values(manifest)
  .map((node) => node.file)
  .filter((file) => file.endsWith('.js'))

const tiny = jsFiles.filter((file) => statSync(join(BUILD, file)).size < 2048)

if (process.argv.includes('--json')) {
  report.push({
    entry: '__session__',
    ...sessionTotal,
    fileCount: union.size,
    tinyChunks: tiny.length,
  })
}

if (process.argv.includes('--json')) {
  console.log(JSON.stringify(report, null, 2))
} else {
  const kb = (n) => (n / 1024).toFixed(1).padStart(7)

  console.log('\n  بارگذاریِ اولِ هر ورودی (فقط importهای ایستا)\n')
  console.log('  ' + 'ورودی'.padEnd(46) + 'خام(KB)   gzip(KB)  چانک')
  console.log('  ' + '─'.repeat(74))

  for (const item of report) {
    console.log(`  ${item.entry.padEnd(46)}${kb(item.raw)}   ${kb(item.gzip)}   ${item.chunkCount}`)
  }

  console.log('  ' + '─'.repeat(74))
  console.log(
    `  ${'یکتا در کلِ نشست (هر ۵ ورودی)'.padEnd(46)}${kb(sessionTotal.raw)}   ${kb(sessionTotal.gzip)}   ${union.size} فایل`,
  )
  console.log(`\n  چانکِ زیر ۲KB: ${tiny.length} از ${jsFiles.length} فایلِ JS`)

  console.log('\n  سنگین‌ترین فایل‌ها در هر ورودی:\n')

  for (const item of report) {
    console.log(`  ▸ ${item.entry}`)

    for (const file of item.files.slice(0, 4)) {
      console.log(`      ${file.file.replace('assets/', '').padEnd(44)}${kb(file.gzip)} KB`)
    }
  }

  console.log()
}
