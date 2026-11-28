import { useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useTranslate } from '../../i18n/index.js'
import {
  formatContextPercent,
  formatCost,
  formatPrice,
  formatTokens,
  type UsageBreakdown,
} from '../../services/chat/usage.js'
import { useAnchoredPosition, useDismiss } from '../base/anchored.js'
import { IconDatabaseOutline16 } from '../base/icons.js'
import styles from './UsagePopover.module.css'

export type UsagePopoverVariant = 'round' | 'session'

/**
 * Token 用量浮层。点击触发元素展开，点外部或 Esc 关闭。
 * round 展示单轮明细，session 展示整个会话的累计用量。
 */
export const UsagePopover = ({
  variant,
  usage,
  context,
  side = 'top',
  align = 'end',
  children,
}: {
  variant: UsagePopoverVariant
  usage: UsageBreakdown
  /** 用于底部「上下文已占用」，缺省时按 usage 自身计算 */
  context?: UsageBreakdown | null
  side?: 'top' | 'bottom'
  align?: 'start' | 'center' | 'end'
  children: (props: { open: boolean; toggle: () => void }) => ReactNode
}) => {
  const t = useTranslate()
  const [open, setOpen] = useState(false)
  const { anchorRef, floatingRef, position } = useAnchoredPosition<HTMLSpanElement, HTMLDivElement>(
    open,
    { side, align, offset: 8 }
  )
  useDismiss(open, [anchorRef, floatingRef], () => setOpen(false))

  const contextUsage = context ?? usage
  const percent = formatContextPercent(contextUsage.contextRatio)
  const groups = [
    {
      key: 'cacheHit',
      title: t('usageInputGroup'),
      sub: t('usageCacheHit'),
      price: usage.config.cacheHit,
      tokens: usage.cacheHit,
      amount: usage.cost.cacheHit,
    },
    {
      key: 'cacheMiss',
      title: t('usageInputGroup'),
      sub: t('usageCacheMiss'),
      price: usage.config.cacheMiss,
      tokens: usage.cacheMiss,
      amount: usage.cost.cacheMiss,
    },
    {
      key: 'output',
      title: t('usageOutputGroup'),
      sub: null,
      price: usage.config.output,
      tokens: usage.output,
      amount: usage.cost.output,
    },
  ]

  return (
    <>
      <span ref={anchorRef} className={styles.anchor}>
        {children({ open, toggle: () => setOpen((value) => !value) })}
      </span>
      {open
        ? createPortal(
            <div ref={floatingRef} className={styles.card} style={position ?? undefined} role="dialog">
              <div className={styles.header}>
                <div className={styles.heading}>
                  <span className={styles.headingIcon}>
                    <IconDatabaseOutline16 />
                  </span>
                  <span className={styles.title}>
                    {t(variant === 'round' ? 'usageRoundTitle' : 'usageSessionTitle')}
                  </span>
                </div>
                <div className={styles.total}>
                  {formatCost(usage.cost.total)} <span>{t('usageYuan')}</span>
                </div>
              </div>
              <div className={styles.note}>{t('usagePricingNote')}</div>
              <div className={styles.groups}>
                {groups.map((group) => (
                  <div key={group.key} className={styles.group}>
                    <div className={styles.groupLabel}>
                      <span>{group.title}</span>
                      {group.sub ? <span className={styles.groupSub}>{group.sub}</span> : null}
                    </div>
                    <div className={styles.rows}>
                      <div className={styles.row}>
                        <span>{t('usagePriceLabel')}</span>
                        <span>
                          {formatPrice(group.price)} {t('usagePerMillion')}
                        </span>
                      </div>
                      <div className={styles.row}>
                        <span>{t('usageTokensLabel')}</span>
                        <span>
                          {formatTokens(group.tokens)} {t('usageTokenUnit')}
                        </span>
                      </div>
                      <div className={styles.row}>
                        <span>{t('usageAmountLabel')}</span>
                        <span>
                          {formatCost(group.amount)} {t('usageYuan')}
                        </span>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
              <div className={styles.footer}>
                <span className={styles.footerDot} aria-hidden />
                {t(variant === 'round' ? 'usageContextRound' : 'usageContextSession', {
                  percent,
                })}
              </div>
            </div>,
            document.body
          )
        : null}
    </>
  )
}
