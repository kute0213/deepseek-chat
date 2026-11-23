import { create } from 'zustand'
import { requestJson } from './http.js'

/** 站点公开信息，登录页与侧边栏共用 */
export type SiteInfo = {
  siteName: string
  allowRegister: boolean
  announcement: string
  maxUploadMb: number
}

type SiteState = { info: SiteInfo | null }

export const useSite = create<SiteState>(() => ({ info: null }))

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
