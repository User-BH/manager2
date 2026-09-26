import type { PageMeta } from '@/shared/ui/Pager'

/** شکلِ پاسخ‌های مدیریتِ بلاگ (مرحلهٔ ۶ · فاز ۱۱). */

export type BlogStatus = 'draft' | 'published'

export interface BlogPostRow {
  id: number
  title: string
  slug: string
  status: BlogStatus
  /** منتشرشده ولی زمانش هنوز نرسیده */
  scheduled: boolean
  category: string | null
  publishedAt: string | null
  updatedAt: string
}

export interface BlogPostsResponse {
  data: BlogPostRow[]
  meta: PageMeta
}

export interface BlogPostDetail {
  id: number
  title: string
  slug: string
  excerpt: string | null
  body: string
  categoryId: number | null
  tags: string[]
  status: BlogStatus
  publishedAt: string | null
  cover: string | null
  metaTitle: string | null
  metaDescription: string | null
}

export interface BlogCategory {
  id: number
  name: string
  slug: string
  description: string | null
  postsCount: number
}

export interface BlogImageUpload {
  file: string
  url: string
  markdown: string
}
