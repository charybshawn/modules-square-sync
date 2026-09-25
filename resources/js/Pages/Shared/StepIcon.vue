<template>
  <span :class="['mt-0.5 shrink-0 inline-flex h-5 w-5 items-center justify-center rounded-full', STYLES[status].class]" :aria-label="STYLES[status].label" role="img">
    <svg v-if="status === 'pending'" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
    <svg v-else class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="STYLES[status].path" />
    </svg>
  </span>
</template>

<script setup lang="ts">
// One pass/warn/fail/skip/pending marker for a diagnostics or test-sale step.
defineProps<{ status: 'pass' | 'warn' | 'fail' | 'skip' | 'pending' }>()

const STYLES = {
  pass: { label: 'Passed', path: 'M5 13l4 4L19 7', class: 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300' },
  warn: { label: 'Warning', path: 'M12 8v5m0 3h.01', class: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300' },
  fail: { label: 'Failed', path: 'M6 6l12 12M18 6L6 18', class: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300' },
  skip: { label: 'Skipped', path: 'M6 12h12', class: 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400' },
  pending: { label: 'In progress', path: '', class: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300' },
} as const
</script>
