import clsx from 'clsx'
import { useMemo } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { useAuth } from '../../services/auth.js'
import { getVisibleMessages } from '../../services/chat/branches.js'
import type { Session } from '../../services/chat/types.js'
import { IconLinkOutline16 } from '../base/icons.js'
import { IconButton } from '../base/IconButton.js'
import { ChatInput } from '../chatInput/ChatInput.js'
import { MessageList } from '../messages/MessageList.js'
import { MessageNavigator } from '../messages/MessageNavigator.js'
import { openShare } from '../share/shareDialogState.js'
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

  return (
    <div className={styles.view}>
      {isMobile ? (
        <MobileHeader
          title={title}
          onShare={loggedIn ? () => openShare(session.id) : undefined}
        />
      ) : (
        <div className={styles.header}>
          <div className={clsx(styles.title, siderCollapsed && styles.withCollapsedSider)}>
            {title}
          </div>
          {loggedIn ? (
            <div className={styles.headerActions}>
              <IconButton
                size="m"
                icon={<IconLinkOutline16 />}
                tooltip={t('shareSelectTitle')}
                tooltipPlacement="bottom"
                onClick={() => openShare(session.id)}
              />
            </div>
          ) : null}
        </div>
      )}
      <MessageList
        session={session}
        streaming={streaming}
        footer={
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
        }
      />
      <MessageNavigator session={session} />
    </div>
  )
}
