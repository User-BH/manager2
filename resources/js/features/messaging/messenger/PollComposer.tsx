import { Plus, Trash2, X } from 'lucide-react'

export interface PollDraft {
  question: string
  options: string[]
}

export const EMPTY_POLL: PollDraft = { question: '', options: ['', ''] }

/** حداقل/حداکثرِ گزینه‌ها — عمداً همان قیدِ `StoreMessageRequest`. */
const MIN_OPTIONS = 2
const MAX_OPTIONS = 10

/**
 * ساختِ نظرسنجی توسط مدیر (R23b).
 *
 * فرمِ جدا ندارد و بخشی از همان فرمِ ارسالِ پیام است، چون نظرسنجی هم یک
 * **پیام** است: مخاطبش را همان انتخابگرِ گیرنده (R23a) تعیین می‌کند و
 * مدیر می‌تواند نظرسنجی را فقط برای چند واحد بفرستد.
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

      {draft.options.length < MAX_OPTIONS && (
        <button
          type="button"
          onClick={() => onChange({ ...draft, options: [...draft.options, ''] })}
          className="mt-2 flex items-center gap-1 text-[12px]"
          style={{ color: 'var(--color-brand-500)' }}
        >
          <Plus size={13} />
          افزودن گزینه
        </button>
      )}
    </div>
  )
}
