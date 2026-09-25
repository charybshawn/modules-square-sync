<template>
  <div class="pb-6 md:pt-6">
    <AdminMobileHeader title="Square Sync" />

    <!-- Desktop title block + labelled actions. Mobile gets the same
         actions as ActionShelf icons below instead. -->
    <div class="hidden md:flex md:items-start md:justify-between md:gap-6 mb-6">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Square Sync</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
          Local stock is the source of truth. Square sales come back in and are recorded as orders.
        </p>
      </div>
      <div class="flex shrink-0 flex-wrap gap-2">
        <button
          type="button"
          :disabled="syncChecking"
          class="tap-target-touch inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
          @click="runSyncCheck"
        >
          {{ syncChecking ? 'Checking…' : 'Run Sync Check' }}
        </button>
        <button
          type="button"
          :disabled="catalogLoading"
          class="tap-target-touch inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50"
          @click="openCatalog"
        >
          Link Catalog Items
        </button>
      </div>
    </div>

    <!-- Mobile: icon shelf over the headline stats, same pattern as the
         host's dashboard/index pages. -->
    <ActionShelf class="md:hidden" overlay="always">
      <ShelfAction icon="check" label="Run sync check" @click="runSyncCheck" />
      <ShelfAction icon="download" label="Link catalog items" @click="openCatalog" />
      <ShelfAction icon="alert" label="Drift reports" target="square-drift" :count="driftEvents.length" attention />
      <ShelfAction icon="clock" label="Sync activity" target="square-activity" />
    </ActionShelf>

    <StatHero class="md:hidden -mt-4" :headline="heroHeadline" :stats="heroStats" />
    <hr class="md:hidden border-gray-200 dark:border-gray-700" />

    <dl class="hidden md:grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div
        v-for="stat in [heroHeadline, ...heroStats]"
        :key="stat.label"
        class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5"
      >
        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ stat.label }}</dt>
        <dd class="mt-1 text-2xl font-semibold" :class="TONES[stat.tone ?? 'default']">{{ stat.value }}</dd>
        <dd v-if="stat.hint" class="text-sm" :class="stat.hintTone && stat.hintTone !== 'default' ? TONES[stat.hintTone] : 'text-gray-500 dark:text-gray-400'">{{ stat.hint }}</dd>
      </div>
    </dl>

    <div class="space-y-4 md:space-y-6">
      <div v-if="!connection.configured" class="px-4 pt-4 md:p-0">
        <AdminAlert level="warning" title="Square isn't fully set up">
          <span v-if="!connection.access_token_configured">Set SQUARE_ACCESS_TOKEN in .env, then choose a sync location below.</span>
          <span v-else>Choose a sync location below. Nothing syncs until one is set.</span>
        </AdminAlert>
      </div>

      <!-- Connection -->
      <section :class="SECTION">
        <div :class="SECTION_HEADER">
          <h2 class="text-lg font-medium text-gray-900 dark:text-white">Connection</h2>
        </div>
        <dl class="divide-y divide-gray-100 dark:divide-gray-700 border-t border-gray-100 dark:border-gray-700">
          <div class="flex items-center justify-between gap-4 px-4 md:px-6 py-3">
            <dt class="text-sm text-gray-500 dark:text-gray-400">Environment</dt>
            <dd>
              <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 capitalize">
                {{ connection.environment }}
              </span>
            </dd>
          </div>
          <div class="flex items-center justify-between gap-4 px-4 md:px-6 py-3">
            <dt class="text-sm text-gray-500 dark:text-gray-400">Access token</dt>
            <dd><StatusBadge :ok="connection.access_token_configured" true-label="Configured" false-label="Missing" /></dd>
          </div>
          <div class="px-4 md:px-6 py-4">
            <dt>
              <InputLabel for="square-location" value="Sync location" />
            </dt>
            <dd class="mt-1.5">
              <p v-if="!connection.access_token_configured" class="text-sm text-gray-500 dark:text-gray-400">
                Available once the access token is set.
              </p>
              <p v-else-if="connection.locations.length === 0" class="text-sm text-amber-700 dark:text-amber-400">
                Square locations couldn't be loaded. Check the access token, or try again shortly if Square is temporarily unreachable.
              </p>
              <template v-else>
                <select
                  id="square-location"
                  v-model="selectedLocationId"
                  :disabled="locationForm.processing"
                  class="block w-full md:max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-base sm:text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-50"
                  @change="onLocationChange"
                >
                  <option value="" disabled>Select a location…</option>
                  <option v-for="location in connection.locations" :key="location.id" :value="location.id">
                    {{ location.name }}{{ location.status === 'INACTIVE' ? ' (inactive)' : '' }}
                  </option>
                </select>
                <InputError class="mt-1.5" :message="locationForm.errors.location_id" />
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                  <template v-if="locationForm.processing">Saving…</template>
                  <template v-else-if="connection.location_source === 'env'">Set by SQUARE_LOCATION_ID in .env. Choosing one here takes over.</template>
                  <template v-else>Stock pushes and Square sales use this location. Saves when changed.</template>
                </p>
              </template>
            </dd>
          </div>
        </dl>
      </section>

      <!-- Linked products. No overflow-hidden: it breaks DataTable's sticky toolbar. -->
      <section :class="SECTION">
        <div :class="SECTION_HEADER">
          <h2 class="text-lg font-medium text-gray-900 dark:text-white">Linked products</h2>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Local products mapped to a Square catalog item.</p>
        </div>
        <DataTable
          :columns="mappingColumns"
          :items="mappings.data"
          :actions="mappingActions"
          empty-message="No products linked to Square yet."
          table-id="square-sync-mappings"
          item-key="id"
          mobile-row-style="line"
          hide-toolbar
          @action="handleMappingAction"
        >
          <template #mobile-card="{ item }">
            <div class="flex items-center gap-3 min-w-0">
              <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">
                {{ item.product_title ?? '(deleted product)' }}
              </span>
              <span :class="['shrink-0 inline-flex px-2 py-0.5 text-xs font-medium rounded-full capitalize', syncStatusClass(item.sync_status)]">
                {{ item.sync_status }}
              </span>
            </div>
          </template>

          <template #cell-product="{ item }">
            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.product_title ?? '(deleted product)' }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ item.product_sku ?? '—' }}</div>
          </template>

          <template #cell-sync_status="{ item }">
            <span :class="['inline-flex px-2 py-1 text-xs font-medium rounded-full capitalize', syncStatusClass(item.sync_status)]">
              {{ item.sync_status }}
            </span>
          </template>

          <template #cell-square_object_id="{ item }">
            <code class="text-xs text-gray-600 dark:text-gray-300 break-all">{{ item.square_object_id }}</code>
          </template>

          <template #cell-last_pushed_at="{ item }">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ formatTimestamp(item.last_pushed_at) }}</span>
          </template>

          <template #cell-last_pulled_at="{ item }">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ formatTimestamp(item.last_pulled_at) }}</span>
          </template>
        </DataTable>
        <Pagination v-if="mappings.meta.last_page > 1" :links="mappings.meta.links" :meta="mappings.meta" @navigate="changeMappingsPage" />
      </section>

      <!-- Link Square catalog items to local products by hand. -->
      <section id="square-link-catalog" :class="[SECTION, 'scroll-mt-40 md:scroll-mt-20']">
        <div :class="[SECTION_HEADER, 'flex items-start justify-between gap-4']">
          <div class="min-w-0">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Link catalog items</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
              Square items with no local product yet. Pick the matching product for each.
            </p>
          </div>
          <button
            v-if="catalogItems !== null"
            type="button"
            :disabled="catalogLoading"
            class="tap-target-touch shrink-0 inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
            @click="downloadCatalog"
          >
            {{ catalogLoading ? 'Refreshing…' : 'Refresh' }}
          </button>
        </div>

        <div v-if="catalogError || linkError" class="px-4 md:px-6 pb-4">
          <AdminAlert level="error" title="Square request failed">
            {{ catalogError ?? linkError }} This isn't something you did wrong -- try again in a moment.
          </AdminAlert>
        </div>

        <div v-if="catalogItems === null" class="px-4 md:px-6 pb-6">
          <p class="text-sm text-gray-500 dark:text-gray-400">
            The catalog is fetched from Square on demand.
          </p>
          <button
            type="button"
            :disabled="catalogLoading"
            class="tap-target-touch mt-3 w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50"
            @click="downloadCatalog"
          >
            <svg v-if="catalogLoading" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            {{ catalogLoading ? 'Downloading catalog…' : 'Download Catalog' }}
          </button>
        </div>

        <p v-else-if="catalogItems.length === 0" class="px-4 md:px-6 pb-6 text-sm text-gray-500 dark:text-gray-400">
          Every Square catalog item is already linked.
        </p>

