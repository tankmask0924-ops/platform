import { http } from './http'

export interface User {
  id: number
  name: string
  email: string
  created_at: string | null
  updated_at: string | null
}

export type UserForm = Pick<User, 'name' | 'email'>

/** 对应后端 App\Controller\UserController */
export const userApi = {
  list: (params: { page: number; per_page: number }) => http.get<User[]>('/users', params),
  create: (data: UserForm) => http.post<User>('/users', data),
  update: (id: number, data: UserForm) => http.put<{ success: boolean }>(`/users/${id}`, data),
  remove: (id: number) => http.delete<{ success: boolean }>(`/users/${id}`),
}
