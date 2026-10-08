import { h, run } from './api.js'
import { ansiToHtml } from './ansi.js'
import { stat } from './fuzz.js'

export function mutateView (project) {
  const workers = h('input', { type: 'number', min: 1, max: 16, value: 4 })
  const max = h('input', { type: 'number', min: 1, max: 2000, value: 200 })
  const minScore = h('input', { type: 'number', min: 0, max: 100, placeholder: 'none' })
  const version = h('input', { type: 'text', placeholder: project.pocketmine, size: 18 })
  const button = h('button', { class: 'primary', onclick: () => current ? current.stop() : start() }, 'Start mutating')
  const status = h('div', { class: 'status-line' })
  const stats = h('div', { class: 'stats' })
  const grid = h('div', { class: 'mutant-grid' })
  const survivors = h('div')
  const output = h('pre', { class: 'console' })
  const consoleBox = h('details', { class: 'console-box' }, h('summary', {}, 'Console output'), output)
  let current = null

  async function start () {
    const options = { workers: workers.value, max: max.value, minScore: minScore.value, pocketmine: version.value.trim() }
    let raw = ''
    let total = 0
    const results = []
    output.innerHTML = ''
    grid.replaceChildren()
    survivors.replaceChildren()
    stats.replaceChildren()
    status.replaceChildren(h('span', { class: 'spinner' }), ' Running the tests once with coverage, to see which lines each test runs…')
    const render = () => {
      const caught = results.filter((r) => r.status !== 'survived').length
      stats.replaceChildren(stat(`${results.length}/${total}`, 'mutants tried'), stat(caught, 'caught'), stat(results.length - caught, 'survived', results.length > caught))
    }
    try {
      current = await run('mutate', options, (state) => {
        raw += state.output
        output.innerHTML = ansiToHtml(raw)
        for (const event of state.events) {
          if (event.type === 'mutate_start') {
            total = event.mutants
            status.replaceChildren(h('span', { class: 'spinner' }), ` Trying ${total} mutants: each one is a small bug the tests should catch…`)
            render()
          }
          if (event.type === 'mutant') {
            results.push(event)
            grid.append(h('span', { class: `mutant ${event.status.replace(' ', '-')}`, title: `src/${event.file}:${event.line} ${event.description} (${event.status})` }))
            if (event.status === 'survived') {
              survivors.append(h('div', { class: 'panel survivor' },
                h('p', { class: 'location' }, `src/${event.file}:${event.line} · ${event.description}`),
                h('pre', { class: 'code' }, h('span', { class: 'line missed' }, '- ' + event.before + '\n'), h('span', { class: 'line ran' }, '+ ' + event.after))))
            }
            render()
          }
          if (event.type === 'mutate_end') {
            status.replaceChildren(
              h('span', { class: `badge ${event.score >= 80 ? 'pass' : 'fail'}` }, `SCORE ${event.score}%`),
              ` ${event.killed} of ${event.total} mutants caught by the tests`,
              event.uncovered ? h('span', { class: 'hint' }, ` · ${event.uncovered} not tried: no test runs their line`) : '')
            if (results.some((r) => r.status === 'survived')) {
              survivors.prepend(h('h3', { class: 'class-name' }, 'Survivors: changes the tests did not notice'))
            }
            window.dispatchEvent(new CustomEvent('golem-run', { detail: { kind: 'mutate', options, label: `${event.total} mutants`, summary: { score: event.score } } }))
          }
        }
      })
      button.textContent = 'Stop'
      button.classList.add('danger')
      const end = await current.done
      if (!raw.includes('Mutation score')) {
        status.replaceChildren(h('span', { class: 'badge fail' }, end.exitCode === 2 ? 'ERROR' : 'STOPPED'), ' See the console output below.')
        consoleBox.open = true
      }
    } catch (error) {
      status.replaceChildren(h('span', { class: 'badge fail' }, 'ERROR'), ' ', error.message)
    } finally {
      current = null
      button.textContent = 'Start mutating'
      button.classList.remove('danger')
    }
  }

  window.addEventListener('golem-rerun', (event) => {
    const { kind, options = {} } = event.detail
    if (kind !== 'mutate' || current) return
    workers.value = options.workers ?? 4
    max.value = options.max ?? 200
    minScore.value = options.minScore ?? ''
    version.value = options.pocketmine ?? ''
    start()
  })

  return h('section', { class: 'content wide' },
    h('h2', {}, 'Mutation testing'),
    h('p', { class: 'lead' }, 'Golem changes your code one small mutation at a time (=== into !==, < into <=, true into false…) and runs the tests that cover each line. A mutant the tests do not notice is a bug they would let through.'),
    h('div', { class: 'toolbar' },
      h('label', {}, 'Servers ', workers),
      h('label', {}, 'At most ', max, ' mutants'),
      h('label', {}, 'Min score ', minScore),
      h('label', {}, 'PocketMine-MP ', version),
      button),
    status, stats, grid, survivors, consoleBox)
}