<!-- Searchable, filterable, sortable in the browser: the whole
             unlinked catalog is already loaded. No overflow-hidden on the
             section, or DataTable's sticky toolbar stops sticking. -->
        <DataTable
          v-else
          :columns="catalogColumns"
          :items="sortedCatalogRows"
          :sort-field="catalogSort.field"
          :sort-direction="catalogSort.direction"
          searchable
          search-placeholder="Search name or SKU…"
          empty-message="No Square items match."
          table-id="square-sync-catalog"
          item-key="square_object_id"
          mobile-row-style="flat"
          :extra-filter-count="catalogCategory !== '' ? 1 : 0"
          @sort="sortCatalog"
          @clear-filters="catalogCategory = ''"
        >
          <template #filters-extra>
            <div>
              <label for="square-catalog-category" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Category</label>
              <select
                id="square-catalog-category"
                v-model="catalogCategory"
                class="w-full text-base sm:text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Any</option>
                <option v-for="category in catalogCategories" :key="category" :value="category">{{ category }}</option>
                <option v-if="hasUncategorized" :value="UNCATEGORIZED">No category</option>
              </select>
            </div>
          </template>

          <template #mobile-card="{ item }">
            <div class="space-y-2.5">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-900 dark:text-white">{{ item.name }}</p>
                  <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ item.sku ?? 'No SKU' }}<template v-if="item.price !== null"> · {{ formatPrice(item.price, item.currency) }}</template>
                  </p>
                  <p v-if="item.category" class="text-xs text-gray-500 dark:text-gray-400">{{ item.category }}</p>
                </div>
                <CatalogBadges :archived="item.archived" :suggested="item.suggested_product_id !== null" />
              </div>
              <CatalogLinkControl
                v-model="linkSelections[item.square_object_id]"
                :item-id="item.square_object_id"
                :item-name="item.name"
                :products="unmappedProducts.items"
                :disabled="linkForm.processing || linkingId !== null"
                :busy="linkingId === item.square_object_id"
                @link="linkCatalogItem(item)"
              />
            </div>
          </template>

          <template #cell-name="{ item }">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-medium text-gray-900 dark:text-white">{{ item.name }}</span>
              <CatalogBadges :archived="item.archived" :suggested="item.suggested_product_id !== null" />
            </div>
          </template>

          <template #cell-category="{ item }">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ item.category || '—' }}</span>
          </template>

          <template #cell-sku="{ item }">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ item.sku ?? '—' }}</span>
          </template>

          <template #cell-price="{ item }">
            <span class="text-sm text-gray-900 dark:text-white">{{ item.price !== null ? formatPrice(item.price, item.currency) : 'Variable' }}</span>
          </template>

          <template #cell-link="{ item }">
            <CatalogLinkControl
              v-model="linkSelections[item.square_object_id]"
              class="min-w-[18rem]"
              :item-id="item.square_object_id"
              :item-name="item.name"
              :products="unmappedProducts.items"
              :disabled="linkForm.processing || linkingId !== null"
              :busy="linkingId === item.square_object_id"
              @link="linkCatalogItem(item)"
            />
          </template>
        </DataTable>

        <!-- The local side of the same job: products still waiting for a
             Square item. Collapsed by default -- it's reference, and the
             product pickers above already list them. -->
        <div v-if="unmappedProducts.total > 0" class="border-t border-gray-100 dark:border-gray-700">
          <button
            type="button"
            class="tap-target-touch w-full flex items-center justify-between gap-3 px-4 md:px-6 py-3 text-left text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
            :aria-expanded="showUnmapped"
            @click="showUnmapped = !showUnmapped"
          >
            <span>{{ unmappedProducts.total }} local {{ unmappedProducts.total === 1 ? 'product isn’t' : 'products aren’t' }} linked yet</span>
            <svg class="w-4 h-4 shrink-0 text-gray-400 transition-transform" :class="{ 'rotate-180': showUnmapped }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <ul v-if="showUnmapped" class="divide-y divide-gray-100 dark:divide-gray-700 border-t border-gray-100 dark:border-gray-700">
            <li v-for="product in unmappedProducts.items" :key="product.id" class="px-4 md:px-6 py-2.5 flex items-center justify-between gap-3">
              <span class="min-w-0 truncate text-sm text-gray-900 dark:text-white">{{ product.title }}</span>
              <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ product.sku ?? '—' }}</span>
            </li>
            <li v-if="unmappedProducts.total > unmappedProducts.items.length" class="px-4 md:px-6 py-2.5 text-xs text-gray-500 dark:text-gray-400">
              Showing {{ unmappedProducts.items.length }} of {{ unmappedProducts.total }}.
            </li>
          </ul>
        </div>
      </section>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6">
        <!-- Drift -->
        <section id="square-drift" :class="[SECTION, 'scroll-mt-40 md:scroll-mt-20']">
          <div :class="SECTION_HEADER">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Drift</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Recent sync checks where Square's count didn't match local stock.</p>
          </div>
          <p v-if="driftEvents.length === 0" class="px-4 md:px-6 pb-6 text-sm text-gray-500 dark:text-gray-400">
            No drift found recently.
          </p>
          <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700 border-t border-gray-100 dark:border-gray-700">
            <li v-for="event in driftEvents" :key="event.id" class="px-4 md:px-6 py-3">
              <div class="flex items-start justify-between gap-3">
                <p class="min-w-0 text-sm text-gray-900 dark:text-white">{{ event.description }}</p>
                <SeverityBadge :severity="event.severity" />
              </div>
              <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ formatTimestamp(event.created_at) }}</p>
            </li>
          </ul>
        </section>

        <!-- Recent activity -->
        <section id="square-activity" :class="[SECTION, 'scroll-mt-40 md:scroll-mt-20']">
          <div :class="SECTION_HEADER">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Recent sync activity</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Each group is one sync run.</p>
          </div>
          <p v-if="recentActivity.length === 0" class="px-4 md:px-6 pb-6 text-sm text-gray-500 dark:text-gray-400">
            No Square activity recorded yet.
          </p>
          <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700 border-t border-gray-100 dark:border-gray-700">
            <li v-for="(group, index) in recentActivity" :key="group.correlation_id ?? `single-${index}`" class="px-4 md:px-6 py-3">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ formatTimestamp(group.started_at) }}</p>
              <ul class="mt-1.5 space-y-1.5">
                <li
                  v-for="event in group.events"
                  :key="event.id"
                  :style="{ paddingLeft: `${Math.min(event.depth ?? 0, 3) * 0.75}rem` }"
                  class="flex items-start gap-2 min-w-0"
                >
                  <SeverityBadge class="mt-0.5 shrink-0" :severity="event.severity" small />
                  <div class="min-w-0">
                    <p class="text-sm text-gray-900 dark:text-white">{{ event.type_label }}</p>
                    <p v-if="event.description" class="text-xs text-gray-500 dark:text-gray-400 break-words">{{ event.description }}</p>
                  </div>
                </li>
              </ul>
            </li>
          </ul>
        </section>
      </div>
    </div>

    <!-- Sync check results: custom content (a list with per-row actions),
         so it's built on ResponsiveModal -- full-screen on mobile. -->
    <ResponsiveModal :show="showSyncResultModal" max-width="2xl" @close="closeSyncResultModal">
      <div class="flex h-full flex-col">
        <div class="px-4 sm:px-6 pt-5 pb-3">
          <h2 class="text-lg font-medium text-gray-900 dark:text-white">Sync check</h2>
          <p v-if="syncResult && syncResult.drifted > 0" class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ syncResult.drifted }} of {{ syncResult.checked }} linked {{ syncResult.checked === 1 ? 'product doesn’t' : 'products don’t' }} match Square.
            Local stock is the source of truth -- push it to overwrite Square's count. Rows you leave alone aren't changed.
          </p>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
          <div v-if="syncChecking" class="flex items-center gap-3 px-4 sm:px-6 py-8 text-sm text-gray-600 dark:text-gray-300">
            <svg class="animate-spin h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            Comparing linked products with Square…
          </div>

          <div v-else-if="syncResultError" class="px-4 sm:px-6 pb-4">
            <AdminAlert level="error" title="Sync check didn't finish">
              {{ syncResultError }} This isn't something you did wrong -- try again in a moment.
            </AdminAlert>
          </div>

          <template v-else-if="syncResult">
            <p v-if="syncResult.checked === 0" class="px-4 sm:px-6 pb-6 text-sm text-gray-600 dark:text-gray-400">
              No linked products to check yet.
            </p>
            <p v-else-if="syncResult.drifted === 0" class="px-4 sm:px-6 pb-6 text-sm text-gray-600 dark:text-gray-400">
              Everything matches -- local stock and Square agree for all {{ syncResult.checked }} linked products.
            </p>
            <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700 border-y border-gray-100 dark:border-gray-700">
              <li v-for="row in syncResult.rows" :key="row.product_id" class="px-4 sm:px-6 py-3 flex items-center gap-3">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ row.product_title }}</p>
                  <p class="text-xs text-gray-500 dark:text-gray-400">
                    Local <span class="font-medium text-gray-900 dark:text-white">{{ row.local_quantity }}</span>
                    · Square <span class="font-medium text-gray-900 dark:text-white">{{ row.square_quantity }}</span>
                    <span :class="row.difference >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                      ({{ row.difference >= 0 ? '+' : '' }}{{ row.difference }})
                    </span>
                  </p>
                </div>
                <span v-if="resolvedRows[row.product_id]" class="shrink-0 text-xs font-medium text-green-600 dark:text-green-400">Pushed</span>
                <button
                  v-else
                  type="button"
                  :disabled="resolvingProductIds.has(row.product_id) || pushingAll"
                  class="tap-target-touch shrink-0 inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
                  @click="resolveDriftRow(row)"
                >
                  {{ resolvingProductIds.has(row.product_id) ? 'Pushing…' : 'Push local' }}
                </button>
              </li>
            </ul>
          </template>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 px-4 sm:px-6 py-4 border-t border-gray-100 dark:border-gray-700">
          <button
            type="button"
            class="tap-target-touch inline-flex justify-center items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600"
            @click="closeSyncResultModal"
          >
            Close
          </button>
          <button
            v-if="unresolvedRows.length > 1"
            type="button"
            :disabled="pushingAll"
            class="tap-target-touch inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
            @click="pushAllRows"
          >
            {{ pushingAll ? 'Pushing…' : `Push All Local Counts (${unresolvedRows.length})` }}
          </button>
        </div>
      </div>
    </ResponsiveModal>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminMobileHeader from '@/Components/Admin/AdminMobileHeader.vue'
