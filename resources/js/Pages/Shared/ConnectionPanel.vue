<template>
  <section :class="SECTION">
    <div class="px-4 md:px-6 pt-5 pb-3 flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="text-lg font-medium text-gray-900 dark:text-white">Connection</h2>
          <span :class="['inline-flex items-center gap-1.5 px-2 py-0.5 text-xs font-medium rounded-full', badge.class]">
            <span :class="['h-1.5 w-1.5 rounded-full', badge.dot, { 'animate-pulse': checking }]" aria-hidden="true" />
            {{ badge.label }}
          </span>
        </div>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
          <template v-if="checking">Checking the token, account, location and every product link with Square…</template>
          <template v-else-if="health">Checked with Square {{ timeAgo(health.checked_at) }}. Rechecked every 15 minutes.</template>
          <template v-else>Not checked yet.</template>
        </p>
      </div>
      <button
        type="button"
        :disabled="checking"
        class="tap-target-touch shrink-0 inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
        @click="$emit('check')"
      >
        {{ checking ? 'Checking…' : 'Check now' }}
      </button>
    </div>

    <div v-if="checkError || problems.length > 0 || warnings.length > 0 || health?.stale" class="px-4 md:px-6 pb-4 space-y-3">
      <AdminAlert v-if="checkError" level="error" title="The check didn't finish">
        {{ checkError }}
      </AdminAlert>
      <AdminAlert v-if="problems.length > 0" level="error" :title="health?.status === 'not_configured' ? 'Square isn\'t set up' : 'Square sync is offline -- nothing syncs until this is fixed'">
        <ul class="list-disc pl-5 space-y-1">
          <li v-for="problem in problems" :key="problem.key">{{ problem.message }}</li>
        </ul>
      </AdminAlert>
      <AdminAlert v-if="warnings.length > 0" level="warning" :title="warnings.length === 1 ? 'Heads up' : `${warnings.length} things to look at`">
        <ul class="list-disc pl-5 space-y-1">
          <li v-for="warning in warnings" :key="warning.key">{{ warning.message }}</li>
        </ul>
      </AdminAlert>
      <AdminAlert v-if="health?.stale && !checking" level="warning" title="The scheduled check isn't running">
        The last automatic check was {{ timeAgo(health.checked_at) }}. Problems won't be noticed while you're away until the scheduler runs square:check again.
      </AdminAlert>
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
        <dd><StatusBadge :ok="tokenOk" :true-label="health?.merchant_id ? 'Valid' : 'Configured'" :false-label="connection.access_token_configured ? 'Rejected' : 'Missing'" /></dd>
      </div>
      <div v-if="health?.merchant_id" class="flex items-center justify-between gap-4 px-4 md:px-6 py-3">
        <dt class="text-sm text-gray-500 dark:text-gray-400">Square account</dt>
        <dd><code class="text-xs text-gray-600 dark:text-gray-300 break-all">{{ health.merchant_id }}</code></dd>
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
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminAlert from '@/Components/Admin/AdminAlert.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import { useConfirmDialog } from '@/composables/useConfirmDialog'
import StatusBadge from './StatusBadge.vue'
import { timeAgo, type ConnectionConfig, type Health } from './types'

interface Props {
  connection: ConnectionConfig
  health: Health | null
  checking: boolean
  checkError: string | null
}

const props = defineProps<Props>()

const emit = defineEmits<{ check: [] }>()

const SECTION = 'bg-white dark:bg-gray-800 md:rounded-lg md:shadow-sm md:border md:border-gray-200 md:dark:border-gray-700'

const problems = computed(() => props.health?.problems ?? [])
const warnings = computed(() => props.health?.warnings ?? [])

const tokenOk = computed(() => props.connection.access_token_configured
  && !problems.value.some((problem) => problem.key === 'token_invalid' || problem.key === 'token_missing'))

const badge = computed(() => {
  if (props.checking && !props.health) {
    return { label: 'Checking…', class: 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200', dot: 'bg-gray-400' }
  }

  switch (props.health?.status) {
    case 'online':
      return { label: 'Online', class: 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200', dot: 'bg-green-500' }
    case 'offline':
      return { label: 'Offline', class: 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200', dot: 'bg-red-500' }
    case 'not_configured':
      return { label: 'Not set up', class: 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200', dot: 'bg-red-500' }
    default:
      return { label: 'Unknown', class: 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200', dot: 'bg-gray-400' }
  }
})

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

const { confirmDialog } = useConfirmDialog()

// Changing the location repoints every stock push and every Square sale
// the sync applies, so switching away from a saved one confirms first.
// A saved change is rechecked straight away.
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
    onSuccess: () => emit('check'),
    onError: () => {
      selectedLocationId.value = previous
    },
  })
}
</script>
