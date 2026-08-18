<template>
  <span
    :class="[
      'inline-flex items-center rounded-full font-medium capitalize',
      small ? 'px-1.5 py-0.5 text-[10px]' : 'px-2 py-1 text-xs',
      severityClass,
    ]"
  >
    {{ severity }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  severity: 'info' | 'warning' | 'error'
  small?: boolean
}

const props = withDefaults(defineProps<Props>(), { small: false })

const severityClass = computed(() => ({
  info: 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200',
  warning: 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200',
  error: 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
}[props.severity] ?? 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200'))
</script>
