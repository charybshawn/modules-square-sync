<template>
  <section :class="SECTION">
    <div class="px-4 md:px-6 pt-5 pb-3 flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Diagnostics</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Checks every step a Square sale takes to reach local stock. Nothing here changes stock or your Square catalog.
        </p>
      </div>
      <button
        type="button"
        :disabled="running"
        class="tap-target-touch shrink-0 inline-flex items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
        @click="runChecks"
      >
        {{ running ? 'Checking…' : diagnostics ? 'Run Again' : 'Run Checks' }}
      </button>
    </div>

    <div v-if="error" class="px-4 md:px-6 pb-4">
      <AdminAlert level="error" title="Checks didn't finish">{{ error }}</AdminAlert>
    </div>

    <ol v-if="diagnostics" class="divide-y divide-gray-100 dark:divide-gray-700 border-t border-gray-100 dark:border-gray-700">
      <li v-for="check in diagnostics.checks" :key="check.key" class="px-4 md:px-6 py-3 flex items-start gap-3">
        <StepIcon :status="check.status" />
        <div class="min-w-0">
          <p class="text-sm font-medium text-gray-900 dark:text-white">{{ check.label }}</p>
          <p class="text-sm text-gray-600 dark:text-gray-400 break-words">{{ check.detail }}</p>
        </div>
      </li>
    </ol>
    <p v-else-if="!running" class="px-4 md:px-6 pb-5 text-sm text-gray-500 dark:text-gray-400">
      Includes a live webhook delivery test: Square sends this app a sample event and reports whether it was accepted.
    </p>

    <!-- End to end: a real sandbox sale. -->
    <div class="border-t border-gray-200 dark:border-gray-700 px-4 md:px-6 py-5">
      <h3 class="text-sm font-medium text-gray-900 dark:text-white">Test sale</h3>
      <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        Rings up 1 unit of a linked product on Square like a POS sale, then follows it back: order completed, Square notifies this
        app, local stock goes down, sale recorded. The unit is put back afterwards.
      </p>

      <p v-if="environment !== 'sandbox'" class="mt-3 text-sm text-amber-700 dark:text-amber-400">
        Sandbox only -- in production the sale would be real. Use Run Checks and Recent sync activity to verify production.
      </p>
      <template v-else>
        <p v-if="diagnostics && !diagnostics.test_sale.available" class="mt-3 text-sm text-amber-700 dark:text-amber-400">
          {{ diagnostics.test_sale.reason }}
        </p>
        <p v-else-if="!diagnostics" class="mt-3 text-sm text-gray-500 dark:text-gray-400">Run the checks first to choose a product.</p>
        <div v-else class="mt-3 flex flex-col sm:flex-row gap-2">
          <label for="square-test-product" class="sr-only">Product to sell</label>
          <select
            id="square-test-product"
            v-model="testProductId"
            :disabled="testBusy"
            class="block w-full sm:max-w-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-base sm:text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-50"
          >
            <option value="" disabled>Choose a product…</option>
            <option v-for="product in diagnostics.test_sale.products" :key="product.id" :value="product.id">
              {{ product.title }} ({{ product.stock }} in stock)
            </option>
          </select>
          <button
            type="button"
            :disabled="!testProductId || testBusy"
            class="tap-target-touch inline-flex justify-center items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50"
            @click="startTestSale"
          >
            {{ testBusy ? 'Running…' : 'Start Test Sale' }}
          </button>
        </div>
      </template>

      <div v-if="testError" class="mt-3">
        <AdminAlert level="error" title="Test sale failed">{{ testError }}</AdminAlert>
      </div>

      <div v-if="run" class="mt-4 rounded-md border border-gray-200 dark:border-gray-700">
        <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-700">
          <p class="text-sm text-gray-900 dark:text-white">
            1 × {{ run.product_title }}
            <span class="text-gray-500 dark:text-gray-400">· started {{ timeAgo(run.started_at) }}</span>
          </p>
          <span :class="['inline-flex px-2 py-0.5 text-xs font-medium rounded-full', runBadge.class]">{{ runBadge.label }}</span>
        </div>
        <ol class="divide-y divide-gray-100 dark:divide-gray-700">
          <li v-for="step in run.steps" :key="step.key" class="px-4 py-2.5 flex items-start gap-3">
            <StepIcon :status="step.status" />
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-white">{{ step.label }}</p>
              <p class="text-xs text-gray-600 dark:text-gray-400 break-words">{{ step.detail }}</p>
            </div>
          </li>
        </ol>
        <div v-if="run.can_pull" class="px-4 py-3 border-t border-gray-100 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center gap-2">
          <p class="text-xs text-gray-500 dark:text-gray-400 sm:flex-1">
            No webhook yet? Pulling from Square runs the same catch-up the scheduler does -- if stock then goes down, only Square's
            notification is broken.
          </p>
          <button
            type="button"
            :disabled="pulling"
            class="tap-target-touch shrink-0 inline-flex justify-center items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
            @click="pullNow"
          >
            {{ pulling ? 'Pulling…' : 'Pull from Square Now' }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import AdminAlert from '@/Components/Admin/AdminAlert.vue'
import StepIcon from './StepIcon.vue'
import { timeAgo, type Diagnostics, type Health, type TestSaleRun } from './types'

interface Props {
  environment: string
  initialTestSale: TestSaleRun | null
}

const props = defineProps<Props>()

const emit = defineEmits<{ health: [health: Health]; changed: [] }>()

const SECTION = 'bg-white dark:bg-gray-800 md:rounded-lg md:shadow-sm md:border md:border-gray-200 md:dark:border-gray-700'

const POLL_MS = 3000

// ── Checks ─────────────────────────────────────────────────────────────

const diagnostics = ref<Diagnostics | null>(null)
const running = ref(false)
const error = ref<string | null>(null)

const runChecks = async () => {
  running.value = true
  error.value = null
  try {
    const response = await axios.post(route('admin.square.diagnostics'))
    diagnostics.value = response.data
    emit('health', response.data.health)
  } catch (err: any) {
    error.value = err?.response?.data?.error ?? err?.response?.data?.message ?? 'Square didn\'t respond. Try again in a moment.'
  } finally {
    running.value = false
  }
}

// ── Test sale ──────────────────────────────────────────────────────────

const run = ref<TestSaleRun | null>(props.initialTestSale)
const testProductId = ref<number | ''>('')
const starting = ref(false)
const pulling = ref(false)
const testError = ref<string | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null

const testBusy = computed(() => starting.value || (run.value !== null && !run.value.finished))

const runBadge = computed(() => {
  if (!run.value?.finished) return { label: 'In progress', class: 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200' }
  return run.value.passed
    ? { label: 'Passed', class: 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200' }
    : { label: 'Failed', class: 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200' }
})

const stopPolling = () => {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
}

const schedulePoll = () => {
  stopPolling()
  if (run.value && !run.value.finished) {
    pollTimer = setTimeout(poll, POLL_MS)
  }
}

const settle = (next: TestSaleRun) => {
  const wasFinished = run.value?.finished ?? true
  run.value = next
  // Stock and the activity feed changed -- let the page refresh them.
  if (next.finished && !wasFinished) emit('changed')
  schedulePoll()
}

const poll = async () => {
  if (!run.value) return
  try {
    const response = await axios.post(route('admin.square.test-sale.check', run.value.id))
    settle(response.data)
  } catch (err: any) {
    testError.value = err?.response?.data?.error ?? 'Lost track of the test sale.'
    stopPolling()
  }
}

const startTestSale = async () => {
  if (!testProductId.value) return
  starting.value = true
  testError.value = null
  try {
    const response = await axios.post(route('admin.square.test-sale.start'), { product_id: testProductId.value })
    run.value = null
    settle(response.data)
  } catch (err: any) {
    testError.value = err?.response?.data?.error ?? err?.response?.data?.message ?? 'Couldn\'t start the test sale.'
  } finally {
    starting.value = false
  }
}

const pullNow = async () => {
  if (!run.value) return
  pulling.value = true
  try {
    const response = await axios.post(route('admin.square.test-sale.pull', run.value.id))
    settle(response.data)
  } catch (err: any) {
    testError.value = err?.response?.data?.error ?? 'The pull from Square failed.'
  } finally {
    pulling.value = false
  }
}

// A run still in progress when the page was (re)loaded picks up where it was.
onMounted(schedulePoll)
onBeforeUnmount(stopPolling)
</script>
