import type { ReactNode } from 'react'
import { Building2, ChevronDown, Info, LifeBuoy, UserPlus } from 'lucide-react'

/**
 * بخشِ «راهنما» ِ داشبوردِ ثبت‌نام‌کننده‌ی اولیه (مرحلهٔ ۹ · فاز ۳ · بندِ ۳۰ ِ «بررسی جدید ۲»).
 *
 * هدف این است که کاربر برای فهمیدنِ مسیرِ خودش مجبور نباشد از داشبورد بیرون برود.
 *
 * ⚠️ هر جمله فقط چیزی را می‌گوید که **همین حالا** ساخته شده؛ قابلیتِ آینده وعده
 * داده نمی‌شود. مسیرِ مدیر شدن عمداً «پرداخت» می‌گوید و روشش را نام نمی‌برد:
 * تصمیمِ کارفرما (فقط پرداختِ آنلاینِ تأییدشده) در ۹/۴ و ۹/۵ پیاده می‌شود.
 * ویدیوی نهایی هم طبقِ همان تصمیم آخرِ پروژه ساخته می‌شود؛ اینجا پیوندِ صفحه‌ی
 * دموی موجود است (`DemoVideo` در داشبورد).
 *
 * `<details>` ِ بومی: کیبورد، صفحه‌خوان و حالتِ باز/بسته بی هیچ اسکریپتی.
 *
 * ⚠️ فقط آیکون‌هایی که پروژه از قبل دارد: هر آیکونِ تازه به `vendor-icons` می‌رود
 * که در **همه‌ی** صفحه‌ها بار می‌شود — `BookOpen` و `UserPen` همین ‎۱۵۶ بایت را
 * به صفحه‌ی پشتیبانی افزودند و از بودجه‌اش گذشت (اندازه‌گیری‌شده).
 */
export function RegistrantGuide() {
  return (
    <section aria-labelledby="registrant-guide" className="flex flex-col gap-3">
      <h2
        id="registrant-guide"
        className="flex items-center gap-2 text-sm font-bold"
        style={{ color: 'var(--text-primary)' }}
      >
        <Info size={16} style={{ color: 'var(--color-brand-500)' }} aria-hidden="true" />
        راهنما
      </h2>

      <GuideItem icon={<Info size={16} />} title="ساکنا چیست؟">
        <p>
          ساکنا سامانه‌ی مدیریتِ ساختمان و مجتمع است: شارژ و قبض، پرداختِ آنلاین، اطلاعیه‌ها،
          پیام‌رسان و درخواست‌های ساکنان، همه در یک جا.
        </p>
      </GuideItem>

      <GuideItem icon={<UserPlus size={16} />} title="چطور عضوِ مجتمعم شوم؟">
        <ol className="list-[persian] space-y-1.5 pr-5">
          <li>
            شماره‌ی موبایلِ مدیرِ ساختمان را در بخشِ درخواستِ همین صفحه وارد کنید، یا شماره‌ی خودتان
            را به مدیر بدهید.
          </li>
          <li>
            مدیر درخواستتان را تأیید می‌کند یا برایتان دعوت می‌فرستد؛ دعوت همین‌جا نمایش داده
            می‌شود.
          </li>
          <li>پس از تأیید یا پذیرشِ دعوت، داشبوردِ واحدتان باز می‌شود.</li>
        </ol>
        <p>مالک یا مستأجر برای عضویت اشتراکی نمی‌خرد.</p>
      </GuideItem>

      <GuideItem icon={<Building2 size={16} />} title="چطور مدیرِ مجتمع شوم؟">
        <ol className="list-[persian] space-y-1.5 pr-5">
          <li>
            از بخشِ{' '}
            <a href="/account" className="font-semibold underline">
              حساب و اشتراک
            </a>{' '}
            اشتراکِ مناسبِ مجتمعتان را انتخاب کنید.
          </li>
          <li>پرداخت را انجام دهید.</li>
          <li>پس از پرداختِ موفق، مجتمعتان ساخته می‌شود و مدیرِ آن می‌شوید.</li>
        </ol>
      </GuideItem>

      <GuideItem icon={<LifeBuoy size={16} />} title="پرسش دارید؟">
        <p>
          پاسخِ پرسش‌های پرتکرار و راهِ تماس با پشتیبانی در{' '}
          <a href="/support" className="font-semibold underline">
            صفحه‌ی پشتیبانی
          </a>{' '}
          است.
        </p>
      </GuideItem>
    </section>
  )
}

function GuideItem({
  icon,
  title,
  children,
}: {
  icon: ReactNode
  title: string
  children: ReactNode
}) {
  return (
    <details
      className="group rounded-2xl border"
      style={{ borderColor: 'var(--border-default)', backgroundColor: 'var(--surface-base)' }}
    >
      <summary
        className="flex cursor-pointer list-none items-center gap-2 p-4 text-[13.5px] font-semibold [&::-webkit-details-marker]:hidden"
        style={{ color: 'var(--text-primary)' }}
      >
        <span style={{ color: 'var(--color-brand-500)' }} aria-hidden="true">
          {icon}
        </span>
        <span className="flex-1">{title}</span>
        <ChevronDown
          size={16}
          aria-hidden="true"
          className="transition-transform group-open:rotate-180"
          style={{ color: 'var(--text-tertiary)' }}
        />
      </summary>
      <div
        className="flex flex-col gap-2 px-4 pb-4 text-[13px] leading-7"
        style={{ color: 'var(--text-secondary)' }}
      >
        {children}
      </div>
    </details>
  )
}
