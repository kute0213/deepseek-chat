import { create } from 'zustand'
import { requestJson } from './http.js'

/** 站点公开信息，登录页与侧边栏共用 */
export type SiteInfo = {
  siteName: string
  allowRegister: boolean
  announcement: string
  maxUploadMb: number
  /** Token 单价（元 / 百万 tokens）与上下文窗口，由管理后台维护 */
  priceCacheHit?: number
  priceCacheMiss?: number
  priceOutput?: number
  maxContext?: number
}

type SiteState = { info: SiteInfo | null }

declare global {
  interface Window {
    /** 由 PHP 首页注入的公开站点信息，用于首屏渲染 */
    __SITE__?: SiteInfo
  }
}

const embedded = typeof window === 'undefined' ? null : window.__SITE__
const initialInfo = embedded && typeof embedded.siteName === 'string' ? embedded : null

export const useSite = create<SiteState>(() => ({ info: initialInfo }))

let loading: Promise<void> | null = null

/** 失败时保留默认值，不阻塞页面 */
export const loadSite = (): Promise<void> => {
  loading ??= requestJson<SiteInfo>('/api/site', 'GET')
    .then((info) => useSite.setState({ info }))
    .catch(() => {})
    .finally(() => {
      loading = null
    })
  return loading
}
