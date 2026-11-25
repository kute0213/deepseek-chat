import { useTranslate } from '../../i18n/index.js'
import { undoRound } from '../../services/chat/store.js'
import { Button } from '../base/Button.js'
import { Modal } from '../base/Modal.js'
import { showToast } from '../base/toastState.js'

/** 撤销某轮对话的确认弹窗：用户消息与回答共用 */
export const UndoRoundDialog = ({
  sessionId,
  messageId,
  onClose,
}: {
  sessionId: string
  messageId: string
  onClose: () => void
}) => {
  const t = useTranslate()
  return (
    <Modal
      title={t('undoRoundTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="outlined" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button
            variant="danger"
            onClick={async () => {
              onClose()
              if (await undoRound(sessionId, messageId)) {
                showToast({ key: 'undoRoundDone' })
              } else {
                showToast({ key: 'undoRoundFailed', type: 'error' })
              }
            }}
          >
            {t('undoRound')}
          </Button>
        </>
      }
    >
      {t('undoRoundDescription')}
    </Modal>
  )
}
