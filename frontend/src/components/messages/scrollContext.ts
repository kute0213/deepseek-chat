import { createContext, useContext } from 'react'

/** 渲染更新后请求滚到底部；用户上滑离开底部时由列表忽略 */
export const ScrollToBottomContext = createContext<() => void>(() => {})

export const useScrollToBottom = () => useContext(ScrollToBottomContext)

/** 列表当前是否停在底部，用于回底按钮的显隐 */
export const AtBottomContext = createContext(true)

export const useAtBottom = () => useContext(AtBottomContext)

/** 用户主动点击回底：无条件跳到底部并恢复跟随 */
export const JumpToBottomContext = createContext<() => void>(() => {})

export const useJumpToBottom = () => useContext(JumpToBottomContext)
