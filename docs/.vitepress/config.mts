import { defineConfig } from 'vitepress'

const site = 'https://achedon12.github.io/golem/'
const description = 'Integration tests for PocketMine-MP plugins: a real server, simulated players, one command.'

export default defineConfig({
  title: 'Golem',
  titleTemplate: ':title · Golem',
  description,
  base: '/golem/',
  lang: 'en-US',
  cleanUrls: true,
  lastUpdated: true,
  appearance: 'dark',
  srcExclude: ['README.md', 'node_modules/**'],

  sitemap: { hostname: site },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/golem/favicon.svg' }],
    ['meta', { name: 'theme-color', content: '#22c55e' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Golem' }],
    ['meta', { property: 'og:image', content: site + 'og.png' }],
    ['meta', { property: 'og:image:width', content: '1280' }],
    ['meta', { property: 'og:image:height', content: '640' }],
    ['meta', { property: 'og:image:alt', content: 'Golem: integration tests for PocketMine-MP plugins' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:image', content: site + 'og.png' }],
  ],

  // Per page: canonical URL, and titles and descriptions for link previews.
  transformHead({ pageData, title, description: pageDescription }) {
    const path = pageData.relativePath.replace(/(^|\/)index\.md$/, '$1').replace(/\.md$/, '')
    const url = site + path
    const head: [string, Record<string, string>, string?][] = [
      ['link', { rel: 'canonical', href: url }],
      ['meta', { property: 'og:url', content: url }],
      ['meta', { property: 'og:title', content: title }],
      ['meta', { property: 'og:description', content: pageDescription }],
      ['meta', { name: 'twitter:title', content: title }],
      ['meta', { name: 'twitter:description', content: pageDescription }],
    ]
    if (path === '') {
      head.push(['script', { type: 'application/ld+json' }, JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'SoftwareSourceCode',
        name: 'Golem',
        description,
        url: site,
        codeRepository: 'https://github.com/achedon12/golem',
        programmingLanguage: 'PHP',
        runtimePlatform: 'PocketMine-MP 5',
        license: 'https://opensource.org/licenses/MIT',
        keywords: 'PocketMine-MP, PMMP, Minecraft Bedrock, plugin testing, integration testing, PHP',
        author: { '@type': 'Person', name: 'Achedon12', url: 'https://github.com/achedon12' },
      })])
    }
    return head
  },

  markdown: {
    theme: { light: 'github-light', dark: 'github-dark' },
  },

  themeConfig: {
    logo: { src: '/favicon.svg', alt: 'Golem' },
    siteTitle: 'golem',

    nav: [
      { text: 'Guide', link: '/getting-started', activeMatch: '^/(getting-started|writing-tests|golems|assertions)' },
      { text: 'Reference', link: '/configuration', activeMatch: '^/(configuration|ci|how-it-works)' },
      {
        text: 'v0.1.1',
        items: [
          { text: 'Changelog', link: 'https://github.com/achedon12/golem/blob/main/CHANGELOG.md' },
          { text: 'Releases', link: 'https://github.com/achedon12/golem/releases' },
          { text: 'Packagist', link: 'https://packagist.org/packages/achedon12/golem' },
          { text: 'GitHub Action', link: 'https://github.com/marketplace/actions/golem-pocketmine-mp-tests' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'Getting started', link: '/getting-started' },
          { text: 'Writing tests', link: '/writing-tests' },
          { text: 'Golems', link: '/golems' },
          { text: 'Assertions', link: '/assertions' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Configuration', link: '/configuration' },
          { text: 'Continuous integration', link: '/ci' },
          { text: 'How it works', link: '/how-it-works' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/achedon12/golem' },
      { icon: 'packagist', link: 'https://packagist.org/packages/achedon12/golem' },
    ],

    editLink: {
      pattern: 'https://github.com/achedon12/golem/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: { provider: 'local' },

    outline: { level: [2, 3] },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 Achedon12',
    },
  },
})
