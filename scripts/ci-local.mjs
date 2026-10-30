#!/usr/bin/env node
/**
 * CI ِ محلی — همان گام‌های `.github/workflows/ci.yml` روی همین سیستم (مرحلهٔ ۱۳ · فاز ۴؛ درخواستِ کارفرما ۲۰۲۶-۱۰-۲۶).
 *
 * چرا: هر دورِ CI ِ گیت‌هاب ۲۵ تا ۴۰ دقیقه است و بخشی از شکست‌هایش مالِ خودِ رانر است (`a9da951`: نصبِ مرورگرها ۲۴ دقیقه
 * گیر کرد). این اسکریپت همان دروازه‌ها را پیش از push این‌جا می‌گذراند؛ CI ِ گیت‌هاب سرِ جایش می‌ماند و فقط تأیید است.
 *
 *   npm run ci:local                 همه: بیلد ← (فرانت ‖ بک‌اند ‖ امنیت ‖ MySQL) ← سرتاسری
 *   npm run ci:local -- --quick      بی سرتاسری (چند دقیقه)
 *   npm run ci:local -- --e2e-only   فقط سرتاسری (بیلدِ موجود را می‌سازد و می‌سنجد)
 *   npm run ci:local -- --spec top-bar.spec.ts   سرتاسری فقط برای همین فایل‌ها (با «,»)
 *   npm run ci:local -- --parallel   سرتاسری هم‌زمان با بک‌اند (سریع‌تر، بارِ بیشتر روی CPU)
 *
 * تفاوت‌های ناگزیر با گیت‌هاب — هر کدام در خروجی صریح گفته می‌شود، نه پنهان:
 *   - Firefox فقط اگر روی این سیستم بالا بیاید (ویندوز بی Visual C++ Runtime خطای side-by-side می‌دهد)؛ وگرنه «ردشده».
 *   - قیدهای MySQL فقط اگر Docker روشن باشد (`mysql:8.4` روی درگاهِ ۳۳۳۰۶، پس از اجرا پاک می‌شود)؛ وگرنه «ردشده».
 *   - دو آزمونِ بارگذاریِ فایلِ ساختگیِ PHP که Defender ِ ویندوز می‌گیرد، این‌جا اجرا نمی‌شوند (فقط CI).
 *
 * گزارشِ هر کار در `storage/logs/ci-local/<زمان>/`. کدِ خروج ۰ فقط وقتی هیچ کاری نشکسته باشد.
 */
