<template>
    <PageHeader title="Not Found" />
    <PageBody>
        <Block>
            <p>This page does not exist.</p>
            <RouterLink :to="{ name: 'projects' }" class="text-blue-600 hover:underline">Back to the projects</RouterLink>
        </Block>
    </PageBody>
</template>

<script setup lang="ts">
import PageHeader from '../ui/PageHeader.vue';
import PageBody from '../ui/PageBody.vue';
import Block from '../ui/Block.vue';
</script>
