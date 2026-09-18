import type { FormRules } from 'element-plus'

/** 资质资料表单：注册和驳回后重新提交共用，字段和必填规则跟后端 QualificationService 一致 */
export interface QualificationForm {
  type: 'company' | 'individual'
  company_name: string
  business_license_no: string
  legal_person_name: string
  contact_name: string
  contact_phone: string
  id_card_name: string
  id_card_no: string
}

/** 按类型只提交对应的字段 */
export type QualificationPayload = Pick<QualificationForm, 'type' | 'contact_phone'> &
  Partial<Omit<QualificationForm, 'type' | 'contact_phone'>>

export function emptyQualificationForm(): QualificationForm {
  return {
    type: 'company',
    company_name: '',
    business_license_no: '',
    legal_person_name: '',
    contact_name: '',
    contact_phone: '',
    id_card_name: '',
    id_card_no: '',
  }
}

const required = (message: string) => [{ required: true, message, trigger: 'blur' }]

export const qualificationRules: FormRules = {
  company_name: required('请输入公司名称'),
  business_license_no: required('请输入营业执照号'),
  legal_person_name: required('请输入法人姓名'),
  contact_name: required('请输入联系人姓名'),
  contact_phone: required('请输入联系电话'),
  id_card_name: required('请输入姓名'),
  id_card_no: required('请输入身份证号'),
}

export function qualificationPayload(form: QualificationForm): QualificationPayload {
  const base = { type: form.type, contact_phone: form.contact_phone.trim() }
  return form.type === 'company'
    ? {
        ...base,
        company_name: form.company_name.trim(),
        business_license_no: form.business_license_no.trim(),
        legal_person_name: form.legal_person_name.trim(),
        contact_name: form.contact_name.trim(),
      }
    : { ...base, id_card_name: form.id_card_name.trim(), id_card_no: form.id_card_no.trim() }
}
