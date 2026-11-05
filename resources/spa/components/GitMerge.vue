<template>
    <div class="text-left">
        <p class="text-sm mb-2">
            Add commits from another branch onto <code>{{ trackedBranch }}</code>. This is only offered when
            it's a clean fast-forward — if the branches have diverged, nothing here is changed.
        </p>

        <label :for="`wd-git-merge-branch-${projectId}`" class="block text-sm font-medium">Branch to add commits from</label>
        <input
            :id="`wd-git-merge-branch-${projectId}`"
            type="text"
            class="wd-input mt-1"
            v-model="query"
            @input="onQueryInput"
            autocomplete="off"
            placeholder="Search branches…"
        />
        <ul v-if="suggestions.length" class="border border-gray-300 rounded-md mt-1 max-h-40 overflow-auto">
            <li v-for="b in suggestions" :key="b">
                <button type="button" class="w-full text-left px-2 py-1 hover:bg-gray-100" @click="selectBranch(b)">
                    {{ b }}
                </button>
            </li>
        </ul>

        <div v-if="loadingStatus" class="mt-4">Checking…</div>

        <div v-else-if="status" class="mt-4">
            <template v-if="status.status === 'ff'">
                <p class="text-green-700 font-bold">{{ status.commitCount }} commit(s) can be added.</p>
                <p>{{ status.changedFiles.length }} file(s) will change.</p>
                <ul class="text-sm max-h-40 overflow-auto border border-gray-300 rounded-md p-2 mt-2">
                    <li v-for="(f, i) in status.changedFiles" :key="i">{{ f.status }} {{ f.path }}</li>
                </ul>
                <PrimaryButton
                    v-if="maintenanceModeOn"
                    type="button"
                    class="my-2"
                    :disabled="adding || addSuccess"
                    @click="confirmAdd"
                >
                    {{ adding ? 'Adding…' : `Add commits to ${trackedBranch}` }}
                </PrimaryButton>
                <template v-else>
                    <PrimaryButton type="button" class="my-2" disabled>
                        {{ `Add commits to ${trackedBranch}` }}
                    </PrimaryButton>
                    <p class="text-sm">Enable maintenance mode in the Settings panel above before adding commits.</p>
                </template>
            </template>
            <p v-else-if="status.status === 'up_to_date'">Already up to date. There's nothing to add.</p>
            <p v-else class="text-red-600 font-bold">
                This branch has diverged from <code>{{ trackedBranch }}</code>. It needs to be rebased onto
                <code>{{ trackedBranch }}</code> before its commits can be added here.
            </p>
        </div>

        <p v-if="error" class="text-red-600 font-bold mt-4">{{ error }}</p>
        <p v-if="addSuccess" class="text-green-700 font-bold mt-4">Commits added.</p>
    </div>
</template>

<script setup lang="ts">
import {onBeforeUnmount, ref} from 'vue';
import {useApp} from '../context';
import {errorMessage} from '../http';
import PrimaryButton from '../ui/PrimaryButton.vue';

interface BranchStatus {
    status: 'ff' | 'up_to_date' | 'diverged';
    // Only for 'ff'.
    commitCount: number;
    changedFiles: { status: string; path: string }[];
}

const props = defineProps<{
    projectId: number;
    trackedBranch: string;
    maintenanceModeOn: boolean;
}>();

const {http} = useApp();

const query = ref('');
const suggestions = ref<string[]>([]);
const selectedBranch = ref<string | null>(null);
const status = ref<BranchStatus | null>(null);
const loadingStatus = ref(false);
const adding = ref(false);
const addSuccess = ref(false);
const error = ref('');

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(debounceTimer));

function branchPath(branch: string): string {
    return branch.split('/').map(encodeURIComponent).join('/');
}

function onQueryInput() {
    selectedBranch.value = null;
    status.value = null;
    error.value = '';
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(searchBranches, 250);
}

async function searchBranches() {
    if (!query.value) {
        suggestions.value = [];
        return;
    }
    try {
        const response = await http.get<{ branches: string[] }>(`/project/${props.projectId}/git-branches`, {params: {q: query.value}});
        suggestions.value = response.data.branches;
    } catch (e) {
        error.value = errorMessage(e, 'Could not list branches.');
    }
}

async function selectBranch(branch: string) {
    selectedBranch.value = branch;
    query.value = branch;
    suggestions.value = [];
    status.value = null;
    error.value = '';
    addSuccess.value = false;
    loadingStatus.value = true;

    try {
        const response = await http.get<BranchStatus>(`/project/${props.projectId}/git-branches/${branchPath(branch)}/status`);
        status.value = response.data;
    } catch (e) {
        error.value = errorMessage(e, 'Could not check that branch.');
    } finally {
        loadingStatus.value = false;
    }
}

async function confirmAdd() {
    if (!selectedBranch.value || adding.value) return;
    adding.value = true;
    error.value = '';

    try {
        await http.post(`/project/${props.projectId}/git-branches/${branchPath(selectedBranch.value)}/merge`);
        addSuccess.value = true;
    } catch (e) {
        error.value = errorMessage(e, 'Could not add those commits.');
    } finally {
        adding.value = false;
    }
}
</script>
