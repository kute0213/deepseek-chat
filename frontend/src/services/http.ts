import { translate, type TranslationMessage } from '../i18n/index.js'

/** 接口请求失败的分类 */
export type ApiErrorKind =
  /** 非 2xx 响应 */
  | 'http'
  /** 流中返回的错误帧（如等待生成超时），随后连接关闭 */
  | 'stream'
  /** 请求没有到达服务端（断网、跨域被拦截等） */
  | 'network'
  /** 流在 message_stop 之前结束 */
  | 'interrupted'
  /** 响应不符合协议（如非 SSE 响应） */
  | 'protocol'

export class ApiError extends Error {
  readonly localMessage?: TranslationMessage

  constructor(
    readonly kind: ApiErrorKind,
    message: string | TranslationMessage,
    readonly status?: number
  ) {
    super(typeof message === 'string' ? message : translate(message.key, message.params))
    this.name = 'ApiError'
    this.localMessage = typeof message === 'string' ? undefined : message
  }
}

type ErrorBody = { error: { message: string } }

const isErrorBody = (value: unknown): value is ErrorBody =>
  typeof value === 'object' &&
  value !== null &&
  'error' in value &&
  typeof (value as { error?: { message?: unknown } }).error?.message === 'string'

let csrfToken: string | null = null

/** 登录后由 auth 服务写入；所有写请求自动携带 */
export const setCsrfToken = (token: string | null) => {
  csrfToken = token
}

let unauthorizedHandler: (() => void) | null = null

/** 会话失效（401）时由 auth 服务清空登录态 */
export const setUnauthorizedHandler = (handler: () => void) => {
  unauthorizedHandler = handler
}

const readErrorResponse = async (
  response: Response,
  kind: 'http' | 'protocol' = 'http'
): Promise<ApiError> => {
  const text = await response.text().catch(() => '')
  let message = text || response.statusText
  try {
    const body: unknown = JSON.parse(text)
    if (isErrorBody(body)) {
      message = body.error.message
    }
  } catch {
    // 非 JSON 响应体直接展示原文
  }
  return new ApiError(kind, message, response.status)
}

/** 同源请求：自动带 Cookie 与 CSRF 头，非 2xx 统一抛出 ApiError */
export const request = async (url: string, init: RequestInit = {}): Promise<Response> => {
  const method = (init.method ?? 'GET').toUpperCase()
  const headers = new Headers(init.headers)
  if (csrfToken && method !== 'GET' && method !== 'HEAD') {
    headers.set('X-CSRF-Token', csrfToken)
  }
  let response: Response
  try {
    response = await fetch(url, { ...init, headers, credentials: 'same-origin' })
  } catch (error) {
    if (init.signal?.aborted) {
      throw error
    }
    throw new ApiError('network', error instanceof Error ? error.message : String(error))
  }
  if (!response.ok) {
    if (response.status === 401) {
      unauthorizedHandler?.()
    }
    throw await readErrorResponse(response)
  }
  return response
}

/** 发送 JSON 并解析 JSON 响应 */
export const requestJson = async <T>(url: string, method: string, body?: unknown): Promise<T> => {
  const response = await request(url, {
    method,
    ...(body === undefined
      ? {}
      : { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }),
  })
  return (await response.json()) as T
}
