<template>
    <PageHeader title="Projects">
        <template #after-title>
            <div v-if="projects.length > 1" class="flex items-center gap-2">
                <span id="wd-projects-sort-label" class="text-sm text-gray-600">Sort by</span>
                <div role="radiogroup" aria-labelledby="wd-projects-sort-label" class="inline-flex rounded-md shadow-sm overflow-hidden border border-gray-300 bg-white text-sm">
                    <button
                        v-for="option in sortOptions"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="sort === option.value"
                        class="px-3 py-1.5 border-l border-gray-300 first:border-l-0 transition"
                        :class="sort === option.value ? 'bg-gray-800 text-white' : 'text-gray-700 hover:bg-gray-50'"
                        @click="sort = option.value"
                    >{{ option.label }}</button>
                </div>
            </div>
        </template>
        <RouterLink :to="{ name: 'projects.new' }" class="wd-btn-primary">Create New Project</RouterLink>
    </PageHeader>
    <PageBody :loading="loading" :error="error">
        <RouterLink
            v-for="project in sortedProjects"
            :key="project.id"
            :to="{ name: 'projects.show', params: { id: project.id } }"
            class="block"
        >
            <Block>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <span>
                        Name: {{ project.name }}
                        <span v-if="!project.initialized" class="ml-4 text-sm text-red-600 font-bold">(Not Initialized)</span>
                        <span v-if="project.isInMaintenanceMode" class="ml-4 text-sm text-red-600 font-bold">(In Maintenance Mode)</span>
                    </span>
                    <span class="text-sm text-gray-500">
                        {{ project.lastEditedAt ? `Last edited ${formatDate(project.lastEditedAt)}` : 'Never edited' }}
                    </span>
                </div>
            </Block>
        </RouterLink>
        <Block v-if="projects.length === 0">
            <p class="text-center text-sm text-gray-500">(No Projects)</p>
        </Block>
    </PageBody>

    <InitializeProjectDialog
        v-if="creating"
        mode="create"
        @close="router.push({ name: 'projects' })"
        @done="(id) => router.push({ name: 'projects.show', params: { id } })"
    />
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {useRouter} from 'vue-router';
import {useApp} from '../context';
import {errorMessage} from '../http';
import type {ProjectSummary} from '../types';
import PageHeader from '../ui/PageHeader.vue';
import PageBody from '../ui/PageBody.vue';
import Block from '../ui/Block.vue';
import InitializeProjectDialog from '../components/InitializeProjectDialog.vue';

/** `creating`: the /projects/new route, which shows the create dialog over the list. */
defineProps<{ creating?: boolean }>();

const {http} = useApp();
const router = useRouter();

const projects = ref<ProjectSummary[]>([]);
const loading = ref(true);
const error = ref('');

type Sort = 'name-asc' | 'name-desc' | 'last-edited';

const sortOptions: { value: Sort; label: string }[] = [
    {value: 'name-asc', label: 'A–Z'},
    {value: 'name-desc', label: 'Z–A'},
    {value: 'last-edited', label: 'Last edited'},
];

/** Alphabetical by default, on every visit; the choice is not remembered. */
const sort = ref<Sort>('name-asc');

const collator = new Intl.Collator(undefined, {sensitivity: 'base', numeric: true});
const byName = (a: ProjectSummary, b: ProjectSummary) => collator.compare(a.name, b.name);

/** Most recently edited first; never-edited projects last, alphabetically among themselves. */
function byLastEdited(a: ProjectSummary, b: ProjectSummary): number {
    const ta = a.lastEditedAt ? Date.parse(a.lastEditedAt) : -Infinity;
    const tb = b.lastEditedAt ? Date.parse(b.lastEditedAt) : -Infinity;
    return ta === tb ? byName(a, b) : tb - ta;
}

const sortedProjects = computed(() => {
    const list = [...projects.value];
    switch (sort.value) {
        case 'name-asc': return list.sort(byName);
        case 'name-desc': return list.sort((a, b) => byName(b, a));
        case 'last-edited': return list.sort(byLastEdited);
    }
});

const dateFormat = new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'});
const formatDate = (iso: string) => dateFormat.format(new Date(iso));

onMounted(async () => {
    try {
        projects.value = (await http.get<ProjectSummary[]>('/projects')).data;
    } catch (e) {
        error.value = errorMessage(e, 'Could not load the projects.');
    } finally {
        loading.value = false;
    }
});
</script>
