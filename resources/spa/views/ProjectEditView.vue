<template>
    <PageHeader :title="settings?.name ?? 'Project'" />
    <PageBody :loading="loading" :error="loadError">
        <Block v-if="settings">
            <form class="space-y-6" @submit.prevent="save">
                <TextField id="wd-edit-name" v-model="fields.name" label="Project Name" :error="errors.name?.[0]" />
                <div>
                    <TextField
                        id="wd-edit-slug"
                        v-model="fields.slug"
                        label="Slug (lowercase letters and digits, words separated by single hyphens)"
                        readonly
                        :error="errors.slug?.[0]"
                    />
                    <p class="text-sm text-gray-600 mt-1">
                        The slug can't be changed after the project has been created -- it names this project's
                        folder on the server and is how the platform's other apps refer to it.
                    </p>
                </div>
                <TextField id="wd-edit-git-remote-url" v-model="fields.git_remote_url" label="Git Remote URL" :error="errors.git_remote_url?.[0]" />
                <TextField id="wd-edit-git-username" v-model="fields.git_username" label="Git Username" :error="errors.git_username?.[0]" />
                <SecretField
                    id="wd-edit-git-token"
                    v-model="secrets.git_access_token"
                    label="Git Personal Access Token"
                    :saved="settings.has_git_access_token"
                    :error="errors.git_access_token?.[0]"
                />
                <TextField id="wd-edit-git-branch" v-model="fields.git_branch" label="Tracked Git Branch" :error="errors.git_branch?.[0]" />
                <div>
                    <label for="wd-edit-push-ediarum-services" class="font-medium text-sm text-gray-700">Push to ediarum services?</label>
                    <input id="wd-edit-push-ediarum-services" v-model="fields.push_to_ediarum_services" type="checkbox" class="mx-5" />
                    <InlineError :message="errors.push_to_ediarum_services?.[0]" />
                </div>
                <div>
                    <label for="wd-edit-index-source-of-truth" class="font-medium text-sm text-gray-700">Index app is the source of truth?</label>
                    <input id="wd-edit-index-source-of-truth" v-model="fields.is_index_app_source_of_truth" type="checkbox" class="mx-5" />
                    <InlineError :message="errors.is_index_app_source_of_truth?.[0]" />
                </div>
                <TextField id="wd-edit-exist-url" v-model="fields.exist_base_url" label="eXist-db Base Url" :error="errors.exist_base_url?.[0]" />
                <TextField
                    id="wd-edit-exist-path"
                    v-model="fields.exist_data_path"
                    label="eXist-db Data Path (for example: '/db/apps/project-name/data')"
                    :error="errors.exist_data_path?.[0]"
                />
                <TextField id="wd-edit-exist-username" v-model="fields.exist_username" label="eXist-db Username" :error="errors.exist_username?.[0]" />
                <SecretField
                    id="wd-edit-exist-password"
                    v-model="secrets.exist_password"
                    v-model:delete="deletes.exist_password"
                    label="eXist-db Password"
                    :saved="settings.has_exist_password"
                    deletable
                    :error="errors.exist_password?.[0]"
                />

                <InlineError :message="saveError" />

                <div class="flex gap-2">
                    <PrimaryButton :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</PrimaryButton>
                    <RouterLink :to="{ name: 'projects.show', params: { id } }" class="wd-btn-secondary">Cancel</RouterLink>
                </div>
            </form>
        </Block>
    </PageBody>
</template>

<script setup lang="ts">
import {onMounted, reactive, ref} from 'vue';
import {useRouter} from 'vue-router';
import {useApp} from '../context';
import {errorMessage, validationErrors} from '../http';
import type {ProjectDetail, ProjectSettings} from '../types';
import PageHeader from '../ui/PageHeader.vue';
import PageBody from '../ui/PageBody.vue';
import Block from '../ui/Block.vue';
import TextField from '../ui/TextField.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import InlineError from '../ui/InlineError.vue';
import SecretField from '../components/SecretField.vue';

const props = defineProps<{ id: string }>();

const {http} = useApp();
const router = useRouter();

const settings = ref<ProjectSettings | null>(null);
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);
const saveError = ref('');
const errors = ref<Record<string, string[]>>({});

const fields = reactive({
    name: '',
    slug: '',
    git_remote_url: '' as string | null,
    git_username: '' as string | null,
    git_branch: '' as string | null,
    push_to_ediarum_services: false,
    is_index_app_source_of_truth: false,
    exist_base_url: '' as string | null,
    exist_data_path: '' as string | null,
    exist_username: '' as string | null,
});

// Never filled from the server; a blank secret keeps the saved one.
const secrets = reactive({
    git_access_token: '',
    exist_password: '',
});

const deletes = reactive({
    exist_password: false,
});

onMounted(async () => {
    try {
        const project = (await http.get<ProjectDetail>(`/projects/${props.id}`)).data;
        settings.value = project.settings;
        for (const key of Object.keys(fields) as (keyof typeof fields)[]) {
            (fields as Record<string, unknown>)[key] = project.settings[key];
        }
    } catch (e) {
        loadError.value = errorMessage(e, 'Could not load this project.');
    } finally {
        loading.value = false;
    }
});

async function save() {
    if (saving.value) return;
    saving.value = true;
    saveError.value = '';
    errors.value = {};

    const payload: Record<string, string | boolean | null> = {...fields};
    for (const [key, value] of Object.entries(secrets)) {
        if (value) payload[key] = value;
    }
    for (const [key, value] of Object.entries(deletes)) {
        if (value) payload[`${key}_delete`] = true;
    }

    try {
        await http.put(`/projects/${props.id}`, payload);
        router.push({name: 'projects.show', params: {id: props.id}});
    } catch (e) {
        const invalid = validationErrors(e);
        if (invalid) {
            errors.value = invalid;
        } else {
            saveError.value = errorMessage(e, 'Could not save this project.');
        }
    } finally {
        saving.value = false;
    }
}
</script>
