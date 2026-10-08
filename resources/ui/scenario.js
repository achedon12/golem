import { api, h, run, seconds } from './api.js'

// What each step needs. "golem" is the golem acting or checked.
const STEPS = {
  actions: {
    command: { label: 'runs a command', fields: ['golem', ['text', 'command, without /']] },
    chat: { label: 'chats', fields: ['golem', ['text', 'message']] },
    clickButton: { label: 'clicks a form button', fields: ['golem', ['text', 'label or number']] },
    clickSlot: { label: 'clicks a window slot', fields: ['golem', ['slot', 'slot', 'number']] },
    closeWindow: { label: 'closes the window', fields: ['golem'] },
    walk: { label: 'walks', fields: ['golem', ['x', 'x', 'number'], ['z', 'z', 'number']] },
    jump: { label: 'jumps', fields: ['golem'] },
    breakBlock: { label: 'breaks a block', fields: ['golem', ['dx', 'dx', 'number'], ['dy', 'dy', 'number'], ['dz', 'dz', 'number']] },
    interactBlock: { label: 'right-clicks a block', fields: ['golem', ['dx', 'dx', 'number'], ['dy', 'dy', 'number'], ['dz', 'dz', 'number']] },
    give: { label: 'is given an item', fields: ['golem', ['item', 'diamond_sword'], ['count', 'count', 'number']] },
    attack: { label: 'attacks', fields: ['golem', 'target'] },
    quit: { label: 'leaves', fields: ['golem'] },
    wait: { label: 'Wait', fields: [['ticks', 'ticks', 'number']], noGolem: true },
  },
  checks: {
    receivedMessage: { label: 'received a message containing', fields: ['golem', ['text', 'text']] },
    notReceivedMessage: { label: 'did not receive', fields: ['golem', ['text', 'text']] },
    lastMessage: { label: 'last message is', fields: ['golem', ['text', 'text']] },
    formOpen: { label: 'sees a form', fields: ['golem', ['text', 'title contains (optional)']] },
    noFormOpen: { label: 'sees no form', fields: ['golem'] },
    windowOpen: { label: 'has a window open', fields: ['golem'] },
    noWindowOpen: { label: 'has no window open', fields: ['golem'] },
    hasItem: { label: 'has the item', fields: ['golem', ['item', 'diamond_sword'], ['count', 'count', 'number']] },
    notHasItem: { label: 'does not have the item', fields: ['golem', ['item', 'diamond_sword']] },
    health: { label: 'has health', fields: ['golem', ['value', 'health', 'number']] },
    gamemode: { label: 'is in game mode', fields: ['golem', ['text', 'mode', 'gamemode']] },
    title: { label: 'sees the title', fields: ['golem', ['text', 'text']] },
    actionBar: { label: 'sees the action bar', fields: ['golem', ['text', 'text']] },
    scoreboardContains: { label: 'scoreboard shows', fields: ['golem', ['text', 'text']] },
    hasPermission: { label: 'has the permission', fields: ['golem', ['text', 'permission']] },
    online: { label: 'is online', fields: ['golem'] },
    offline: { label: 'is offline', fields: ['golem'] },
    kicked: { label: 'was kicked', fields: ['golem', ['text', 'reason contains (optional)']] },
    tpsAbove: { label: 'Server TPS above', fields: [['value', 'TPS', 'number']], noGolem: true },
  },
}
const ALL = { ...STEPS.actions, ...STEPS.checks }
const isCheck = (type) => type in STEPS.checks

const example = () => ({
  id: crypto.randomUUID(),
  name: 'Welcome',
  test: 'new players are greeted and get a kit',
  description: '',
  golems: [{ name: 'Steve', op: true, gamemode: '' }],
  steps: [
    { type: 'receivedMessage', golem: 'Steve', text: 'Welcome' },
    { type: 'command', golem: 'Steve', text: 'heal' },
    { type: 'receivedMessage', golem: 'Steve', text: 'healed' },
  ],
})

