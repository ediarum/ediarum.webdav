<template>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <p v-if="loading" class="px-4 text-white">Loading…</p>
            <p v-else-if="error" class="px-4 py-2 bg-red-600 text-white text-lg">{{ error }}</p>
            <slot v-else />
        </div>
    </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ loading?: boolean; error?: string }>(), {loading: false, error: ''});
</script>
