<template>
    <PageHeader title="Register New User" />
    <PageBody>
        <Block>
            <form class="space-y-4" @submit.prevent="submit">
                <TextField id="wd-user-name" v-model="fields.name" label="Name" autocomplete="off" :error="errors.name?.[0]" />
                <TextField id="wd-user-email" v-model="fields.email" type="email" label="Email" :error="errors.email?.[0]" />
                <TextField
                    id="wd-user-password"
                    v-model="fields.password"
                    type="password"
                    autocomplete="new-password"
                    label="Password"
                    :error="errors.password?.[0]"
                />
                <TextField
                    id="wd-user-password-confirmation"
                    v-model="fields.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    label="Confirm Password"
                    :error="errors.password_confirmation?.[0]"
                />
                <div>
                    <label for="wd-user-is-admin" class="font-medium text-sm text-gray-700">Make the user an Admin?</label>
                    <input id="wd-user-is-admin" v-model="fields.is_admin" type="checkbox" class="mx-5" />
                </div>

                <InlineError :message="error" />

                <div class="flex gap-2 justify-end">
                    <RouterLink :to="{ name: 'users' }" class="wd-btn-secondary">Cancel</RouterLink>
                    <PrimaryButton :disabled="saving">Register New User</PrimaryButton>
                </div>
            </form>
        </Block>
    </PageBody>
</template>

<script setup lang="ts">
import {reactive, ref} from 'vue';
import {useRouter} from 'vue-router';
import {useApp} from '../context';
import {errorMessage, validationErrors} from '../http';
import PageHeader from '../ui/PageHeader.vue';
import PageBody from '../ui/PageBody.vue';
import Block from '../ui/Block.vue';
import TextField from '../ui/TextField.vue';
import PrimaryButton from '../ui/PrimaryButton.vue';
import InlineError from '../ui/InlineError.vue';

const {http} = useApp();
const router = useRouter();

const fields = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
});
const errors = ref<Record<string, string[]>>({});
const error = ref('');
const saving = ref(false);

async function submit() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    error.value = '';

    try {
        await http.post('/users', fields);
        router.push({name: 'users'});
    } catch (e) {
        const invalid = validationErrors(e);
        if (invalid) {
            errors.value = invalid;
        } else {
            error.value = errorMessage(e, 'Could not create the user.');
        }
    } finally {
        saving.value = false;
    }
}
</script>
