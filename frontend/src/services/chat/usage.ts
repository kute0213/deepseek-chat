import type { MessageUsage } from '../api/types.js'

/**
 * DeepSeek「空闲时段」参考单价，单位：元 / 百万 tokens。
 * 仅用于界面估算，实际计费以官方账单为准。
 */
export const USAGE_PRICE = {
  /** 输入 · 缓存命中 */
  cacheHit: 0.02,
  /** 输入 · 缓存未命中 */
  cacheMiss: 1,
  /** 输出 */
  output: 4,
} as const

/** 上下文窗口（tokens），用于估算占用比例 */
export const CONTEXT_WINDOW = 128_000

export type UsageCost = {
  cacheHit: number
  cacheMiss: number
  output: number
  total: number
}

export type UsageBreakdown = {
  /** 输入 · 缓存命中 */
  cacheHit: number
  /** 输入 · 缓存未命中（含缓存写入） */
  cacheMiss: number
  /** 输入合计 */
  input: number
  output: number
  total: number
  cost: UsageCost
  /** 输入占上下文窗口的比例（0-1），用于「上下文已占用」 */
  contextRatio: number
}

const costOf = (tokens: number, price: number) => (tokens * price) / 1_000_000

export const makeUsage = (
  cacheHit: number,
  cacheMiss: number,
  output: number
): UsageBreakdown => {
  const input = cacheHit + cacheMiss
  const cacheHitCost = costOf(cacheHit, USAGE_PRICE.cacheHit)
  const cacheMissCost = costOf(cacheMiss, USAGE_PRICE.cacheMiss)
  const outputCost = costOf(output, USAGE_PRICE.output)
  return {
    cacheHit,
    cacheMiss,
    input,
    output,
    total: input + output,
    cost: {
      cacheHit: cacheHitCost,
      cacheMiss: cacheMissCost,
      output: outputCost,
      total: cacheHitCost + cacheMissCost + outputCost,
    },
    contextRatio: CONTEXT_WINDOW > 0 ? input / CONTEXT_WINDOW : 0,
  }
}

/** 把一条回答的 usage 拆成缓存命中 / 未命中 / 输出三段；缺失时返回 null */
export const breakdownUsage = (usage?: MessageUsage | null): UsageBreakdown | null => {
  if (!usage) {
    return null
  }
  return makeUsage(
    usage.cache_read_input_tokens ?? 0,
    (usage.input_tokens ?? 0) + (usage.cache_creation_input_tokens ?? 0),
    usage.output_tokens ?? 0
  )
}

/** 累加多轮用量（逐项求和后重新计算费用，避免浮点累加误差） */
export const addUsage = (left: UsageBreakdown, right: UsageBreakdown): UsageBreakdown =>
  makeUsage(
    left.cacheHit + right.cacheHit,
    left.cacheMiss + right.cacheMiss,
    left.output + right.output
  )

/** 空用量，作为累加初值 */
export const emptyUsage = (): UsageBreakdown => makeUsage(0, 0, 0)

/** 千分位 Token 数 */
export const formatTokens = (value: number) => Math.round(value).toLocaleString()

/** 金额：最多 8 位小数并去掉末尾的 0 */
export const formatCost = (value: number) => {
  if (!Number.isFinite(value) || value === 0) {
    return '0'
  }
  return value
    .toFixed(8)
    .replace(/0+$/, '')
    .replace(/\.$/, '')
}

/** 每百万 tokens 的单价文案（0.02 / 1 / 4） */
export const formatPrice = (value: number) => String(value)

/** 上下文占用百分比，向上取整且最小 1% */
export const formatContextPercent = (ratio: number) =>
  Math.max(1, Math.round(ratio * 100))
