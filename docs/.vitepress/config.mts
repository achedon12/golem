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
    ['meta', { property: 'og:title', content: 'Golem · integration tests for PocketMine-MP plugins' }],
    ['meta', { property: 'og:description', content: description }],
    ['meta', { property: 'og:image', content: site + 'og.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:image', content: site + 'og.png' }],
  ],

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
