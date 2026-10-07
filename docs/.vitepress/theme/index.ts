import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import { inBrowser } from 'vitepress'
import './custom.css'

declare global {
  interface Window { _paq?: unknown[][] }
}

export default {
  extends: DefaultTheme,
  enhanceApp({ router }) {
    if (!inBrowser) {
      return
    }
    // The first page view is tracked by the snippet in <head>; this tracks client-side navigation.
    let lastUrl = location.href
    router.onAfterRouteChange = () => {
      if (location.href === lastUrl) {
        return
      }
      const referrer = lastUrl
      lastUrl = location.href
      // let the new page set its title first
      setTimeout(() => {
        const paq = (window._paq = window._paq || [])
        paq.push(['setReferrerUrl', referrer])
        paq.push(['setCustomUrl', location.href])
        paq.push(['setDocumentTitle', document.title])
        paq.push(['trackPageView'])
      }, 0)
    }
  },
} satisfies Theme
