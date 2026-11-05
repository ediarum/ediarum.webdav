import type {RouteRecordRaw} from 'vue-router';

/**
 * The app's pages. In the portal they live under /webdav/, which the reverse proxy shares with
 * the WebDAV endpoint (/webdav/connection/{slug} goes to Laravel), so no route may start with
 * /connection.
 */
export const routes: RouteRecordRaw[] = [
    {path: '/', redirect: {name: 'projects'}},
    {path: '/projects', name: 'projects', component: () => import('./views/ProjectsView.vue')},
    {
        path: '/projects/new',
        name: 'projects.new',
        component: () => import('./views/ProjectsView.vue'),
        props: {creating: true},
    },
    {path: '/projects/:id(\\d+)', name: 'projects.show', component: () => import('./views/ProjectShowView.vue'), props: true},
    {path: '/projects/:id(\\d+)/edit', name: 'projects.edit', component: () => import('./views/ProjectEditView.vue'), props: true},
    {path: '/users', name: 'users', component: () => import('./views/UsersView.vue')},
    {path: '/users/new', name: 'users.new', component: () => import('./views/UserCreateView.vue')},
    {path: '/admin', name: 'admin', component: () => import('./views/AdminView.vue')},
    {path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./views/NotFoundView.vue')},
];
