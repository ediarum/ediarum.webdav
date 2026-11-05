<!--
    A password input for a project secret. The saved value is never sent to the browser, only
    whether one exists: then a blank input keeps it, and (if `deletable`) a checkbox removes it.
-->
<template>
    <div>
        <TextField
            :id="id"
            v-model="model"
            type="password"
            autocomplete="new-password"
            :label="saved ? `A ${label} is currently saved for this project. You can enter a new ${label}:` : label"
            :error="error"
        />
        <div v-if="saved && deletable" class="mt-2 text-sm text-gray-700">
            <label :for="`${id}-delete`">Or delete the current {{ label }}:</label>
            <input :id="`${id}-delete`" v-model="deleteModel" type="checkbox" class="ml-2" />
        </div>
    </div>
</template>

<script setup lang="ts">
import TextField from '../ui/TextField.vue';

withDefaults(defineProps<{
    id: string;
    label: string;
    saved: boolean;
    deletable?: boolean;
    error?: string;
}>(), {deletable: false, error: ''});

const model = defineModel<string>({required: true});
const deleteModel = defineModel<boolean>('delete', {default: false});
</script>
