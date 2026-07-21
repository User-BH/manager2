import {
  ShieldCheck,
  Wallet,
  Users2,
  Wrench,
  Bell,
  BarChart3,
  type LucideIcon,
} from 'lucide-react'
import { featureImages, testimonialAvatars } from './images'

export interface Feature {
  icon: LucideIcon
  title: string
  description: string
  image: string
}

export const features: Feature[] = [
  {
    icon: ShieldCheck,
    title: 'امنیت و کنترل دسترسی',
    description: 'مدیریت ساکنین، مهمانان و تردد با سطوح دسترسی مجزا برای هر نقش در مجتمع.',
    image: featureImages.security,
  },
  {
    icon: Wallet,
    title: 'مدیریت مالی شفاف',
    description: 'ثبت قبوض، شارژ ماهانه، تخفیف‌ها و گزارش کامل هزینه و درآمد در یک نگاه.',
    image: featureImages.payments,
  },
  {
    icon: Users2,
    title: 'ارتباط با ساکنین',
    description: 'اطلاعیه‌ها، پیام‌رسان داخلی و معرفی ساکنین خوش‌حساب برای فضای محله‌ای بهتر.',
    image: featureImages.community,
  },
  {
    icon: Wrench,
    title: 'پیگیری تعمیر و نگهداری',
    description: 'ثبت درخواست‌های تعمیرات و پیگیری روند انجام آن‌ها توسط مدیران مجتمع.',
    image: featureImages.maintenance,
  },
]

export interface Stat {
  value: string
  label: string
}

export const stats: Stat[] = [
  { value: '+۲۵۰', label: 'مجتمع مسکونی فعال' },
  { value: '+۴۰هزار', label: 'واحد مدیریت‌شده' },
  { value: '۹۸٪', label: 'رضایت مدیران' },
  { value: '۲۴/۷', label: 'دسترسی به پنل' },
]

export interface Testimonial {
  name: string
  role: string
  quote: string
  avatar: string
}

export const testimonials: Testimonial[] = [
  {
    name: 'مهندس رضایی',
    role: 'مدیر مجتمع آرمان',
    quote: 'پیگیری شارژها و هزینه‌ها از وقتی این پنل رو گرفتیم چند برابر شفاف‌تر شده.',
    avatar: testimonialAvatars[0],
  },
  {
    name: 'خانم احمدی',
    role: 'مدیر مجتمع نگین',
    quote: 'ساکنین خودشون وضعیت پرداختی‌هاشون رو می‌بینن و دیگه تماس‌های تکراری نداریم.',
    avatar: testimonialAvatars[1],
  },
  {
    name: 'آقای کریمی',
    role: 'مدیر مجتمع پردیس',
    quote: 'بخش پیام‌رسان داخلی ارتباط با ساکنین رو خیلی ساده‌تر کرده.',
    avatar: testimonialAvatars[2],
  },
]

export const heroHighlights: { icon: LucideIcon; label: string }[] = [
  { icon: Bell, label: 'اطلاع‌رسانی آنی' },
  { icon: BarChart3, label: 'گزارش‌های دقیق' },
  { icon: ShieldCheck, label: 'امنیت بالا' },
]
