import clsx from 'clsx'
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { getVisibleMessages } from '../../services/chat/branches.js'
import { loadOlderMessages, useChatStore } from '../../services/chat/store.js'
import type { Message, Session } from '../../services/chat/types.js'
import styles from './MessageNavigator.module.css'

/** 右侧圆点导航里的一条记录：一条用户提问。 */
const previewText = (message: Message): string => {
  if (message.role !== 'user') {
    return ''
  }
  const text = message.content.trim()
  if (text) {
    return text
  }
  return message.files.length > 0 ? `[${message.files.length} 个附件]` : '（空消息）'
}

/**
 * 会话右侧的消息导航：圆点对应每条用户提问，悬浮展开预览列表，
 * 点击跳转到对应记录；预览列表滚动到顶部时加载更早的一页消息。
 */
export const MessageNavigator = ({ session }: { session: Session }) => {
  const t = useTranslate()
  const isMobile = useIsMobile()
  const hasMore = useChatStore((state) => state.messagePages[session.id]?.hasMore ?? false)
  const loading = useChatStore((state) => state.messagePages[session.id]?.loading ?? false)
  const items = useMemo(
    () => getVisibleMessages(session).filter((message) => message.role === 'user'),
    [session]
  )
  const [open, setOpen] = useState(false)
  const [activeId, setActiveId] = useState<string | null>(null)
  const [hoveredId, setHoveredId] = useState<string | null>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const closeTimer = useRef<number>()
  const pendingPanelRef = useRef<{ height: number; top: number } | null>(null)

  const openPanel = useCallback(() => {
    window.clearTimeout(closeTimer.current)
    setOpen(true)
  }, [])

  const scheduleClose = useCallback(() => {
    window.clearTimeout(closeTimer.current)
    closeTimer.current = window.setTimeout(() => {
      setOpen(false)
      setHoveredId(null)
    }, 120)
  }, [])

  useEffect(() => () => window.clearTimeout(closeTimer.current), [])

  // 跟随主列表滚动，标记当前停留在的提问
  useEffect(() => {
    const container = document.querySelector<HTMLElement>('[data-message-scroll]')
    if (!container || items.length === 0) {
      return
    }
    let frame = 0
    const update = () => {
      frame = 0
      const top = container.getBoundingClientRect().top
      let current: string | null = null
      for (const item of items) {
        const el = container.querySelector<HTMLElement>(
          `[data-message-id="${CSS.escape(item.id)}"]`
        )
        if (!el) {
          continue
        }
        if (el.getBoundingClientRect().top - top <= 88) {
          current = item.id
        } else {
          break
        }
      }
      setActiveId(current)
    }
    const onScroll = () => {
      if (!frame) {
        frame = requestAnimationFrame(update)
      }
    }
    update()
    container.addEventListener('scroll', onScroll, { passive: true })
    return () => {
      container.removeEventListener('scroll', onScroll)
      cancelAnimationFrame(frame)
    }
  }, [items, session.id])

  // 展开时定位到最近浏览的记录；悬浮某个圆点时定位到对应记录
  useLayoutEffect(() => {
    if (!open) {
      return
    }
    const panel = panelRef.current
    if (!panel) {
      return
    }
    const target = hoveredId
      ? panel.querySelector<HTMLElement>(`[data-nav-id="${CSS.escape(hoveredId)}"]`)
      : null
    if (target) {
      target.scrollIntoView({ block: 'nearest' })
    } else {
      panel.scrollTop = panel.scrollHeight
    }
  }, [open, hoveredId, items.length])

  // 预览列表顶部插入更早记录后保持滚动位置
  useLayoutEffect(() => {
    const panel = panelRef.current
    const pending = pendingPanelRef.current
    if (!panel || !pending) {
      return
    }
    pendingPanelRef.current = null
    const delta = panel.scrollHeight - pending.height
    if (delta > 0) {
      panel.scrollTop = pending.top + delta
    }
  }, [items.length, hasMore])

  // 预览列表滚动到顶部时加载更早的一页，并显示加载状态
  const onPanelScroll = () => {
    const panel = panelRef.current
    if (!panel || !hasMore || loading || panel.scrollTop > 8) {
      return
    }
    pendingPanelRef.current = { height: panel.scrollHeight, top: panel.scrollTop }
    void loadOlderMessages(session.id)
  }

  const jump = (messageId: string) => {
    const target = document.querySelector<HTMLElement>(
      `[data-message-id="${CSS.escape(messageId)}"]`
    )
    target?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    setActiveId(messageId)
    if (isMobile) {
      setOpen(false)
    }
  }

  if (isMobile || items.length === 0) {
    return null
  }

  return (
    <div
      className={styles.root}
      onMouseEnter={openPanel}
      onMouseLeave={scheduleClose}
      aria-label={t('messageNavigator')}
    >
      {open ? (
        <div className={styles.panel} ref={panelRef} onScroll={onPanelScroll}>
          {hasMore ? (
            <div className={styles.panelLoading} role="status" aria-live="polite">
              {loading ? t('messagesLoadingMore') : ''}
            </div>
          ) : null}
          <div className={styles.list}>
            {items.map((item) => (
              <button
                key={item.id}
                type="button"
                data-nav-id={item.id}
                className={clsx(
                  styles.item,
                  activeId === item.id && styles.itemActive,
                  hoveredId === item.id && styles.itemHovered
                )}
                onMouseEnter={() => setHoveredId(item.id)}
                onFocus={() => setHoveredId(item.id)}
                onClick={() => jump(item.id)}
              >
                <span className={styles.itemText}>{previewText(item)}</span>
              </button>
            ))}
          </div>
        </div>
      ) : null}
      <div className={styles.rail}>
        {items.map((item) => (
          <button
            key={item.id}
            type="button"
            className={clsx(
              styles.dot,
              activeId === item.id && styles.dotActive,
              hoveredId === item.id && styles.dotHovered
            )}
            onMouseEnter={() => {
              setHoveredId(item.id)
              openPanel()
            }}
            onFocus={() => {
              setHoveredId(item.id)
              openPanel()
            }}
            onClick={() => jump(item.id)}
            aria-label={previewText(item).slice(0, 40)}
          />
        ))}
      </div>
    </div>
  )
}
