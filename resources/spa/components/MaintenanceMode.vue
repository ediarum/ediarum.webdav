<!--
    Turns maintenance mode on (after a confirmation) or off. The backend refuses with 409 while
    WebDAV locks are active, so the confirmation shows that refusal rather than checking itself.
-->
<template>
    <span>
        <template v-if="enabled">
            <span class="text-red-600 font-bold">(In Maintenance Mode!)</span>
            <PrimaryButton type="button" class="ml-6" :disabled="busy" @click="disable">End Maintenance Mode</PrimaryButton>
        </template>
        <PrimaryButton v-else type="button" class="ml-6" @click="openConfirm">Disable for Maintenance</PrimaryButton>
        <InlineError :message="enabled ? error : ''" />

        <Modal v-if="confirming" title="Maintenance Mode" @close="confirming = false">
            <p>Are you sure you want to enable maintenance mode for this project?</p>
            <p>During maintenance mode, no one can access the WebDAV functionality.</p>
            <p v-if="error" class="text-red-600 font-bold mt-4">{{ error }}</p>
            <div class="mt-6 flex gap-2">
                <PrimaryButton type="button" :disabled="busy" @click="enable">Yes, Turn On Maintenance Mode</PrimaryButton>
                <SecondaryButton @click="confirming = false">Cancel</SecondaryButton>
            </div>
        </Modal>
    </span>
</template>

<script setup lang="ts">
import {ref} from 'vue';
import {useApp} from '../context';
import {errorMessage} from '../http';
import Modal from '../ui/Modal.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import SecondaryButton from '../ui/SecondaryButton.vue';
import InlineError from '../ui/InlineError.vue';

const props = defineProps<{
    projectId: number;
    enabled: boolean;
}>();

const emit = defineEmits<{ change: [enabled: boolean] }>();

const {http} = useApp();

const confirming = ref(false);
const busy = ref(false);
const error = ref('');

function openConfirm() {
    error.value = '';
    confirming.value = true;
}

async function toggle(action: 'enable' | 'disable') {
    busy.value = true;
    error.value = '';

    try {
        const response = await http.post<{ isInMaintenanceMode: boolean }>(
            `/project/${props.projectId}/${action}-maintenances-mode`
        );
        confirming.value = false;
        emit('change', response.data.isInMaintenanceMode);
    } catch (e) {
        error.value = errorMessage(e, 'Could not change maintenance mode.');
    } finally {
        busy.value = false;
    }
}

const enable = () => toggle('enable');
const disable = () => toggle('disable');
</script>
