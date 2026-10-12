import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Check,
  Copy,
  Loader2,
  Link2 as QrIcon,
  RefreshCw,
  ShieldCheck,
  ToggleLeft,
} from 'lucide-react'
import { api } from '@/shared/lib/api'
import { copyText } from '@/shared/lib/clipboard'
import { queryKeys } from '@/shared/lib/queryKeys'
import { useAction } from '@/shared/hooks/useAction'
import { QrCode } from '@/shared/ui/QrCode'

interface JoinLink {
  link: string | null
  createdAt: string | null
}

/**
 * QR ِ ورودِ عمومیِ مجتمع (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۲ و ۲۵ ِ فایلِ معماری).
 *
 * ⚠️ بندِ ۲۵: «این تفاوت باید در UI ِ مدیر کاملاً واضح باشد؛ دو flow نباید با هم مخلوط شوند». پس همین‌جا،
 * کنارِ QR، دو مسیر کنارِ هم گفته می‌شوند: QR ← درخواست ← تأییدِ شما، در برابرِ دعوتِ مستقیم که بی
 * تأییدِ دوباره عضو می‌کند. «نمایش» کدِ موجود را نگه می‌دارد؛ فقط «QR ِ تازه» QR ِ چاپ‌شده را باطل می‌کند.
 */
export function JoinQrPanel() {
  const { run, pendingKey } = useAction()
  const [copied, setCopied] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.joinLink.current(),
    queryFn: ({ signal }) => api<JoinLink>('/join-link', { signal }),
  })

  const change = (
    key: 'create' | 'rotate' | 'disable',
    request: () => Promise<JoinLink>,
    options: Parameters<typeof run>[1] = {},
  ) =>
    run(request, {
      key,
      invalidate: [queryKeys.joinLink.current()],
      ...options,
    })

  const link = data?.link ?? null

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-2 sm:grid-cols-2">
        <FlowCard
          title="QR ِ عمومی"
          steps="اسکن ← ثبت‌نام یا ورود ← درخواست ← تأییدِ شما ← عضویت"
          note="اسکن به‌تنهایی عضویت نیست؛ هر درخواست در «درخواست‌های عضویت» منتظرِ تصمیمِ شماست."
          highlight
        />
        <FlowCard
          title="دعوتِ مستقیم"
          steps="«ساکن جدید» ← پیوندِ شخصی ← ثبت‌نام ← عضویتِ مستقیم"
          note="به یک شماره بسته است و بی تأییدِ دوباره عضو می‌کند."
        />
      </div>

      {isLoading ? (
        <p
          className="flex items-center justify-center gap-2 py-8 text-sm"
          style={{ color: 'var(--text-secondary)' }}
        >
          <Loader2 size={16} className="animate-spin" />
          در حال بارگذاری…
        </p>
      ) : link === null ? (
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          <QrIcon size={36} style={{ color: 'var(--text-tertiary)' }} />
          <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
            ورودِ عمومی با QR برای این مجتمع خاموش است.
          </p>
          <button
            type="button"
            disabled={pendingKey !== null}
            onClick={() =>
              void change('create', () => api<JoinLink>('/join-link', { method: 'POST' }), {
                success: 'QR ِ عمومی ساخته شد.',
              })
            }
            className="rounded-xl px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--color-brand-500)' }}
          >
            ساختِ QR ِ عمومی
          </button>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-3">
          <QrCode value={link} label="QR ِ ورودِ عمومیِ مجتمع" />

          <div className="flex w-full items-stretch gap-2">
            <input
              readOnly
              value={link}
              dir="ltr"
              aria-label="پیوندِ QR"
              onFocus={(event) => event.currentTarget.select()}
              className="field min-w-0 flex-1 rounded-xl px-3 py-2.5 text-xs"
            />
            <button
              type="button"
              onClick={() => void copyText(link).then(setCopied)}
              className="flex shrink-0 items-center gap-1.5 rounded-xl px-3 text-xs font-bold text-white"
              style={{ backgroundColor: 'var(--color-brand-500)' }}
            >
              {copied ? <Check size={14} /> : <Copy size={14} />}
              {copied ? 'کپی شد' : 'کپی'}
            </button>
          </div>

          <div className="flex w-full flex-wrap justify-center gap-2">
            <button
              type="button"
              disabled={pendingKey !== null}
              onClick={() =>
                void change(
                  'rotate',
                  () => api<JoinLink>('/join-link/rotate', { method: 'POST' }),
                  {
                    confirm: {
                      title: 'QR ِ تازه ساخته شود؟',
                      text: 'QR ِ فعلی — هر جا چاپ یا فرستاده شده — از همین لحظه «نامعتبر» می‌گوید. درخواست‌های فرستاده‌شده می‌مانند.',
                      confirmLabel: 'QR ِ تازه',
                      danger: true,
                    },
                    success: 'QR ِ تازه ساخته شد؛ قبلی دیگر کار نمی‌کند.',
                    onDone: () => setCopied(false),
                  },
                )
              }
              className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-primary)' }}
            >
              <RefreshCw size={13} />
              QR ِ تازه
            </button>
            <button
              type="button"
              disabled={pendingKey !== null}
              onClick={() =>
                void change('disable', () => api<JoinLink>('/join-link', { method: 'DELETE' }), {
                  confirm: {
                    title: 'ورودِ عمومی خاموش شود؟',
                    text: 'QR دیگر کار نمی‌کند. درخواست‌های فرستاده‌شده می‌مانند و دعوتِ مستقیم مثلِ قبل کار می‌کند.',
                    confirmLabel: 'خاموش کن',
                    danger: true,
                  },
                  success: 'ورودِ عمومی خاموش شد.',
                })
              }
              className="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-60"
              style={{ borderColor: 'var(--border-default)', color: 'var(--text-secondary)' }}
            >
              <ToggleLeft size={13} />
              خاموش کردن
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function FlowCard({
  title,
  steps,
  note,
  highlight = false,
}: {
  title: string
  steps: string
  note: string
  highlight?: boolean
}) {
  return (
    <section
      aria-label={title}
      className="flex flex-col gap-1 rounded-xl border p-3"
      style={{
        borderColor: highlight ? 'var(--color-brand-500)' : 'var(--border-default)',
        backgroundColor: 'var(--surface-sunken)',
      }}
    >
      <p
        className="flex items-center gap-1.5 text-xs font-bold"
        style={{ color: 'var(--text-primary)' }}
      >
        <ShieldCheck size={13} style={{ color: 'var(--color-brand-500)' }} />
        {title}
      </p>
      <p className="text-xs leading-6" style={{ color: 'var(--text-secondary)' }}>
        {steps}
      </p>
      <p className="text-[11px] leading-5" style={{ color: 'var(--text-tertiary)' }}>
        {note}
      </p>
    </section>
  )
}
