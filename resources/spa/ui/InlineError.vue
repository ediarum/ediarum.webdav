<template>
    <p v-if="message" class="text-sm text-red-600 mt-2">{{ message }}</p>
</template>

<script setup lang="ts">
defineProps<{ message?: string }>();
</script>
