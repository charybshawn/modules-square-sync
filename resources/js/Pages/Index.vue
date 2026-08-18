<template>
  <div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
      <div class="md:flex md:items-center md:justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Square Sync</h1>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Catalog and inventory agreement between this app and Square.
          </p>
        </div>
        <div class="mt-4 md:mt-0">
          <button
            type="button"
            @click="runSyncCheck"
            :disabled="syncForm.processing"
            class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
          >
            {{ syncForm.processing ? 'Running…' : 'Run Sync Check' }}
          </button>
        </div>
      </div>

      <div v-if="$page.props.flash?.success" class="rounded-md bg-green-50 dark:bg-green-900/20 p-4">
        <p class="text-sm font-medium text-green-800 dark:text-green-200 whitespace-pre-line">{{ $page.props.flash.success }}</p>
      </div>

      <!-- Connection status -->
      <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Connection</h2>
        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Environment</dt>
            <dd class="mt-1">
              <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 capitalize">
                {{ connection.environment }}
              </span>
            </dd>
          </div>
          <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Access Token</dt>
            <dd class="mt-1">
              <StatusBadge :ok="connection.access_token_configured" true-label="Configured" false-label="Missing" />
            </dd>
          </div>
        </dl>

        <!-- Sync location: which Square location this app pushes/pulls inventory for. -->
        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
          <dt class="text-sm text-gray-500 dark:text-gray-400">Sync Location</dt>

          <div v-if="!connection.access_token_configured" class="mt-2">
            <StatusBadge :ok="false" true-label="Configured" false-label="Missing" />
            <p class="mt-2 text-sm text-amber-700 dark:text-amber-400">
              Set SQUARE_ACCESS_TOKEN before choosing a sync location.
            </p>
          </div>

          <div v-else-if="connection.locations.length === 0" class="mt-2">
            <StatusBadge :ok="false" true-label="Configured" false-label="Unavailable" />
            <p class="mt-2 text-sm text-amber-700 dark:text-amber-400">
              Square locations could not be loaded. Check the access token, or try again shortly if Square is temporarily unreachable.
            </p>
          </div>

          <div v-else class="mt-2 space-y-2">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
              <select
                v-model="selectedLocationId"
                class="block w-full max-w-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="" disabled>Select a location…</option>
                <option v-for="location in connection.locations" :key="location.id" :value="location.id">
                  {{ location.name }} ({{ location.id }}){{ location.status === 'INACTIVE' ? ' — inactive' : '' }}
                </option>
              </select>
              <button
                type="button"
                @click="saveLocation"
                :disabled="locationForm.processing || !selectedLocationId || selectedLocationId === connection.selected_location_id"
                class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
              >
                {{ locationForm.processing ? 'Saving…' : 'Save' }}
              </button>
            </div>
            <p v-if="locationForm.errors.location_id" class="text-sm text-red-600 dark:text-red-400">
              {{ locationForm.errors.location_id }}
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
              <span v-if="connection.location_source === 'env'">Currently set via SQUARE_LOCATION_ID in .env. Saving here will take over.</span>
              <span v-else-if="connection.location_source === 'setting'">Currently set in-app.</span>
              <span v-else>No location selected yet.</span>
            </p>
          </div>
        </div>
      </div>

      <!-- Linked products -->
      <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-lg font-medium text-gray-900 dark:text-white">Linked Products</h2>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Local products currently mapped to a Square catalog object.</p>
        </div>
        <DataTable
          :columns="mappingColumns"
          :items="mappings.data"
          empty-message="No products linked to Square yet."
          table-id="square-sync-mappings"
          item-key="id"
        >
          <template #cell-product="{ item }">
            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.product_title ?? '(deleted product)' }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ item.product_sku ?? '—' }}</div>
          </template>

          <template #cell-square_object_id="{ item }">
            <code class="text-xs text-gray-600 dark:text-gray-300">{{ item.square_object_id }}</code>
          </template>

          <template #cell-sync_status="{ item }">
            <span :class="['inline-flex px-2 py-1 text-xs font-medium rounded-full', syncStatusClass(item.sync_status)]">
              {{ item.sync_status }}
            </span>
          </template>

          <template #cell-last_pushed_at="{ item }">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ formatTimestamp(item.last_pushed_at) }}</span>
          </template>

          <template #cell-last_pulled_at="{ item }">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ formatTimestamp(item.last_pulled_at) }}</span>
          </template>

          <template #cell-actions="{ item }">
            <button
              type="button"
              @click="unlinkMapping(item)"
              class="text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:underline"
            >
              Unlink
            </button>
          </template>
        </DataTable>
        <Pagination :links="mappings?.meta?.links" :meta="mappings?.meta" @navigate="changeMappingsPage" />
      </div>

      <!-- Unmapped products -->
      <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-lg font-medium text-gray-900 dark:text-white">Unmapped Products</h2>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Local products with no Square mapping yet. Link them by running <code class="text-xs">php artisan square:pull-catalog</code>,
            which matches by SKU.
          </p>
        </div>
        <div v-if="unmappedProducts.items.length === 0" class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400 text-center">
          Every product is linked to Square.
        </div>
        <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700">
          <li v-for="product in unmappedProducts.items" :key="product.id" class="px-6 py-3 flex items-center justify-between">
            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ product.title }}</span>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ product.sku ?? '—' }}</span>
          </li>
        </ul>
        <p v-if="unmappedProducts.total > unmappedProducts.items.length" class="px-6 py-3 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700">
          Showing {{ unmappedProducts.items.length }} of {{ unmappedProducts.total }}.
        </p>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Drift -->
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Drift</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Recent stock disagreements found between local and Square.</p>
          </div>
          <div v-if="driftEvents.length === 0" class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400 text-center">
            No drift detected recently.
          </div>
          <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700">
            <li v-for="event in driftEvents" :key="event.id" class="px-6 py-3">
              <div class="flex items-start justify-between gap-4">
                <p class="text-sm text-gray-900 dark:text-white">{{ event.description }}</p>
                <SeverityBadge :severity="event.severity" />
              </div>
              <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ formatTimestamp(event.created_at) }}</p>
            </li>
          </ul>
        </div>

        <!-- Recent activity -->
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Recent Sync Activity</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Each group is one sync run.</p>
          </div>
          <div v-if="recentActivity.length === 0" class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400 text-center">
            No Square activity recorded yet.
          </div>
          <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700">
            <li v-for="(group, index) in recentActivity" :key="group.correlation_id ?? `single-${index}`" class="px-6 py-3">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ formatTimestamp(group.started_at) }}</p>
              <ul class="mt-1 space-y-1">
                <li
                  v-for="event in group.events"
                  :key="event.id"
                  :style="{ marginLeft: `${(event.depth ?? 0) * 1}rem` }"
                  class="flex items-center gap-2"
                >
                  <SeverityBadge :severity="event.severity" small />
                  <span class="text-sm text-gray-900 dark:text-white">{{ event.type_label }}</span>
                  <span v-if="event.description" class="text-xs text-gray-500 dark:text-gray-400 truncate">— {{ event.description }}</span>
                </li>
              </ul>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Admin/DataTable.vue'
