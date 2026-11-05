<template>
    <button :type="type" class="wd-btn-primary">
        <slot />
    </button>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ type?: 'button' | 'submit' }>(), {type: 'submit'});
</script>
