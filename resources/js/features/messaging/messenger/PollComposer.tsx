import { useState } from 'react'
import { ChevronDown, Plus, Trash2, X } from 'lucide-react'

export interface PollDraft {
  question: string
  options: string[]
  /** چه کسانی حقِ رأی دارند (R24). */
  voterScope: 'residents' | 'owners'
  /** رأی چطور شمرده می‌شود (R24). */
  weightMode: 'per_person' | 'per_unit' | 'by_area'
  /** حد نصابِ مشارکت به درصد؛ رشته‌ی خالی یعنی بدونِ حد نصاب. */
  quorumPercent: string
  allowChange: boolean
  /** مهلتِ رأی‌گیری به شکلِ `datetime-local`؛ خالی یعنی بی‌مهلت. */
  closesAt: string
}

export const EMPTY_POLL: PollDraft = {
  question: '',
  options: ['', ''],
  // پیش‌فرض‌ها عمداً همان نظرسنجیِ ساده‌ی R23b‌اند
  voterScope: 'residents',
  weightMode: 'per_person',
  quorumPercent: '',
  allowChange: true,
  closesAt: '',
}

/** حداقل/حداکثرِ گزینه‌ها — عمداً همان قیدِ `StoreMessageRequest`. */
const MIN_OPTIONS = 2
const MAX_OPTIONS = 10

const SCOPES: Array<{ value: PollDraft['voterScope']; label: string }> = [
  { value: 'residents', label: 'همه‌ی ساکنین' },
  { value: 'owners', label: 'فقط مالکان' },
]

const WEIGHTS: Array<{ value: PollDraft['weightMode']; label: string; hint: string }> = [
  { value: 'per_person', label: 'هر نفر یک رأی', hint: 'نظرسنجی سلیقه‌ای' },
  { value: 'per_unit', label: 'هر واحد یک رأی', hint: 'تصمیم مشترک' },
  { value: 'by_area', label: 'وزنی بر اساس متراژ', hint: 'تصمیم هزینه‌بر' },
]

/**
 * ساختِ نظرسنجی توسط مدیر (R23b) با تنظیماتِ حرفه‌ای (R24).
 *
 * فرمِ جدا ندارد و بخشی از همان فرمِ ارسالِ پیام است، چون نظرسنجی هم یک
 * **پیام** است: مخاطبش را همان انتخابگرِ گیرنده (R23a) تعیین می‌کند و
 * مدیر می‌تواند نظرسنجی را فقط برای چند واحد بفرستد.
 *
 * تنظیماتِ حرفه‌ای پشتِ یک آکاردئون بسته‌اند: نظرسنجیِ روزمره با یک سوال و
 * دو گزینه ساخته می‌شود و نباید پنج تصمیمِ اضافه جلوی مدیر بگذارد.
 */
