<template>
  <span
    :class="[
      'inline-flex px-2 py-1 text-xs font-medium rounded-full',
      ok
        ? 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200'
        : 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
    ]"
  >
    {{ ok ? trueLabel : falseLabel }}
  </span>
</template>

<script setup lang="ts">
interface Props {
  ok: boolean
  trueLabel: string
  falseLabel: string
}

defineProps<Props>()
</script>
