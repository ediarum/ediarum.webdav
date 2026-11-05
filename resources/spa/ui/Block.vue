<template>
    <section class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
        <h2 v-if="title" class="text-xl pb-4">{{ title }}</h2>
        <slot />
    </section>
</template>

<script setup lang="ts">
defineProps<{ title?: string }>();
</script>
