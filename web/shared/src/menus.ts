import type { MenuItem } from './types'

/** 去掉没有权限的菜单项；分组下一个子菜单都不剩时整个分组也不显示 */
export function filterMenus(menus: MenuItem[], can: (permission: string) => boolean): MenuItem[] {
  return menus.flatMap((item) => {
    if (item.permission && !can(item.permission)) {
      return []
    }
    if (!item.children) {
      return [item]
    }
    const children = filterMenus(item.children, can)
    return children.length > 0 ? [{ ...item, children }] : []
  })
}
