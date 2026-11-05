<template>
    <PageHeader :title="project?.name ?? 'Project'">
        <RouterLink v-if="project" :to="{ name: 'projects.edit', params: { id: project.id } }" class="wd-btn-primary">
            Edit
        </RouterLink>
    </PageHeader>
    <PageBody :loading="loading" :error="error">
        <template v-if="project">
            <Block v-if="!project.initialized" :title="`Project Settings for &quot;${project.name}&quot;`">
                <div class="space-y-3">
                    <p class="text-red-600 font-bold">
                        This project has not been initialized yet. Its Git repository still needs to be
                        cloned and its tracked branch checked out before WebDAV, git, or sync features become
                        available for it.
                    </p>
                    <PrimaryButton type="button" @click="initializing = true">Initialize Project</PrimaryButton>
                </div>
            </Block>

            <template v-else>
                <Block :title="`Project Settings for &quot;${project.name}&quot;`">
                    <div class="space-y-3">
                        <p>
                            Ediarum WebDAV Path: <code>{{ project.webdavUrl }}</code>
                            <MaintenanceMode
                                :project-id="project.id"
                                :enabled="project.isInMaintenanceMode"
                                @change="(on) => (project!.isInMaintenanceMode = on)"
                            />
                        </p>
                        <p v-if="project.gitCommitBroken" class="text-red-600 font-bold">
                            Git commits are currently failing for this project; recent WebDAV changes are not
                            being committed. Last error: {{ project.gitCommitBrokenReason }}
                        </p>
                        <p>Pushes to Git remote: <code>{{ project.gitRemoteConfigured ? `Yes: ${project.gitRemoteUrl}` : 'No' }}</code></p>
                        <p>Tracks branch: <code>{{ project.gitBranch }}</code></p>
                        <p>Pushes to ediarum services: <code>{{ project.ediarumServicesUrl ? `Yes: ${project.ediarumServicesUrl}` : 'No' }}</code></p>
                        <p>Pushes to eXist-db: <code>{{ project.existConfigured ? `Yes: ${project.existUrl}` : 'No' }}</code></p>
                    </div>
                </Block>

                <Block title="Add Commits From Another Branch">
                    <GitMerge
                        v-if="project.gitRemoteConfigured"
                        :project-id="project.id"
                        :tracked-branch="project.gitBranch ?? ''"
                        :maintenance-mode-on="project.isInMaintenanceMode"
                    />
                    <p v-else class="text-sm">
                        Configure a Git remote (URL, username, and personal access token) in the project settings to
                        enable adding commits from another branch.
                    </p>
                </Block>

                <Block v-if="project.existConfigured" title="Manual eXist-db Synchronization">
                    <ExistSync :project-id="project.id" :folders="project.folders" />
                </Block>
            </template>

            <Block :title="`Users for ${project.name}`">
                <ProjectUsers
                    :project-id="project.id"
                    :initial-users="project.users"
                    :initial-available-users="project.availableUsers"
                />
            </Block>

            <template v-if="project.initialized">
                <Block title="WebDAV Locks">
                    <WebdavLocks :project-id="project.id" :initial-locks="project.locks" />
                </Block>
                <Block title="Locked Paths">
                    <LockedPaths :project-id="project.id" :initial-locked-paths="project.lockedPaths" />
                </Block>
                <Block v-if="project.existConfigured" title="Failed Pushes to eXist-db">
                    <FailedPushes :project-id="project.id" :initial-failed-jobs="project.failedJobs" />
                </Block>
            </template>

            <InitializeProjectDialog
                v-if="initializing"
                mode="retry"
                :project-id="project.id"
                :initial-name="project.settings.name"
                :initial-slug="project.settings.slug"
                :initial-git-remote-url="project.settings.git_remote_url"
                :initial-git-username="project.settings.git_username"
                :initial-git-branch="project.settings.git_branch"
                @close="initializing = false"
                @done="onInitialized"
            />
        </template>
    </PageBody>
</template>

<script setup lang="ts">
import {ref, watch} from 'vue';
import {useApp} from '../context';
import {errorMessage} from '../http';
import type {ProjectDetail} from '../types';
import PageHeader from '../ui/PageHeader.vue';
import PageBody from '../ui/PageBody.vue';
import Block from '../ui/Block.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import MaintenanceMode from '../components/MaintenanceMode.vue';
import GitMerge from '../components/GitMerge.vue';
import ExistSync from '../components/ExistSync.vue';
import ProjectUsers from '../components/ProjectUsers.vue';
import WebdavLocks from '../components/WebdavLocks.vue';
import LockedPaths from '../components/LockedPaths.vue';
import FailedPushes from '../components/FailedPushes.vue';
import InitializeProjectDialog from '../components/InitializeProjectDialog.vue';

const props = defineProps<{ id: string }>();

const {http} = useApp();

const project = ref<ProjectDetail | null>(null);
const loading = ref(true);
const error = ref('');
const initializing = ref(false);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        project.value = (await http.get<ProjectDetail>(`/projects/${props.id}`)).data;
    } catch (e) {
        project.value = null;
        error.value = errorMessage(e, 'Could not load this project.');
    } finally {
        loading.value = false;
    }
}

function onInitialized() {
    initializing.value = false;
    load();
}

watch(() => props.id, load, {immediate: true});
</script>
