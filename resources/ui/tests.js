import { api, h, run, seconds } from './api.js'
import { ansiToHtml } from './ansi.js'

const icons = { passed: '✓', failed: '✗', errored: '✗', skipped: '–', running: '…' }

export function testsView (project, app) {
  const total = project.tests.reduce((sum, c) => sum + c.tests.length, 0)
  const keyOf = (c, t) => `${c.short}::${t.method}`
  const allKeys = project.tests.flatMap((c) => c.tests.map((t) => keyOf(c, t)))
  const STORE = `golem-selected-tests:${project.root}`
  let current = null
  const statusOf = new Map() // "Class::method" -> status

  // ---------- which tests to run: every test has a checkbox, kept in the browser
  let selected
  try {
    const saved = JSON.parse(localStorage.getItem(STORE) ?? 'null')
    selected = new Set(Array.isArray(saved) ? saved.filter((k) => allKeys.includes(k)) : allKeys)
  } catch { selected = new Set(allKeys) }
  function saveSelection () {
    try { localStorage.setItem(STORE, JSON.stringify([...selected])) } catch {}
  }

  // golem's --filter: everything, whole classes, or single tests, separated by |
  function selection () {
    if (selected.size === allKeys.length) return { filter: '', label: `all ${total} tests`, count: total }
    const parts = []
    for (const c of project.tests) {
      const keys = c.tests.map((t) => keyOf(c, t))
      const picked = keys.filter((k) => selected.has(k))
      if (picked.length === keys.length) parts.push(`${c.short}::`)
      else parts.push(...picked)
    }
    const count = selected.size
    return { filter: parts.join('|'), label: count === 1 ? [...selected][0].replace('::', ' › ') : `${count} tests`, count }
  }
  function select (keys, on) {
    for (const k of keys) on ? selected.add(k) : selected.delete(k)
    saveSelection()
    renderTree()
    renderRunButton()
  }
  function selectFromFilter (filter) {
    if (!filter) { selected = new Set(allKeys); return }
    const parts = filter.split('|').map((p) => p.trim().toLowerCase()).filter(Boolean)
    selected = new Set(allKeys.filter((k) => parts.some((p) => k.toLowerCase().includes(p))))
  }

  // ---------- the tree of tests
  const tree = h('div', { class: 'tree' })
  function checkbox (keys) {
    const on = keys.filter((k) => selected.has(k)).length
    const box = h('input', { type: 'checkbox', checked: on === keys.length && keys.length > 0, onclick: (e) => e.stopPropagation(), onchange: (e) => select(keys, e.target.checked) })
    box.indeterminate = on > 0 && on < keys.length
    return box
  }
  function renderTree () {
    const row = (label, keys, count, status, depth) => h('label', { class: `row depth-${depth} ${keys.some((k) => selected.has(k)) ? '' : 'off'}` },
      checkbox(keys),
      h('span', { class: `status ${status ?? ''}` }, status ? icons[status] : ''),
      h('span', { class: 'label' }, depth === 2 ? describe(label) : label),
      count !== null ? h('span', { class: 'count' }, count) : null)

    const classes = project.tests.map((c) => {
      const keys = c.tests.map((t) => keyOf(c, t))
      const statuses = keys.map((k) => statusOf.get(k))
      const status = statuses.some((st) => st === 'failed' || st === 'errored') ? 'failed' : statuses.every((st) => st === 'passed' || st === 'skipped') && statuses.length ? 'passed' : null
      return [
        row(c.short, keys, c.tests.length, status, 1),
        ...c.tests.map((t) => row(t.method, [keyOf(c, t)], null, statusOf.get(keyOf(c, t)), 2)),
      ]
    })
    const failed = allKeys.filter((k) => statusOf.get(k) === 'failed' || statusOf.get(k) === 'errored')
    tree.replaceChildren(...[
      h('div', { class: 'tree-tools' },
        h('button', { class: 'link', onclick: () => select(allKeys, true) }, 'All'),
        h('button', { class: 'link', onclick: () => select(allKeys, false) }, 'None'),
        failed.length ? h('button', { class: 'link', onclick: () => { selected = new Set(failed); saveSelection(); renderTree(); renderRunButton() } }, `Failed (${failed.length})`) : null,
        h('span', { class: 'count' }, `${selected.size}/${total}`)),
      row('All tests', allKeys, total, null, 0),
      ...classes.flat(),
      project.tests.length === 0 ? h('p', { class: 'hint' }, 'No tests yet: build one in the Scenario tab, or run golem init.') : null,
    ].filter(Boolean))
  }

  // ---------- options and run button
  const version = h('input', { type: 'text', placeholder: project.pocketmine, title: 'PocketMine-MP version, or owner/repository[@tag] for a fork', size: 18 })
  const parallel = h('select', { title: 'Servers running side by side' }, ...[1, 2, 4, 8].map((n) => h('option', { value: n }, n === 1 ? '1 server' : `${n} servers`)))
  const coverage = h('input', { type: 'checkbox' })
  const snapshots = h('input', { type: 'checkbox' })
  const runButton = h('button', { class: 'primary', onclick: () => current ? current.stop() : start() })
  function renderRunButton () {
    const { label, count } = selection()
    runButton.textContent = current ? 'Stop' : count === 0 ? 'Pick tests to run' : `Run ${label}`
    runButton.disabled = !current && count === 0
    runButton.classList.toggle('danger', !!current)
  }

  // ---------- results
  const status = h('div', { class: 'status-line' })
  const results = h('div', { class: 'results' })
  const details = h('div', { class: 'details' })
  const coveragePanel = h('div', { class: 'coverage' })
  const consoleOut = h('pre', { class: 'console' })
  const consoleBox = h('details', { class: 'console-box' }, h('summary', {}, 'Console output'), consoleOut)

  async function start () {
    statusOf.clear()
    results.replaceChildren()
    details.replaceChildren()
    coveragePanel.replaceChildren()
    consoleOut.innerHTML = ''
    renderTree()
    const options = {
      filter: selection().filter,
      pocketmine: version.value.trim(),
      parallel: Number(parallel.value),
      coverage: coverage.checked,
      updateSnapshots: snapshots.checked,
    }
    let count = 0
    let done = 0
    let raw = ''
    const startedAt = Date.now()
    status.replaceChildren(h('span', { class: 'spinner' }), ' Starting PocketMine-MP…')
    try {
      current = await run('test', options, (state) => {
        raw += state.output
        consoleOut.innerHTML = ansiToHtml(raw)
        for (const event of state.events) {
          if (event.type === 'started') count = event.count
          if (event.type === 'test') {
            done++
            statusOf.set(`${event.shortClass}::${event.method}`, event.status)
            addResult(event)
          }
          if (event.type === 'finished') finish(event)
        }
        if (state.running && count > 0) {
          status.replaceChildren(h('span', { class: 'spinner' }), ` ${done}/${count} tests · ${((Date.now() - startedAt) / 1000).toFixed(0)}s`)
        }
        renderTree()
      })
      renderRunButton()
      status.append(h('code', { class: 'command' }, current.command))
      const end = await current.done
      if (!results.querySelector('.summary')) {
        status.replaceChildren(h('span', { class: 'badge fail' }, end.exitCode === 2 ? 'ERROR' : 'STOPPED'), ' See the console output below.')
        consoleBox.open = true
      }
      window.dispatchEvent(new CustomEvent('golem-run', { detail: { kind: 'test', options, label: selection().label, summary: lastSummary } }))
    } catch (error) {
      status.replaceChildren(h('span', { class: 'badge fail' }, 'ERROR'), ' ', error.message)
    } finally {
      current = null
      renderRunButton()
    }
  }

  let lastClass = null
  let lastSummary = null
  function addResult (event) {
    if (event.shortClass !== lastClass) {
      lastClass = event.shortClass
      results.append(h('h3', { class: 'class-name' }, event.shortClass))
    }
    const problem = event.status === 'failed' || event.status === 'errored'
    const row = h('button', { class: `result ${event.status}`, onclick: () => showDetails(event, row) },
      h('span', { class: `status ${event.status}` }, icons[event.status]),
      h('span', { class: 'label' }, event.description),
      h('span', { class: 'time' }, event.status === 'skipped' ? (event.message ?? 'skipped') : `${seconds(event.seconds)} · ${event.ticks} ticks`))
    results.append(row)
    if (problem && !details.hasChildNodes()) showDetails(event, row)
  }

  async function showDetails (event, row) {
    for (const r of results.querySelectorAll('.result.open')) r.classList.remove('open')
    row.classList.add('open')
    const file = event.failureFile ?? event.file
    const line = event.failureLine ?? event.line
    details.replaceChildren(...[
      h('h3', {}, `${event.shortClass} › ${event.description}`),
      h('p', { class: `message ${event.status}` }, event.message ?? (event.status === 'passed' ? `Passed with ${event.assertions} assertion(s).` : '')),
      event.demoNote ? h('p', { class: 'demo-note' }, event.demoNote) : null,
      event.expected !== null ? h('div', { class: 'compare' }, h('span', {}, 'expected'), h('pre', {}, event.expected)) : null,
      event.actual !== null ? h('div', { class: 'compare' }, h('span', {}, 'actual'), h('pre', {}, event.actual)) : null,
      h('p', { class: 'location' }, `${file.replace(project.root + '/', '')}:${line}`),
    ].filter(Boolean))
    try {
      const source = await api(`source?file=${encodeURIComponent(file)}`)
      details.append(snippet(source.content, line))
    } catch {}
    if (event.trace?.length) details.append(h('pre', { class: 'trace' }, event.trace.join('\n')))
  }

  function finish (event) {
    lastSummary = { ...event.counts, seconds: event.seconds, successful: event.successful }
    const c = event.counts
    status.replaceChildren(
      h('span', { class: `badge ${event.successful ? 'pass' : 'fail'}` }, event.successful ? 'PASSED' : (event.crashed ? 'CRASHED' : 'FAILED')),
      ` ${c.passed} passed`, c.failed ? `, ${c.failed} failed` : '', c.errored ? `, ${c.errored} errored` : '', c.skipped ? `, ${c.skipped} skipped` : '',
      ` · ${event.assertions} assertions · ${event.seconds}s`,
    )
    results.append(h('div', { class: 'summary' }))
    if (event.abortReason) details.replaceChildren(h('p', { class: 'message failed' }, event.abortReason))
    if (event.crashed && event.serverLog) details.append(h('pre', { class: 'trace' }, event.serverLog))
    if (event.coverage) renderCoverage(event.coverage)
  }

  function renderCoverage (coverage) {
    const missed = (counts) => Object.entries(counts).filter(([, n]) => n === 0).map(([name]) => name)
    const lines = coverage.lines ?? {}
    const files = Object.entries(lines).map(([file, fileLines]) => {
      const values = Object.values(fileLines)
      const ran = values.filter((v) => v === 1).length
      return { file, ran, total: values.length, lines: fileLines }
    }).sort((a, b) => a.ran / a.total - b.ran / b.total)
    const ran = files.reduce((s, f) => s + f.ran, 0)
    const all = files.reduce((s, f) => s + f.total, 0)
    const source = h('div', { class: 'coverage-source' })
    coveragePanel.replaceChildren(...[
      h('h3', {}, 'Coverage'),
      h('div', { class: 'coverage-totals' },
        h('span', {}, `Commands ${Object.keys(coverage.commands).length - missed(coverage.commands).length}/${Object.keys(coverage.commands).length}`),
        h('span', {}, `Listeners ${Object.keys(coverage.listeners).length - missed(coverage.listeners).length}/${Object.keys(coverage.listeners).length}`),
        all ? h('span', {}, `Lines ${(ran / all * 100).toFixed(1)}% (${ran}/${all})`) : h('span', { class: 'hint' }, 'Lines need pcov or Xdebug'),
      ),
      missed(coverage.commands).length ? h('p', { class: 'hint' }, 'Never run: ', missed(coverage.commands).map((n) => '/' + n).join(', ')) : null,
      missed(coverage.listeners).length ? h('p', { class: 'hint' }, 'Never called: ', missed(coverage.listeners).join(', ')) : null,
      ...files.map((f) => h('button', { class: 'coverage-file', onclick: () => showCoverage(f, source) },
        h('span', { class: 'label' }, `src/${f.file}`),
        h('span', { class: 'bar' }, h('span', { style: `width:${(f.ran / f.total * 100).toFixed(1)}%` })),
        h('span', { class: 'count' }, `${Math.floor(f.ran / f.total * 100)}%`))),
      source,
    ].filter(Boolean))
  }

  async function showCoverage (file, target) {
    const source = await api(`source?file=${encodeURIComponent('src/' + file.file)}`)
    target.replaceChildren(h('pre', { class: 'code' }, ...source.content.split('\n').map((text, i) => {
      const state = file.lines[i + 1]
      return h('span', { class: `line ${state === 1 ? 'ran' : state === 0 ? 'missed' : ''}` }, h('span', { class: 'number' }, i + 1), text + '\n')
    })))
  }

  // "Run again" from the history
  window.addEventListener('golem-rerun', (event) => {
    const { kind, options = {}, label } = event.detail
    if (kind !== 'test' || current) return
    selectFromFilter(options.filter ?? '')
    saveSelection()
    version.value = options.pocketmine ?? ''
    parallel.value = String(options.parallel ?? 1)
    coverage.checked = !!options.coverage
    snapshots.checked = !!options.updateSnapshots
    renderTree()
    start()
  })

  renderTree()
  renderRunButton()
  return h('div', { class: 'tests-view' },
    h('aside', { class: 'side' }, tree),
    h('section', { class: 'content' },
      h('div', { class: 'toolbar' },
        h('label', {}, 'PocketMine-MP ', version),
        h('label', {}, parallel),
        h('label', { class: 'check' }, coverage, ' Coverage'),
        h('label', { class: 'check', title: 'Rewrite the snapshots of assertMatchesSnapshot()' }, snapshots, ' Update snapshots'),
        runButton),
      status, results, details, coveragePanel, consoleBox),
  )
}

// "testVisitorsCannotBreakBlocks" becomes "visitors cannot break blocks", as in the console
export function describe (method) {
  return method.replace(/^test_?/, '').replace(/_/g, ' ').replace(/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/g, ' ').toLowerCase().trim()
}

export function snippet (content, line, around = 4) {
  const lines = content.split('\n')
  const from = Math.max(1, line - around)
  const to = Math.min(lines.length, line + around)
  const rows = []
  for (let n = from; n <= to; n++) rows.push(h('span', { class: `line ${n === line ? 'focus' : ''}` }, h('span', { class: 'number' }, n), lines[n - 1] + '\n'))
  return h('pre', { class: 'code' }, ...rows)
}