import ActionShelf from '@/Components/Admin/ActionShelf.vue'
import ShelfAction from '@/Components/Admin/ShelfAction.vue'
import StatHero, { type HeroStat, type StatTone } from '@/Components/Admin/StatHero.vue'
import AdminAlert from '@/Components/Admin/AdminAlert.vue'
import DataTable, { type Action, type Column } from '@/Components/Admin/DataTable.vue'
import Pagination from '@/Components/Admin/Pagination.vue'
import ResponsiveModal from '@/Components/ResponsiveModal.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import { useConfirmDialog } from '@/composables/useConfirmDialog'
import StatusBadge from './Shared/StatusBadge.vue'
import CatalogBadges from './Shared/CatalogBadges.vue'
import CatalogLinkControl from './Shared/CatalogLinkControl.vue'
import SeverityBadge from './Shared/SeverityBadge.vue'

defineOptions({ layout: (h: any, page: any) => h(AdminLayout, { hideBreadcrumbOnMobile: true }, () => page) })

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
  price: number | null
  currency: string | null
  archived: boolean
  categories: string[]
}

// A catalog item as the table shows it: the filterable columns (status,
// match) are derived here, since DataTable filters on plain row fields.
interface CatalogTableRow extends CatalogItemRow {
  status: 'Active' | 'Archived'
  // Joined for display and so DataTable's search also matches categories.
  category: string
  suggested_product_id: number | null
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

interface SyncSummary {
  sales_recorded: number
  refunds_recorded: number
  stock_changes_applied: number
  last_sale_at: string | null
}

interface Props {
  connection: ConnectionStatus
  mappings: Paginated<MappingRow>
  unmappedProducts: UnmappedProducts
  driftEvents: SquareEventRow[]
  recentActivity: ActivityGroup[]
  summary?: SyncSummary
}

// summary is newer than the rest of the page's props -- defaulted so the
// page still renders if it's ever published ahead of the PHP that sends
// it (a deploy that hasn't reloaded PHP yet), instead of crashing.
const props = withDefaults(defineProps<Props>(), {
  summary: () => ({ sales_recorded: 0, refunds_recorded: 0, stock_changes_applied: 0, last_sale_at: null }),
})

const { confirmDialog, askDialog } = useConfirmDialog()

// Full-bleed white on mobile, a card on desktop -- the host's dashboard
// section treatment (see the costing module's Dashboard).
const SECTION = 'bg-white dark:bg-gray-800 md:rounded-lg md:shadow-sm md:border md:border-gray-200 md:dark:border-gray-700'
const SECTION_HEADER = 'px-4 md:px-6 pt-5 pb-3'

// Same tone palette as StatHero, for the desktop cards.
const TONES: Record<StatTone, string> = {
  default: 'text-gray-900 dark:text-white',
  good: 'text-green-600 dark:text-green-400',
  warning: 'text-amber-600 dark:text-amber-400',
  danger: 'text-red-600 dark:text-red-400',
}

const formatTimestamp = (value: string | null): string => {
  if (!value) return 'Never'
  return new Date(value).toLocaleString()
}

const heroHeadline = computed<HeroStat>(() => ({
  label: 'Linked products',
  value: props.mappings.meta.total,
  hint: props.unmappedProducts.total > 0 ? `${props.unmappedProducts.total} not linked yet` : 'Every product is linked',
  hintTone: props.unmappedProducts.total > 0 ? 'warning' : 'good',
}))

const heroStats = computed<HeroStat[]>(() => [
  {
    label: 'Square sales (30 days)',
    value: props.summary.sales_recorded,
    hint: props.summary.refunds_recorded > 0 ? `${props.summary.refunds_recorded} refunded` : undefined,
  },
  { label: 'Stock changes from Square', value: props.summary.stock_changes_applied, hint: 'Last 30 days' },
  {
    label: 'Connection',
    value: props.connection.configured ? 'Ready' : 'Needs setup',
    tone: props.connection.configured ? 'good' : 'danger',
    hint: props.connection.environment === 'production' ? 'Production' : 'Sandbox',
  },
])

// ── Sync location (save-on-change) ──────────────────────────────────────

// Local, since the prop only reflects what's saved. Synced back from the
// prop so a refused save (or another tab's save) snaps the select back.
const selectedLocationId = ref(props.connection.selected_location_id ?? '')

watch(
  () => props.connection.selected_location_id,
  (value) => {
    selectedLocationId.value = value ?? ''
  },
)

const locationForm = useForm({ location_id: '' })

// Changing the location repoints every stock push and every Square sale
// the sync applies, so switching away from a saved one confirms first.
const onLocationChange = async () => {
  const previous = props.connection.selected_location_id ?? ''
  const next = selectedLocationId.value

  if (!next || next === previous) return

  if (previous !== '') {
    const confirmed = await confirmDialog({
      title: 'Change sync location?',
      message: 'Stock pushes and Square sales will use the new location from now on. Counts already on Square at the old location aren\'t changed.',
      confirmLabel: 'Change Location',
    })

    if (!confirmed) {
      selectedLocationId.value = previous
      return
    }
  }

  locationForm.location_id = next
  locationForm.post(route('admin.square.location'), {
    preserveScroll: true,
    onError: () => {
      selectedLocationId.value = previous
    },
  })
}

// ── Linked products ────────────────────────────────────────────────────

const mappingColumns: Column[] = [
  { key: 'product', label: 'Product' },
  { key: 'sync_status', label: 'Status', hideable: true },
  { key: 'last_pushed_at', label: 'Last pushed', hideable: true },
  { key: 'last_pulled_at', label: 'Last Square sale', hideable: true },
  { key: 'square_object_id', label: 'Square ID', hideable: true },
]

const mappingActions: Action[] = [
  { name: 'unlink', icon: 'cancel', color: 'red', label: 'Unlink' },
]

const syncStatusClass = (status: MappingRow['sync_status']): string => {
  return {
    linked: 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200',
    pending: 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200',
    conflict: 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
    orphaned: 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200',
  }[status] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200'
}

const changeMappingsPage = (url: string | null) => {
  if (url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true })
  }
}

