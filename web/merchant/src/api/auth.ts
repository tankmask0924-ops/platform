import type { LoginForm } from '@platform/shared'
import { http } from './http'

export interface LoginResult {
  token: string
  username: string
}

/** 对应后端 App\Controller\Merchant\AuthController */
export async function login(form: LoginForm): Promise<LoginResult> {
  return http.post<LoginResult>('/merchant/auth/login', form)
}

export interface RegisterForm {
  type: 'company' | 'individual'
  phone: string
  email: string
  password: string
  contact_phone: string
  // 企业
  company_name?: string
  business_license_no?: string
  legal_person_name?: string
  contact_name?: string
  // 个人
  id_card_name?: string
  id_card_no?: string
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
