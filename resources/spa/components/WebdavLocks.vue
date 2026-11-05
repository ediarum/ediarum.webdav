<template>
    <div>
        <p class="text-sm mb-4">(Warning: Unlocking a file by force will generate an error when the user tries
            to save that file.)</p>
        <InlineError :message="error" />
        <DataTable
            :columns="[
                { key: 'owner', label: 'Owner' },
                { key: 'file', label: 'File' },
                { key: 'created', label: 'Locked Since' },
                { key: 'timeElapsed', label: 'Locked Time' },
            ]"
            :rows="locks"
            empty-text="(No Locks)"
        >
            <template #actions="{ row }">
                <PrimaryButton type="button" :disabled="removingId === row.id" @click="forceUnlock(row as Lock)">
                    Force Unlock
                </PrimaryButton>
            </template>
        </DataTable>
    </div>
</template>

<script setup lang="ts">
import {ref} from 'vue';
import {useApp} from '../context';
import {errorMessage} from '../http';
import type {Lock} from '../types';
import DataTable from '../ui/DataTable.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import InlineError from '../ui/InlineError.vue';

const props = defineProps<{
    projectId: number;
    initialLocks: Lock[];
}>();

const emit = defineEmits<{ change: [count: number] }>();

const {http} = useApp();

const locks = ref<Lock[]>([...props.initialLocks]);
const error = ref('');
const removingId = ref<number | null>(null);

async function forceUnlock(lock: Lock) {
    if (removingId.value !== null) return;
    removingId.value = lock.id;
    error.value = '';

    try {
        await http.delete(`/project/${props.projectId}/lock/${lock.id}`);
        locks.value = locks.value.filter((l) => l.id !== lock.id);
        emit('change', locks.value.length);
    } catch (e) {
        error.value = errorMessage(e, 'Could not force unlock that file.');
    } finally {
        removingId.value = null;
    }
}
</script>
