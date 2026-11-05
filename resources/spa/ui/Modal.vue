<!--
    Rendered in place (no Teleport): the app's theme variables only exist inside .wd-root, so
    nothing may be moved to <body>. position: fixed still covers the viewport.
-->
<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        @keydown.esc="$emit('close')"
    >
        <div class="bg-white rounded-lg shadow w-full max-h-[90vh] overflow-y-auto p-6 text-left" :class="wide ? 'max-w-4xl' : 'max-w-2xl'">
            <h2 class="text-xl font-semibold mb-4">{{ title }}</h2>
            <slot />
        </div>
    </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ title: string; wide?: boolean }>(), {wide: false});
defineEmits<{ close: [] }>();
</script>
