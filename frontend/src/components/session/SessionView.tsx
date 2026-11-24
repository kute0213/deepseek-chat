import clsx from 'clsx'
import { useState } from 'react'
import { useIsMobile } from '../../app/viewport.js'
import { useTranslate } from '../../i18n/index.js'
import { useAuth } from '../../services/auth.js'
import type { Session } from '../../services/chat/types.js'
import { IconLinkOutline16 } from '../base/icons.js'
import { IconButton } from '../base/IconButton.js'
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
  const [sharing, setSharing] = useState(false)
  const title = session.title || t('untitledChat')
  return (
    <div className={styles.view}>
      {isMobile ? (
        <MobileHeader
          title={title}
          onShare={loggedIn ? () => setSharing(true) : undefined}
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
                tooltip={t('shareDialogTitle')}
                tooltipPlacement="bottom"
                onClick={() => setSharing(true)}
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
            <div className={styles.caveat}>{t('contentCaveat')}</div>
          </div>
        }
      />
      <MessageNavigator session={session} />
      {sharing ? (
        <ShareDialog sessionId={session.id} onClose={() => setSharing(false)} />
      ) : null}
    </div>
  )
}
