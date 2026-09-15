import type { LoginForm } from '@platform/shared'
import { http } from './http'

export interface LoginResult {
  token: string
  username: string
}

export async function login(form: LoginForm): Promise<LoginResult> {
  return http.post<LoginResult>('/admin/auth/login', form)
}
