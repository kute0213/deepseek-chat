import type { MessageUsage } from '../api/types.js'

/**
 * Token 计费配置：单价单位「元 / 百万 tokens」，以及上下文窗口大小。
 * 由管理后台维护，前端从 /api/site 读取，仅用于界面估算。
 */
export type UsageConfig = {
  cacheHit: number
  cacheMiss: number
  output: number
  contextWindow: number
}

/** 默认值：DeepSeek 空闲时段价格与 128K 上下文 */
export const DEFAULT_USAGE_CONFIG: UsageConfig = {
  cacheHit: 0.02,
  cacheMiss: 1,
  output: 4,
  contextWindow: 128000,
}

/** 从 /api/site 的公开信息里提取计费配置，缺失或非法时回退默认值 */
export const usageConfigFrom = (
  info?: {
    priceCacheHit?: number
    priceCacheMiss?: number
    priceOutput?: number
    maxContext?: number
  } | null
): UsageConfig => {
  const price = (value: number | undefined, fallback: number) =>
    typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : fallback
  return {
    cacheHit: price(info?.priceCacheHit, DEFAULT_USAGE_CONFIG.cacheHit),
    cacheMiss: price(info?.priceCacheMiss, DEFAULT_USAGE_CONFIG.cacheMiss),
    output: price(info?.priceOutput, DEFAULT_USAGE_CONFIG.output),
    contextWindow: Math.max(1, Math.round(price(info?.maxContext, DEFAULT_USAGE_CONFIG.contextWindow))),
  }
}

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
  /** 生成该结果时使用的计费配置 */
  config: UsageConfig
}

const costOf = (tokens: number, price: number) => (tokens * price) / 1_000_000

export const makeUsage = (
  cacheHit: number,
  cacheMiss: number,
  output: number,
  config: UsageConfig = DEFAULT_USAGE_CONFIG
): UsageBreakdown => {
  const input = cacheHit + cacheMiss
  const cacheHitCost = costOf(cacheHit, config.cacheHit)
  const cacheMissCost = costOf(cacheMiss, config.cacheMiss)
  const outputCost = costOf(output, config.output)
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
    contextRatio: config.contextWindow > 0 ? input / config.contextWindow : 0,
    config,
  }
}

/** 把一条回答的 usage 拆成缓存命中 / 未命中 / 输出三段；缺失时返回 null */
export const breakdownUsage = (
  usage?: MessageUsage | null,
  config: UsageConfig = DEFAULT_USAGE_CONFIG
): UsageBreakdown | null => {
  if (!usage) {
    return null
  }
  return makeUsage(
    usage.cache_read_input_tokens ?? 0,
    (usage.input_tokens ?? 0) + (usage.cache_creation_input_tokens ?? 0),
    usage.output_tokens ?? 0,
    config
  )
}

/** 累加多轮用量（逐项求和后按配置重新计算费用，避免浮点累加误差） */
export const addUsage = (
  left: UsageBreakdown,
  right: UsageBreakdown,
  config: UsageConfig = left.config
): UsageBreakdown =>
  makeUsage(
    left.cacheHit + right.cacheHit,
    left.cacheMiss + right.cacheMiss,
    left.output + right.output,
    config
  )

/** 空用量，作为累加初值 */
export const emptyUsage = (config: UsageConfig = DEFAULT_USAGE_CONFIG): UsageBreakdown =>
  makeUsage(0, 0, 0, config)

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
