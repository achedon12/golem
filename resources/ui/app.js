import { api, h } from './api.js'
import { testsView } from './tests.js'
import { fuzzView } from './fuzz.js'
import { benchView } from './bench.js'
import { mutateView } from './mutate.js'
import { scenarioView } from './scenario.js'
import { historyView } from './history.js'

// Each section of the dashboard: a tab, and a function that builds its view once.
const sections = [
  { id: 'tests', label: 'Tests', view: testsView },
  { id: 'scenario', label: 'Scenario editor', view: scenarioView },
  { id: 'fuzz', label: 'Fuzz', view: fuzzView },
  { id: 'bench', label: 'Benchmark', view: benchView },
  { id: 'mutate', label: 'Mutation', view: mutateView },
  { id: 'history', label: 'History', view: historyView },
]

const tabs = document.getElementById('tabs')
const view = document.getElementById('view')
const built = new Map()

function show (id, project) {
  const section = sections.find((s) => s.id === id) ?? sections[0]
  for (const button of tabs.children) button.classList.toggle('active', button.dataset.tab === section.id)
  if (!built.has(section.id)) built.set(section.id, section.view(project, { show: (tab) => show(tab, project) }))
  view.replaceChildren(built.get(section.id))
  location.hash = section.id
}

async function main () {
  let project
  try {
    project = await api('project')
  } catch (error) {
    view.replaceChildren(h('div', { class: 'empty' }, h('h2', {}, 'Cannot reach the dashboard'), h('p', {}, error.message)))
    return
  }
  document.getElementById('plugin').textContent = `${project.name}${project.version ? ' ' + project.version : ''}`
  document.getElementById('meta').textContent = `Golem ${project.golem} · PocketMine-MP ${project.pocketmine}`
  document.title = `${project.name} · Golem`
  for (const section of sections) {
    tabs.append(h('button', { 'data-tab': section.id, onclick: () => show(section.id, project) }, section.label))
  }
  show(location.hash.slice(1), project)
}

main()
