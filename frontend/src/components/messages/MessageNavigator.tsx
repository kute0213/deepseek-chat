import clsx from 'clsx'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { focusMessage } from '../../services/chat/focus.js'
import { loadOutline, type OutlineItem } from '../../services/chat/outline.js'
import type { Session } from '../../services/chat/types.js'
import styles from './MessageNavigator.module.css'

/** 每次从精简大纲接口读取的条数 */
const PAGE_SIZE = 20
/** 右侧轨道最多显示的短横数量 */
const MAX_BARS = 5
/** 相邻短横线的垂直间距（含线高） */
const BAR_PITCH = 20

/**
 * 右侧会话导航：用一列短横线表示用户提问，悬浮后展开前 50 个字预览；
 * 短横数量随屏幕高度自适应，滚轮平滑切换选中项，点击跳转到对应消息。
 * 目标消息尚未加载时由消息列表自动拉取它所在的窗口。
 */
export const MessageNavigator = ({ session }: { session: Session }) => {
  const t = useTranslate()
  const isMobile = useIsMobile()
  const rootRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const [total, setTotal] = useState(0)
  const [items, setItems] = useState<OutlineItem[]>([])
  const [active, setActive] = useState(-1)
  const [open, setOpen] = useState(false)
  const [bars, setBars] = useState(MAX_BARS)
  const requestRef = useRef(0)
  const activeRef = useRef(-1)
  const itemsRef = useRef<OutlineItem[]>([])
  const totalRef = useRef(0)
  activeRef.current = active
  itemsRef.current = items
  totalRef.current = total

  // 短横数量按屏幕高度计算，最多 5 条
  useEffect(() => {
    const update = () => {
      const available = window.innerHeight * 0.5
      setBars(Math.max(1, Math.min(MAX_BARS, Math.floor(available / BAR_PITCH))))
    }
    update()
    window.addEventListener('resize', update)
    return () => window.removeEventListener('resize', update)
  }, [])

  // 切换会话时回到最后一条提问
  useEffect(() => {
    const seq = ++requestRef.current
    setItems([])
    setTotal(0)
    setActive(-1)
    setOpen(false)
    void loadOutline(session.id, { offset: Number.MAX_SAFE_INTEGER, limit: PAGE_SIZE })
      .then((page) => {
        if (seq !== requestRef.current) {
          return
        }
        setTotal(page.total)
        setItems(page.items)
        setActive(page.total - 1)
      })
      .catch(() => {
        // 会话没有正文或接口失败时隐藏导航
      })
    return () => {
      requestRef.current += 1
    }
  }, [session.id])

  const loadWindowAt = useCallback(
    async (index: number, aroundId?: string) => {
      const seq = ++requestRef.current
      const known = Math.max(totalRef.current, index + 1)
      const offset = Math.max(
        0,
        Math.min(index - Math.floor(PAGE_SIZE / 2), Math.max(0, known - PAGE_SIZE))
      )
      try {
        const page = aroundId
          ? await loadOutline(session.id, { around: aroundId, limit: PAGE_SIZE })
          : await loadOutline(session.id, { offset, limit: PAGE_SIZE })
        if (seq !== requestRef.current) {
          return
        }
        setTotal(page.total)
        setItems(page.items)
        if (aroundId) {
          const hit = page.items.find((item) => item.id === aroundId)
          if (hit) {
            setActive(hit.index)
          }
        }
      } catch {
        // 保持当前窗口，不打断阅读
      }
    },
    [session.id]
  )

  // 跟随聊天滚动：把当前视口上方的最后一条用户提问标为选中项
  useEffect(() => {
    if (isMobile) {
      return
    }
    const view = rootRef.current?.parentElement
    const scroller = view?.querySelector<HTMLElement>('[data-message-scroll]')
    if (!scroller) {
      return
    }
    const roles = new Map<string, string>()
    for (const message of session.messages) {
      roles.set(message.id, message.role)
    }
    let frame = 0
    let timer: ReturnType<typeof setTimeout> | undefined
    const update = () => {
      frame = 0
      if (itemsRef.current.length === 0) {
        return
      }
      const line = scroller.getBoundingClientRect().top + scroller.clientHeight * 0.45
      let candidate: string | null = null
      for (const element of scroller.querySelectorAll<HTMLElement>('[data-message-id]')) {
        const id = element.dataset.messageId ?? ''
        if (roles.get(id) !== 'user') {
          continue
        }
        if (element.getBoundingClientRect().top > line) {
          break
        }
        candidate = id
      }
      if (!candidate) {
        return
      }
      const hit = itemsRef.current.find((item) => item.id === candidate)
      if (hit) {
        if (hit.index !== activeRef.current) {
          setActive(hit.index)
        }
        return
      }
      clearTimeout(timer)
      timer = setTimeout(() => void loadWindowAt(0, candidate), 120)
    }
    const onScroll = () => {
      if (frame === 0) {
        frame = requestAnimationFrame(update)
      }
    }
    scroller.addEventListener('scroll', onScroll, { passive: true })
    update()
    return () => {
      scroller.removeEventListener('scroll', onScroll)
      if (frame !== 0) {
        cancelAnimationFrame(frame)
      }
      clearTimeout(timer)
    }
  }, [isMobile, loadWindowAt, session.id, session.messages.length])

  // 选中项滚出预览面板时把它带入可视区
  useEffect(() => {
    panelRef.current
      ?.querySelector<HTMLElement>('[data-active="true"]')
      ?.scrollIntoView({ block: 'nearest' })
  }, [active, open])

  const railItems = useMemo(() => {
    if (items.length <= bars) {
      return items
    }
    const position = Math.max(
      0,
      items.findIndex((item) => item.index === active)
    )
    const start = Math.max(0, Math.min(position - Math.floor(bars / 2), items.length - bars))
    return items.slice(start, start + bars)
  }, [active, bars, items])

  // 展开时滚轮直接滚动预览列表，而不是切换选中的会话
  useEffect(() => {
    const root = rootRef.current
    if (!root || !open) {
      return
    }
    const onWheel = (event: WheelEvent) => {
      const panel = panelRef.current
      if (!panel || event.deltaY === 0) {
        return
      }
      event.preventDefault()
      panel.scrollTop += event.deltaY
    }
    root.addEventListener('wheel', onWheel, { passive: false })
    return () => root.removeEventListener('wheel', onWheel)
  }, [open])

  if (isMobile || total <= 0) {
    return null
  }

  const jump = (item: OutlineItem) => {
    setActive(item.index)
    focusMessage(session.id, item.id)
  }

  return (
    <div
      ref={rootRef}
      className={styles.root}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <div
        ref={panelRef}
        className={clsx(styles.panel, open && styles.panelOpen)}
        role="listbox"
        aria-label={t('messageNavTitle')}
        aria-hidden={!open}
      >
        {items.map((item) => (
          <button
            key={item.id}
            type="button"
            role="option"
            aria-selected={item.index === active}
            data-active={item.index === active}
            className={clsx(styles.item, item.index === active && styles.itemActive)}
            title={item.preview}
            onClick={() => jump(item)}
          >
            <span className={styles.itemText}>{item.preview || t('messageNavAttachment')}</span>
          </button>
        ))}
      </div>
      <div className={styles.rail}>
        {railItems.map((item) => (
          <button
            key={item.id}
            type="button"
            className={styles.barButton}
            aria-label={item.preview || t('messageNavTitle')}
            title={item.preview || undefined}
            onClick={() => jump(item)}
          >
            <span className={clsx(styles.bar, item.index === active && styles.barActive)} />
          </button>
        ))}
      </div>
    </div>
  )
}
