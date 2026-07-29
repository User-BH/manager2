import { useEffect, useState } from 'react'
import { Loader2, Save } from 'lucide-react'
import { Card } from '@/shared/ui/Card'
import { TextField } from '@/shared/ui/Field'
import { ErrorState, LoadingState } from '@/shared/ui/PageState'
import {
  InstagramIcon,
  TelegramIcon,
  WhatsappIcon,
  RubikaIcon,
  BaleIcon,
} from '@/shared/common/SocialIcons'
import { useApi } from '@/shared/hooks/useApi'
import { useDocumentTitle } from '@/shared/hooks'
import { api } from '@/shared/lib/api'
import { alertError, toastSuccess } from '@/shared/lib/alert'

interface Contact {
  title: string
  address: string
  phone: string
  email: string
  mapEmbedUrl: string
  showAddress: boolean
  showPhone: boolean
  showEmail: boolean
  showMap: boolean
}

interface Social {
  id: string
  label: string
  href: string
  enabled: boolean
}

const socialIconMap = {
  instagram: InstagramIcon,
  telegram: TelegramIcon,
  whatsapp: WhatsappIcon,
  rubika: RubikaIcon,
  bale: BaleIcon,
} as const

/**
 * تنظیماتِ فوترِ سایت برای ادمینِ کل: بخشِ «ارتباط با ما» (آدرس/تلفن/ایمیل/نقشه
 * با کلیدِ روشن‌وخاموش) و لینکِ پنجِ شبکه‌ی اجتماعی.
 */
export function SiteSettingsPage() {
  useDocumentTitle('فوتر و شبکه‌های اجتماعی')

  const { data, error, isLoading, reload } = useApi<{ footer: { contact: Contact; social: Social[] } }>(
    '/system/site-settings',
  )

  const [contact, setContact] = useState<Contact | null>(null)
  const [social, setSocial] = useState<Social[] | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (data) {
      setContact(data.footer.contact)
      setSocial(data.footer.social)
    }
  }, [data])

  async function save() {
    if (!contact || !social) return
    setSaving(true)
    try {
      await api('/system/site-settings', { method: 'PUT', body: { contact, social } })
      toastSuccess('تنظیمات فوتر ذخیره شد.')
      reload()
    } catch (err) {
      alertError(err, 'ذخیره‌ی تنظیمات ممکن نشد.')
    } finally {
      setSaving(false)
    }
  }

  if (isLoading) return <LoadingState rows={5} />
  if (error) return <ErrorState message={error} onRetry={reload} />
  if (!contact || !social) return null

  const setC = <K extends keyof Contact>(key: K, value: Contact[K]) =>
    setContact((c) => (c ? { ...c, [key]: value } : c))

  const setS = (id: string, patch: Partial<Social>) =>
    setSocial((list) => list?.map((s) => (s.id === id ? { ...s, ...patch } : s)) ?? list)

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-xl font-extrabold" style={{ color: 'var(--text-primary)' }}>
          فوتر و شبکه‌های اجتماعی
        </h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-tertiary)' }}>
          اطلاعاتِ تماس و لینکِ شبکه‌های اجتماعیِ صفحه‌ی اصلی از اینجا مدیریت می‌شود.
        </p>
      </header>

      {/* --- ارتباط با ما --- */}
      <Card title="ارتباط با ما" subtitle="هر ردیف می‌تواند نمایش داده یا پنهان شود">
        <div className="flex flex-col gap-4">
          <TextField
            label="عنوان بخش"
            value={contact.title}
            onChange={(e) => setC('title', e.target.value)}
          />

          <ContactRow
            label="آدرس"
            shown={contact.showAddress}
            onToggle={(v) => setC('showAddress', v)}
          >
            <TextField
              label="متن آدرس"
              value={contact.address}
              onChange={(e) => setC('address', e.target.value)}
            />
          </ContactRow>

          <ContactRow label="تلفن" shown={contact.showPhone} onToggle={(v) => setC('showPhone', v)}>
            <TextField
              label="شماره تلفن"
              dir="ltr"
              value={contact.phone}
              onChange={(e) => setC('phone', e.target.value)}
            />
          </ContactRow>

          <ContactRow label="ایمیل" shown={contact.showEmail} onToggle={(v) => setC('showEmail', v)}>
            <TextField
              label="آدرس ایمیل"
              dir="ltr"
              value={contact.email}
              onChange={(e) => setC('email', e.target.value)}
            />
          </ContactRow>

          <ContactRow label="نقشه" shown={contact.showMap} onToggle={(v) => setC('showMap', v)}>
            <TextField
              label="لینک embed نقشه (Google Maps)"
              dir="ltr"
              value={contact.mapEmbedUrl}
              onChange={(e) => setC('mapEmbedUrl', e.target.value)}
            />
          </ContactRow>
        </div>
      </Card>

      {/* --- شبکه‌های اجتماعی --- */}
      <Card
        title="شبکه‌های اجتماعی"
        subtitle="لینک هر شبکه را وارد و در صورت نیاز خاموش کنید"
        delay={0.05}
      >
        <div className="flex flex-col gap-3">
          {social.map((item) => {
            const Icon = socialIconMap[item.id as keyof typeof socialIconMap]
            return (
              <div
                key={item.id}
                className="flex flex-col gap-3 rounded-2xl border p-3 sm:flex-row sm:items-end"
                style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
              >
                <div className="flex items-center gap-2 sm:w-32 sm:shrink-0 sm:pb-3">
                  <span
                    className="flex h-8 w-8 items-center justify-center rounded-lg"
                    style={{ backgroundColor: 'var(--surface-base)', color: 'var(--text-secondary)' }}
                  >
                    {Icon && <Icon size={16} />}
                  </span>
                  <span className="text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
                    {item.label}
                  </span>
                </div>

                <div className="min-w-0 flex-1">
                  <TextField
                    label="لینک"
                    dir="ltr"
                    placeholder="https://…"
                    value={item.href}
                    onChange={(e) => setS(item.id, { href: e.target.value })}
                  />
                </div>

                <label
                  className="flex items-center gap-2 pb-3 text-[12.5px] font-medium sm:shrink-0"
                  style={{ color: 'var(--text-secondary)' }}
                >
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded"
                    checked={item.enabled}
                    onChange={(e) => setS(item.id, { enabled: e.target.checked })}
                  />
                  نمایش
                </label>
              </div>
            )
          })}
        </div>
      </Card>

      <div>
        <button
          type="button"
          onClick={save}
          disabled={saving}
          className="flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-bold text-white disabled:opacity-70"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
          ذخیره تنظیمات
        </button>
      </div>
    </div>
  )
}

/** یک ردیفِ تماس با کلیدِ نمایش/پنهان. وقتی خاموش است، ورودی کم‌رنگ می‌شود. */
function ContactRow({
  label,
  shown,
  onToggle,
  children,
}: {
  label: string
  shown: boolean
  onToggle: (value: boolean) => void
  children: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-2 rounded-2xl border p-3" style={{ borderColor: 'var(--border-subtle)' }}>
      <label className="flex items-center gap-2 text-[13px] font-bold" style={{ color: 'var(--text-primary)' }}>
        <input
          type="checkbox"
          className="h-4 w-4 rounded"
          checked={shown}
          onChange={(e) => onToggle(e.target.checked)}
        />
        نمایشِ {label}
      </label>
      <div style={{ opacity: shown ? 1 : 0.55 }}>{children}</div>
    </div>
  )
}
