<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useData } from 'vitepress'

const { theme } = useData()
const golem = computed(() => theme.value.golem as { version: string, latest: string, versions: { version: string, base: string }[] })
const latestBase = computed(() => golem.value.versions.find((v) => v.version === golem.value.latest)?.base ?? '/golem/')
const changes = computed(() => golem.value.version === 'next'
  ? `https://github.com/achedon12/golem/compare/${golem.value.latest}...main`
  : `https://github.com/achedon12/golem/compare/${golem.value.version}...${golem.value.latest}`)

// the navigation bar is fixed: tell the theme how much room the banner takes above it
const banner = ref<HTMLElement | null>(null)
let observer: ResizeObserver | null = null
onMounted(() => {
  if (!banner.value) return
  const update = () => document.documentElement.style.setProperty('--vp-layout-top-height', `${banner.value?.offsetHeight ?? 0}px`)
  observer = new ResizeObserver(update)
  observer.observe(banner.value)
  update()
})
onUnmounted(() => observer?.disconnect())
</script>

<template>
  <div v-if="golem.version !== golem.latest" ref="banner" class="golem-banner">
    <template v-if="golem.version === 'next'">
      You are reading the documentation of the next version of Golem, not released yet.
    </template>
    <template v-else>
      You are reading the documentation of Golem {{ golem.version }}. The latest version is {{ golem.latest }}.
    </template>
    <a :href="latestBase" target="_self">Latest docs</a>
    ·
    <a :href="changes" target="_blank" rel="noreferrer">What changed</a>
    ·
    <a href="https://github.com/achedon12/golem/blob/main/CHANGELOG.md" target="_blank" rel="noreferrer">Changelog</a>
  </div>
</template>

<style scoped>
.golem-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 40;
  padding: 8px 24px;
  background: var(--vp-c-warning-soft);
  color: var(--vp-c-text-1);
  font-size: 14px;
  text-align: center;
}

.golem-banner a {
  color: var(--vp-c-brand-1);
  font-weight: 600;
  text-decoration: underline;
}
</style>
