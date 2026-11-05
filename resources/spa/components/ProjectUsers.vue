<template>
    <div>
        <InlineError :message="error" />
        <div class="py-4 flex w-80" v-for="user in users" :key="user.id">
            <div class="inline-block py-2 flex-grow">{{ user.name }}</div>
            <PrimaryButton type="button" class="mt-1" :disabled="removingId === user.id" @click="removeUser(user)">
                Remove
            </PrimaryButton>
        </div>
        <p v-if="users.length === 0" class="text-sm text-gray-500 py-2">(No Users)</p>
        <form class="pt-10 flex gap-4 items-center" @submit.prevent="addUser">
            <select v-model="selectedUserId" class="wd-input w-auto" aria-label="User to add">
                <option value="" disabled>Select a user</option>
                <option v-for="user in availableUsers" :key="user.id" :value="user.id">{{ user.name }}</option>
            </select>
            <PrimaryButton :disabled="adding || !selectedUserId">Add User</PrimaryButton>
        </form>
    </div>
</template>

<script setup lang="ts">
import {ref} from 'vue';
import {useApp} from '../context';
import {errorMessage} from '../http';
import type {UserRef} from '../types';
import PrimaryButton from '../ui/PrimaryButton.vue';
import InlineError from '../ui/InlineError.vue';

const props = defineProps<{
    projectId: number;
    initialUsers: UserRef[];
    initialAvailableUsers: UserRef[];
}>();

const {http} = useApp();

const users = ref<UserRef[]>([...props.initialUsers]);
const availableUsers = ref<UserRef[]>([...props.initialAvailableUsers]);
const selectedUserId = ref<number | ''>('');
const error = ref('');
const adding = ref(false);
const removingId = ref<number | null>(null);

const byName = (a: UserRef, b: UserRef) => a.name.localeCompare(b.name);

async function addUser() {
    if (!selectedUserId.value || adding.value) return;
    adding.value = true;
    error.value = '';

    try {
        await http.post('/project-user', {project_id: props.projectId, user_id: selectedUserId.value});

        const idx = availableUsers.value.findIndex((u) => u.id === selectedUserId.value);
        if (idx !== -1) {
            users.value.push(availableUsers.value[idx]);
            users.value.sort(byName);
            availableUsers.value.splice(idx, 1);
        }
        selectedUserId.value = '';
    } catch (e) {
        error.value = errorMessage(e, 'Could not add that user.');
    } finally {
        adding.value = false;
    }
}

async function removeUser(user: UserRef) {
    if (removingId.value !== null) return;
    removingId.value = user.id;
    error.value = '';

    try {
        await http.delete('/project-user', {data: {project_id: props.projectId, user_id: user.id}});

        users.value = users.value.filter((u) => u.id !== user.id);
        availableUsers.value.push(user);
        availableUsers.value.sort(byName);
    } catch (e) {
        error.value = errorMessage(e, 'Could not remove that user.');
    } finally {
        removingId.value = null;
    }
}
</script>