export function PollComposer({
  draft,
  onChange,
  onClose,
}: {
  draft: PollDraft
  onChange: (draft: PollDraft) => void
  onClose: () => void
}) {
  const [showSettings, setShowSettings] = useState(false)

  const inputStyle = {
    backgroundColor: 'var(--surface-base)',
    borderColor: 'var(--border-subtle)',
    color: 'var(--text-primary)',
  }

  return (
    <div
      className="mb-2 rounded-xl border p-3"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
    >
      <div className="mb-2 flex items-center justify-between">
        <span className="text-[12.5px] font-bold" style={{ color: 'var(--text-primary)' }}>
          نظرسنجی
        </span>
        <button
          type="button"
          onClick={onClose}
          aria-label="حذف نظرسنجی"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <X size={15} />
        </button>
      </div>

      <input
        value={draft.question}
        onChange={(event) => onChange({ ...draft, question: event.target.value })}
        maxLength={255}
        placeholder="سوال نظرسنجی…"
        className="mb-2 w-full rounded-lg border px-3 py-2 text-[13px] outline-none"
        style={inputStyle}
      />

      <ul className="flex flex-col gap-1.5">
        {draft.options.map((option, index) => (
          // گزینه‌ها هنوز شناسه‌ی سرور ندارند؛ کلید همان جایگاهشان است
          <li key={index} className="flex items-center gap-1.5">
            <input
              value={option}
              onChange={(event) =>
                onChange({
                  ...draft,
                  options: draft.options.map((current, i) =>
                    i === index ? event.target.value : current,
                  ),
                })
              }
              maxLength={120}
              placeholder={`گزینه ${index + 1}`}
              className="flex-1 rounded-lg border px-3 py-1.5 text-[12.5px] outline-none"
              style={inputStyle}
            />
            {draft.options.length > MIN_OPTIONS && (
              <button
                type="button"
                onClick={() =>
                  onChange({ ...draft, options: draft.options.filter((_, i) => i !== index) })
                }
                aria-label={`حذف گزینه ${index + 1}`}
                style={{ color: 'var(--text-tertiary)' }}
              >
                <Trash2 size={14} />
              </button>
            )}
          </li>
        ))}
      </ul>

      <div className="mt-2 flex items-center justify-between">
        {draft.options.length < MAX_OPTIONS ? (
          <button
            type="button"
            onClick={() => onChange({ ...draft, options: [...draft.options, ''] })}
            className="flex items-center gap-1 text-[12px]"
            style={{ color: 'var(--color-brand-500)' }}
          >
            <Plus size={13} />
            افزودن گزینه
          </button>
        ) : (
          <span />
        )}

        <button
          type="button"
          onClick={() => setShowSettings((open) => !open)}
          aria-expanded={showSettings}
          className="flex items-center gap-1 text-[12px]"
          style={{ color: 'var(--text-tertiary)' }}
        >
          <ChevronDown
            size={13}
            className={showSettings ? 'rotate-180 transition-transform' : 'transition-transform'}
          />
          تنظیمات رأی‌گیری
        </button>
      </div>

      {showSettings && (
        <div
          className="mt-2 flex flex-col gap-2.5 border-t pt-2.5"
          style={{ borderColor: 'var(--border-subtle)' }}
        >
          <label
            className="flex flex-col gap-1 text-[11.5px]"
            style={{ color: 'var(--text-secondary)' }}
          >
            حق رأی با
            <select
              value={draft.voterScope}
              onChange={(event) =>
                onChange({ ...draft, voterScope: event.target.value as PollDraft['voterScope'] })
              }
              className="rounded-lg border px-2.5 py-1.5 text-[12.5px] outline-none"
              style={inputStyle}
            >
              {SCOPES.map((scope) => (
                <option key={scope.value} value={scope.value}>
                  {scope.label}
                </option>
              ))}
            </select>
          </label>

          <label
            className="flex flex-col gap-1 text-[11.5px]"
            style={{ color: 'var(--text-secondary)' }}
          >
            شمارش رأی
            <select
              value={draft.weightMode}
              onChange={(event) =>
                onChange({ ...draft, weightMode: event.target.value as PollDraft['weightMode'] })
              }
              className="rounded-lg border px-2.5 py-1.5 text-[12.5px] outline-none"
              style={inputStyle}
            >
              {WEIGHTS.map((weight) => (
                <option key={weight.value} value={weight.value}>
                  {weight.label} — {weight.hint}
                </option>
              ))}
            </select>
          </label>

          <div className="flex gap-2">
            <label
              className="flex flex-1 flex-col gap-1 text-[11.5px]"
              style={{ color: 'var(--text-secondary)' }}
            >
              حد نصاب مشارکت (٪)
              <input
                type="number"
                min={1}
                max={100}
                value={draft.quorumPercent}
                onChange={(event) => onChange({ ...draft, quorumPercent: event.target.value })}
                placeholder="بدون حد نصاب"
                className="rounded-lg border px-2.5 py-1.5 text-[12.5px] outline-none"
                style={inputStyle}
              />
            </label>

            <label
              className="flex flex-1 flex-col gap-1 text-[11.5px]"
              style={{ color: 'var(--text-secondary)' }}
            >
              مهلت رأی‌گیری
              <input
                type="datetime-local"
                value={draft.closesAt}
                onChange={(event) => onChange({ ...draft, closesAt: event.target.value })}
                className="rounded-lg border px-2.5 py-1.5 text-[12.5px] outline-none"
                style={inputStyle}
              />
            </label>
          </div>

          <label
            className="flex items-center gap-2 text-[11.5px]"
            style={{ color: 'var(--text-secondary)' }}
          >
            <input
              type="checkbox"
              checked={!draft.allowChange}
              onChange={(event) => onChange({ ...draft, allowChange: !event.target.checked })}
            />
            رأی پس از ثبت قابل تغییر نباشد
          </label>
        </div>
      )}
    </div>
  )
}
