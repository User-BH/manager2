import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  CANCEL_DISTANCE,
  HoldRecorder,
  MIN_RECORD_MS,
} from '@/features/messaging/messenger/HoldRecorder'
import { Composer } from '@/features/messaging/messenger/Composer'
import { MessageBubble } from '@/features/messaging/messenger/MessageBubble'
import type { BubbleHandlers } from '@/features/messaging/messenger/MessageBubble'

/**
 * فایل و رسانه در رابط — مرحلهٔ ۱۰ · فاز ۱۲ (بندهای ۴۳ تا ۴۶ ِ فایلِ معماری).
 *
 * «نگه دار، ضبط کن، رها کن، فرستاده شد» برای صدا و ویدیو، با لغو با کشیدن، رها کردنِ زودهنگام و کیبورد؛ سقف و نوعِ
 * پیوست از سرور پیش از آپلود؛ و پیامِ ویدیویی که پخش‌کننده‌ی خودش را دارد. `MediaRecorder` و دوربین ساختگی‌اند — موضوع
 * ترتیبِ رویدادهاست، نه رمزگذاریِ مرورگر.
 */

class FakeRecorder {
  static isTypeSupported = () => true
  state: 'inactive' | 'recording' = 'inactive'
  mimeType: string
  ondataavailable: ((event: { data: Blob }) => void) | null = null
  onstop: (() => void) | null = null

  constructor(_stream: unknown, options: { mimeType?: string } = {}) {
    this.mimeType = options.mimeType ?? 'audio/webm'
  }

  start() {
    this.state = 'recording'
  }

  stop() {
    this.state = 'inactive'
    this.ondataavailable?.({ data: new Blob(['ضبط']) })
    this.onstop?.()
  }
}

const getUserMedia = vi.fn()
let now = 1_000_000

beforeEach(() => {
  vi.stubGlobal('MediaRecorder', FakeRecorder)
  getUserMedia.mockReset()
  getUserMedia.mockResolvedValue({ getTracks: () => [{ stop: vi.fn() }] })
  Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia }, configurable: true })
  vi.spyOn(Date, 'now').mockImplementation(() => now)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

async function press(button: HTMLElement, x = 100) {
  fireEvent.pointerDown(button, { clientX: x, pointerId: 1 })
  await waitFor(() => expect(button).toHaveAttribute('aria-pressed', 'true'))
}

describe('نگه دار، ضبط کن، رها کن', () => {
  it('رها کردن می‌فرستد', async () => {
    const onRecorded = vi.fn()
    render(<HoldRecorder mode="voice" onRecorded={onRecorded} onError={vi.fn()} />)
    const button = screen.getByRole('button', { name: 'برای ضبطِ پیامِ صوتی نگه دارید' })

    await press(button)
    now += MIN_RECORD_MS + 500
    act(() => {
      fireEvent.pointerUp(button, { clientX: 100, pointerId: 1 })
    })

    expect(onRecorded).toHaveBeenCalledTimes(1)
    expect((onRecorded.mock.calls[0][0] as File).name).toMatch(/^voice\./)
  })

  it('کشیدن به کنار لغو است و چیزی نمی‌فرستد', async () => {
    const onRecorded = vi.fn()
    render(<HoldRecorder mode="voice" onRecorded={onRecorded} onError={vi.fn()} />)
    const button = screen.getByRole('button', { name: 'برای ضبطِ پیامِ صوتی نگه دارید' })

    await press(button)
    now += 2000
    fireEvent.pointerMove(button, { clientX: 100 + CANCEL_DISTANCE + 20, pointerId: 1 })
    expect(await screen.findByText('رها کنید تا لغو شود')).toBeInTheDocument()
    act(() => {
      fireEvent.pointerUp(button, { clientX: 100 + CANCEL_DISTANCE + 20, pointerId: 1 })
    })

    expect(onRecorded).not.toHaveBeenCalled()
  })

  it('رها کردنِ زودهنگام فقط راهنما می‌گوید', async () => {
    const onRecorded = vi.fn()
    const onError = vi.fn()
    render(<HoldRecorder mode="voice" onRecorded={onRecorded} onError={onError} />)
    const button = screen.getByRole('button', { name: 'برای ضبطِ پیامِ صوتی نگه دارید' })

    await press(button)
    act(() => {
      fireEvent.pointerUp(button, { clientX: 100, pointerId: 1 })
    })

    expect(onRecorded).not.toHaveBeenCalled()
    expect(onError).toHaveBeenCalledWith('برای ضبطِ پیامِ صوتی دکمه را نگه دارید.')
  })

  it('با کیبورد: نگه داشتنِ Space می‌فرستد، Escape لغو می‌کند', async () => {
    const onRecorded = vi.fn()
    render(<HoldRecorder mode="voice" onRecorded={onRecorded} onError={vi.fn()} />)
    const button = screen.getByRole('button', { name: 'برای ضبطِ پیامِ صوتی نگه دارید' })

    fireEvent.keyDown(button, { key: ' ' })
    await waitFor(() => expect(button).toHaveAttribute('aria-pressed', 'true'))
    fireEvent.keyDown(button, { key: 'Escape' })
    expect(onRecorded).not.toHaveBeenCalled()

    fireEvent.keyDown(button, { key: ' ' })
    await waitFor(() => expect(button).toHaveAttribute('aria-pressed', 'true'))
    now += 3000
    act(() => {
      fireEvent.keyUp(button, { key: ' ' })
    })
    expect(onRecorded).toHaveBeenCalledTimes(1)
  })

  it('پیامِ ویدیویی دوربین می‌خواهد و فایلِ ویدیویی می‌سازد', async () => {
    const onRecorded = vi.fn()
    render(<HoldRecorder mode="video" onRecorded={onRecorded} onError={vi.fn()} />)
    const button = screen.getByRole('button', { name: 'برای ضبطِ پیامِ ویدیویی نگه دارید' })

    await press(button)
    expect(getUserMedia).toHaveBeenCalledWith(expect.objectContaining({ video: expect.anything() }))
    now += 2000
    act(() => {
      fireEvent.pointerUp(button, { clientX: 100, pointerId: 1 })
    })

    expect((onRecorded.mock.calls[0][0] as File).name).toMatch(/^video-message\./)
  })
})