const handleMappingAction = async (action: string, item: MappingRow) => {
  if (action !== 'unlink') return

  const label = item.product_title ?? item.square_object_id
  const confirmed = await confirmDialog({
    title: 'Unlink from Square?',
    message: `'${label}' stops syncing with Square. Its sync history is kept, and it can be linked again later.`,
    confirmLabel: 'Unlink',
    variant: 'danger',
  })

  if (confirmed) {
    router.post(route('admin.square.unlink', item.id), {}, { preserveScroll: true })
  }
}

// ── Sync check ─────────────────────────────────────────────────────────

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

// sync() returns ReconcileInventoryDrift's structured result; the dialog
// opens straight away with a progress state, then lists each drifted row
// with its own push.
const syncResult = ref<ReconcileResult | null>(null)
const syncResultError = ref<string | null>(null)
const showSyncResultModal = ref(false)
const syncChecking = ref(false)

// Per-row resolve state. Local stock is the source of truth, so resolving
// a row always pushes its local count to Square. Keyed by product_id so
// several rows can be in flight independently.
const resolvingProductIds = ref<Set<number>>(new Set())
const resolvedRows = ref<Record<number, true>>({})
const anyRowResolved = ref(false)
const pushingAll = ref(false)

const unresolvedRows = computed(() => syncResult.value?.rows.filter((row) => !resolvedRows.value[row.product_id]) ?? [])

