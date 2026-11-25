import clsx from 'clsx'
import { useEffect, useMemo, useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { useAuth } from '../../services/auth.js'
import { getVisibleMessages } from '../../services/chat/branches.js'
import { createShare, type ShareSummary } from '../../services/chat/shares.js'
import {
  startShareSelection,
  stopShareSelection,
  useShareSelection,
} from '../../services/chat/shareSelection.js'
import type { Session } from '../../services/chat/types.js'
import { IconShareOutline16 } from '../base/icons.js'
import { IconButton } from '../base/IconButton.js'
import { Button } from '../base/Button.js'
import { showToast } from '../base/toastState.js'
import { ChatInput } from '../chatInput/ChatInput.js'
import { MessageList } from '../messages/MessageList.js'
import { MessageNavigator } from '../messages/MessageNavigator.js'
import { ShareDialog } from '../share/ShareDialog.js'
import { MobileHeader } from './MobileHeader.js'
import styles from './SessionView.module.css'

/** 会话页：标题栏（移动端为顶栏）+ 消息列表，输入框吸底 */
export const SessionView = ({
  session,
  streaming,
  siderCollapsed,
}: {
  session: Session
  streaming: boolean
  siderCollapsed: boolean
}) => {
  const t = useTranslate()
  const isMobile = useIsMobile()
  const loggedIn = useAuth((state) => state.user !== null)
  const title = session.title || t('untitledChat')
  const shareSessionId = useShareSelection((state) => state.sessionId)
  const shareMode = loggedIn && shareSessionId === session.id
  const [selected, setSelected] = useState<Set<string>>(() => new Set())
  const [creating, setCreating] = useState(false)
  const [created, setCreated] = useState<ShareSummary | null>(null)
  // 当前可见分支的 Token 用量：与服务端统计口径一致
  const usage = useMemo(() => {
    let input = 0
    let output = 0
    for (const message of getVisibleMessages(session)) {
      if (message.role !== 'assistant' || !message.usage) {
        continue
      }
      input +=
        (message.usage.input_tokens ?? 0) +
        (message.usage.cache_creation_input_tokens ?? 0) +
        (message.usage.cache_read_input_tokens ?? 0)
      output += message.usage.output_tokens ?? 0
    }
    return { input, output }
  }, [session])

  useEffect(() => {
    setSelected(new Set())
    setCreated(null)
    setCreating(false)
  }, [session.id])

  const startShare = () => {
    setSelected(new Set())
    setCreated(null)
    startShareSelection(session.id)
  }

  const cancelShare = () => {
    setSelected(new Set())
    setCreated(null)
    stopShareSelection()
  }

  const toggleShare = (messageId: string) => {
    const path = getVisibleMessages(session)
    const index = path.findIndex((message) => message.id === messageId)
    if (index < 0) {
      return
    }
    const target = path[index]
    const partner = target.role === 'user' ? path[index + 1] : path[index - 1]
    const ids = [target.id]
    if (partner && partner.role !== target.role) {
      ids.push(partner.id)
    }
    setSelected((previous) => {
      const next = new Set(previous)
      if (previous.has(target.id)) {
        ids.forEach((id) => next.delete(id))
      } else {
        ids.forEach((id) => next.add(id))
      }
      return next
    })
  }

  const submitShare = async () => {
    if (creating || selected.size === 0) {
      return
    }
    setCreating(true)
    try {
      setCreated(await createShare(session.id, Array.from(selected)))
      stopShareSelection()
    } catch {
      showToast({ key: 'shareCreateFailed', type: 'error' })
    } finally {
      setCreating(false)
    }
  }

  return (
    <div className={styles.view}>
      {isMobile ? (
        <MobileHeader title={title} onShare={loggedIn ? startShare : undefined} />
      ) : (
        <div className={styles.header}>
          <div className={clsx(styles.title, siderCollapsed && styles.withCollapsedSider)}>
            {title}
          </div>
          {loggedIn ? (
            <div className={styles.headerActions}>
              <IconButton
                size="m"
                icon={<IconShareOutline16 />}
                tooltip={t('shareDialogTitle')}
                tooltipPlacement="bottom"
                onClick={startShare}
              />
            </div>
          ) : null}
        </div>
      )}
      <MessageList
        session={session}
        streaming={streaming}
        shareMode={shareMode}
        shareSelected={selected}
        onToggleShare={toggleShare}
        footer={
          shareMode ? (
            <div className={styles.shareBar}>
              <span className={styles.shareCount}>
                {t('shareSelectedCount', { count: selected.size })}
              </span>
              <div className={styles.shareActions}>
                <Button variant="outlined" size="s" onClick={cancelShare}>
                  {t('cancel')}
                </Button>
                <Button
                  variant="primary"
                  size="s"
                  disabled={selected.size === 0 || creating}
                  onClick={() => void submitShare()}
                >
                  {creating ? t('shareCreating') : t('shareCreate')}
                </Button>
              </div>
            </div>
          ) : (
            <div className={styles.inputContainer}>
              <ChatInput sessionId={session.id} />
              <div className={styles.caveatRow}>
                <div className={styles.caveat}>{t('contentCaveat')}</div>
                <div className={styles.tokenUsage}>
                  <span>{t('tokenUsageInput', { count: usage.input })}</span>
                  <span>{t('tokenUsageOutput', { count: usage.output })}</span>
                </div>
              </div>
            </div>
          )
        }
      />
      <MessageNavigator session={session} />
      {created ? (
        <ShareDialog
          sessionId={session.id}
          share={created}
          onClose={() => {
            setCreated(null)
            setSelected(new Set())
          }}
        />
      ) : null}
    </div>
  )
}
