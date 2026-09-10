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
        <!-- One "Actions" menu, not a growing row of buttons -- matches
             DataTable's own Columns-dropdown pattern for consistency, and
             scales cleanly as more maintenance actions get added here. -->
        <div class="mt-4 md:mt-0 relative" ref="actionsMenuRef">
          <button
            type="button"
            @click="showActionsMenu = !showActionsMenu"
            :disabled="runningAction !== null"
            class="tap-target-touch inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
          >
            {{ actionsButtonLabel }}
            <svg class="w-4 h-4 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <div
            v-if="showActionsMenu"
            class="absolute right-0 z-10 mt-1 w-72 bg-white dark:bg-gray-700 rounded-md shadow-lg ring-1 ring-black ring-opacity-5 dark:ring-white/10 py-1"
          >
            <button type="button" @click="runSyncCheck" class="w-full text-left px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">
              <span class="block text-sm font-medium text-gray-900 dark:text-white">Run Sync Check</span>
              <span class="block text-xs text-gray-500 dark:text-gray-400">Compare local stock against Square, then choose how to resolve any drift</span>
            </button>
          </div>
        </div>
      </div>

      <div v-if="$page.props.flash?.success" class="rounded-md bg-green-50 dark:bg-green-900/20 p-4">
        <p class="text-sm font-medium text-green-800 dark:text-green-200 whitespace-pre-line">{{ $page.props.flash.success }}</p>
      </div>

      <div v-if="$page.props.flash?.warning" class="rounded-md bg-amber-50 dark:bg-amber-900/20 p-4">
        <p class="text-sm font-medium text-amber-800 dark:text-amber-200 whitespace-pre-line">{{ $page.props.flash.warning }}</p>
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
                class="tap-target-touch inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
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
              class="tap-target-touch inline-flex items-center text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:underline"
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
            Local products with no Square mapping yet. Link them from the catalog panel below.
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

      <!-- Manual catalog linking -->
      <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Link Square Catalog</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
              Square catalog items with no local mapping yet. Pick the matching product and link them by hand.
            </p>
          </div>
          <button
            type="button"
            @click="downloadCatalog"
            :disabled="catalogLoading"
            class="tap-target-touch shrink-0 inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
          >
            {{ catalogLoading ? 'Downloading…' : (catalogItems === null ? 'Download Catalog' : 'Refresh Catalog') }}
          </button>
        </div>

        <p v-if="catalogError" class="px-6 py-3 text-sm text-red-600 dark:text-red-400">{{ catalogError }}</p>
        <p v-if="linkPreviewError" class="px-6 py-3 text-sm text-red-600 dark:text-red-400">{{ linkPreviewError }}</p>

        <div v-if="catalogItems === null && !catalogLoading" class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400 text-center">
          Not downloaded yet -- click "Download Catalog" to fetch unlinked Square items.
        </div>

        <div v-else-if="catalogItems && catalogItems.length === 0" class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400 text-center">
          Every Square catalog item is already linked.
        </div>

        <ul v-else-if="catalogItems" class="divide-y divide-gray-200 dark:divide-gray-700">
          <li
            v-for="catalogItem in catalogItems"
            :key="catalogItem.square_object_id"
            class="px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4"
          >
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ catalogItem.name }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ catalogItem.sku ?? 'no SKU' }}</div>
            </div>
            <div class="flex items-center gap-2">
              <select
                v-model="linkSelections[catalogItem.square_object_id]"
                class="block w-56 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select a product…</option>
                <option v-for="product in unmappedProducts.items" :key="product.id" :value="product.id">
                  {{ product.title }}{{ product.sku ? ` (${product.sku})` : '' }}
                </option>
              </select>
              <button
                type="button"
                @click="linkCatalogItem(catalogItem)"
                :disabled="!linkSelections[catalogItem.square_object_id] || linkForm.processing || linkPreviewLoading"
                class="inline-flex items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
              >
                {{ linkPreviewLoading ? 'Checking…' : 'Link' }}
              </button>
            </div>
          </li>
        </ul>
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

    <Modal :show="showLinkSourceModal" max-width="lg" @close="cancelLinkSource">
      <div v-if="linkPreviewData && linkPreviewCatalogItem" class="p-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Which inventory is correct?</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
          Linking "{{ linkPreviewCatalogItem.name }}" for the first time. Pick which count is the real one right now — the other side gets overwritten to match.
        </p>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <button
            type="button"
            @click="chooseInventorySource('local')"
            class="text-left rounded-lg border-2 border-gray-300 dark:border-gray-600 p-4 hover:border-indigo-500 dark:hover:border-indigo-400 transition-colors"
          >
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Local App</div>
            <div class="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{{ linkPreviewData.local_quantity }}</div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Use this — pushes {{ linkPreviewData.local_quantity }} to Square.</div>
          </button>
          <button
            type="button"
            @click="chooseInventorySource('square')"
            class="text-left rounded-lg border-2 border-gray-300 dark:border-gray-600 p-4 hover:border-indigo-500 dark:hover:border-indigo-400 transition-colors"
          >
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Square</div>
            <div class="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{{ linkPreviewData.square_quantity }}</div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Use this — sets local stock to {{ linkPreviewData.square_quantity }}.</div>
          </button>
        </div>

        <div class="mt-6 flex justify-end">
          <button
            type="button"
            @click="cancelLinkSource"
            class="px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-50 dark:hover:bg-gray-600"
          >
            Cancel
          </button>
        </div>
      </div>
    </Modal>

    <Modal :show="showSyncResultModal" max-width="2xl" @close="closeSyncResultModal">
      <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Sync Check Results</h2>

        <p v-if="syncResultError" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ syncResultError }}</p>

        <template v-else-if="syncResult">
          <p v-if="syncResult.checked === 0" class="mt-3 text-sm text-gray-600 dark:text-gray-400">
            No linked Square product mappings to reconcile.
          </p>
          <p v-else-if="syncResult.drifted === 0" class="mt-3 text-sm text-gray-600 dark:text-gray-400">
            No drift detected — local stock matches Square for every linked product ({{ syncResult.checked }} checked).
          </p>
          <template v-else>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
              {{ syncResult.drifted }} of {{ syncResult.checked }} linked product(s) drifted from Square. Pick which count is correct for each — the other side gets overwritten to match. Rows you leave alone are untouched.
            </p>

            <div class="mt-4 overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-md">
              <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Square</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Local</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Diff</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Resolve</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                  <tr v-for="row in syncResult.rows" :key="row.product_id">
                    <td class="px-4 py-2 text-sm text-gray-900 dark:text-white">
                      {{ row.product_title }}
                      <span v-if="row.sku" class="block text-xs text-gray-500 dark:text-gray-400">{{ row.sku }}</span>
                    </td>
                    <td class="px-4 py-2 text-sm text-right text-gray-900 dark:text-white">{{ row.square_quantity }}</td>
                    <td class="px-4 py-2 text-sm text-right text-gray-900 dark:text-white">{{ row.local_quantity }}</td>
                    <td
                      class="px-4 py-2 text-sm text-right"
                      :class="row.difference >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                    >
                      {{ row.difference >= 0 ? '+' : '' }}{{ row.difference }}
                    </td>
                    <td class="px-4 py-2 text-xs">
                      <span v-if="resolvedRows[row.product_id] === 'local'" class="text-green-600 dark:text-green-400">
                        Resolved — Local ({{ row.local_quantity }})
                      </span>
                      <span v-else-if="resolvedRows[row.product_id] === 'square'" class="text-green-600 dark:text-green-400">
                        Resolved — Square ({{ row.square_quantity }})
                      </span>
                      <div v-else class="flex gap-1">
                        <button
                          type="button"
                          :disabled="resolvingProductIds.has(row.product_id)"
                          @click="resolveDriftRow(row, 'local')"
                          class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-200 hover:border-indigo-500 dark:hover:border-indigo-400 disabled:opacity-50"
                        >
                          Use Local
                        </button>
                        <button
                          type="button"
                          :disabled="resolvingProductIds.has(row.product_id)"
                          @click="resolveDriftRow(row, 'square')"
                          class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-200 hover:border-indigo-500 dark:hover:border-indigo-400 disabled:opacity-50"
                        >
                          Use Square
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </template>

        <div class="mt-6 flex justify-end">
          <button
            type="button"
            @click="closeSyncResultModal"
            class="px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-50 dark:hover:bg-gray-600"
          >
            Close
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
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

