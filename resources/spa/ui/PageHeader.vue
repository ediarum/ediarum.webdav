<template>
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <h1 class="font-semibold text-xl text-gray-800 leading-tight">{{ title }}</h1>
                <!-- Controls that belong to the title, e.g. how the list below is sorted. -->
                <slot name="after-title" />
            </div>
            <div v-if="$slots.default" class="flex gap-2">
                <slot />
            </div>
        </div>
    </header>
</template>

<script setup lang="ts">
defineProps<{ title: string }>();
</script>
