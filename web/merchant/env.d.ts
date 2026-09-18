/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APP_TITLE: string
  readonly VITE_API_BASE_URL: string
  /** 接口文档页展示给商户的开放 API 地址，不填时按当前域名 + VITE_API_BASE_URL 推算 */
  readonly VITE_OPEN_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
