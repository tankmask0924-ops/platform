import type { LoginForm } from '@platform/shared'
import { http } from './http'

export interface LoginResult {
  token: string
  username: string
}

export async function login(form: LoginForm): Promise<LoginResult> {
  return http.post<LoginResult>('/admin/auth/login', form)
}

/** 当前登录管理员：App\Service\Admin\AuthService::me() */
export interface Me {
  id: number
  username: string
  real_name: string
  role_id: number
  role_name: string | null
  is_super_admin: boolean
  status: string
  permissions: string[]
}

export const fetchMe = () => http.get<Me>('/admin/auth/me')

export const changePassword = (oldPassword: string, newPassword: string) =>
  http.put<{ token: string }>('/admin/auth/password', { old_password: oldPassword, new_password: newPassword })
