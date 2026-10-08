import { h } from './api.js'
import { chart } from './bench.js'

const KEY = 'golem-history'
const LIMIT = 50

export function readHistory () {
  try { return JSON.parse(localStorage.getItem(KEY) ?? '[]') } catch { return [] }
}

function writeHistory (entries) {
  // pinned runs are kept, the others only the last ones
  const pinned = entries.filter((e) => e.pinned)
  const others = entries.filter((e) => !e.pinned).slice(0, LIMIT)
  try { localStorage.setItem(KEY, JSON.stringify([...pinned, ...others].sort((a, b) => b.date - a.date))) } catch {}
}

// Every run started from the dashboard ends up here.
window.addEventListener('golem-run', (event) => {
  writeHistory([{ id: crypto.randomUUID(), date: Date.now(), pinned: false, ...event.detail }, ...readHistory()])
})

const kinds = { test: 'Tests', fuzz: 'Fuzz', bench: 'Benchmark', mutate: 'Mutation' }
const tabOf = { test: 'tests', fuzz: 'fuzz', bench: 'bench', mutate: 'mutate' }

function summary (entry) {
  const s = entry.summary ?? {}
  if (entry.kind === 'test') {
    if (s.passed === undefined) return h('span', { class: 'hint' }, 'did not finish')
    return h('span', {}, h('span', { class: `badge ${s.successful ? 'pass' : 'fail'}` }, s.successful ? 'PASSED' : 'FAILED'), ` ${s.passed} passed${s.failed ? `, ${s.failed} failed` : ''}${s.errored ? `, ${s.errored} errored` : ''} · ${s.seconds}s`)
  }
  if (entry.kind === 'fuzz') {
    if (s.problems === null || s.problems === undefined) return h('span', { class: 'hint' }, 'stopped')
    return h('span', {}, h('span', { class: `badge ${s.problems ? 'fail' : 'pass'}` }, s.problems ? `${s.problems} PROBLEM(S)` : 'NO CRASH'), ` ${s.actions} actions · seed ${entry.options?.seed ?? '?'}`)
  }
  if (entry.kind === 'mutate') return s.score === undefined ? h('span', { class: 'hint' }, 'stopped') : h('span', {}, h('span', { class: `badge ${s.score >= 80 ? 'pass' : 'fail'}` }, `SCORE ${s.score}%`))
  if (!s.steps) return h('span', { class: 'hint' }, 'stopped')
  return h('span', {}, h('span', { class: `badge ${s.minTps >= 19.5 ? 'pass' : 'info'}` }, `min ${s.minTps.toFixed(1)} TPS`), ` ${s.steps} steps`)
}

function describeOptions (options = {}) {
  return Object.entries(options)
    .filter(([, value]) => value !== '' && value !== false && value !== null && value !== undefined && value !== 1)
    .map(([name, value]) => value === true ? name : `${name}=${value}`)
    .join(' · ')
}

export function historyView (project, app) {
  const list = h('div', { class: 'history' })
  const benchmarks = h('div')

  function render () {
    const entries = readHistory()
    list.replaceChildren(...(entries.length === 0
      ? [h('p', { class: 'hint' }, 'Runs started from the dashboard appear here, to run them again in a click. They are kept in this browser.')]
      : entries.map((entry) => h('div', { class: `history-row ${entry.pinned ? 'pinned' : ''}` },
        h('button', { class: 'icon star', title: entry.pinned ? 'Unpin' : 'Pin: keep these settings', onclick: () => { writeHistory(readHistory().map((e) => e.id === entry.id ? { ...e, pinned: !e.pinned } : e)); render() } }, entry.pinned ? '★' : '☆'),
        h('span', { class: 'kind' }, kinds[entry.kind] ?? entry.kind),
        h('span', { class: 'what' }, h('strong', {}, entry.label ?? ''), h('span', { class: 'hint' }, describeOptions(entry.options))),
        summary(entry),
        h('span', { class: 'when' }, new Date(entry.date).toLocaleString()),
        h('button', { class: 'secondary', onclick: () => { app.show(tabOf[entry.kind]); window.dispatchEvent(new CustomEvent('golem-rerun', { detail: entry })) } }, 'Run again')))))

    let saved = []
    try { saved = JSON.parse(localStorage.getItem('golem-benchmarks') ?? '[]') } catch {}
    benchmarks.replaceChildren(saved.length === 0 ? '' : h('div', { class: 'panel' },
      h('h3', {}, 'Benchmarks over time'),
      chart(saved.map((b) => ({ steps: b.steps }))),
      h('p', { class: 'hint' }, `The last ${saved.length} benchmark(s), the latest in green: `, saved.map((b) => new Date(b.date).toLocaleDateString()).join(', '))))
  }

  window.addEventListener('golem-run', () => setTimeout(render, 0))
  render()
  return h('section', { class: 'content wide' },
    h('div', { class: 'heading' },
      h('div', {}, h('h2', {}, 'History'), h('p', { class: 'lead' }, 'The last runs, with their settings. Pin the ones you use often.')),
      h('button', { class: 'secondary', onclick: () => { writeHistory(readHistory().filter((e) => e.pinned)); render() } }, 'Clear unpinned')),
    list, benchmarks)
}
