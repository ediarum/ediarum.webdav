<!--
    The app's own layout: the single header on screen, and the current page below it.

    The header hosts the platform's controls (ediarum.platform docs/integrating-an-app.md, 3.10):
    <ediarum-home> at the far left (app switcher) and <ediarum-user-menu> at the far right (profile,
    logout). While the user menu is in our element, the platform hides its own top bar. Outside the
    platform (standalone dev page) both tags are unknown elements and render as nothing.

    Only the content below the header scrolls (3.7); the header is never fixed to the viewport.
-->
<template>
    <div class="wd-root h-full flex flex-col bg-ediarum-gray font-sans antialiased text-gray-900">
        <header class="flex-none flex items-center gap-4 h-13 px-3 bg-white border-b border-gray-100 text-gray-700">
            <ediarum-home class="flex-none"></ediarum-home>
            <span class="flex-none font-semibold text-gray-900">WebDAV</span>
            <nav class="min-w-0 flex-1 self-stretch flex gap-6 overflow-x-auto" aria-label="WebDAV">
                <RouterLink
                    v-for="item in nav"
                    :key="item.name"
                    :to="{ name: item.name }"
                    class="flex-none inline-flex items-center px-1 border-b-2 text-sm font-medium leading-5 whitespace-nowrap transition"
                    :class="isActive(item.name)
                        ? 'border-ediarum-red text-gray-900'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                >{{ item.label }}</RouterLink>
            </nav>
            <ediarum-user-menu class="flex-none"></ediarum-user-menu>
        </header>
        <div class="flex-1 min-h-0 overflow-y-auto">
            <RouterView />
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {useRoute} from 'vue-router';
import {useApp} from './context';
import type {AppConfig} from './types';

const {http} = useApp();
const route = useRoute();

const config = ref<AppConfig>({adminPanelEnabled: false});

onMounted(async () => {
    try {
        config.value = (await http.get<AppConfig>('/config')).data;
    } catch {
        // Keep the defaults; a 401 is handled by the http client.
    }
});

const nav = computed(() => [
    {name: 'projects', label: 'Manage Projects'},
    {name: 'users', label: 'Manage Users'},
    ...(config.value.adminPanelEnabled ? [{name: 'admin', label: 'Admin Panel'}] : []),
]);

function isActive(name: string): boolean {
    return String(route.name ?? '').split('.')[0] === name;
}
</script>
