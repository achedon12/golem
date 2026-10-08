import { existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

const site = 'https://achedon12.github.io/golem/'
const description = 'Integration tests for PocketMine-MP plugins: a real server, simulated players, one command.'

// One build per version of the docs, made by .github/scripts/build-docs.py: the latest release
// at the root, main under /next/, older releases under /vX.Y.Z/. Without these variables (npm run
// dev), it builds main at the root.
const version = process.env.GOLEM_DOCS_VERSION ?? 'next'
const latest = process.env.GOLEM_DOCS_LATEST ?? version
const base = process.env.GOLEM_DOCS_BASE ?? '/golem/'
const versions: { version: string, base: string }[] = JSON.parse(process.env.GOLEM_DOCS_VERSIONS ?? '[]')
// archived releases are built from an export of their docs/, not from a git checkout
const archived = process.env.GOLEM_DOCS_ARCHIVED === '1'
const isLatest = version === latest

// the sidebar only lists the pages this version of the docs has
const srcDir = fileURLToPath(new URL('..', import.meta.url))
const page = (text: string, link: string) => existsSync(`${srcDir}${link.slice(1)}.md`) ? [{ text, link }] : []

export default defineConfig({
  title: 'Golem',
  titleTemplate: ':title · Golem',
  description,
  base,
  lang: 'en-US',
  cleanUrls: true,
  lastUpdated: !archived,
  // older docs are kept as they were released, links included
  ignoreDeadLinks: archived,
  appearance: 'dark',
  srcExclude: ['README.md', 'node_modules/**', 'demo/**'],

  // only the latest docs are listed for search engines; other versions point to them as canonical
  sitemap: isLatest ? { hostname: site } : undefined,

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/golem/favicon.svg' }],
    ['meta', { name: 'theme-color', content: '#22c55e' }],
    ['meta', { name: 'google-site-verification', content: 'sfpqyLks88mzFsBGPMgSJI0BO6-7PdtQX4uYTHtrcnw' }],
    // Matomo, cookieless: no consent banner needed. Later page views are tracked from theme/index.ts.
    ['script', {}, `
      var _paq = window._paq = window._paq || [];
      _paq.push(['disableCookies']);
      _paq.push(['trackPageView']);
      _paq.push(['enableLinkTracking']);
      (function () {
        var u = 'https://matomo.leoderoin.fr/';
        _paq.push(['setTrackerUrl', u + 'matomo.php']);
        _paq.push(['setSiteId', '14']);
        var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
        g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g, s);
      })();
    `],
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
      { text: 'Guide', link: '/getting-started', activeMatch: '^/(getting-started|writing-tests|golems|assertions|server-plugin|fuzzing|benchmark|mutation|compatibility|dashboard)' },
      { text: 'Reference', link: '/configuration', activeMatch: '^/(configuration|ci|how-it-works)' },
      {
        text: 'Links',
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
          ...page('Getting started', '/getting-started'),
          ...page('Writing tests', '/writing-tests'),
          ...page('Golems', '/golems'),
          ...page('Assertions', '/assertions'),
          ...page('Server plugin', '/server-plugin'),
          ...page('Fuzzing', '/fuzzing'),
          ...page('Benchmark', '/benchmark'),
          ...page('Mutation testing', '/mutation'),
          ...page('Compatibility', '/compatibility'),
          ...page('Dashboard', '/dashboard'),
        ],
      },
      {
        text: 'Reference',
        items: [
          ...page('Configuration', '/configuration'),
          ...page('Continuous integration', '/ci'),
          ...page('How it works', '/how-it-works'),
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/achedon12/golem' },
      { icon: 'packagist', link: 'https://packagist.org/packages/achedon12/golem' },
    ],

    // a released version's docs are not edited anymore
    editLink: archived ? undefined : {
      pattern: 'https://github.com/achedon12/golem/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    // read by the version switcher and banner of theme/
    golem: { version, latest, versions },

    search: { provider: 'local' },

    outline: { level: [2, 3] },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 Achedon12',
    },
  },
})
