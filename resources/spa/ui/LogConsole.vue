<!-- Black log box for streamed progress. Follows new lines unless the user scrolled up. -->
<template>
    <div
        ref="box"
        class="w-full bg-black text-white overflow-auto p-2 text-sm font-mono"
        :class="heightClass"
        @scroll="onScroll"
    >
        <p
            v-for="(line, index) in lines"
            :key="index"
            class="text-left"
            :class="line.status ? 'text-green-500' : 'text-red-500'"
        >{{ line.content }}</p>
        <p v-if="spinner" class="text-left pl-2">{{ spinnerChar }}</p>
    </div>
</template>

<script setup lang="ts">
import {nextTick, onBeforeUnmount, ref, watch} from 'vue';

export interface LogLine {
    status: boolean;
    content: string;
}

const props = withDefaults(defineProps<{
    lines: LogLine[];
    spinner?: boolean;
    heightClass?: string;
}>(), {spinner: false, heightClass: 'h-72'});

const box = ref<HTMLElement | null>(null);
let follow = true;

function onScroll() {
    const el = box.value;
    if (el) follow = el.scrollHeight - el.scrollTop - el.clientHeight < 50;
}

watch(() => props.lines.length, () => {
    nextTick(() => {
        const el = box.value;
        if (el && follow) el.scrollTop = el.scrollHeight;
    });
});

const spinnerChars = ['|', '/', '-', '\\'];
const spinnerChar = ref('');
let spinnerIndex = 0;
const timer = setInterval(() => {
    spinnerChar.value = spinnerChars[spinnerIndex++ % spinnerChars.length];
}, 150);
onBeforeUnmount(() => clearInterval(timer));
</script>