const runSyncCheck = async () => {
  if (syncChecking.value) return

  syncChecking.value = true
  syncResult.value = null
  syncResultError.value = null
  resolvedRows.value = {}
  showSyncResultModal.value = true

  try {
    const response = await axios.post(route('admin.square.sync'))
    syncResult.value = response.data
  } catch (error: any) {
    syncResultError.value = error?.response?.data?.error ?? 'Square didn\'t respond.'
  } finally {
    syncChecking.value = false
  }
}

const resolveDriftRow = async (row: DriftRow) => {
  if (resolvingProductIds.value.has(row.product_id)) return

  resolvingProductIds.value.add(row.product_id)
  try {
    await axios.post(route('admin.square.resolve-drift'), {
      product_id: row.product_id,
      source: 'local',
    })
    resolvedRows.value[row.product_id] = true
    anyRowResolved.value = true
  } catch (error: any) {
    syncResultError.value = error?.response?.data?.error ?? `Couldn't push '${row.product_title}'.`
  } finally {
    resolvingProductIds.value.delete(row.product_id)
  }
}

const pushAllRows = async () => {
  pushingAll.value = true
  try {
    for (const row of [...unresolvedRows.value]) {
      await resolveDriftRow(row)
    }
  } finally {
    pushingAll.value = false
  }
}

