import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, FileSearch, Loader2, ShieldCheck, Upload } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { TextField } from '@/components/ui/Field'
import { useAuth } from '@/context/AuthContext'
import { api, ApiError } from '@/lib/api'

/** عبارتی که سرور برای انجام بازیابی می‌خواهد. */
const CONFIRM_PHRASE = 'بازیابی'

interface DryRunResult {
  generatedAt: string | null
  /** تعداد سطرهای هر جدول در فایل بکاپ */
  tables: Record<string, number>
  /** تعداد سطرهای فعلی همان جدول‌ها */
  current: Record<string, number>
}

/**
 * بازیابی کل سیستم.
 *
 * جریان عمداً دو مرحله‌ای است: اول فایل بررسی می‌شود و گزارشِ «چه چیزی با چه
 * چیزی جایگزین می‌شود» نشان داده می‌شود، بعد ادمین عبارت تایید را تایپ می‌کند.
 * پیش از این یک انتخاب فایل، بی‌درنگ کل دیتابیس را جایگزین می‌کرد.
 */
export function RestorePanel() {
  const [file, setFile] = useState<File | null>(null)
  const [dryRun, setDryRun] = useState<DryRunResult | null>(null)
  const [confirmText, setConfirmText] = useState('')
  const [checking, setChecking] = useState(false)
  const [restoring, setRestoring] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const navigate = useNavigate()
  const { setUser } = useAuth()

  function reset() {
    setFile(null)
    setDryRun(null)
    setConfirmText('')
    setError(null)
    if (fileRef.current) fileRef.current.value = ''
  }

  /** مرحله‌ی اول: بررسی فایل بدون هیچ تغییری در داده. */
  async function inspect(selected: File) {
    setFile(selected)
    setDryRun(null)
    setConfirmText('')
    setError(null)
    setChecking(true)

    try {
      const body = new FormData()
      body.append('backup', selected)
      body.append('dry_run', '1')

      setDryRun(await api<DryRunResult>('/system/backups/restore', { method: 'POST', body }))
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'بررسی فایل بکاپ ممکن نشد.')
    } finally {
      setChecking(false)
    }
  }

  /** مرحله‌ی دوم: بازیابی واقعی. */
  async function restore() {
    if (!file) return

    setRestoring(true)
    setError(null)

    try {
      const body = new FormData()
      body.append('backup', file)
      body.append('confirm', confirmText.trim())

      await api('/system/backups/restore', { method: 'POST', body })

      // حساب کاربری فعلی هم بازنویسی شده و نشست بسته شده است.
      setUser(null)
      navigate('/auth', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'بازیابی ناموفق بود.')
    } finally {
      setRestoring(false)
    }
  }

  const ready = confirmText.trim() === CONFIRM_PHRASE

  return (
    <Card title="بازیابی از فایل" delay={0.05}>
      <div
        className="mb-4 flex items-start gap-2.5 rounded-xl px-4 py-3"
        style={{
          backgroundColor: 'color-mix(in srgb, var(--color-danger) 10%, transparent)',
          color: 'var(--color-danger)',
        }}
      >
        <AlertTriangle size={17} className="mt-0.5 shrink-0" />
        <p className="text-[13px] leading-6">
          بازیابی تمام جدول‌ها را خالی می‌کند و از روی فایل دوباره می‌سازد. پیش از شروع، یک بکاپ
          ایمنی خودکار گرفته می‌شود تا اگر اشتباه شد بتوانید برگردید. در پایان از حساب خارج
          می‌شوید.
        </p>
      </div>

      {/* --- مرحله‌ی اول: انتخاب و بررسی فایل --- */}
      <div className="flex flex-wrap items-center gap-3">
        <input
          ref={fileRef}
          type="file"
          accept=".json,application/json"
          disabled={checking || restoring}
          onChange={(e) => {
            const selected = e.target.files?.[0]
            if (selected) void inspect(selected)
          }}
          className="block w-full max-w-sm rounded-xl border px-3 py-2.5 text-[13px] file:ml-3 file:rounded-lg file:border-0 file:px-3 file:py-1.5 file:text-white"
          style={{
            backgroundColor: 'var(--surface-sunken)',
            borderColor: 'var(--border-subtle)',
            color: 'var(--text-primary)',
          }}
        />

        {checking && (
          <span className="flex items-center gap-2 text-[13px]" style={{ color: 'var(--text-secondary)' }}>
            <Loader2 size={15} className="animate-spin" />
            در حال بررسی فایل…
          </span>
        )}

        {!checking && !dryRun && (
          <span className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--text-tertiary)' }}>
            <Upload size={12} />
            فقط فایل JSON بکاپ کامل، حداکثر ۲۰ مگابایت
          </span>
        )}
      </div>

      {error && (
        <p className="mt-3 text-[13px]" style={{ color: 'var(--color-danger)' }}>
          {error}
        </p>
      )}

      {/* --- مرحله‌ی دوم: گزارش و تایید --- */}
      {dryRun && (
        <div className="mt-5 flex flex-col gap-4">
          <div
            className="flex items-start gap-2.5 rounded-xl border px-4 py-3 text-[13px] leading-6"
            style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
          >
            <FileSearch size={16} className="mt-0.5 shrink-0" style={{ color: 'var(--color-success)' }} />
            <span style={{ color: 'var(--text-primary)' }}>
              فایل سالم است. جدول‌های زیر جایگزین می‌شوند — ستون راست تعداد فعلی و ستون چپ تعداد
              پس از بازیابی است.
            </span>
          </div>

          <div className="max-h-64 overflow-y-auto rounded-xl border" style={{ borderColor: 'var(--border-subtle)' }}>
            <table className="w-full text-[12.5px]">
              <thead className="sticky top-0" style={{ backgroundColor: 'var(--surface-sunken)' }}>
                <tr style={{ color: 'var(--text-secondary)' }}>
                  <th className="px-3 py-2 text-right font-semibold">جدول</th>
                  <th className="px-3 py-2 text-center font-semibold">اکنون</th>
                  <th className="px-3 py-2 text-center font-semibold">پس از بازیابی</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(dryRun.tables).map(([table, incoming]) => {
                  const now = dryRun.current[table] ?? 0
                  const lost = now > 0 && incoming === 0

                  return (
                    <tr key={table} className="border-t" style={{ borderColor: 'var(--border-subtle)' }}>
                      <td className="px-3 py-1.5 font-mono text-[11.5px]" style={{ color: 'var(--text-primary)' }} dir="ltr">
                        {table}
                      </td>
                      <td className="px-3 py-1.5 text-center tabular-nums" style={{ color: 'var(--text-tertiary)' }}>
                        {now}
                      </td>
                      <td
                        className="px-3 py-1.5 text-center font-semibold tabular-nums"
                        // جدولی که پر بوده و خالی می‌شود، مهم‌ترین چیزی است که
                        // ادمین باید پیش از تایید ببیند
                        style={{ color: lost ? 'var(--color-danger)' : 'var(--text-primary)' }}
                      >
                        {incoming}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div className="flex-1">
              <TextField
                label={`برای تایید، عبارت «${CONFIRM_PHRASE}» را تایپ کنید`}
                value={confirmText}
                onChange={(e) => setConfirmText(e.target.value)}
                placeholder={CONFIRM_PHRASE}
                disabled={restoring}
              />
            </div>

            <div className="flex gap-2">
              <button
                type="button"
                onClick={reset}
                disabled={restoring}
                className="rounded-xl border px-4 py-2.5 text-[13px] font-semibold transition-colors hover:bg-(--surface-sunken) disabled:opacity-60"
                style={{ borderColor: 'var(--border-subtle)', color: 'var(--text-secondary)' }}
              >
                انصراف
              </button>

              <button
                type="button"
                onClick={() => void restore()}
                disabled={!ready || restoring}
                className="flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-semibold text-white transition-opacity disabled:opacity-50"
                style={{ backgroundColor: 'var(--color-danger)' }}
              >
                {restoring ? <Loader2 size={15} className="animate-spin" /> : <ShieldCheck size={15} />}
                {restoring ? 'در حال بازیابی…' : 'بازیابی کن'}
              </button>
            </div>
          </div>
        </div>
      )}
    </Card>
  )
}
