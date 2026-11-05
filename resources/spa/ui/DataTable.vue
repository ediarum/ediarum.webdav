<template>
    <table class="w-full border-collapse rounded-lg overflow-hidden">
        <thead class="bg-gray-50 text-left text-sm font-semibold text-gray-700">
            <tr>
                <th v-for="col in columns" :key="col.key" class="px-3 py-2">{{ col.label }}</th>
                <th v-if="$slots.actions" class="px-3 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 text-sm">
            <tr v-for="row in rows" :key="rowKeyOf(row)">
                <td v-for="col in columns" :key="col.key" class="px-3 py-2">
                    <slot :name="`cell-${col.key}`" :row="row">{{ row[col.key] }}</slot>
                </td>
                <td v-if="$slots.actions" class="px-3 py-2 text-right">
                    <slot name="actions" :row="row" />
                </td>
            </tr>
        </tbody>
    </table>
    <div v-if="rows.length === 0" class="w-full text-center py-4 text-sm text-gray-500">
        {{ emptyText }}
    </div>
</template>

<script setup lang="ts">
const props = defineProps<{
    columns: { key: string; label: string }[];
    rows: Record<string, any>[];
    emptyText: string;
    rowKey?: (row: Record<string, any>) => string | number;
}>();

const rowKeyOf = props.rowKey ?? ((row: Record<string, any>) => row.id);
</script>
