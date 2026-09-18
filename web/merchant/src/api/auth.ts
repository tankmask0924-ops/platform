import type { LoginForm } from '@platform/shared'
import type { QualificationPayload } from '@/qualification'
import { http } from './http'

export interface LoginResult {
  token: string
  username: string
}

/** 对应后端 App\Controller\Merchant\AuthController */
export async function login(form: LoginForm): Promise<LoginResult> {
  return http.post<LoginResult>('/merchant/auth/login', form)
}

export type RegisterForm = QualificationPayload & {
  phone: string
  email: string
  password: string
}

export async function register(form: RegisterForm): Promise<{ id: number; status: string }> {
  return http.post('/merchant/auth/register', form)
}

export interface Me {
  id: number
  type: string
  status: string
  phone: string | null
  email: string | null
  level_id: number | null
  available_balance: string
  frozen_balance: string
  /** 非 null 表示欠款中，下单已暂停 */
  debt_since: string | null
  /** 欠款超过预警线 */
  debt_warning: boolean
}

export async function fetchMe(): Promise<Me> {
  return http.get<Me>('/merchant/auth/me')
}

export const changePassword = (oldPassword: string, newPassword: string) =>
  http.put<{ token: string }>('/merchant/auth/password', { old_password: oldPassword, new_password: newPassword })

/** 找回密码第一步：给注册手机号发短信验证码；手机号没注册也返回成功 */
export const sendResetCode = (phone: string) =>
  http.post<{ expires_in: number; resend_after: number }>('/merchant/auth/password/reset-code', { phone })

export const resetPassword = (data: { phone: string; code: string; new_password: string }) =>
  http.post<{ success: boolean }>('/merchant/auth/password/reset', data)
