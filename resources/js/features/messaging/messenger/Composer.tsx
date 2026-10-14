import { useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { BarChart3, Loader2, Mic, Paperclip, Pencil, Reply, Send, X } from 'lucide-react'
import { z } from 'zod'
import { alertError } from '@/shared/lib/alert'
import { EmojiPicker } from './EmojiPicker'
import { PollComposer, emptyPoll, type PollDraft } from './PollComposer'
import { VoiceRecorder, isVoiceSupported } from './VoiceRecorder'
import type { ChatMessage } from './types'

/** همان قیدِ سرور؛ اینجا فقط تا کاربر پیش از آپلود بفهمد. */
const MAX_ATTACHMENT_BYTES = 4 * 1024 * 1024
const ACCEPTED_TYPES = '.jpg,.jpeg,.png,.webp,.pdf'

const messageSchema = z.object({ body: z.string().max(1000, 'پیام بیش از حد طولانی است') })
type MessageFormValues = z.infer<typeof messageSchema>

/** آنچه برای ارسال آماده است — «دوباره» عیناً همین را با همان شناسه می‌فرستد. */
export interface OutgoingDraft {
  body: string
  attachment: File | null
  /** پیوست پیامِ صوتی است (فیلدِ `voice`، نه `attachment`). */
  voice: boolean
  poll: PollDraft | null
  replyTo: ChatMessage | null
}

/** پاسخ به یک پیام، یا ویرایشِ پیامِ خود (مرحلهٔ ۶ · فاز ۱۴). */
export type ComposeTarget = { mode: 'reply' | 'edit'; message: ChatMessage } | null

/**
 * نوشتنِ پیام (مرحلهٔ ۶ · فاز ۱۲ و ۱۴).
 *
 * از `ConversationThread` جدا شد تا حالتِ فرم، پیوست، نظرسنجی و ضبط کنارِ
 * حالتِ فهرستِ پیام‌ها انباشته نشود.
 *
 * ⚠️ منتظرِ تحویل می‌ماند تا دکمه در این فاصله خاموش باشد: دوبار-کلیک همان
 * متن را با دو شناسه‌ی جدا دو بار می‌فرستاد (قراردادِ فرم‌ها).
 */
export function Composer({
  isAdmin,
  canPoll = isAdmin,
  target,
  onCancelTarget,
  onSend,
  onEdit,
  onNotice,
}: {
  isAdmin: boolean
  /** نظرسنجی فقط در گروه (۱۰/۱۱؛ بندِ ۶۱) — گفت‌وگوی خصوصی دکمه‌اش را ندارد */
  canPoll?: boolean
  target: ComposeTarget
  onCancelTarget: () => void
  onSend: (draft: OutgoingDraft) => Promise<void>
  onEdit: (message: ChatMessage, body: string) => Promise<void>
  onNotice: (message: string | null) => void
}) {
  const [attachment, setAttachment] = useState<{ file: File; voice: boolean } | null>(null)
  const [pollDraft, setPollDraft] = useState<PollDraft | null>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    setError: setFormError,
    formState: { errors, isSubmitting },
  } = useForm<MessageFormValues>({
    resolver: zodResolver(messageSchema),
    defaultValues: { body: '' },
  })

  const editing = target?.mode === 'edit' ? target.message : null

  // ویرایش: متنِ همان پیام در کادر؛ پاسخ: فقط فوکوس
  useEffect(() => {
    if (editing) setValue('body', editing.body ?? '')
    if (target) textareaRef.current?.focus()
  }, [target, editing, setValue])

  function insertEmoji(emoji: string) {
    const field = textareaRef.current
    if (!field) return
    const start = field.selectionStart ?? field.value.length
    const end = field.selectionEnd ?? start
    setValue('body', field.value.slice(0, start) + emoji + field.value.slice(end), {
      shouldValidate: true,
    })
    requestAnimationFrame(() => {
      field.focus()
      field.setSelectionRange(start + emoji.length, start + emoji.length)
    })
  }

  function pickAttachment(file: File | null, voice = false) {
    if (file && file.size > MAX_ATTACHMENT_BYTES) {
      onNotice('حجم فایل باید کمتر از ۴ مگابایت باشد.')
      return
    }
    onNotice(null)
    setAttachment(file ? { file, voice } : null)
  }

  async function onSubmit(values: MessageFormValues) {
    if (editing) {
      if (!values.body.trim() && !editing.attachment && !editing.poll) {
        setFormError('body', { message: 'متن پیام را وارد کنید' })
        return
      }
      try {
        await onEdit(editing, values.body)
        reset()
      } catch (error) {
        alertError(error, 'ویرایشِ پیام ثبت نشد.')
      }
      return
    }

    const hasPoll = Boolean(pollDraft?.question.trim())

    if (!values.body.trim() && !attachment && !hasPoll) {
      setFormError('body', { message: 'متن پیام را وارد کنید' })
      return
    }
    if (hasPoll && pollDraft!.options.filter((o) => o.text.trim()).length < 2) {
      onNotice('نظرسنجی باید دست‌کم دو گزینه داشته باشد.')
      return
    }

    const draft: OutgoingDraft = {
      body: values.body,
      attachment: attachment?.file ?? null,
      voice: attachment?.voice ?? false,
      poll: hasPoll ? pollDraft : null,
      replyTo: target?.mode === 'reply' ? target.message : null,
    }

    onNotice(null)
    reset()
    setAttachment(null)
    setPollDraft(null)

    await onSend(draft)
  }

  const bodyField = register('body')

  return (
    <div className="flex flex-col">
      {target && (
        <div
          className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-small"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          {editing ? <Pencil size={13} /> : <Reply size={13} />}
          <span className="min-w-0 flex-1 truncate">
            {editing
              ? 'ویرایشِ پیام'
              : `پاسخ به ${target.message.authorName}: ${target.message.body ?? ''}`}
          </span>
          <button
            type="button"
            onClick={() => {
              if (editing) reset()
              onCancelTarget()
            }}
            aria-label={editing ? 'لغوِ ویرایش' : 'لغوِ پاسخ'}
          >
            <X size={14} />
          </button>
        </div>
      )}

      {pollDraft && canPoll && !editing && (
        <PollComposer
          draft={pollDraft}
          onChange={setPollDraft}
          onClose={() => setPollDraft(null)}
        />
      )}

      {attachment && (
        <div
          className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-small"
          style={{ backgroundColor: 'var(--surface-sunken)', color: 'var(--text-secondary)' }}
        >
          {attachment.voice ? <Mic size={13} /> : <Paperclip size={13} />}
          <span className="flex-1 truncate">
            {attachment.voice ? 'پیامِ صوتی آماده‌ی ارسال' : attachment.file.name}
          </span>
          <button type="button" onClick={() => pickAttachment(null)} aria-label="حذف پیوست">
            <X size={14} />
          </button>
        </div>
      )}

      <form
        onSubmit={(event) => void handleSubmit(onSubmit)(event)}
        className="flex items-start gap-2"
      >
        <div className="flex-1">
          <textarea
            rows={1}
            aria-label="متنِ پیام"
            placeholder="پیام خود را بنویسید…"
            className="field w-full resize-none rounded-xl px-3.5 py-3 text-body"
            aria-invalid={Boolean(errors.body) || undefined}
            {...bodyField}
            ref={(element) => {
              bodyField.ref(element)
              textareaRef.current = element
            }}
          />

          <div className="mt-1 flex items-center gap-1">
            <EmojiPicker onPick={insertEmoji} />
            {!editing && (
              <>
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  aria-label="پیوست فایل"
                  className="flex h-9 w-9 items-center justify-center rounded-lg"
                  style={{ color: 'var(--text-tertiary)' }}
                >
                  <Paperclip size={16} />
                </button>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept={ACCEPTED_TYPES}
                  className="hidden"
                  onChange={(event) => {
                    pickAttachment(event.target.files?.[0] ?? null)
                    event.target.value = ''
                  }}
                />
                {isVoiceSupported() && (
                  <VoiceRecorder
                    onRecorded={(file) => pickAttachment(file, true)}
                    onError={onNotice}
                  />
                )}
                {canPoll && (
                  <button
                    type="button"
                    onClick={() => setPollDraft((draft) => (draft ? null : emptyPoll()))}
                    aria-label="ساخت نظرسنجی"
                    aria-pressed={pollDraft !== null}
                    className="flex h-9 w-9 items-center justify-center rounded-lg"
                    style={{ color: pollDraft ? 'var(--color-brand-500)' : 'var(--text-tertiary)' }}
                  >
                    <BarChart3 size={16} />
                  </button>
                )}
              </>
            )}
          </div>
          {errors.body && (
            <p className="mt-1 text-small" style={{ color: 'var(--color-danger)' }}>
              {errors.body.message}
            </p>
          )}
        </div>

        <button
          type="submit"
          disabled={isSubmitting}
          aria-label={editing ? 'ذخیره‌ی ویرایش' : 'ارسال پیام'}
          className="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-xl text-white disabled:opacity-60"
          style={{ backgroundColor: 'var(--color-brand-500)' }}
        >
          {isSubmitting ? <Loader2 size={17} className="animate-spin" /> : <Send size={17} />}
        </button>
      </form>
    </div>
  )
}
