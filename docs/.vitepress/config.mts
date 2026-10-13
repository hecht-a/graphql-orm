import { defineConfig } from 'vitepress'

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
                    { text: 'Dialects', link: '/dialects' },
                ],
            },
            {
                text: 'Going further',
                items: [
                    { text: 'Hydration hooks', link: '/hydration-hooks' },
                    { text: 'Forms', link: '/forms' },
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
                text: 'Project',
                items: [{ text: 'Changelog', link: '/changelog' }],
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
