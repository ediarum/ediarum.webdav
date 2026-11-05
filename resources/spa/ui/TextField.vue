<template>
    <div>
        <label :for="id" class="block font-medium text-sm text-gray-700">
            {{ label }}
            <span v-if="hint" class="font-normal text-gray-500">{{ hint }}</span>
        </label>
        <input
            :id="id"
            v-model="model"
            :type="type"
            :readonly="readonly"
            :autocomplete="autocomplete"
            class="wd-input mt-1"
            :class="readonly ? 'bg-gray-100' : ''"
        />
        <InlineError :message="error" />
    </div>
</template>

<script setup lang="ts">
import InlineError from './InlineError.vue';

withDefaults(defineProps<{
    id: string;
    label: string;
    hint?: string;
    type?: 'text' | 'password' | 'email';
    readonly?: boolean;
    autocomplete?: string;
    error?: string;
}>(), {hint: '', type: 'text', readonly: false, autocomplete: 'off', error: ''});

const model = defineModel<string | null>({required: true});
</script>
