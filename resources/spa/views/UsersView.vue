<template>
    <PageHeader title="Users">
        <RouterLink :to="{ name: 'users.new' }" class="wd-btn-primary">Create New User</RouterLink>
    </PageHeader>
    <PageBody :loading="loading" :error="error">
        <div v-if="newPassword" class="bg-red-100 p-4">
            <h3>New Password for {{ newPassword.name }}: <code>{{ newPassword.password }}</code></h3>
            <p>Copy this password. It will not be displayed again.</p>
        </div>

        <Block v-for="user in users" :key="user.id">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <span>
                    Name: {{ user.name }}, Email: {{ user.email }},
                    Role: {{ user.isAdmin ? 'Admin' : 'Regular User' }}
                </span>
                <SecondaryButton @click="confirmReset = user">Reset Password</SecondaryButton>
            </div>
        </Block>

        <Modal v-if="confirmReset" title="Reset Password" @close="confirmReset = null">
            <p class="mb-5 text-lg text-gray-500">
                Are you sure you want to change the password of {{ confirmReset.name }}?
            </p>
            <InlineError :message="resetError" />
            <div class="flex gap-2">
                <PrimaryButton type="button" :disabled="resetting" @click="resetPassword(confirmReset)">
                    Yes, I'm sure. Reset Password
                </PrimaryButton>
                <SecondaryButton @click="confirmReset = null">No, cancel</SecondaryButton>
            </div>
        </Modal>
    </PageBody>
</template>

<script setup lang="ts">
import {onMounted, ref} from 'vue';
import {useApp} from '../context';
import {errorMessage} from '../http';
import type {User} from '../types';
import PageHeader from '../ui/PageHeader.vue';
import PageBody from '../ui/PageBody.vue';
import Block from '../ui/Block.vue';
import Modal from '../ui/Modal.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import SecondaryButton from '../ui/SecondaryButton.vue';
import InlineError from '../ui/InlineError.vue';

const {http} = useApp();

const users = ref<User[]>([]);
const loading = ref(true);
const error = ref('');

const confirmReset = ref<User | null>(null);
const resetting = ref(false);
const resetError = ref('');
const newPassword = ref<{ name: string; password: string } | null>(null);

onMounted(async () => {
    try {
        users.value = (await http.get<User[]>('/users')).data;
    } catch (e) {
        error.value = errorMessage(e, 'Could not load the users.');
    } finally {
        loading.value = false;
    }
});

async function resetPassword(user: User) {
    resetting.value = true;
    resetError.value = '';
    try {
        newPassword.value = (await http.put<{ name: string; password: string }>(`/users/reset-password/${user.id}`)).data;
        confirmReset.value = null;
    } catch (e) {
        resetError.value = errorMessage(e, 'Could not reset the password.');
    } finally {
        resetting.value = false;
    }
}
</script>
