import tailwindcss from '@tailwindcss/postcss';
import scopeWdRoot from './build-tools/postcss-scope-wd-root.js';

export default {
    plugins: [tailwindcss(), scopeWdRoot()],
};
