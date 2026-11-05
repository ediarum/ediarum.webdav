<template>
    <div>
        <InlineSuccess :message="success" />
        <InlineError :message="error" />
        <DataTable
            :columns="[
                { key: 'file', label: 'File' },
                { key: 'time', label: 'Failed At' },
            ]"
            :rows="failedJobs"
            :row-key="(row) => row.uuid"
            empty-text="(No Failed Pushes to eXist-db)"
        >
            <template #actions="{ row }">
                <PrimaryButton type="button" :disabled="retryingUuid === row.uuid" @click="retryOne(row as FailedPush)">
                    {{ retryingUuid === row.uuid ? 'Retrying…' : 'Retry' }}
                </PrimaryButton>
            </template>
        </DataTable>

        <PrimaryButton
            v-if="failedJobs.length > 0"
            class="my-2"
            type="button"
            :disabled="retryAllInProgress"
            @click="retryAll"
        >
            {{ retryAllInProgress ? 'Retrying All…' : 'Retry All Failed' }}
        </PrimaryButton>

        <LogConsole v-if="consoleLines.length > 0" class="mt-2" height-class="h-64" :lines="consoleLines" />
    </div>
</template>

<script setup lang="ts">
import {ref} from 'vue';
import {useApp, useStreams, type StreamHandle} from '../context';
import {errorMessage} from '../http';
import type {FailedPush} from '../types';
import DataTable from '../ui/DataTable.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import InlineError from '../ui/InlineError.vue';
import InlineSuccess from '../ui/InlineSuccess.vue';
import LogConsole, {type LogLine} from '../ui/LogConsole.vue';

const props = defineProps<{
    projectId: number;
    initialFailedJobs: FailedPush[];
}>();

const {http} = useApp();
const openStream = useStreams();

const failedJobs = ref<FailedPush[]>([...props.initialFailedJobs]);
const error = ref('');
const success = ref('');
const retryingUuid = ref<string | null>(null);
const retryAllInProgress = ref(false);
const consoleLines = ref<LogLine[]>([]);

let stream: StreamHandle | null = null;

async function retryOne(job: FailedPush) {
    if (retryingUuid.value !== null) return;
    retryingUuid.value = job.uuid;
    error.value = '';
    success.value = '';

    try {
        const response = await http.post<{ success: boolean; error: string | null }>(
            `/project/${props.projectId}/failed-push/${job.uuid}/retry`
        );

        if (response.data.success) {
            failedJobs.value = failedJobs.value.filter((f) => f.file !== job.file);
            success.value = `Successfully retried the push for ${job.file}.`;
        } else {
            error.value = response.data.error || 'Could not retry that push.';
        }
    } catch (e) {
        error.value = errorMessage(e, 'Could not retry that push.');
    } finally {
        retryingUuid.value = null;
    }
}

function finish(line: LogLine) {
    consoleLines.value.push(line);
    retryAllInProgress.value = false;
    stream?.close();
    stream = null;
}

function retryAll() {
    if (retryAllInProgress.value) return;
    retryAllInProgress.value = true;
    consoleLines.value = [];
    error.value = '';
    success.value = '';
    stream?.close();

    stream = openStream(`/project/${props.projectId}/retry-failed-pushes`, {}, {
        onMessage(data) {
            if (data === '[DONE]') {
                finish({status: true, content: 'All retries complete.'});
                return;
            }

            const {file, success: succeeded, error: fileError} = JSON.parse(data);
            consoleLines.value.push({
                status: succeeded,
                content: succeeded ? `✓ ${file}` : `✗ ${file}: ${fileError}`,
            });
            if (succeeded) {
                failedJobs.value = failedJobs.value.filter((f) => f.file !== file);
            }
        },
        onAppError: (message) => finish({status: false, content: `ERROR: ${message}`}),
        onConnectionLost: () => finish({status: false, content: 'ERROR: Connection to server lost.'}),
    });
}
</script>