function load () {
  try { return JSON.parse(localStorage.getItem('golem-scenarios') ?? '[]') } catch { return [] }
}
function save (drafts) {
  try { localStorage.setItem('golem-scenarios', JSON.stringify(drafts)) } catch {}
}

export function scenarioView (project) {
  let drafts = load()
  if (drafts.length === 0) drafts = [example()]
  let scenario = drafts[0]
  const list = h('div', { class: 'drafts' })
  const editor = h('div', { class: 'editor' })
  const preview = h('pre', { class: 'code preview' })
  const previewError = h('p', { class: 'message failed' })
  const output = h('div', { class: 'scenario-result' })
  let timer = null
  let running = null

  function changed () {
    drafts = drafts.map((d) => d.id === scenario.id ? scenario : d)
    save(drafts)
    renderList()
    clearTimeout(timer)
    timer = setTimeout(refreshPreview, 250)
  }

  async function refreshPreview () {
    try {
      const { code } = await api('scenarios/preview', scenario)
      preview.textContent = code
      previewError.textContent = ''
    } catch (error) {
      previewError.textContent = error.message
    }
  }

  function renderList () {
    list.replaceChildren(...[
      h('button', { class: 'secondary block', onclick: () => { scenario = { ...example(), name: 'New scenario', steps: [] }; drafts.push(scenario); changed(); renderEditor() } }, '+ New scenario'),
      ...drafts.map((d) => h('div', { class: `draft ${d.id === scenario.id ? 'selected' : ''}` },
        h('button', { class: 'draft-name', onclick: () => { scenario = d; renderList(); renderEditor(); refreshPreview() } }, d.name || 'Untitled', h('span', { class: 'count' }, `${d.steps.length} steps`)),
        h('button', { class: 'icon', title: 'Delete this draft', onclick: () => { drafts = drafts.filter((x) => x.id !== d.id); if (drafts.length === 0) drafts = [example()]; if (scenario.id === d.id) scenario = drafts[0]; changed(); renderEditor() } }, '✕'))),
      h('p', { class: 'hint' }, 'Drafts stay in this browser. Writing a scenario saves it as a test in your tests folder.'),
    ].filter(Boolean))
  }

  const field = (object, key, attributes = {}, onChange = changed) => {
    const input = h('input', { type: 'text', value: object[key] ?? '', ...attributes, oninput: (e) => { object[key] = attributes.type === 'number' ? (e.target.value === '' ? '' : Number(e.target.value)) : e.target.value; onChange() } })
    return input
  }

  function golemSelect (step, key = 'golem') {
    const names = scenario.golems.map((g) => g.name).filter(Boolean)
    if (!names.includes(step[key])) step[key] = names[0] ?? ''
    return h('select', { onchange: (e) => { step[key] = e.target.value; changed() } }, ...names.map((n) => h('option', { value: n, selected: n === step[key] }, n)))
  }

  function stepRow (step, index) {
    const spec = ALL[step.type]
    const parts = []
    for (const f of spec.fields) {
      if (f === 'golem') parts.push(golemSelect(step))
      else if (f === 'target') parts.push(golemSelect(step, 'target'))
      else if (f[2] === 'gamemode') parts.push(h('select', { onchange: (e) => { step.text = e.target.value; changed() } }, ...['SURVIVAL', 'CREATIVE', 'ADVENTURE', 'SPECTATOR'].map((m) => h('option', { value: m, selected: step.text === m }, m.toLowerCase()))))
      else parts.push(field(step, f[0], { placeholder: f[1], type: f[2] === 'number' ? 'number' : 'text', class: f[2] === 'number' ? 'small' : '' }))
    }
    const type = h('select', { class: 'step-type', onchange: (e) => { step.type = e.target.value; changed(); renderEditor() } },
      h('optgroup', { label: 'Actions' }, ...Object.entries(STEPS.actions).map(([t, s]) => h('option', { value: t, selected: t === step.type }, s.label))),
      h('optgroup', { label: 'Checks' }, ...Object.entries(STEPS.checks).map(([t, s]) => h('option', { value: t, selected: t === step.type }, s.label))))
    const golemFirst = !spec.noGolem
    const move = (delta) => { const target = index + delta; if (target < 0 || target >= scenario.steps.length) return; [scenario.steps[index], scenario.steps[target]] = [scenario.steps[target], scenario.steps[index]]; changed(); renderEditor() }
    return h('div', { class: `step ${isCheck(step.type) ? 'check' : 'action'}` },
      h('span', { class: 'step-number' }, index + 1),
      golemFirst ? parts[0] : null,
      type,
      ...(golemFirst ? parts.slice(1) : parts),
      h('span', { class: 'step-tools' },
        h('button', { class: 'icon', title: 'Move up', onclick: () => move(-1) }, '↑'),
        h('button', { class: 'icon', title: 'Move down', onclick: () => move(1) }, '↓'),
        h('button', { class: 'icon', title: 'Remove', onclick: () => { scenario.steps.splice(index, 1); changed(); renderEditor() } }, '✕')))
  }

  function renderEditor () {
    const golems = scenario.golems.map((golem, index) => h('div', { class: 'golem-row' },
      field(golem, 'name', { placeholder: 'Steve', maxlength: 16 }, () => { changed(); clearTimeout(golemTimer); golemTimer = setTimeout(renderEditor, 600) }),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: golem.op, onchange: (e) => { golem.op = e.target.checked; changed() } }), ' op'),
      h('select', { onchange: (e) => { golem.gamemode = e.target.value; changed() } }, ...['', 'SURVIVAL', 'CREATIVE', 'ADVENTURE', 'SPECTATOR'].map((m) => h('option', { value: m, selected: golem.gamemode === m }, m === '' ? 'default mode' : m.toLowerCase()))),
      scenario.golems.length > 1 ? h('button', { class: 'icon', title: 'Remove', onclick: () => { scenario.golems.splice(index, 1); changed(); renderEditor() } }, '✕') : null))

    editor.replaceChildren(...[
      h('div', { class: 'form-grid' },
        h('label', {}, 'Scenario', field(scenario, 'name', { placeholder: 'Kit menu' })),
        h('label', {}, 'Test', field(scenario, 'test', { placeholder: 'picking a kit gives a copy' }))),
      h('h3', {}, 'Golems'),
      ...golems,
      h('button', { class: 'link', onclick: () => { scenario.golems.push({ name: ['Alex', 'Notch', 'Herobrine', 'Bob'].find((n) => !scenario.golems.some((g) => g.name === n)) ?? '', op: false, gamemode: '' }); changed(); renderEditor() } }, '+ Add a golem'),
      h('h3', {}, 'Steps'),
      scenario.steps.length === 0 ? h('p', { class: 'hint' }, 'Add what the golems do, then what should have happened.') : null,
      ...scenario.steps.map(stepRow),
      h('div', { class: 'add-steps' },
        h('button', { class: 'secondary', onclick: () => { scenario.steps.push({ type: 'command', golem: scenario.golems[0]?.name ?? '' }); changed(); renderEditor() } }, '+ Action'),
        h('button', { class: 'secondary', onclick: () => { scenario.steps.push({ type: 'receivedMessage', golem: scenario.golems[0]?.name ?? '' }); changed(); renderEditor() } }, '+ Check'),
        h('button', { class: 'secondary', onclick: () => { scenario.steps.push({ type: 'wait', ticks: 20 }); changed(); renderEditor() } }, '+ Wait')),
    ].filter(Boolean))
  }
  let golemTimer = null

  async function write (andRun) {
    output.replaceChildren()
    let written
    try {
      written = await api('scenarios', scenario)
    } catch (error) {
      if (/already exists/.test(error.message)) offerReplace(error.message, andRun)
      else output.replaceChildren(h('p', { class: 'message failed' }, error.message))
      return
    }
    if (!written) return
    output.replaceChildren(h('p', { class: 'message' }, '✓ Written to ', h('code', {}, written.file.replace(project.root + '/', ''))))
    if (andRun) runTest(written.filter)
  }

  // A replace button rather than a browser dialog.
  function offerReplace (message, andRun) {
    output.replaceChildren(h('p', { class: 'message failed' }, message, ' ',
      h('button', { class: 'secondary', onclick: async () => {
        const written = await api('scenarios', { ...scenario, overwrite: true }).catch((e) => { output.replaceChildren(h('p', { class: 'message failed' }, e.message)); return null })
        if (!written) return
        output.replaceChildren(h('p', { class: 'message' }, '✓ Replaced ', h('code', {}, written.file.replace(project.root + '/', ''))))
        if (andRun) runTest(written.filter)
      } }, andRun ? 'Replace and run' : 'Replace')))
  }

  async function runTest (filter) {
    const status = h('div', { class: 'status-line' }, h('span', { class: 'spinner' }), ' Running the scenario…')
    const rows = h('div')
    output.append(status, rows)
    try {
      running = await run('test', { filter }, (state) => {
        for (const event of state.events) {
          if (event.type === 'test') {
            rows.append(h('div', { class: `result ${event.status}` },
              h('span', { class: `status ${event.status}` }, event.status === 'passed' ? '✓' : event.status === 'skipped' ? '–' : '✗'),
              h('span', { class: 'label' }, event.description),
              h('span', { class: 'time' }, `${seconds(event.seconds)} · ${event.ticks} ticks`)))
            if (event.message && event.status !== 'passed') {
              rows.append(h('p', { class: `message ${event.status}` }, event.message),
                event.expected !== null ? h('div', { class: 'compare' }, h('span', {}, 'expected'), h('pre', {}, event.expected)) : '',
                event.actual !== null ? h('div', { class: 'compare' }, h('span', {}, 'actual'), h('pre', {}, event.actual)) : '',
                event.failureLine ? h('p', { class: 'location' }, `line ${event.failureLine}`) : '')
            }
          }
          if (event.type === 'finished') {
            status.replaceChildren(h('span', { class: `badge ${event.successful ? 'pass' : 'fail'}` }, event.successful ? 'PASSED' : 'FAILED'), ` in ${event.seconds}s`)
            window.dispatchEvent(new CustomEvent('golem-run', { detail: { kind: 'test', options: { filter }, label: scenario.name, summary: { ...event.counts, seconds: event.seconds, successful: event.successful } } }))
          }
        }
      })
      const end = await running.done
      if (status.querySelector('.spinner')) status.replaceChildren(h('span', { class: 'badge fail' }, 'ERROR'), ` exit code ${end.exitCode}`)
    } catch (error) {
      status.replaceChildren(h('span', { class: 'badge fail' }, 'ERROR'), ' ', error.message)
    } finally {
      running = null
    }
  }

  renderList()
  renderEditor()
  refreshPreview()
  return h('div', { class: 'split' },
    h('aside', { class: 'side' }, list),
    h('section', { class: 'content scenario' },
      h('div', { class: 'scenario-columns' },
        h('div', {},
          h('h2', {}, 'Scenario editor'),
          h('p', { class: 'lead' }, 'Build a test without writing PHP: golems, what they do, and what should happen.'),
          editor,
          h('div', { class: 'toolbar end' },
            h('button', { class: 'secondary', onclick: () => write(false) }, 'Write the test'),
            h('button', { class: 'primary', onclick: () => write(true) }, 'Write and run')),
          output),
        h('div', { class: 'preview-box' }, h('h3', {}, 'The test it writes'), previewError, preview))))
}