const closeSyncResultModal = () => {
  showSyncResultModal.value = false

  if (anyRowResolved.value) {
    anyRowResolved.value = false
    // The pushes change mapping timestamps and the activity feed.
    router.reload({ only: ['mappings', 'driftEvents', 'recentActivity', 'summary'] })
  }
}

// ── Link catalog items ─────────────────────────────────────────────────

// Downloaded catalog items live in local state, not an Inertia prop --
// walking the whole Square catalog is too heavy to redo on every prop
// refresh. null means "not downloaded yet", distinct from an empty array
// ("downloaded, nothing left to link").
const catalogItems = ref<CatalogItemRow[] | null>(null)
const catalogLoading = ref(false)
const catalogError = ref<string | null>(null)
const linkError = ref<string | null>(null)
const linkSelections = ref<Record<string, number | ''>>({})
const linkingId = ref<string | null>(null)
const showUnmapped = ref(false)

const downloadCatalog = async () => {
  catalogLoading.value = true
  catalogError.value = null
  try {
    const response = await axios.get(route('admin.square.catalog-items'))
    catalogItems.value = response.data.items
    preselectSuggestions()
  } catch (error: any) {
    catalogError.value = error?.response?.data?.error ?? 'Couldn\'t download the Square catalog.'
  } finally {
    catalogLoading.value = false
  }
}