import { spawn } from 'node:child_process'
import {
  createWriteStream,
  existsSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const args = process.argv.slice(2)
const flag = (name) => args.includes(`--${name}`)
const option = (name) => {
  const i = args.indexOf(`--${name}`)
  return i >= 0 ? args[i + 1] : undefined
}

const QUICK = flag('quick')
const E2E_ONLY = flag('e2e-only')
const PARALLEL = flag('parallel')
const SPECS = (option('spec') ?? '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean)
  .map((s) => (s.includes('/') ? s : `tests/e2e/${s}`))

const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)
const LOGS = join('storage', 'logs', 'ci-local', stamp)
mkdirSync(LOGS, { recursive: true })

// همان env ِ گام‌های CI (ci.yml، «تستِ سرتاسری» و «قیدهای دیتابیس روی MySQL»)
const E2E_ENV = {
  SUBSCRIPTION_GATEWAY: 'sandbox',
  PAYMENT_SANDBOX_ENABLED: 'true',
  AUTH_PASSWORD_TIMEOUT: '5',
}
const MYSQL_PORT = '33306'
const MYSQL_CONTAINER = 'sakena-ci-local-mysql'
// Defender ِ ویندوز فایلِ ساختگیِ web-shell ِ این دو آزمون را قرنطینه می‌کند (یادداشتِ پروژه)؛ CI هر دو را اجرا می‌کند
const DEFENDER_SKIP =
  'test_the_upload_rules_read_the_content_not_the_extension|test_a_disguised_file_is_rejected|polyglot'

/** @type {{name: string, status: 'pass'|'fail'|'skip', seconds: number, note?: string, log?: string}[]} */
const results = []

function run(command, { env = {}, log, label }) {
  return new Promise((resolve) => {
    const out = createWriteStream(log, { flags: 'a' })
    out.write(`\n$ ${command}\n`)
    const child = spawn(command, { shell: true, env: { ...process.env, ...env, FORCE_COLOR: '0' } })
    child.stdout.pipe(out, { end: false })
    child.stderr.pipe(out, { end: false })
    child.on('close', (code) => {
      out.end(`\n[exit ${code}]\n`)
      if (label) process.stdout.write(`   ${code === 0 ? '·' : '✗'} ${label}\n`)
      resolve(code ?? 1)
    })
  })
}

/** یک کار: چند فرمان پشتِ هم؛ نخستین شکست کار را می‌بندد (همان رفتارِ گام‌های یک job) */
async function job(name, steps, { env = {}, note, slug } = {}) {
  // نامِ فایل لاتین: نامِ فارسیِ کار در `\w` نمی‌گنجید و همه‌ی گزارش‌ها در یک `_.log` می‌ریختند
  const log = join(LOGS, `${slug}.log`)
  const started = Date.now()
  process.stdout.write(`▶ ${name}\n`)
  for (const [label, command] of steps) {
    const code = await run(command, { env, log, label: `${name}: ${label}` })
    if (code !== 0) {
      results.push({
        name,
        status: 'fail',
        seconds: (Date.now() - started) / 1000,
        note: `«${label}» شکست`,
        log,
      })
      return false
    }
  }
  results.push({ name, status: 'pass', seconds: (Date.now() - started) / 1000, note, log })
  return true
}

function skip(name, note) {
  process.stdout.write(`⊘ ${name}: ${note}\n`)
  results.push({ name, status: 'skip', seconds: 0, note })
}

function portFree(port) {
  return new Promise((resolve) => {
    const server = createServer()
    server.once('error', () => resolve(false))
    server.once('listening', () => server.close(() => resolve(true)))
    server.listen(port, '127.0.0.1')
  })
}

async function firefoxRuns() {
  const probe = join(LOGS, 'firefox-probe.log')
  const code = await run(
    `node -e "require('@playwright/test').firefox.launch().then((b) => b.close()).catch((e) => { console.error(e.message); process.exit(1) })"`,
    { log: probe },
  )
  return code === 0
}

async function dockerRuns() {
  return (
    (await run('docker info --format "{{.ServerVersion}}"', {
      log: join(LOGS, 'docker-probe.log'),
    })) === 0
  )
}

/* ── کارها ─────────────────────────────────────────────────────────────────────────────────────── */

const build = () =>
  job(
    'بیلد',
    [
      // نماهای کامپایل‌شده‌ی محلی CSS را چاق می‌کنند و بودجه‌ی باندل را می‌شکنند (CI این‌ها را ندارد)
      ['پاکسازیِ نماهای کامپایل‌شده', 'php artisan view:clear'],
      ['vite build', 'npm run build'],
    ],
    { slug: 'build' },
  )

const frontend = () =>
  job(
    'فرانت',
    [
      ['قالب‌بندی', 'npm run format:check'],
      ['تایپ', 'npm run typecheck'],
      ['لینت', 'npm run lint'],
      ['Vitest (با بودجه‌ی باندل)', 'npm run test'],
    ],
    { slug: 'frontend' },
  )

const backend = () =>
  job(
    'بک‌اند',
    [
      ['Pint', 'composer lint'],
      ['PHPStan', 'composer analyse'],
      ['PHPUnit', `php artisan test --exclude-filter "${DEFENDER_SKIP}"`],
    ],
    { slug: 'backend', note: 'دو آزمونِ فایلِ ساختگیِ PHP (Defender) فقط در CI' },
  )

const security = () =>
  job(
    'امنیت',
    [
      ['composer audit', 'composer audit --locked --no-interaction'],
      ['npm audit (محصول، از «بالا»)', 'npm audit --omit=dev --audit-level=high'],
      ['npm audit (ابزارِ توسعه، «بحرانی»)', 'npm audit --audit-level=critical'],
      ['امضای پکیج‌ها', 'npm audit signatures'],
    ],
    { slug: 'security' },
  )

async function mysqlConstraints() {
  if (!(await dockerRuns())) {
    skip(
      'قیدهای MySQL',
      'Docker روشن نیست — Docker Desktop را روشن کنید تا این کار هم اجرا شود (CI اجرا می‌کند)',
    )
    return
  }
  const log = join(LOGS, 'mysql.log')
  await run(`docker rm -f ${MYSQL_CONTAINER}`, { log })
  const started = await run(
    `docker run -d --name ${MYSQL_CONTAINER} -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=testing -p ${MYSQL_PORT}:3306 mysql:8.4`,
    { log },
  )
  if (started !== 0) {
    results.push({
      name: 'قیدهای MySQL',
      status: 'fail',
      seconds: 0,
      note: 'کانتینرِ MySQL بالا نیامد',
      log,
    })
    return
  }
  try {
    let ready = false
    for (let i = 0; i < 45 && !ready; i++) {
      ready =
        (await run(`docker exec ${MYSQL_CONTAINER} mysqladmin ping -h 127.0.0.1 -uroot -proot`, {
          log,
        })) === 0
      if (!ready) await new Promise((r) => setTimeout(r, 2000))
    }
    // همان فهرستِ ci.yml؛ `REQUIRE_DB_CONSTRAINTS` ردشدن را هم شکست می‌کند
    const filter =
      'SchemaConstraintTest|SecretHandlingTest|ChaosTest|CheckoutConcurrencyTest|WalletConcurrencyTest|InvitationConcurrencyTest|PhoneChangeConcurrencyTest'
    await job('قیدهای MySQL', [['PHPUnit روی MySQL', `php artisan test --filter="${filter}"`]], {
      slug: 'mysql',
      env: {
        DB_CONNECTION: 'mysql',
        DB_HOST: '127.0.0.1',
        DB_PORT: MYSQL_PORT,
        DB_DATABASE: 'testing',
        DB_USERNAME: 'root',
        DB_PASSWORD: 'root',
        REQUIRE_DB_CONSTRAINTS: '1',
      },
    })
  } finally {
    await run(`docker rm -f ${MYSQL_CONTAINER}`, { log })
  }
}

async function e2e() {
  if (!(await portFree(8001))) {
    results.push({
      name: 'سرتاسری',
      status: 'fail',
      seconds: 0,
      note: 'درگاهِ ۸۰۰۱ اشغال است — Playwright سرورِ موجود (شاید روی پایگاه‌داده‌ی دیگر) را به کار می‌گرفت',
    })
    return
  }

  const projects = ['chromium', 'webkit', 'موبایل', 'موبایلِ iOS', 'تبلت', 'لپ‌تاپ']
  const firefox = await firefoxRuns()
  if (firefox) projects.push('firefox')
  const engines = firefox
    ? 'سه موتور'
    : 'Chromium و WebKit — Firefox روی این سیستم بالا نمی‌آید (side-by-side)؛ فقط CI'

  // پایگاه‌داده‌ی تازه، هرگز `database/database.sqlite` ِ توسعه (یادداشتِ پروژه)
  const dir = join(tmpdir(), 'sakena-ci-local')
  mkdirSync(dir, { recursive: true })
  const db = join(dir, `e2e-${stamp}.sqlite`)
  writeFileSync(db, '')
  const env = { DB_CONNECTION: 'sqlite', DB_DATABASE: db, ...E2E_ENV }

  const only = SPECS.length > 0 ? ` ${SPECS.map((s) => `"${s}"`).join(' ')}` : ''
  const list = projects.map((p) => `--project="${p}"`).join(' ')
  try {
    await job(
      'سرتاسری',
      [
        ['مهاجرت', 'php artisan migrate --force --no-interaction'],
        ['DemoSeeder', 'php artisan db:seed --class=DemoSeeder --force --no-interaction'],
        ['E2eSeeder', 'php artisan db:seed --class=E2eSeeder --force --no-interaction'],
        ['Playwright', `npx playwright test${only} ${list} --reporter=line`],
      ],
      { slug: 'e2e', env, note: engines },
    )
  } finally {
    rmSync(db, { force: true })
  }
}

/* ── اجرا ──────────────────────────────────────────────────────────────────────────────────────── */

const started = Date.now()
process.stdout.write(`CI ِ محلی — گزارش‌ها در ${LOGS}\n\n`)

/*
 * ترتیب: Vitest با همه‌ی هسته‌ها کار می‌کند و زیرِ بارِ PHPUnit ِ هم‌زمان آزمون‌های زمان‌دار ناپایدار شدند (سنجیده در
 * ۱۳/۳)؛ پس اول فرانت کنارِ امنیت (شبکه‌ای و سبک)، بعد بک‌اند کنارِ MySQL (کانتینرِ جدا)، و سرتاسری آخر.
 */
const checks = async () => {
  await Promise.all([frontend(), security()])
  await Promise.all([backend(), mysqlConstraints()])
}

if (await build()) {
  if (E2E_ONLY) {
    await e2e()
  } else if (QUICK) {
    await checks()
  } else if (PARALLEL) {
    await Promise.all([checks(), e2e()])
  } else {
    await checks()
    await e2e()
  }
}

/* ── گزارش ─────────────────────────────────────────────────────────────────────────────────────── */

const icon = { pass: '✓', fail: '✗', skip: '⊘' }
process.stdout.write('\n──────── نتیجه ────────\n')
for (const r of results) {
  const time = r.seconds ? `${Math.round(r.seconds)}s`.padStart(6) : '      '
  process.stdout.write(`${icon[r.status]} ${time}  ${r.name}${r.note ? ` — ${r.note}` : ''}\n`)
}

for (const r of results.filter((x) => x.status === 'fail' && x.log && existsSync(x.log))) {
  const tail = readFileSync(r.log, 'utf8')
    .split('\n')
    .filter((line) => /✘|✗|FAIL|Error|error|failed|\d+\) \[|Expected|Received|×/.test(line))
    .slice(-25)
  process.stdout.write(`\n── ${r.name} (${r.log}) ──\n${tail.join('\n')}\n`)
}

const failed = results.some((r) => r.status === 'fail')
process.stdout.write(
  `\n${failed ? '✗ شکست' : '✓ سبز'} — ${Math.round((Date.now() - started) / 60000)} دقیقه. ` +
    `${results.some((r) => r.status === 'skip') ? 'کارهای «ردشده» فقط در CI ِ گیت‌هاب سنجیده می‌شوند.' : ''}\n`,
)
process.exit(failed ? 1 : 0)
