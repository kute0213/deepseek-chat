/** 会话地址固定为 /{会话ID}；首页、文档、后台等路径不参与同步 */
const SESSION_PATH = /^\/(\d{1,20})\/?$/

/** 从地址栏读取会话 ID；不是会话地址时返回 null */
export const sessionIdFromPath = (pathname: string = window.location.pathname): string | null => {
  const match = SESSION_PATH.exec(pathname)
  return match ? match[1] : null
}

/**
 * 把地址栏同步到当前会话：有会话时为 /{ID}，回到欢迎页时为 /。
 * 同一路径不重复写入，避免前进/后退里堆出重复记录。
 * replace 用于替换当前记录（新建会话、删除当前会话），否则新增一条。
 */
export const syncSessionUrl = (id: string | null, replace = false): void => {
  const target = id === null ? '/' : `/${id}`
  if (window.location.pathname === target) {
    return
  }
  window.history[replace ? 'replaceState' : 'pushState'](null, '', target)
}
