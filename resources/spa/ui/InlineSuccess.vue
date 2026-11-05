<template>
    <p v-if="message" class="text-sm text-green-600 mb-4">{{ message }}</p>
</template>

<script setup lang="ts">
defineProps<{ message?: string }>();
</script>
