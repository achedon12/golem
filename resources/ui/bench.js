import { h, run } from './api.js'
import { ansiToHtml } from './ansi.js'

const svg = (tag, attributes = {}, ...children) => {
  const element = document.createElementNS('http://www.w3.org/2000/svg', tag)
  for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value)
  element.append(...children)
  return element
}

// TPS as a line, tick usage as bars, against the number of players.
export function chart (series) {
  const width = 640
  const height = 240
  const pad = { left: 40, right: 44, top: 28, bottom: 32 }
  const root = svg('svg', { viewBox: `0 0 ${width} ${height}`, class: 'chart', role: 'img', 'aria-label': 'TPS and tick usage by number of players' })
  const all = series.flatMap((s) => s.steps)
  if (all.length === 0) return root
  const maxPlayers = Math.max(...all.map((s) => s.players))
  const x = (players) => pad.left + (players / maxPlayers) * (width - pad.left - pad.right)
  const yTps = (tps) => pad.top + (1 - tps / 20) * (height - pad.top - pad.bottom)
  const yUsage = (usage) => pad.top + (1 - Math.min(100, usage) / 100) * (height - pad.top - pad.bottom)

  for (const tps of [0, 5, 10, 15, 20]) {
    root.append(svg('line', { x1: pad.left, x2: width - pad.right, y1: yTps(tps), y2: yTps(tps), class: 'grid' }))
    root.append(svg('text', { x: pad.left - 8, y: yTps(tps) + 4, class: 'axis', 'text-anchor': 'end' }, String(tps)))
    root.append(svg('text', { x: width - pad.right + 8, y: yTps(tps) + 4, class: 'axis' }, `${tps * 5}%`))
  }
  root.append(svg('text', { x: pad.left - 8, y: 10, class: 'axis', 'text-anchor': 'end' }, 'TPS'))
  root.append(svg('text', { x: width - pad.right + 8, y: 10, class: 'axis' }, 'tick'))
  const current = series[series.length - 1]
  const bar = Math.max(6, (width - pad.left - pad.right) / (current.steps.length * 3))
  for (const step of current.steps) {
    root.append(svg('rect', { x: x(step.players) - bar / 2, y: yUsage(step.usage), width: bar, height: height - pad.bottom - yUsage(step.usage), class: 'usage' }))
    root.append(svg('text', { x: x(step.players), y: height - pad.bottom + 18, class: 'axis', 'text-anchor': 'middle' }, String(step.players)))
  }
  series.forEach((s, index) => {
    const points = s.steps.map((step) => `${x(step.players)},${yTps(step.tps)}`).join(' ')
    const latest = index === series.length - 1
    root.append(svg('polyline', { points, class: latest ? 'tps' : 'tps old', style: latest ? '' : `opacity:${0.25 + 0.5 * index / series.length}` }))
    if (latest) for (const step of s.steps) root.append(svg('circle', { cx: x(step.players), cy: yTps(step.tps), r: 4, class: 'tps-dot' }))
  })
  return root
}

