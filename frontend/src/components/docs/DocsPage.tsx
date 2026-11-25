import clsx from 'clsx'
import { useEffect, useMemo, useState } from 'react'
import { useTranslate } from '../../i18n/index.js'
import { loadDocs, type DocItem } from '../../services/docs.js'
import { useSettings } from '../../services/settings.js'
import { loadSite, useSite } from '../../services/site.js'
import { IconChevronDownOutline14 } from '../base/icons.js'
import { Markdown } from '../markdown/Markdown.js'
import styles from './DocsPage.module.css'

const currentDocName = () => new URLSearchParams(window.location.search).get('doc')

/** 文档路由：左侧可折叠列表，右侧渲染 docs 目录中的 Markdown */
export const DocsPage = () => {
  const t = useTranslate()
  const locale = useSettings((state) => state.locale)
  const siteName = useSite((state) => state.info?.siteName)
  const [docs, setDocs] = useState<DocItem[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [activeBase, setActiveBase] = useState<string | null>(null)
  const [listOpen, setListOpen] = useState(true)

  useEffect(() => {
    void loadSite()
    void loadDocs()
      .then((list) => {
        setDocs(list)
        const requested = currentDocName()
        const match = requested ? list.find((item) => item.name === requested) : null
        setActiveBase((previous) => previous ?? match?.base ?? list[0]?.base ?? null)
      })
      .catch(() => setFailed(true))
  }, [])

  /** 同一 base 优先当前语言版本，缺失时回退中文 */
  const grouped = useMemo(() => {
    const map = new Map<string, Partial<Record<'zh-CN' | 'en-US', DocItem>>>()
    for (const doc of docs ?? []) {
      const entry = map.get(doc.base) ?? {}
      entry[doc.lang] = doc
      map.set(doc.base, entry)
    }
    return map
  }, [docs])

  const visible = useMemo(
    () =>
      [...grouped.keys()]
        .sort((a, b) => a.localeCompare(b, 'zh-Hans-CN'))
        .map((base) => grouped.get(base)?.[locale] ?? grouped.get(base)?.['zh-CN'])
        .filter((doc): doc is DocItem => Boolean(doc)),
    [grouped, locale]
  )

  const current = useMemo(
    () =>
      (activeBase ? grouped.get(activeBase)?.[locale] ?? grouped.get(activeBase)?.['zh-CN'] : null) ??
      visible[0],
    [activeBase, grouped, locale, visible]
  )

  useEffect(() => {
    if (current) {
      document.title = `${current.title} - ${siteName ?? t('appName')}`
    }
  }, [current, siteName, t])

  const select = (doc: DocItem) => {
    setActiveBase(doc.base)
    const params = new URLSearchParams(window.location.search)
    params.set('doc', doc.name)
    window.history.replaceState(null, '', `/docs?${params.toString()}`)
  }

  return (
    <div className={styles.page}>
      <header className={styles.topbar}>
        <a className={styles.brand} href="/">
          {siteName ?? t('appName')}
        </a>
        <a className={styles.openApp} href="/">
          {t('shareOpenApp')}
        </a>
      </header>
      <div className={styles.layout}>
        <aside className={styles.sidebar}>
          <button
            type="button"
            className={styles.sideHeader}
            onClick={() => setListOpen((open) => !open)}
          >
            <span>{t('docsTitle')}</span>
            <span className={clsx(styles.chevron, !listOpen && styles.chevronClosed)}>
              <IconChevronDownOutline14 />
            </span>
          </button>
          {listOpen ? (
            <nav className={styles.list}>
              {docs === null && !failed ? (
                <div className={styles.state}>{t('sessionLoading')}</div>
              ) : null}
              {visible.map((doc) => (
                <a
                  key={doc.name}
                  className={clsx(styles.item, current?.base === doc.base && styles.itemActive)}
                  href={`/docs?doc=${encodeURIComponent(doc.name)}`}
                  onClick={(event) => {
                    event.preventDefault()
                    select(doc)
                  }}
                >
                  {doc.title}
                </a>
              ))}
            </nav>
          ) : null}
        </aside>
        <main className={styles.main}>
          {failed ? (
            <div className={styles.state} role="alert">
              {t('docsLoadFailed')}
            </div>
          ) : docs === null ? (
            <div className={styles.state} role="status" aria-live="polite">
              {t('sessionLoading')}
            </div>
          ) : current ? (
            <article className={styles.doc}>
              <Markdown
                className="ds-assistant-message-main-content"
                content={current.content}
                loading={false}
                cacheKey={current.name}
                disableLink={false}
              />
            </article>
          ) : (
            <div className={styles.state}>{t('docsEmpty')}</div>
          )}
        </main>
      </div>
    </div>
  )
}