describe('کادرِ نوشتن', () => {
  const props = {
    isAdmin: false,
    target: null,
    onCancelTarget: vi.fn(),
    onEdit: vi.fn(() => Promise.resolve()),
  }
  const uploads = {
    limitsKb: { image: 1, file: 100, audio: 100, video: 100, voice: 1024, video_message: 2048 },
    extensions: ['png', 'pdf', 'mp4'],
  }

  it('سقف و نوعِ سرور را پیش از آپلود می‌گوید', () => {
    const onNotice = vi.fn()
    const { container } = render(
      <Composer {...props} uploads={uploads} onSend={vi.fn()} onNotice={onNotice} />,
    )
    const input = container.querySelector('input[type="file"]') as HTMLInputElement
    expect(input.accept).toBe('.png,.pdf,.mp4')

    fireEvent.change(input, { target: { files: [new File(['x'.repeat(4096)], 'big.png')] } })
    expect(onNotice).toHaveBeenLastCalledWith(expect.stringContaining('سقفِ'))

    fireEvent.change(input, { target: { files: [new File(['<html>'], 'page.html')] } })
    expect(onNotice).toHaveBeenLastCalledWith('این نوع فایل پذیرفته نیست.')
  })

  it('پیامِ ویدیویی همان لحظه‌ی رها کردن فرستاده می‌شود', async () => {
    const onSend = vi.fn(() => Promise.resolve())
    render(<Composer {...props} uploads={uploads} onSend={onSend} onNotice={vi.fn()} />)
    // ۱۲/۷: پیامِ ویدیویی زیرِ «بیشتر» است تا ردیفِ نوشتن یک ردیف بماند
    fireEvent.click(screen.getByRole('button', { name: /^بیشتر/ }))
    const button = screen.getByRole('button', { name: 'برای ضبطِ پیامِ ویدیویی نگه دارید' })

    await press(button)
    now += 2000
    act(() => {
      fireEvent.pointerUp(button, { clientX: 100, pointerId: 1 })
    })

    await waitFor(() =>
      expect(onSend).toHaveBeenCalledWith(
        expect.objectContaining({ videoMessage: true, voice: false, body: '' }),
      ),
    )
  })
})

describe('حبابِ پیام', () => {
  it('پیامِ ویدیویی و ویدیو پخش‌کننده دارند', () => {
    const handlers = new Proxy({}, { get: () => vi.fn() }) as BubbleHandlers
    const base = {
      body: '',
      authorName: 'سارا',
      unitLabel: '',
      isMine: false,
      isHidden: false,
      sentAt: '',
    }
    render(
      <>
        <MessageBubble
          message={{
            ...base,
            id: 1,
            attachment: { name: 'note.webm', kind: 'video_message', url: '/a/1' },
          }}
          isAdmin={false}
          isPinned={false}
          menuOpen={false}
          onToggleMenu={vi.fn()}
          handlers={handlers}
        />
        <MessageBubble
          message={{ ...base, id: 2, attachment: { name: 'clip.mp4', kind: 'video', url: '/a/2' } }}
          isAdmin={false}
          isPinned={false}
          menuOpen={false}
          onToggleMenu={vi.fn()}
          handlers={handlers}
        />
      </>,
    )

    expect(screen.getByLabelText('پیامِ ویدیویی').tagName).toBe('VIDEO')
    expect(screen.getByLabelText('clip.mp4').tagName).toBe('VIDEO')
  })
})
