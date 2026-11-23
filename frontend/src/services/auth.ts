import { create } from 'zustand'
import { requestJson, setCsrfToken, setUnauthorizedHandler } from './http.js'
import { ensureIdPool } from './ids.js'
import { resetChat } from './chat/store.js'

export type AuthUser = {
  id: string
  username: string
  role: 'user' | 'admin'
  hasApiKey: boolean
  apiKeyMask: string | null
}

type AuthState = {
  /** null 表示未登录 */
  user: AuthUser | null
  /** 首次查询登录态完成前不渲染主界面 */
  ready: boolean
}

export const useAuth = create<AuthState>(() => ({ user: null, ready: false }))

type UserPayload = AuthUser & { csrf: string }

const applyUser = (user: UserPayload) => {
  setCsrfToken(user.csrf)
  const { csrf: _csrf, ...rest } = user
  useAuth.setState({ user: rest })
  void ensureIdPool()
}

setUnauthorizedHandler(() => {
  if (useAuth.getState().user === null) {
    return
  }
  setCsrfToken(null)
  useAuth.setState({ user: null })
  resetChat()
})

/** 页面加载时查询登录态；未登录是正常状态，不做提示 */
export const initAuth = async (): Promise<void> => {
  try {
    const { user } = await requestJson<{ user: UserPayload }>('/api/auth/me', 'GET')
    applyUser(user)
  } catch {
    useAuth.setState({ user: null })
  } finally {
    useAuth.setState({ ready: true })
  }
}

export const login = async (
  username: string,
  password: string,
  captchaId: string,
  captchaCode: string
): Promise<void> => {
  const { user } = await requestJson<{ user: UserPayload }>('/api/auth/login', 'POST', {
    username,
    password,
    captchaId,
    captchaCode,
  })
  resetChat(true)
  applyUser(user)
}

export const register = async (
  username: string,
  password: string,
  captchaId: string,
  captchaCode: string
): Promise<void> => {
  const { user } = await requestJson<{ user: UserPayload }>('/api/auth/register', 'POST', {
    username,
    password,
    captchaId,
    captchaCode,
  })
  resetChat(true)
  applyUser(user)
}

export const logout = async (): Promise<void> => {
  try {
    await requestJson<{ ok: boolean }>('/api/auth/logout', 'POST')
  } finally {
    setCsrfToken(null)
    useAuth.setState({ user: null })
    resetChat()
  }
}

/** 保存明文 API Key；服务端先校验有效性再入库 */
export const saveApiKey = async (apiKey: string): Promise<string> => {
  const result = await requestJson<{ apiKeyMask: string; hasApiKey: boolean }>(
    '/api/account/api-key',
    'POST',
    { apiKey }
  )
  useAuth.setState((state) =>
    state.user ? { user: { ...state.user, apiKeyMask: result.apiKeyMask, hasApiKey: true } } : state
  )
  return result.apiKeyMask
}

export const deleteApiKey = async (): Promise<void> => {
  await requestJson<{ ok: boolean }>('/api/account/api-key', 'DELETE')
  useAuth.setState((state) =>
    state.user ? { user: { ...state.user, apiKeyMask: null, hasApiKey: false } } : state
  )
}

export const changePassword = async (
  currentPassword: string,
  newPassword: string
): Promise<void> => {
  await requestJson<{ ok: boolean }>('/api/account/password', 'POST', {
    currentPassword,
    newPassword,
  })
}

/** 修改用户名；服务端校验格式与唯一性，成功后同步本地用户信息 */
export const renameUsername = async (username: string): Promise<string> => {
  const result = await requestJson<{ ok: boolean; username: string }>(
    '/api/account/username',
    'POST',
    { username }
  )
  useAuth.setState((state) =>
    state.user ? { user: { ...state.user, username: result.username } } : state
  )
  return result.username
}
