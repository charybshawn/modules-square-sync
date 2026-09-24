<template>
  <div class="flex items-center gap-2">
    <label :for="selectId" class="sr-only">Local product for {{ itemName }}</label>
    <select
      :id="selectId"
      :value="modelValue ?? ''"
      class="block min-w-0 flex-1 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-base sm:text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
      @change="onChange"
    >
      <option value="">Select a product…</option>
      <option v-for="product in products" :key="product.id" :value="product.id">
        {{ product.title }}{{ product.sku ? ` (${product.sku})` : '' }}
      </option>
    </select>
    <button
      type="button"
      :disabled="!modelValue || disabled"
      class="tap-target-touch shrink-0 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
      @click="emit('link')"
    >
      {{ busy ? 'Checking…' : 'Link' }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

/**
 * The product picker + Link button for one Square catalog item -- shared
 * by the catalog table's desktop cell and its mobile card.
 */
interface Props {
  itemId: string
  itemName: string
  products: { id: number; title: string; sku: string | null }[]
  // undefined until a product is picked (or suggested) for this row.
  modelValue?: number | ''
  disabled?: boolean
  busy?: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: number | '']
  link: []
}>()

const selectId = computed(() => `link-${props.itemId}`)

const onChange = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value
  emit('update:modelValue', value === '' ? '' : Number(value))
}
</script>
