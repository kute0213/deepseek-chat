import { createRoot } from 'react-dom/client'
import { App } from './app/App.js'
import { setupTheme } from './app/theme.js'
import { ensureKatex } from './services/markdown/katex.js'
import { SharePage } from './components/share/SharePage.js'
import { DocsPage } from './components/docs/DocsPage.js'
import './app/theme.css'
import './app/global.css'

ensureKatex()
setupTheme()

/** /?share={token} 与 /share/{token} 都是独立的只读分享页 */
const query = new URLSearchParams(window.location.search)
const shareToken =
  query.get('share') ?? /^\/share\/([A-Za-z0-9]+)\/?$/.exec(window.location.pathname)?.[1]
/** /docs 是文档路由，其余路径进入主应用 */
const isDocs = /^\/docs\/?$/.test(window.location.pathname)

createRoot(document.getElementById('root') as HTMLElement).render(
  isDocs ? <DocsPage /> : shareToken ? <SharePage token={shareToken} /> : <App />
)
