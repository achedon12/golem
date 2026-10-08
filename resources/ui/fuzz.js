import { h, run } from './api.js'
import { ansiToHtml } from './ansi.js'

export function fuzzView (project) {
  const duration = h('input', { type: 'number', min: 5, max: 3600, value: 60 })
  const golems = h('input', { type: 'number', min: 1, max: 20, value: 3 })
  const seed = h('input', { type: 'number', min: 0, placeholder: 'random' })
  const writeTests = h('input', { type: 'checkbox', checked: true })
  const version = h('input', { type: 'text', placeholder: project.pocketmine, size: 18 })
  const button = h('button', { class: 'primary', onclick: () => current ? current.stop() : start() }, 'Start fuzzing')
  const status = h('div', { class: 'status-line' })
  const progress = h('div', { class: 'stats' })
  const written = h('div')
  const output = h('pre', { class: 'console tall' })
  let current = null

  async function start () {
    const options = { duration: duration.value, golems: golems.value, seed: seed.value, writeTests: writeTests.checked, pocketmine: version.value.trim() }
    let raw = ''
    output.innerHTML = ''
    written.replaceChildren()
    progress.replaceChildren()
    status.replaceChildren(h('span', { class: 'spinner' }), ' Starting PocketMine-MP…')
    try {
      current = await run('fuzz', options, (state) => {
        raw += state.output
        output.innerHTML = ansiToHtml(raw)
        output.scrollTop = output.scrollHeight
        const text = raw.replace(/\x1b\[[0-9;]*m/g, '')
        const last = [...text.matchAll(/(\d+)s · (\d+) actions · (\d+) crash/g)].pop()
        if (last && state.running) {
          progress.replaceChildren(stat(`${last[1]}s`, 'elapsed'), stat(last[2], 'actions'), stat(last[3], 'crashes', Number(last[3]) > 0))
          status.replaceChildren(h('span', { class: 'spinner' }), ` Golems are playing with ${project.name}…`)
        }
      })
      button.textContent = 'Stop'
      button.classList.add('danger')
      status.append(h('code', { class: 'command' }, current.command))
      const end = await current.done
      const text = raw.replace(/\x1b\[[0-9;]*m/g, '')
      const summary = text.match(/Fuzzing:\s+(\d+) actions, (nothing crashed|(\d+) problem)/)
      const problems = summary ? Number(summary[3] ?? 0) : null
      const seedUsed = text.match(/seed (\d+)/)?.[1]
      status.replaceChildren(
        h('span', { class: `badge ${end.exitCode === 0 ? 'pass' : 'fail'}` }, end.exitCode === 0 ? 'NO CRASH' : problems ? `${problems} PROBLEM${problems > 1 ? 'S' : ''}` : 'STOPPED'),
        summary ? ` ${summary[1]} actions` : '',
        seedUsed ? ` · seed ${seedUsed}` : '',
      )
      const files = [...text.matchAll(/Wrote (\S+) to replay it/g)].map((m) => m[1])
      if (files.length) {
        written.replaceChildren(h('div', { class: 'panel' }, h('h3', {}, 'Tests written'), h('p', { class: 'hint' }, 'Each one replays a crash. They are in the Tests tab after a reload.'), ...files.map((f) => h('code', { class: 'file' }, f))))
      }
      window.dispatchEvent(new CustomEvent('golem-run', { detail: { kind: 'fuzz', options: { ...options, seed: options.seed || seedUsed }, label: `${options.duration}s, ${options.golems} golems`, summary: { problems, actions: summary ? Number(summary[1]) : null } } }))
    } catch (error) {
      status.replaceChildren(h('span', { class: 'badge fail' }, 'ERROR'), ' ', error.message)
    } finally {
      current = null
      button.textContent = 'Start fuzzing'
      button.classList.remove('danger')
    }
  }

  window.addEventListener('golem-rerun', (event) => {
    const { kind, options = {} } = event.detail
    if (kind !== 'fuzz' || current) return
    duration.value = options.duration ?? 60
    golems.value = options.golems ?? 3
    seed.value = options.seed ?? ''
    writeTests.checked = options.writeTests !== false
    version.value = options.pocketmine ?? ''
    start()
  })

  return h('section', { class: 'content wide' },
    h('h2', {}, 'Fuzzing'),
    h('p', { class: 'lead' }, 'Golems run your commands with odd arguments, answer forms with invalid values, click, fight and reconnect. Every exception is reported with what led to it.'),
    h('div', { class: 'toolbar' },
      h('label', {}, 'Duration (s) ', duration),
      h('label', {}, 'Golems ', golems),
      h('label', {}, 'Seed ', seed),
      h('label', {}, 'PocketMine-MP ', version),
      h('label', { class: 'check' }, writeTests, ' Write a test per crash'),
      button),
    status, progress, written, output)
}

export function stat (value, label, alert = false) {
  return h('div', { class: `stat ${alert ? 'alert' : ''}` }, h('strong', {}, value), h('span', {}, label))
}
