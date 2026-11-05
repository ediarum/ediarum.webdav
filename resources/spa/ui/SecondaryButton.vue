<template>
    <button :type="type" class="wd-btn-secondary">
        <slot />
    </button>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ type?: 'button' | 'submit' }>(), {type: 'button'});
</script>
