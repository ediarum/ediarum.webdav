<template>
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <label :for="`wd-sync-folder-${projectId}`">Choose a folder:</label>
            <select :id="`wd-sync-folder-${projectId}`" v-model="syncFolder" class="wd-input w-auto" :disabled="syncInProgress">
                <option value="">(All contents)</option>
                <option v-for="folder in folders" :key="folder" :value="folder">{{ folder }}</option>
            </select>
            <PrimaryButton type="button" :disabled="syncInProgress" @click="startSync">
                {{ syncInProgress ? 'Syncing…' : 'Start Sync' }}
            </PrimaryButton>
        </div>
        <LogConsole v-if="messages.length > 0 || syncInProgress" class="mt-4" height-class="h-96" :lines="messages" :spinner="syncInProgress" />
    </div>
</template>

<script setup lang="ts">
import {ref} from 'vue';
import {useStreams, type StreamHandle} from '../context';
import PrimaryButton from '../ui/PrimaryButton.vue';
import LogConsole, {type LogLine} from '../ui/LogConsole.vue';

const props = defineProps<{
    projectId: number;
    folders: string[];
}>();

const openStream = useStreams();

const syncFolder = ref('');
const messages = ref<LogLine[]>([]);
const syncInProgress = ref(false);

let stream: StreamHandle | null = null;

function finish(line: LogLine) {
    messages.value.push(line);
    syncInProgress.value = false;
    stream?.close();
    stream = null;
}

function startSync() {
    if (syncInProgress.value) return;
    syncInProgress.value = true;
    messages.value = [];
    stream?.close();

    stream = openStream(`/project/${props.projectId}/push-to-existdb`, {folder: syncFolder.value}, {
        onMessage(data) {
            if (data === '[DONE]') {
                finish({status: true, content: 'Sync complete'});
                return;
            }
            messages.value.push({status: true, content: data});
        },
        onAppError: (message) => finish({status: false, content: `❌ ${message}`}),
        onConnectionLost: () => finish({status: false, content: 'ERROR: Connection to server lost.'}),
    });
}
</script>
