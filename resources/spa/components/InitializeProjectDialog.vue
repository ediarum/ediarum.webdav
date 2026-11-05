<!--
    Create mode saves a new project, retry mode updates the Git remote settings of one whose
    initialization failed. Either way, the clone/checkout progress is then streamed live from
    ProjectController::initializeStream(). The parent shows the dialog with v-if and handles
    navigation: `close` (cancelled) and `done` (initialized, with the project id).
-->
<template>
    <Modal :title="mode === 'create' ? 'Create New Project' : 'Initialize Project'" @close="cancel">
        <form v-if="step === 'form' || step === 'saving'" @submit.prevent="submit">
            <div class="space-y-4">
                <TextField id="wd-ipd-name" v-model="fields.name" label="Project Name" :error="errors.name?.[0]" />
                <TextField
                    id="wd-ipd-slug"
                    v-model="fields.slug"
                    label="Slug (lowercase letters and digits, words separated by single hyphens)"
                    :readonly="mode === 'retry'"
                    :hint="mode === 'create' ? '(can\'t be changed later)' : ''"
                    :error="errors.slug?.[0]"
                />
                <TextField id="wd-ipd-git-remote-url" v-model="fields.git_remote_url" label="Git Remote URL" :error="errors.git_remote_url?.[0]" />
                <TextField id="wd-ipd-git-username" v-model="fields.git_username" label="Git Username" :error="errors.git_username?.[0]" />
                <TextField
                    id="wd-ipd-git-token"
                    v-model="fields.git_access_token"
                    type="password"
                    autocomplete="new-password"
                    label="Git Personal Access Token"
                    :hint="mode === 'retry' ? '(leave blank to keep the currently saved one)' : ''"
                    :error="errors.git_access_token?.[0]"
                />
                <TextField id="wd-ipd-git-branch" v-model="fields.git_branch" label="Tracked Git Branch" :error="errors.git_branch?.[0]" />
            </div>

            <InlineError :message="generalError" />

            <div class="mt-6 flex gap-2">
                <PrimaryButton :disabled="step === 'saving'">
                    {{ step === 'saving' ? 'Saving…' : 'Initialize Project' }}
                </PrimaryButton>
                <SecondaryButton @click="cancel">Cancel</SecondaryButton>
            </div>
        </form>

        <template v-else>
            <LogConsole :lines="messages" :spinner="step === 'initializing'" />

            <div class="mt-6 flex gap-2">
                <PrimaryButton v-if="step === 'succeeded'" type="button" @click="emit('done', projectId!)">OK</PrimaryButton>
                <template v-else-if="step === 'failed'">
                    <PrimaryButton type="button" @click="step = 'form'">Try Again</PrimaryButton>
                    <SecondaryButton @click="cancel">Close</SecondaryButton>
                </template>
            </div>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import {reactive, ref} from 'vue';
import {useApp, useStreams, type StreamHandle} from '../context';
import {errorMessage, validationErrors} from '../http';
import Modal from '../ui/Modal.vue';
import TextField from '../ui/TextField.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import SecondaryButton from '../ui/SecondaryButton.vue';
import InlineError from '../ui/InlineError.vue';
import LogConsole, {type LogLine} from '../ui/LogConsole.vue';

const props = withDefaults(defineProps<{
    mode: 'create' | 'retry';
    projectId?: number | null;
    initialName?: string;
    initialSlug?: string;
    initialGitRemoteUrl?: string | null;
    initialGitUsername?: string | null;
    initialGitBranch?: string | null;
}>(), {
    projectId: null,
    initialName: '',
    initialSlug: '',
    initialGitRemoteUrl: '',
    initialGitUsername: '',
    initialGitBranch: 'main',
});

const emit = defineEmits<{ close: []; done: [projectId: number] }>();

const {http} = useApp();
const openStream = useStreams();

type Step = 'form' | 'saving' | 'initializing' | 'succeeded' | 'failed';

const step = ref<Step>('form');
const projectId = ref<number | null>(props.projectId);

const fields = reactive({
    name: props.initialName,
    slug: props.initialSlug,
    git_remote_url: props.initialGitRemoteUrl ?? '',
    git_username: props.initialGitUsername ?? '',
    git_access_token: '',
    git_branch: props.initialGitBranch ?? 'main',
});

const errors = ref<Record<string, string[]>>({});
const generalError = ref('');
const messages = ref<LogLine[]>([]);

let stream: StreamHandle | null = null;

function cancel() {
    if (step.value === 'saving' || step.value === 'initializing') return;
    emit('close');
}

async function submit() {
    if (step.value === 'saving') return;

    step.value = 'saving';
    errors.value = {};
    generalError.value = '';

    const payload: Record<string, string> = {
        name: fields.name,
        slug: fields.slug,
        git_remote_url: fields.git_remote_url,
        git_username: fields.git_username,
        git_branch: fields.git_branch,
    };
    if (fields.git_access_token) {
        payload.git_access_token = fields.git_access_token;
    }

    try {
        const response = props.mode === 'create'
            ? await http.post<{ id: number }>('/projects', payload)
            : await http.put<{ id: number }>(`/projects/${projectId.value}`, payload);

        projectId.value = response.data.id;
        startInitializing();
    } catch (e) {
        const invalid = validationErrors(e);
        if (invalid) {
            errors.value = invalid;
        } else {
            generalError.value = errorMessage(e, 'Could not save this project.');
        }
        step.value = 'form';
    }
}

function startInitializing() {
    step.value = 'initializing';
    messages.value = [];
    stream?.close();

    stream = openStream(`/project/${projectId.value}/initialize/stream`, {}, {
        onMessage(data) {
            if (data === '[DONE]') {
                stream?.close();
                messages.value.push({status: true, content: 'Project initialized successfully.'});
                step.value = 'succeeded';
                return;
            }
            messages.value.push({status: true, content: data});
        },
        onAppError(message) {
            messages.value.push({status: false, content: `❌ ${message}`});
            step.value = 'failed';
        },
        onConnectionLost() {
            messages.value.push({status: false, content: '❌ Lost connection to the server.'});
            step.value = 'failed';
        },
    });
}
</script>