import Pagination from '@/Components/Admin/Pagination.vue'
import StatusBadge from './Shared/StatusBadge.vue'
import SeverityBadge from './Shared/SeverityBadge.vue'

defineOptions({ layout: AdminLayout })

interface SquareLocation {
  id: string
  name: string
  status: string
  address: string | null
}

interface ConnectionStatus {
  access_token_configured: boolean
  location_id_configured: boolean
  configured: boolean
  environment: string
  locations: SquareLocation[]
  selected_location_id: string | null
  location_source: 'setting' | 'env' | null
}

interface MappingRow {
  id: number
  product_id: number
  product_title: string | null
  product_sku: string | null
  square_object_id: string
  square_object_type: string
  sync_status: 'linked' | 'pending' | 'conflict' | 'orphaned'
  last_pushed_at: string | null
  last_pulled_at: string | null
}

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface PaginationMeta {
  from: number | null
  to: number | null
  total: number
  current_page: number
  last_page: number
  per_page: number
  links?: PaginationLink[]
}

interface Paginated<T> {
  data: T[]
  meta: PaginationMeta
}

interface UnmappedProduct {
  id: number
  title: string
  sku: string | null
}

interface UnmappedProducts {
  items: UnmappedProduct[]
  total: number
}

interface SquareEventRow {
  id: number
  type: string
  type_label: string
  description: string | null
  severity: 'info' | 'warning' | 'error'
  direction: 'inbound' | 'outbound' | 'internal'
  created_at: string | null
  depth?: number
}

interface ActivityGroup {
  correlation_id: string | null
  started_at: string | null
  events: SquareEventRow[]
}

interface Props {
  connection: ConnectionStatus
  mappings: Paginated<MappingRow>
  unmappedProducts: UnmappedProducts
  driftEvents: SquareEventRow[]
  recentActivity: ActivityGroup[]
}

const props = defineProps<Props>()

// Local, since the connection.selected_location_id prop only reflects
// what's actually saved -- the <select> needs its own state to let an
// admin pick a different location before clicking Save. Synced back in
// the watcher below so a fresh page load (or another tab's save) doesn't
// leave this stale.
const selectedLocationId = ref(props.connection.selected_location_id ?? '')

watch(
  () => props.connection.selected_location_id,
  (value) => {
    selectedLocationId.value = value ?? ''
  },
)

const locationForm = useForm({
  location_id: '',
})

const saveLocation = () => {
  locationForm.location_id = selectedLocationId.value
  locationForm.post(route('admin.square.location'), { preserveScroll: true })
}

const mappingColumns: Column[] = [
  { key: 'product', label: 'Product' },
  { key: 'square_object_id', label: 'Square Object' },
  { key: 'sync_status', label: 'Status', hideable: true },
  { key: 'last_pushed_at', label: 'Last Pushed', hideable: true },
  { key: 'last_pulled_at', label: 'Last Pulled', hideable: true },
  { key: 'actions', label: '' },
]

const syncStatusClass = (status: MappingRow['sync_status']): string => {
  return {
    linked: 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200',
    pending: 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200',
    conflict: 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
    orphaned: 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200',
  }[status] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200'
}

const formatTimestamp = (value: string | null): string => {
  if (!value) return 'Never'
  return new Date(value).toLocaleString()
}

const changeMappingsPage = (url: string | null) => {
  if (url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true })
  }
}

const unlinkMapping = (item: MappingRow) => {
  const label = item.product_title ?? item.square_object_id
  if (confirm(`Unlink '${label}' from Square? Its sync history is kept, and it can be relinked later.`)) {
    router.post(route('admin.square.unlink', item.id), {}, { preserveScroll: true })
  }
}

const syncForm = useForm({})

const runSyncCheck = () => {
  syncForm.post(route('admin.square.sync'), { preserveScroll: true })
}
</script>
