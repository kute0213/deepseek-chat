import clsx from 'clsx'
import { memo, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import {
  IconCheckOutline16,
  IconEditOutline16,
  IconEllipsisOutline16,
  IconPinOutline16,
  IconShareOutline16,
  IconSliderOutline16,
  IconTrashOutline16,
} from '../base/icons.js'
import { renameSession, selectSession, togglePinned } from '../../services/chat/store.js'
import type { SessionSummary } from '../../services/chat/types.js'
import { IconButton } from '../base/IconButton.js'
import { Menu } from '../base/Menu.js'
import styles from './SessionItem.module.css'

export const SessionItem = memo(function SessionItem({
  session,
  active,
  batch = false,
  selected = false,
  onToggle,
  onDelete,
  onShare,
  onBatchSelect,
}: {
  session: SessionSummary
  active: boolean
  batch?: boolean
  selected?: boolean
  onToggle?: () => void
  onDelete: () => void
  onShare?: () => void
  onBatchSelect?: () => void
}) {
  const t = useTranslate()
  const [editing, setEditing] = useState(false)
  // 菜单展开期间保持「更多」按钮可见：否则鼠标移到浮层后触发元素被隐藏，菜单会漂移
  const [menuOpen, setMenuOpen] = useState(false)
  const displayTitle = session.title || t('untitledChat')

  if (editing && !batch) {
    const finish = (title: string) => {
      setEditing(false)
      if (title.trim() && title.trim() !== displayTitle) {
        renameSession(session.id, title.trim())
      }
    }
    return (
      <input
        className={styles.renameInput}
        defaultValue={displayTitle}
        autoFocus
        onFocus={(event) => event.target.select()}
        onBlur={(event) => finish(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'Enter' && !event.nativeEvent.isComposing) {
            finish(event.currentTarget.value)
          } else if (event.key === 'Escape') {
            setEditing(false)
          }
        }}
      />
    )
  }

  return (
    <div
      className={clsx(
        styles.item,
        active && !batch && styles.active,
        selected && styles.selected,
        menuOpen && styles.menuOpen
      )}
      onClick={() => {
        if (batch) {
          onToggle?.()
          return
        }
        selectSession(session.id)
      }}
    >
      {batch ? (
        <span className={clsx(styles.checkbox, selected && styles.checkboxChecked)} aria-hidden>
          {selected ? <IconCheckOutline16 /> : null}
        </span>
      ) : null}
      {!batch && session.pinned ? (
        <span className={styles.pin} aria-hidden>
          <IconPinOutline16 />
        </span>
      ) : null}
      <div className={styles.title}>{displayTitle}</div>
      {batch ? null : (
        <div className={styles.action} onClick={(event) => event.stopPropagation()}>
          <Menu
            onOpenChange={setMenuOpen}
            options={[
              {
                key: 'rename',
                label: t('sessionItemMenuRenameOption'),
                icon: <IconEditOutline16 />,
                onSelect: () => setEditing(true),
              },
              {
                key: 'pin',
                label: t(session.pinned ? 'sessionItemMenuUnpinOption' : 'sessionItemMenuPinOption'),
                icon: <IconPinOutline16 />,
                onSelect: () => togglePinned(session.id, !session.pinned),
              },
              {
                key: 'share',
                label: t('sessionItemMenuShareOption'),
                icon: <IconShareOutline16 />,
                onSelect: () => onShare?.(),
              },
              {
                key: 'select',
                label: t('sessionItemMenuSelectOption'),
                icon: <IconSliderOutline16 />,
                onSelect: () => onBatchSelect?.(),
              },
              {
                key: 'delete',
                label: t('delete'),
                icon: <IconTrashOutline16 />,
                danger: true,
                onSelect: onDelete,
              },
            ]}
          >
            {({ toggle }) => (
              <IconButton
                icon={<IconEllipsisOutline16 />}
                aria-label={t('more')}
                onClick={toggle}
              />
            )}
          </Menu>
        </div>
      )}
    </div>
  )
})
