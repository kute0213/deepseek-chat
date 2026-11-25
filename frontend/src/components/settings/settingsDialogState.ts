import { create } from 'zustand'
import { useAuth } from '../../services/auth.js'
import { showToast } from '../base/toastState.js'

/** 设置弹窗的分组：通用设置 / 账号 / 数据管理 / 用户协议 */
export type SettingsSection = 'general' | 'account' | 'data' | 'terms'

/** 设置弹窗：侧边栏底部、登录入口与 401 提示都可以打开；section 为打开时选中的分组 */
export const useSettingsDialog = create(() => ({
  open: false,
  section: 'general' as SettingsSection,
}))

export const openSettings = (section: SettingsSection) =>
  useSettingsDialog.setState({ open: true, section })

export const closeSettings = () => useSettingsDialog.setState({ open: false })

/**
 * 需要账号的操作统一走这里：未登录时提示并打开设置里的登录 / 注册。
 * 已登录但没有 API Key 时打开数据管理，返回是否可以直接继续。
 */
export const ensureApiKey = () => {
  const user = useAuth.getState().user
  if (!user) {
    openSettings('account')
    showToast({ key: 'authLoginRequired' })
    return false
  }
  if (user.hasApiKey) {
    return true
  }
  openSettings('data')
  showToast({ key: 'apiKeyRequired' })
  return false
}