// ── Catalog table ──

const normalize = (value: string | null | undefined): string => (value ?? '').trim().toLowerCase()

// An exact SKU match first, then an exact name match, among the local
// products that aren't linked yet -- only ever a suggestion the admin
// confirms with "Link".
const suggestedProductFor = (item: CatalogItemRow): UnmappedProduct | undefined => {
  const products = props.unmappedProducts.items

  if (item.sku) {
    const bySku = products.find((product) => product.sku && normalize(product.sku) === normalize(item.sku))
    if (bySku) return bySku
  }

  return products.find((product) => normalize(product.title) === normalize(item.name))
}

const catalogRows = computed<CatalogTableRow[]>(() => (catalogItems.value ?? []).map((item) => {
  const suggestion = suggestedProductFor(item)

  return {
    ...item,
    status: item.archived ? 'Archived' : 'Active',
    category: item.categories.join(', '),
    suggested_product_id: suggestion?.id ?? null,
  }
}))

// Fills each row's picker with its suggestion, without overriding a pick
// the admin already made.
const preselectSuggestions = () => {
  for (const row of catalogRows.value) {
    if (row.suggested_product_id !== null && !linkSelections.value[row.square_object_id]) {
      linkSelections.value[row.square_object_id] = row.suggested_product_id
    }
  }
}

const catalogColumns: Column[] = [
  { key: 'name', label: 'Square item', sortable: true },
  { key: 'category', label: 'Category', sortable: true, hideable: true },
  { key: 'sku', label: 'SKU', sortable: true, hideable: true },
  { key: 'price', label: 'Price', sortable: true, hideable: true },
  { key: 'status', label: 'Status', filterOnly: true, filterable: true, options: ['Active', 'Archived'] },
  { key: 'link', label: 'Local product' },
]

// Category is the page's own filter (DataTable's filters-extra slot)
// rather than a filterable column: an item can be in several categories,
// and DataTable's select filter only matches one exact value.
const catalogCategory = ref<string>('')

const catalogCategories = computed(() => [...new Set((catalogItems.value ?? []).flatMap((item) => item.categories))]
  .sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })))

