import type { AnchorHTMLAttributes, ReactNode } from 'react'

/**
 * ناوبریِ بدونِ روتر برای صفحاتِ عمومیِ MPA.
 *
 * خانه/دمو/پشتیبانی هرکدام یک سندِ HTMLِ مستقل‌اند (island) و روتری ندارند؛
 * پس هر رفتن به صفحه‌ی دیگر باید ناوبریِ واقعیِ مرورگر باشد، نه pushStateِ
 * سمت کلاینت. این ماژول همان API‌ای را که این صفحه‌ها از react-router
 * می‌خواستند (`Link`, `useNavigate`, `useSearchParams`) با ناوبریِ واقعی
 * فراهم می‌کند، تا کد کامپوننت‌ها تقریباً دست‌نخورده بماند.
 */

interface LinkProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
  to: string
  children?: ReactNode
}

export function Link({ to, children, ...rest }: LinkProps) {
  return (
    <a href={to} {...rest}>
      {children}
    </a>
  )
}

export function useNavigate() {
  return (to: string | number) => {
    if (typeof to === 'number') window.history.go(to)
    else window.location.assign(to)
  }
}

export function useSearchParams(): [URLSearchParams] {
  return [new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '')]
}
