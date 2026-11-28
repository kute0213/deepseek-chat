import clsx from 'clsx'
import { useTranslate } from '../../i18n/index.js'
import { IconChevronDownOutline14 } from '../base/icons.js'
import { useAtBottom, useJumpToBottom } from './scrollContext.js'
import styles from './ScrollToBottomButton.module.css'

/** 回到底部：只在离开底部时出现，点击后无条件跳到底部并恢复跟随 */
export const ScrollToBottomButton = () => {
  const t = useTranslate()
  const atBottom = useAtBottom()
  const jumpToBottom = useJumpToBottom()
  return (
    <button
      type="button"
      className={clsx(styles.button, !atBottom && styles.visible)}
      aria-label={t('scrollToBottom')}
      title={t('scrollToBottom')}
      aria-hidden={atBottom}
      tabIndex={atBottom ? -1 : 0}
      onClick={jumpToBottom}
    >
      <IconChevronDownOutline14 />
    </button>
  )
}
