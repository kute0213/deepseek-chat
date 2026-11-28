import clsx from 'clsx'
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { focusMessage } from '../../services/chat/focus.js'
import { loadOutline, type OutlineItem } from '../../services/chat/outline.js'
import type { Session } from '../../services/chat/types.js'
import styles from './MessageNavigator.module.css'

/** 每次从精简大纲接口读取的条数 */
const PAGE_SIZE = 20
/** 右侧轨道固定显示的短横数量 */
const MAX_BARS = 7
/** 相邻短横的基准间距（含点击区高度），屏幕过矮时自动缩小 */
const BAR_PITCH = 32
/** 预览列表滚到距顶部还剩 5 轮时就预加载更早的提问 */
const PRELOAD_ROUNDS = 5

/**
 * 右侧会话导航：用一列短横线表示用户提问，悬浮后渐显前 50 个字预览；
 * 固定露出 7 条短横，按屏高自动缩放间距，点击跳转到对应消息。
 * 收起与展开始终是同一份列表、同一个滚动位置：
 * 收起时只隐藏文字、宽度收窄到短横，因此高亮与位置不会在收起后丢失。
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
  // 相邻短横的中心间距：基准 32px，屏幕过矮时自动缩小
  const [pitch, setPitch] = useState(BAR_PITCH)
  const requestRef = useRef(0)
  const activeRef = useRef(-1)
  const itemsRef = useRef<OutlineItem[]>([])
  const totalRef = useRef(0)
  /** 正在加载更早的大纲窗口，避免滚动时重复请求 */
  const earlierRef = useRef(false)
  /** 前插更早条目后按高度差补偿滚动位置，避免预览与短横跳动 */
  const prependRef = useRef<{ height: number } | null>(null)
  activeRef.current = active
  itemsRef.current = items
  totalRef.current = total

  // 面板一次性最多露出 7 行，行数不足时按实际条数收缩
  const rows = Math.max(1, Math.min(items.length, MAX_BARS))
  const panelHeight = rows * pitch + 8

  // 间距按屏高自动缩放：常规屏幕 32px，过矮时最小 22px
  useEffect(() => {
    const update = () => {
      setPitch(Math.max(22, Math.min(BAR_PITCH, Math.round(window.innerHeight / 24))))
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
      // 窗口会被整页替换，放弃未完成的前插补偿
      prependRef.current = null
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
        if (aroundId) {
          const hit = page.items.find((item) => item.id === aroundId)
          // 目标不在大纲里时保持当前窗口，避免被“从头开始”的一页覆盖
          if (!hit) {
            return
          }
          setTotal(page.total)
          setItems(page.items)
          setActive(hit.index)
          return
        }
        setTotal(page.total)
        setItems(page.items)
      } catch {
        // 保持当前窗口，不打断阅读
      }
    },
    [session.id]
  )

  /**
   * 加载更早的提问并前插到当前窗口：保留已有条目与滚动位置，
   * 只补充当前第一条之前的 20 条，滚动时不会跳回已选中的提问。
   */
  const loadEarlier = useCallback(async () => {
    if (earlierRef.current) {
      return
    }
    const first = itemsRef.current[0]
    if (!first || first.index <= 0) {
      return
    }
    earlierRef.current = true
    const panel = panelRef.current
    prependRef.current = panel ? { height: panel.scrollHeight } : null
    const seq = requestRef.current
    try {
      const offset = Math.max(0, first.index - PAGE_SIZE)
      const page = await loadOutline(session.id, { offset, limit: PAGE_SIZE })
      if (seq !== requestRef.current) {
        prependRef.current = null
        return
      }
      const existing = new Set(itemsRef.current.map((item) => item.id))
      const earlier = page.items.filter((item) => !existing.has(item.id))
      setTotal(page.total)
      if (earlier.length === 0) {
        prependRef.current = null
        return
      }
      setItems([...earlier, ...itemsRef.current])
    } catch {
      prependRef.current = null
      // 保持当前窗口，不打断阅读
    } finally {
      earlierRef.current = false
    }
  }, [session.id])

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

  // 高亮行始终停在可视区中间：收起时露出的 7 条短横、展开后的预览行，
  // 使用同一个滚动位置，因此收起 / 展开不会“跳回初始化”。
  // 前插更早条目时改为补偿高度，保持用户当前看到的位置不变。
  useLayoutEffect(() => {
    const panel = panelRef.current
    if (!panel) {
      return
    }
    const prepend = prependRef.current
    if (prepend) {
      prependRef.current = null
      panel.scrollTop += panel.scrollHeight - prepend.height
      return
    }
    const row = panel.querySelector<HTMLElement>('[data-active="true"]')
    if (!row) {
      return
    }
    panel.scrollTop = row.offsetTop - panel.clientHeight / 2 + row.offsetHeight / 2
  }, [active, open, panelHeight, pitch, items])

  // 预览列表滚到距顶部还剩 5 轮提问时，自动加载更早的一页
  useEffect(() => {
    const panel = panelRef.current
    if (!panel || !open) {
      return
    }
    let frame = 0
    const onScroll = () => {
      if (frame !== 0) {
        return
      }
      frame = requestAnimationFrame(() => {
        frame = 0
        if (panel.scrollTop > pitch * PRELOAD_ROUNDS) {
          return
        }
        void loadEarlier()
      })
    }
    panel.addEventListener('scroll', onScroll, { passive: true })
    return () => {
      panel.removeEventListener('scroll', onScroll)
      if (frame !== 0) {
        cancelAnimationFrame(frame)
      }
    }
  }, [open, pitch, loadEarlier])

  // 展开后滚轮直接滚动预览列表，而不是切换选中的会话
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
      style={{ height: panelHeight }}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <div
        ref={panelRef}
        className={clsx(styles.panel, open ? styles.panelOpen : styles.panelCollapsed)}
        style={{ height: panelHeight }}
        role="listbox"
        aria-label={t('messageNavTitle')}
      >
        {items.map((item) => (
          <button
            key={item.id}
            type="button"
            role="option"
            aria-selected={item.index === active}
            data-active={item.index === active}
            className={clsx(styles.item, item.index === active && styles.itemActive)}
            style={{ height: pitch }}
            title={item.preview}
            onClick={() => jump(item)}
          >
            <span className={styles.itemText}>{item.preview || t('messageNavAttachment')}</span>
            <span className={styles.itemBar} />
          </button>
        ))}
      </div>
    </div>
  )
}