interface CatalogItemRow {
  square_object_id: string
  square_parent_object_id: string | null
  name: string
  sku: string | null
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

// One in-flight action at a time -- the trigger button's own label
// reflects which is running, so there's no separate spinner state to keep
// in sync per menu item.
const runningAction = ref<'sync' | null>(null)

const actionsButtonLabel = computed(() => (runningAction.value === 'sync' ? 'Checking…' : 'Actions'))

const showActionsMenu = ref(false)
const actionsMenuRef = ref<HTMLElement | null>(null)

const onClickOutsideActionsMenu = (event: MouseEvent) => {
  if (actionsMenuRef.value && !actionsMenuRef.value.contains(event.target as Node)) {
    showActionsMenu.value = false
  }
}

onMounted(() => document.addEventListener('click', onClickOutsideActionsMenu))
onBeforeUnmount(() => document.removeEventListener('click', onClickOutsideActionsMenu))

interface DriftRow {
  product_id: number
  product_title: string
  sku: string | null
  square_quantity: number
  local_quantity: number
  difference: number
  fix_attempted: boolean
  fix_applied: boolean
}

interface ReconcileResult {
  checked: number
  drifted: number
  corrected: number
  rows: DriftRow[]
}

// sync() returns real structured data (ReconcileInventoryDrift's result)
// rather than a redirect -- shown in a modal with an actual <table>, not
// the console command's ASCII table dumped into a flash message. Each
// drifted row can be resolved right there (see resolveDriftRow below)
// instead of a separate "Pull Inventory Now" that unilaterally trusts
// Square for everything at once.
const syncResult = ref<ReconcileResult | null>(null)
const syncResultError = ref<string | null>(null)
const showSyncResultModal = ref(false)

const closeSyncResultModal = () => {
  showSyncResultModal.value = false
  syncResult.value = null
  syncResultError.value = null
  resolvedRows.value = {}

  if (anyRowResolved.value) {
    anyRowResolved.value = false
    // Stock/mapping state may have changed for whichever rows got
    // resolved -- refresh the page's own data in the background.
    router.reload({ only: ['mappings', 'driftEvents', 'recentActivity'] })
  }
}

const runSyncCheck = async () => {
  showActionsMenu.value = false
  runningAction.value = 'sync'
  syncResultError.value = null

  try {
    const response = await axios.post(route('admin.square.sync'))
    syncResult.value = response.data
  } catch (error: any) {
    syncResultError.value = error?.response?.data?.error ?? 'Sync check failed.'
  } finally {
    runningAction.value = null
    showSyncResultModal.value = true
  }
}

// Downloaded catalog items live in local state, not an Inertia prop --
// walking the whole Square catalog is too heavy to redo on every prop
// refresh, so it's fetched once on demand and only ever updated by this
// panel's own actions (a fresh download, or splicing out an item just
// linked). null means "not downloaded yet", distinct from an empty array
// ("downloaded, nothing left to link").
const catalogItems = ref<CatalogItemRow[] | null>(null)
const catalogLoading = ref(false)
const catalogError = ref<string | null>(null)
const linkSelections = ref<Record<string, string>>({})

const downloadCatalog = async () => {
  catalogLoading.value = true
  catalogError.value = null
  try {
    const response = await axios.get(route('admin.square.catalog-items'))
    catalogItems.value = response.data.items
  } catch (error: any) {
    catalogError.value = error?.response?.data?.error ?? 'Failed to download the Square catalog.'
  } finally {
    catalogLoading.value = false
  }
}

const linkForm = useForm({
  product_id: '',
  square_object_id: '',
  square_parent_object_id: '',
  inventory_source: 'local' as 'local' | 'square',
})

interface LinkPreview {
  track_inventory: boolean
  local_quantity?: number
  square_quantity?: number
}

// Populated by linkCatalogItem's preview fetch below, held until the admin
// picks a source (or cancels) in the modal.
const linkPreviewCatalogItem = ref<CatalogItemRow | null>(null)
const linkPreviewData = ref<LinkPreview | null>(null)
const linkPreviewLoading = ref(false)
const linkPreviewError = ref<string | null>(null)
const showLinkSourceModal = ref(false)

const finishLink = (catalogItem: CatalogItemRow) => {
  // unmappedProducts and mappings refresh automatically as part of the
  // Inertia response; catalogItems is local state, so the linked row
  // is removed here to match.
  catalogItems.value = catalogItems.value?.filter((item) => item.square_object_id !== catalogItem.square_object_id) ?? null
  delete linkSelections.value[catalogItem.square_object_id]
  linkPreviewCatalogItem.value = null
  linkPreviewData.value = null
}

const submitLink = (catalogItem: CatalogItemRow, source: 'local' | 'square') => {
  linkForm.inventory_source = source
  linkForm.post(route('admin.square.link'), {
    preserveScroll: true,
    onSuccess: () => finishLink(catalogItem),
  })
}

// Read-only dry run before anything is actually linked: shows local vs.
// Square's live count so the admin can see what each choice would set
// stock to, rather than committing blind. A product that doesn't track
// inventory has no such choice to make -- link straight through.
const linkCatalogItem = async (catalogItem: CatalogItemRow) => {
  const productId = linkSelections.value[catalogItem.square_object_id]
  if (!productId) return

  linkForm.product_id = productId
  linkForm.square_object_id = catalogItem.square_object_id
  linkForm.square_parent_object_id = catalogItem.square_parent_object_id ?? ''

  linkPreviewLoading.value = true
  linkPreviewError.value = null

  try {
    const response = await axios.get(route('admin.square.link-preview'), {
      params: { product_id: productId, square_object_id: catalogItem.square_object_id },
    })
    const preview: LinkPreview = response.data

    if (!preview.track_inventory) {
      submitLink(catalogItem, 'local')
      return
    }

    linkPreviewData.value = preview
    linkPreviewCatalogItem.value = catalogItem
    showLinkSourceModal.value = true
  } catch (error: any) {
    linkPreviewError.value = error?.response?.data?.error ?? 'Failed to check inventory counts.'
  } finally {
    linkPreviewLoading.value = false
  }
}

const chooseInventorySource = (source: 'local' | 'square') => {
  if (!linkPreviewCatalogItem.value) return
  showLinkSourceModal.value = false
  submitLink(linkPreviewCatalogItem.value, source)
}

const cancelLinkSource = () => {
  showLinkSourceModal.value = false
  linkPreviewCatalogItem.value = null
  linkPreviewData.value = null
}

// Per-row resolve state for the sync-check results table below -- rather
// than a separate "Pull Inventory Now" that unilaterally trusts Square for
// every drifted product, the same results table lets the admin resolve
// each row individually (or leave any of them alone). Keyed by product_id
// so several rows can be in flight independently.
const resolvingProductIds = ref<Set<number>>(new Set())
const resolvedRows = ref<Record<number, 'local' | 'square'>>({})
const anyRowResolved = ref(false)

const resolveDriftRow = async (row: DriftRow, source: 'local' | 'square') => {
  if (resolvingProductIds.value.has(row.product_id)) return

  resolvingProductIds.value.add(row.product_id)
  try {
    await axios.post(route('admin.square.resolve-drift'), {
      product_id: row.product_id,
      source,
    })
    resolvedRows.value[row.product_id] = source
    anyRowResolved.value = true
  } catch (error: any) {
    syncResultError.value = error?.response?.data?.error ?? `Failed to resolve '${row.product_title}'.`
  } finally {
    resolvingProductIds.value.delete(row.product_id)
  }
}
</script>
