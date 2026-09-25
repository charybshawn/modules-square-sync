// Shapes shared by the Square Sync page and its panels -- each mirrors the
// PHP Action that produces it.

export interface SquareLocation {
  id: string
  name: string
  status: string
  address: string | null
}

export interface ConnectionConfig {
  access_token_configured: boolean
  location_id_configured: boolean
  configured: boolean
  environment: string
  locations: SquareLocation[]
  selected_location_id: string | null
  location_source: 'setting' | 'env' | null
}

export type LinkIssue = 'missing' | 'archived' | 'not_at_location'

export interface LinkCheck {
  checked: number
  ok: number
  missing: number
  archived: number
  not_at_location: number
  issues: { mapping_id: number; product_id: number; product_title: string | null; square_object_id: string; status: LinkIssue }[]
}

export interface HealthNote {
  key: string
  message: string
}

// CheckSquareHealth's snapshot. `stale` is only on the page-load copy.
export interface Health {
  status: 'online' | 'offline' | 'not_configured'
  checked_at: string
  environment: string
  merchant_id: string | null
  problems: HealthNote[]
  warnings: HealthNote[]
  scopes: string[] | null
  location: { id: string; name: string | null; status: string | null } | null
  links: LinkCheck | null
  stale?: boolean
}

export interface DiagnosticCheck {
  key: string
  label: string
  status: 'pass' | 'warn' | 'fail' | 'skip'
  detail: string
}

export interface TestSaleProduct {
  id: number
  title: string
  stock: number
}

export interface Diagnostics {
  health: Health
  checks: DiagnosticCheck[]
  test_sale: { available: boolean; reason: string | null; products: TestSaleProduct[] }
  /** Only when run with "Show raw responses": every Square call and webhook of the run. */
  debug?: Record<string, unknown>
}

export interface TestSaleStep {
  key: string
  label: string
  status: 'pass' | 'fail' | 'pending' | 'skip'
  detail: string
}

export interface TestSaleRun {
  id: string
  product_title: string
  order_id: string | null
  started_at: string
  finished: boolean
  passed: boolean
  can_pull: boolean
  steps: TestSaleStep[]
}

export const formatTimestamp = (value: string | null): string => {
  if (!value) return 'Never'
  return new Date(value).toLocaleString()
}

// "3 min ago" -- for "last checked" lines that should read at a glance.
export const timeAgo = (value: string | null): string => {
  if (!value) return 'never'
  const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000)
  if (seconds < 45) return 'just now'
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} h ago`
  return formatTimestamp(value)
}

// A failed request as something an admin can act on: the app's own error
// when it sent one, otherwise what actually happened (a timeout, a gateway
// error) rather than a vague "didn't respond".
export const requestError = (error: any, action: string): string => {
  const data = error?.response?.data
  if (typeof data?.error === 'string') return data.error
  if (typeof data?.message === 'string' && data.message !== '') return `${action} failed: ${data.message}`
  if (error?.code === 'ECONNABORTED') return `${action} timed out. Square may be slow -- try again in a moment.`
  const status = error?.response?.status
  return status ? `${action} failed (HTTP ${status}). Try again in a moment.` : `${action} failed -- the server couldn't be reached.`
}

// Long enough for a slow Square, short enough that a stuck request
// doesn't leave a panel spinning forever.
export const REQUEST_TIMEOUT_MS = 60000