const hasUncategorized = computed(() => (catalogItems.value ?? []).some((item) => item.categories.length === 0))

// Sentinel for "items with no category" -- can't collide with a real
// category name, which Square never leaves blank.
const UNCATEGORIZED = '__none__'

// DataTable leaves sorting to the page (it only emits which column).
const catalogSort = ref<{ field: keyof CatalogTableRow; direction: 'asc' | 'desc' }>({ field: 'name', direction: 'asc' })

const sortCatalog = (field: string) => {
  const key = field as keyof CatalogTableRow
  catalogSort.value = catalogSort.value.field === key
    ? { field: key, direction: catalogSort.value.direction === 'asc' ? 'desc' : 'asc' }
    : { field: key, direction: 'asc' }
}

const sortedCatalogRows = computed<CatalogTableRow[]>(() => {
  const { field, direction } = catalogSort.value
  const sign = direction === 'asc' ? 1 : -1
  const category = catalogCategory.value

  const rows = category === ''
    ? catalogRows.value
    : catalogRows.value.filter((row) => category === UNCATEGORIZED ? row.categories.length === 0 : row.categories.includes(category))

  // Blanks (no SKU, no category, variable price) always sort last.
  return [...rows].sort((a, b) => {
    const left = a[field] === '' ? null : a[field]
    const right = b[field] === '' ? null : b[field]
    if (left === null || left === undefined) return right === null || right === undefined ? 0 : 1
    if (right === null || right === undefined) return -1
    return typeof left === 'number' && typeof right === 'number'
      ? (left - right) * sign
      : String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: 'base' }) * sign
  })
})

const formatPrice = (amount: number, currency: string | null): string => {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency ?? 'CAD' }).format(amount)
  } catch {
    return amount.toFixed(2)
  }
}

// Header button / shelf icon: jump to the section and fetch the catalog if
// it hasn't been yet.
const openCatalog = () => {
  document.getElementById('square-link-catalog')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  if (catalogItems.value === null && !catalogLoading.value) {
    downloadCatalog()
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

const finishLink = (catalogItem: CatalogItemRow) => {
  // unmappedProducts and mappings refresh with the Inertia response;
  // catalogItems is local state, so the linked row is removed here.
  catalogItems.value = catalogItems.value?.filter((item) => item.square_object_id !== catalogItem.square_object_id) ?? null
  delete linkSelections.value[catalogItem.square_object_id]
}

const submitLink = (catalogItem: CatalogItemRow, source: 'local' | 'square') => {
  linkForm.inventory_source = source
  linkForm.post(route('admin.square.link'), {
    preserveScroll: true,
    onSuccess: () => finishLink(catalogItem),
  })
}

// Read-only dry run before anything is linked: shows local vs. Square's
// live count so the admin picks the starting truth knowingly. A product
// that doesn't track inventory has nothing to choose -- link straight
// through.
const linkCatalogItem = async (catalogItem: CatalogItemRow) => {
  const productId = linkSelections.value[catalogItem.square_object_id]
  if (!productId) return

  linkForm.product_id = String(productId)
  linkForm.square_object_id = catalogItem.square_object_id
  linkForm.square_parent_object_id = catalogItem.square_parent_object_id ?? ''

  linkingId.value = catalogItem.square_object_id
  linkError.value = null

  let preview: LinkPreview
  try {
    const response = await axios.get(route('admin.square.link-preview'), {
      params: { product_id: productId, square_object_id: catalogItem.square_object_id },
    })
    preview = response.data
  } catch (error: any) {
    linkError.value = error?.response?.data?.error ?? 'Couldn\'t check inventory counts.'
    return
  } finally {
    linkingId.value = null
  }

  if (!preview.track_inventory) {
    submitLink(catalogItem, 'local')
    return
  }

  // Keeping local stock is the normal, safe choice, so it's the main
  // button; taking Square's count is offered alongside it.
  const choice = await askDialog({
    title: 'Which count is correct?',
    message: `Linking "${catalogItem.name}". Local stock is ${preview.local_quantity}; Square has ${preview.square_quantity}. The other side is overwritten to match.`,
    actions: [
      { key: 'local', label: `Keep Local Count (${preview.local_quantity})`, style: 'primary' },
      { key: 'square', label: `Use Square's Count (${preview.square_quantity})`, style: 'secondary' },
    ],
    cancelLabel: 'Cancel',
    variant: 'info',
  })

  if (choice === 'local' || choice === 'square') {
    submitLink(catalogItem, choice)
  }
}
</script>
