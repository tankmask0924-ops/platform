import type { LoginForm } from '@platform/shared'

export interface LoginResult {
  token: string
  username: string
}

/**
 * TODO: 后端还没有登录接口，目前是本地假登录（任意账号密码都能进）。
 * 接好后替换为：return http.post<LoginResult>('/merchant/auth/login', form)
 */
export async function login(form: LoginForm): Promise<LoginResult> {
  return { token: `dev-token-${Date.now()}`, username: form.username }
}
