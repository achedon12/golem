<script setup lang="ts">
import { computed } from 'vue'
import { useData, useRoute } from 'vitepress'

const { theme, site } = useData()
const route = useRoute()
const golem = computed(() => theme.value.golem as { version: string, latest: string, versions: { version: string, base: string }[] })

function label(version: string): string {
  if (version === 'next') return 'next (main)'
  return version === golem.value.latest ? `${version} (latest)` : version
}

// Opens the same page in the chosen version, or its home page when that version does not
// have the page.
async function go(event: Event): Promise<void> {
  const target = golem.value.versions.find((v) => v.version === (event.target as HTMLSelectElement).value)
  if (!target) return
  const page = route.path.startsWith(site.value.base) ? route.path.slice(site.value.base.length) : ''
  let url = target.base + page
  try {
    const response = await fetch(url, { method: 'HEAD' })
    if (!response.ok) url = target.base
  } catch {
    url = target.base
  }
  window.location.href = url
}
</script>

<template>
  <label v-if="golem.versions.length > 1" class="golem-version" :title="'Documentation of Golem ' + golem.version">
    <span class="visually-hidden">Version</span>
    <select :value="golem.version" @change="go">
      <option v-for="v in golem.versions" :key="v.version" :value="v.version">{{ label(v.version) }}</option>
    </select>
  </label>
</template>

<style scoped>
.golem-version {
  display: flex;
  align-items: center;
  margin-left: 12px;
}

.golem-version select {
  border: 1px solid var(--vp-c-divider);
  border-radius: 6px;
  background: var(--vp-c-bg-soft);
  color: var(--vp-c-text-1);
  font-size: 13px;
  font-weight: 500;
  padding: 4px 8px;
  cursor: pointer;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
