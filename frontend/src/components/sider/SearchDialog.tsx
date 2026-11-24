import { useEffect, useRef, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import { searchSessions, type SearchResult } from '../../services/chat/db.js'
import { focusMessage } from '../../services/chat/focus.js'
import { selectSession } from '../../services/chat/store.js'
import { useSettings } from '../../services/settings.js'
import { Modal } from '../base/Modal.js'
import { closeMobileSider } from './mobileSiderState.js'
import styles from './SearchDialog.module.css'

const formatDate = (ms: number, locale: string) =>
  new Date(ms).toLocaleDateString(locale, { month: '2-digit', day: '2-digit' })

/** 搜索全部对话：标题与消息正文，命中正文时跳转到对应消息位置。 */
export const SearchDialog = ({ onClose }: { onClose: () => void }) => {
  const t = useTranslate()
  const locale = useSettings((state) => state.locale)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<SearchResult[] | null>(null)
  const [searching, setSearching] = useState(false)
  const [failed, setFailed] = useState(false)
  const sequence = useRef(0)

  useEffect(() => {
    const keyword = query.trim()
    if (keyword === '') {
      setResults(null)
      setSearching(false)
      setFailed(false)
      return
    }
    const id = ++sequence.current
    setSearching(true)
    const timer = setTimeout(() => {
      void searchSessions(keyword)
        .then((list) => {
          if (sequence.current === id) {
            setResults(list)
            setFailed(false)
          }
        })
        .catch(() => {
          if (sequence.current === id) {
            setFailed(true)
          }
        })
        .finally(() => {
          if (sequence.current === id) {
            setSearching(false)
          }
        })
    }, 250)
    return () => clearTimeout(timer)
  }, [query])

  const open = (result: SearchResult) => {
    selectSession(result.sessionId)
    if (result.messageId) {
      focusMessage(result.sessionId, result.messageId)
    }
    closeMobileSider()
    onClose()
  }

  return (
    <Modal title={t('searchChats')} closable onClose={onClose}>
      <input
        className={styles.input}
        value={query}
        autoFocus
        spellCheck={false}
        placeholder={t('searchChatsPlaceholder')}
        onChange={(event) => setQuery(event.target.value)}
      />
      <div className={styles.results}>
        {failed ? (
          <div className={styles.state}>{t('searchFailed')}</div>
        ) : query.trim() === '' ? (
          <div className={styles.state}>{t('searchHint')}</div>
        ) : searching && results === null ? (
          <div className={styles.state} role="status" aria-live="polite">
            {t('searching')}
          </div>
        ) : results && results.length === 0 ? (
          <div className={styles.state}>{t('searchEmpty')}</div>
        ) : (
          results?.map((result) => (
            <button
              key={result.sessionId}
              type="button"
              className={styles.result}
              onClick={() => open(result)}
            >
              <div className={styles.resultTop}>
                <span className={styles.resultTitle}>{result.title || t('untitledChat')}</span>
                <span className={styles.resultDate}>{formatDate(result.updatedAt, locale)}</span>
              </div>
              {result.snippet ? <div className={styles.snippet}>{result.snippet}</div> : null}
            </button>
          ))
        )}
      </div>
    </Modal>
  )
}
