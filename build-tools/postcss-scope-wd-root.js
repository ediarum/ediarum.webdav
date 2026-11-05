/**
 * Confines Tailwind's global styles in resources/spa/ CSS to the app's root element, so the
 * stylesheet can be injected into the ediarum.portal page without touching the portal.
 *
 * Inside `@layer base` and `@layer theme` (preflight and the theme variables), every selector is
 * rewritten:
 *   html, :root, :host        ->  :where(.wd-root)
 *   body                      ->  dropped (preflight's `body { line-height: inherit }` would
 *                                 otherwise override the html rule on the same element)
 *   *, ::before, ::backdrop   ->  the root itself and everything inside it
 *   anything else (h1, a, …)  ->  :where(.wd-root) h1
 * `:where()` adds no specificity, so the rules keep their original weight. Utilities and
 * components are left alone: they only apply where their classes are used.
 *
 * See planning/portal-integration.md ("CSS is scoped to .wd-root").
 */
import path from 'node:path';

const ROOT = ':where(.wd-root)';
const SCOPED_DIR = path.resolve('resources/spa') + path.sep;
const SCOPED_LAYERS = new Set(['base', 'theme']);

function scopeSelector(selector) {
    const s = selector.trim();

    if (/^body(?![\w-])/.test(s)) return [];

    const docRoot = s.match(/^(html|:root|:host)(?![\w-])(.*)$/);
    if (docRoot) return [`${ROOT}${docRoot[2]}`];

    if (s.startsWith('*')) return [`${ROOT}${s.slice(1)}`, `${ROOT} ${s}`];
    if (s.startsWith('::')) return [`${ROOT}${s}`, `${ROOT} ${s}`];

    return [`${ROOT} ${s}`];
}

function inScopedLayer(node) {
    for (let parent = node.parent; parent; parent = parent.parent) {
        if (parent.type === 'atrule' && parent.name === 'layer' && SCOPED_LAYERS.has(parent.params.trim())) {
            return true;
        }
    }
    return false;
}

/** @type {import('postcss').PluginCreator} */
export default function scopeWdRoot() {
    return {
        postcssPlugin: 'postcss-scope-wd-root',
        OnceExit(root) {
            const file = root.source?.input?.file;
            if (!file || !path.resolve(file).startsWith(SCOPED_DIR)) return;

            root.walkRules((rule) => {
                // Selectors inside @keyframes are keyframe offsets, not elements.
                if (rule.parent?.type === 'atrule' && /keyframes$/.test(rule.parent.name)) return;
                if (!inScopedLayer(rule)) return;

                const selectors = [...new Set(rule.selectors.flatMap(scopeSelector))];
                if (selectors.length === 0) rule.remove();
                else rule.selectors = selectors;
            });
        },
    };
}
scopeWdRoot.postcss = true;
