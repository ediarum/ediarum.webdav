<template>
    <PageHeader title="Admin Panel" />
    <PageBody :loading="loading && !data" :error="error">
        <template v-if="data">
            <Block>
                <div class="flex flex-wrap items-center justify-between gap-2 pb-4">
                    <h2 class="text-xl">Recent Commits (last {{ data.commitHours }} {{ data.commitHours === 1 ? 'hour' : 'hours' }})</h2>
                    <div class="flex gap-2 text-sm">
                        <RouterLink
                            v-for="w in windows"
                            :key="w.hours"
                            :to="{ name: 'admin', query: { hours: w.hours } }"
                            class="px-2 py-1 rounded"
                            :class="data.commitHours === w.hours ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                        >{{ w.label }}</RouterLink>
                    </div>
                </div>
                <div class="max-h-96 overflow-y-auto rounded-lg">
                    <DataTable
                        :columns="[{ key: 'project', label: 'Project' }, { key: 'commitHash', label: 'Commit' }, { key: 'committedAt', label: 'Committed At' }]"
                        :rows="data.recentCommits"
                        :row-key="(row) => `${row.project.id}-${row.committedAt}`"
                        :empty-text="`(No Commits in the Last ${data.commitHours} ${data.commitHours === 1 ? 'Hour' : 'Hours'})`"
                    >
                        <template #cell-project="{ row }"><ProjectLink :project="row.project" /></template>
                        <template #cell-commitHash="{ row }">
                            <a
                                v-if="row.commitHash && row.commitUrl"
                                :href="row.commitUrl"
                                target="_blank"
                                rel="noopener"
                                class="font-mono text-blue-600 hover:underline"
                                :title="row.commitHash"
                            >{{ row.commitHash.slice(0, 8) }}</a>
                            <span v-else-if="row.commitHash" class="font-mono" :title="row.commitHash">{{ row.commitHash.slice(0, 8) }}</span>
                            <span v-else class="text-gray-400">–</span>
                        </template>
                        <template #cell-committedAt="{ row }">
                            <span class="whitespace-nowrap">{{ row.committedAgo }} ({{ row.committedAt }})</span>
                        </template>
                    </DataTable>
                </div>
            </Block>

            <Block title="Failed Pushes to Git Remotes">
                <DataTable
                    :columns="[{ key: 'project', label: 'Project' }, { key: 'failedAt', label: 'Failed At' }, { key: 'reason', label: 'Reason' }]"
                    :rows="data.failedGitRemotePushes"
                    :row-key="(row) => `${row.project.id}-${row.failedAt}`"
                    empty-text="(No Failed Pushes to Git Remotes)"
                >
                    <template #cell-project="{ row }"><ProjectLink :project="row.project" /></template>
                </DataTable>
            </Block>

            <Block title="Projects With Invalid Git Credentials">
                <p class="pb-4 text-sm text-gray-600">
                    The Git credentials stored for these projects were rejected when pushing, so their pushes
                    currently go through the master Git user instead. "Since" is the first time the Git credentials failed.
                </p>
                <p>
                    Please renew the project's personal access token to fix this.
                </p>
                <DataTable
                    :columns="[{ key: 'project', label: 'Project' }, { key: 'since', label: 'Since' }]"
                    :rows="data.projectsUsingMasterFallback"
                    :row-key="(row) => row.project.id"
                    empty-text="(No Projects With Invalid Git Credentials)"
                >
                    <template #cell-project="{ row }"><ProjectLink :project="row.project" /></template>
                </DataTable>
            </Block>

            <Block title="Projects Currently Failing to Commit">
                <DataTable
                    :columns="[{ key: 'project', label: 'Project' }, { key: 'since', label: 'Since' }, { key: 'reason', label: 'Reason' }]"
                    :rows="data.projectsWithBrokenCommits"
                    :row-key="(row) => row.project.id"
                    empty-text="(No Projects Currently Failing to Commit)"
                >
                    <template #cell-project="{ row }"><ProjectLink :project="row.project" /></template>
                </DataTable>
            </Block>
        </template>
    </PageBody>
</template>

<script setup lang="ts">
import {defineComponent, h, ref, watch, type PropType} from 'vue';
import {RouterLink, useRoute} from 'vue-router';
import axios from 'axios';
import {useApp} from '../context';
import {errorMessage} from '../http';
import type {AdminData, ProjectRef} from '../types';
import PageHeader from '../ui/PageHeader.vue';
import PageBody from '../ui/PageBody.vue';
import Block from '../ui/Block.vue';
import DataTable from '../ui/DataTable.vue';

const ProjectLink = defineComponent({
    props: {project: {type: Object as PropType<ProjectRef>, required: true}},
    setup: (props) => () => h(RouterLink, {
        to: {name: 'projects.show', params: {id: props.project.id}},
        class: 'text-blue-600 hover:underline',
    }, () => props.project.name),
});

const windows = [
    {hours: 1, label: '1h'},
    {hours: 5, label: '5h'},
    {hours: 24, label: '24h'},
    {hours: 168, label: '7d'},
];

const {http} = useApp();
const route = useRoute();

const data = ref<AdminData | null>(null);
const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const hours = route.query.hours ? Number(route.query.hours) : undefined;
        data.value = (await http.get<AdminData>('/admin', {params: {hours}})).data;
    } catch (e) {
        data.value = null;
        error.value = axios.isAxiosError(e) && e.response?.status === 404
            ? 'The admin panel is disabled on this server.'
            : errorMessage(e, 'Could not load the admin panel.');
    } finally {
        loading.value = false;
    }
}

watch(() => route.query.hours, load, {immediate: true});
</script>
