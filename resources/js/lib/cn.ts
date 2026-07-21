import { clsx, type ClassValue } from 'clsx'

/** ادغام شرطی کلاس‌های Tailwind/CSS */
export function cn(...inputs: ClassValue[]) {
  return clsx(inputs)
}
