<template>
  <span v-if="archived || suggested" class="inline-flex flex-wrap gap-1.5">
    <span v-if="archived" class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
      Archived
    </span>
    <span v-if="suggested" class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">
      Suggested match
    </span>
  </span>
</template>

<script setup lang="ts">
interface Props {
  archived: boolean
  suggested: boolean
}

defineProps<Props>()
</script>