export function benchView (project) {
  const players = h('input', { type: 'number', min: 1, max: 200, value: 50 })
  const duration = h('input', { type: 'number', min: 10, max: 3600, value: 60 })
  const minTps = h('input', { type: 'number', min: 0, max: 20, step: 0.5, placeholder: 'none' })
  const version = h('input', { type: 'text', placeholder: project.pocketmine, size: 18 })
  const button = h('button', { class: 'primary', onclick: () => current ? current.stop() : start() }, 'Start benchmark')
  const status = h('div', { class: 'status-line' })
  const chartBox = h('div', { class: 'panel chart-panel' })
  const table = h('table', { class: 'table' })
  const listeners = h('div')
  const output = h('pre', { class: 'console' })
  const consoleBox = h('details', { class: 'console-box' }, h('summary', {}, 'Console output'), output)
  let current = null

  function previous () {
    try { return JSON.parse(localStorage.getItem('golem-benchmarks') ?? '[]') } catch { return [] }
  }

  function render (steps) {
    chartBox.replaceChildren(h('h3', {}, 'TPS and tick usage'), chart([...previous().slice(-3).map((b) => ({ steps: b.steps })), { steps }]),
      h('p', { class: 'hint' }, 'Green line: TPS (left axis). Bars: average tick usage (right axis). Faded lines: your previous benchmarks.'))
    table.replaceChildren(
      h('tr', {}, h('th', {}, 'Players'), h('th', {}, 'TPS'), h('th', {}, 'Tick usage (avg / max)'), h('th', {}, 'Memory')),
      ...steps.map((s) => h('tr', {}, h('td', {}, s.players), h('td', { class: s.tps >= 19.5 ? 'green' : s.tps >= 17 ? 'yellow' : 'red' }, s.tps.toFixed(1)), h('td', {}, `${s.usage.toFixed(1)}% / ${s.usageMax.toFixed(1)}%`), h('td', {}, `${Math.round(s.memory / 1048576)} MB`))))
  }

  async function start () {
    const options = { players: players.value, duration: duration.value, minTps: minTps.value, pocketmine: version.value.trim() }
    const steps = []
    let raw = ''
    output.innerHTML = ''
    listeners.replaceChildren()
    render(steps)
    status.replaceChildren(h('span', { class: 'spinner' }), ' Starting PocketMine-MP…')
    try {
      current = await run('bench', options, (state) => {
        raw += state.output
        output.innerHTML = ansiToHtml(raw)
        for (const event of state.events) {
          if (event.type === 'step') {
            steps.push(event)
            render(steps)
            status.replaceChildren(h('span', { class: 'spinner' }), ` ${event.players} golems online, measuring…`)
          }
          if (event.type === 'listeners' && event.listeners.length) {
            listeners.replaceChildren(h('div', { class: 'panel' }, h('h3', {}, `Slowest listeners and tasks of ${project.name}`),
              h('table', { class: 'table' }, h('tr', {}, h('th', {}, 'Listener or task'), h('th', {}, 'Calls'), h('th', {}, 'Average'), h('th', {}, 'Total')),
                ...event.listeners.map((l) => h('tr', {}, h('td', { class: 'mono' }, l.name), h('td', {}, l.count), h('td', {}, `${l.average.toFixed(3)} ms`), h('td', {}, `${l.total.toFixed(1)} ms`))))))
          }
        }
      })
      button.textContent = 'Stop'
      button.classList.add('danger')
      status.append(h('code', { class: 'command' }, current.command))
      const end = await current.done
      const text = raw.replace(/\x1b\[[0-9;]*m/g, '')
      const verdict = text.match(/The server (stayed above|fell below)[^\n]*/)?.[0]
      status.replaceChildren(h('span', { class: `badge ${end.exitCode === 0 ? 'pass' : 'fail'}` }, steps.length ? (end.exitCode === 0 ? 'DONE' : 'BELOW MIN TPS') : 'STOPPED'), ' ', verdict ?? '')
      if (steps.length) {
        const saved = [...previous(), { date: Date.now(), players: Number(options.players), steps }].slice(-10)
        try { localStorage.setItem('golem-benchmarks', JSON.stringify(saved)) } catch {}
      }
      window.dispatchEvent(new CustomEvent('golem-run', { detail: { kind: 'bench', options, label: `up to ${options.players} players`, summary: { steps: steps.length, minTps: steps.length ? Math.min(...steps.map((s) => s.tps)) : null } } }))
    } catch (error) {
      status.replaceChildren(h('span', { class: 'badge fail' }, 'ERROR'), ' ', error.message)
    } finally {
      current = null
      button.textContent = 'Start benchmark'
      button.classList.remove('danger')
    }
  }

  render([])
  return h('section', { class: 'content wide' },
    h('h2', {}, 'Benchmark'),
    h('p', { class: 'lead' }, 'Golems join a few at a time, walk and chat, while Golem measures how the server holds up.'),
    h('div', { class: 'toolbar' },
      h('label', {}, 'Players ', players),
      h('label', {}, 'Duration (s) ', duration),
      h('label', {}, 'Min TPS ', minTps),
      h('label', {}, 'PocketMine-MP ', version),
      button),
    status, chartBox, table, listeners, consoleBox)
}
