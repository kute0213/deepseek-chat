import { requestJson } from './http.js'

/** 一次领取的雪花 ID 数量；剩余低于阈值时自动补充 */
const BATCH_SIZE = 500
const THRESHOLD = 100

const pool: string[] = []
let refilling: Promise<void> | null = null

const refill = async (): Promise<void> => {
  const { ids } = await requestJson<{ ids: string[] }>('/api/ids', 'POST', { count: BATCH_SIZE })
  pool.push(...ids)
}

/** 登录后调用；失败保留现有池，下次消费时自动重试 */
export const ensureIdPool = async (): Promise<void> => {
  if (pool.length >= THRESHOLD || refilling) {
    return refilling ?? Promise.resolve()
  }
  refilling = refill()
    .catch(() => {})
    .finally(() => {
      refilling = null
    })
  return refilling
}

/** 会话、消息统一使用服务端下发的雪花 ID */
export const nextSnowflakeId = (): string => {
  const id = pool.pop()
  if (id === undefined) {
    void ensureIdPool()
    throw new Error('ID 池已耗尽，请稍后重试')
  }
  if (pool.length < THRESHOLD) {
    void ensureIdPool()
  }
  return id
}
