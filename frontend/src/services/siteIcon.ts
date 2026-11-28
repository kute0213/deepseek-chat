/**
 * AI 搜索结果里的站点图标地址可能是相对路径（如 /api/files/{uuid}），
 * 直接交给 <img> 会请求到本站并 404；统一转给 /api/site-icon，
 * 由服务端解析、下载并缓存到 uploads。
 */
export const siteIconSrc = (
  raw: string | null | undefined,
  pageUrl?: string | null
): string | null => {
  const value = raw?.trim()
  if (!value) {
    return null
  }
  if (value.startsWith('/api/site-icon')) {
    return value
  }
  if (value.startsWith('data:image/')) {
    return value
  }
  if (/^https?:\/\//i.test(value) || value.startsWith('//') || value.startsWith('/')) {
    const params = new URLSearchParams({ url: value })
    if (pageUrl) {
      params.set('page', pageUrl)
    }
    return `/api/site-icon?${params.toString()}`
  }
  return null
}
