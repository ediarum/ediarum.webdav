<template>
    <div>
        <p class="text-sm mb-4">Locking a path blocks all WebDAV edits to any file within the given path.</p>
        <DataTable
            :columns="[
                { key: 'path', label: 'Path' },
                { key: 'lockedBy', label: 'Locked By' },
                { key: 'createdAt', label: 'Locked Since' },
            ]"
            :rows="lockedPaths"
            empty-text="(No Locked Paths)"
        >
            <template #actions="{ row }">
                <PrimaryButton type="button" :disabled="removingId === row.id" @click="unlockPath(row as LockedPath)">
                    Unlock
                </PrimaryButton>
            </template>
        </DataTable>
        <form class="flex items-end gap-4 mt-4" @submit.prevent="lockPath">
            <div class="flex-grow">
                <label :for="`wd-lock-path-${projectId}`" class="block font-medium text-sm text-gray-700">
                    Path to lock (relative to the data folder, e.g. folder/subfolder)
                </label>
                <input :id="`wd-lock-path-${projectId}`" v-model="newPath" type="text" class="wd-input mt-1" />
            </div>
            <PrimaryButton :disabled="submitting">Lock Path</PrimaryButton>
        </form>
        <InlineError :message="error" />
    </div>
</template>

<script setup lang="ts">
import {ref} from 'vue';
import {useApp} from '../context';
import {errorMessage} from '../http';
import type {LockedPath} from '../types';
import DataTable from '../ui/DataTable.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import InlineError from '../ui/InlineError.vue';

const props = defineProps<{
    projectId: number;
    initialLockedPaths: LockedPath[];
}>();

const {http} = useApp();

const lockedPaths = ref<LockedPath[]>([...props.initialLockedPaths]);
const newPath = ref('');
const error = ref('');
const submitting = ref(false);
const removingId = ref<number | null>(null);

async function lockPath() {
    if (submitting.value) return;
    submitting.value = true;
    error.value = '';

    try {
        const response = await http.post<LockedPath>(`/project/${props.projectId}/locked-path`, {path: newPath.value});
        lockedPaths.value.push(response.data);
        lockedPaths.value.sort((a, b) => a.path.localeCompare(b.path));
        newPath.value = '';
    } catch (e) {
        error.value = errorMessage(e, 'Could not lock that path.');
    } finally {
        submitting.value = false;
    }
}

async function unlockPath(lockedPath: LockedPath) {
    if (removingId.value !== null) return;
    removingId.value = lockedPath.id;
    error.value = '';

    try {
        await http.delete(`/project/${props.projectId}/locked-path/${lockedPath.id}`);
        lockedPaths.value = lockedPaths.value.filter((lp) => lp.id !== lockedPath.id);
    } catch (e) {
        error.value = errorMessage(e, 'Could not unlock that path.');
    } finally {
        removingId.value = null;
    }
}
</script>
