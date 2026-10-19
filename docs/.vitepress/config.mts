import { defineConfig } from 'vitepress'

// The documentation of each version, built by .github/workflows/docs.yml from its branch: main at the root, 4.x, no
// longer maintained, under /4.x/. The links are absolute, the versions being separate builds.
const SITE = 'https://hecht-a.github.io/graphql-orm'
const VERSIONS = [
    { text: '5.x (current)', link: `${SITE}/` },
    { text: '4.x (unmaintained)', link: `${SITE}/4.x/` },
]

// Documentation site of the bundle, built from the Markdown pages of docs/ and published on GitHub Pages.
export default defineConfig({
    title: 'GraphQL ORM',
    description: 'A GraphQL ORM for PHP and Symfony, inspired by Doctrine',
    // Served as a project page: https://hecht-a.github.io/graphql-orm/
    base: '/graphql-orm/',
    cleanUrls: true,
    lastUpdated: true,
    srcExclude: ['**/node_modules/**'],
    markdown: {
        // NEON (PHPStan configuration) is close enough to YAML to be highlighted as such.
        languageAlias: { neon: 'yaml' },
    },
    themeConfig: {
        nav: [
            { text: 'Guide', link: '/configuration' },
            { text: 'Changelog', link: '/changelog' },
            { text: '5.x', items: VERSIONS },
        ],
        sidebar: [
            {
                text: 'Getting started',
                items: [
                    { text: 'Introduction', link: '/' },
                    { text: 'Configuration', link: '/configuration' },
                    { text: 'Entity mapping', link: '/entity-mapping' },
                ],
            },
            {
                text: 'Querying',
                items: [
                    { text: 'Repositories', link: '/repositories' },
                    { text: 'Query Builder', link: '/query-builder' },
                    { text: 'Pagination', link: '/pagination' },
                    { text: 'Writing entities', link: '/writing' },
                    { text: 'Dialects', link: '/dialects' },
                ],
            },
            {
                text: 'Going further',
                items: [
                    { text: 'Hydration hooks', link: '/hydration-hooks' },
                    { text: 'Forms', link: '/forms' },
                    { text: 'Serializer', link: '/serializer' },
                    { text: 'Doctrine', link: '/doctrine' },
                    { text: 'Caching', link: '/caching' },
                    { text: 'Several APIs', link: '/multiple-apis' },
                    { text: 'Events', link: '/events' },
                    { text: 'Schema validation', link: '/schema-validation' },
                    { text: 'Console commands', link: '/commands' },
                ],
            },
            {
                text: 'Tooling',
                items: [
                    { text: 'Logging', link: '/logging' },
                    { text: 'Profiler', link: '/profiler' },
                    { text: 'PHPStan rules', link: '/phpstan' },
                    { text: 'Testing', link: '/testing' },
                ],
            },
            {
                text: 'Cookbook',
                items: [
                    { text: 'From HttpClient to the bundle', link: '/cookbook/from-http-client' },
                    { text: 'When to use it, and when not', link: '/cookbook/when-to-use' },
                    { text: 'A paginated list', link: '/cookbook/paginated-list' },
                    { text: 'A form creating an entity', link: '/cookbook/creation-form' },
                    { text: 'Speeding up a slow page', link: '/cookbook/slow-page' },
                ],
            },
            {
                text: 'Project',
                items: [
                    { text: 'Upgrading from 4.x', link: '/upgrading' },
                    { text: 'Changelog', link: '/changelog' },
                ],
            },
        ],
        search: { provider: 'local' },
        socialLinks: [{ icon: 'github', link: 'https://github.com/hecht-a/graphql-orm' }],
        editLink: {
            pattern: 'https://github.com/hecht-a/graphql-orm/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },
        footer: {
            message: 'Released under the MIT License.',
        },
    },
})
