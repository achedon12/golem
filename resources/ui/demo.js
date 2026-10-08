// The dashboard on Golem's website: no server behind it, the runs are replays of real runs
// recorded on the example plugin, sped up. Only demo.html loads it.

const fixtures = await (await fetch(new URL('fixtures.json', import.meta.url))).json()
const ROOT = fixtures.project.root
const SPEED = { test: 2, fuzz: 1.5, bench: 2.5 }
const runs = new Map()

function source (file) {
  const relative = decodeURIComponent(file).replace(ROOT + '/', '')
  if (!(relative in fixtures.sources)) throw new Error('Not a source file of the plugin')
  return { file: relative, content: fixtures.sources[relative] }
}

// A recorded test run, keeping only the tests the filter picks, as golem run --filter would.
function testRecording (filter) {
  const recording = fixtures.runs.tests
  if (!filter) return recording
  const parts = filter.toLowerCase().split('|').map((p) => p.trim()).filter(Boolean)
  const wanted = (event) => parts.some((p) => `${event.class}::${event.method}`.toLowerCase().includes(p))
  const frames = []
  const counts = { passed: 0, failed: 0, errored: 0, skipped: 0 }
  let count = 0
  for (const frame of recording.frames) {
    for (const event of frame.events) if (event.type === 'test' && wanted(event)) count++
  }
  for (const frame of recording.frames) {
    const events = []
    for (const event of frame.events) {
      if (event.type === 'started') events.push({ ...event, count })
      else if (event.type === 'test') { if (wanted(event)) { events.push(event); counts[event.status]++ } }
      else if (event.type === 'finished') events.push({ ...event, counts, successful: counts.failed + counts.errored === 0, coverage: null })
      else events.push(event)
    }
    frames.push({ t: frame.t, output: frame === recording.frames[0] ? frame.output : '', events })
  }
  if (count === 0) return scenarioRecording(filter)
  return { exitCode: counts.failed + counts.errored ? 1 : 0, frames }
}

// A test written in the scenario editor was not recorded: say so instead of pretending.
function scenarioRecording (filter) {
  const short = filter.replace(/::.*$/, '')
  return {
    exitCode: 0,
    frames: [
      { t: 1, output: '\x1b[2m  Golem is starting PocketMine-MP 5.44.3…\x1b[0m\n', events: [{ type: 'started', count: 1, plugin: 'HelloWorld 1.0.0', pocketmine: '5.44.3' }] },
      { t: 2, output: '', events: [{ type: 'test', shortClass: short, class: short, method: 'testScenario', name: 'testScenario', description: 'your scenario', status: 'skipped', seconds: 0, ticks: 0, assertions: 0, message: 'This demo replays recorded runs: install Golem and run golem ui to run your scenario', expected: null, actual: null, failureFile: null, failureLine: null, file: `${ROOT}/tests/${short}.php`, line: 1, trace: [] }] },
      { t: 2.2, output: '', events: [{ type: 'finished', successful: true, crashed: false, abortReason: null, counts: { passed: 0, failed: 0, errored: 0, skipped: 1 }, assertions: 0, seconds: 1.2, bootSeconds: 0.6, coverage: null, serverLog: '' }] },
    ],
  }
}

function start ({ kind, options = {} }) {
  const recording = kind === 'test' ? testRecording(options.filter) : fixtures.runs[kind]
  if (!recording) throw new Error(`Unknown run kind "${kind}"`)
  const id = String(Date.now() + Math.random())
  runs.set(id, { recording, kind, started: performance.now(), next: 0, stopped: false })
  const flags = Object.entries(options).filter(([, v]) => v !== '' && v !== false && v !== null && v !== undefined && !(v === 1 && kind === 'test'))
    .map(([k, v]) => v === true ? `--${k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())}` : `--${k.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())}=${v}`)
  return { id, command: `golem ${kind === 'test' ? 'run' : kind} ${flags.join(' ')}`.trim() }
}

function poll (id) {
  const run = runs.get(id)
  if (!run) throw new Error('Unknown run')
  const elapsed = (performance.now() - run.started) / 1000 * SPEED[run.kind]
  let output = ''
  const events = []
  while (run.next < run.recording.frames.length && (run.recording.frames[run.next].t <= elapsed || run.stopped)) {
    const frame = run.recording.frames[run.next++]
    if (run.stopped) continue
    output += frame.output
    events.push(...frame.events)
  }
  const done = run.next >= run.recording.frames.length
  return { running: !done, exitCode: done ? (run.stopped ? 143 : run.recording.exitCode) : null, output, outputOffset: 0, events, eventsOffset: 0 }
}

export async function api (path, body) {
  await new Promise((resolve) => setTimeout(resolve, 60))
  const [route, query = ''] = path.split('?')
  if (route === 'project') return fixtures.project
  if (route === 'source') return source(new URLSearchParams(query).get('file') ?? '')
  if (route === 'runs') return start(body)
  let match = route.match(/^runs\/([^/]+)\/stop$/)
  if (match) { const run = runs.get(match[1]); if (run) run.stopped = true; return { stopped: true } }
  match = route.match(/^runs\/([^/]+)$/)
  if (match) return poll(match[1])
  if (route === 'scenarios/preview') return { code: scenarioCode(body) }
  if (route === 'scenarios') {
    const code = scenarioCode(body)
    const name = code.match(/final class (\w+)/)[1]
    return { file: `${ROOT}/tests/${name}.php`, class: name, filter: `${name}::` }
  }
  throw new Error(`No ${path}`)
}

// ---------- the PHP the scenario editor writes, as ScenarioWriter does on a real dashboard

function literal (value) {
  if (value === null) return 'null'
  if (typeof value === 'number') return String(value)
  if (/^[\x20-\x7e]*$/.test(value)) return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
  return '"' + [...value].map((c) => c === '\\' ? '\\\\' : c === '"' ? '\\"' : c === '$' ? '\\$' : c === '\n' ? '\\n' : /[\x20-\x7e]/.test(c) ? c : `\\u{${c.codePointAt(0).toString(16).toUpperCase()}}`).join('') + '"'
}

function scenarioCode (scenario) {
  const words = (text) => (text ?? '').split(/[^A-Za-z0-9]+/).filter(Boolean)
  const name = (scenario.name ?? '').trim()
  if (!name) throw new Error('The scenario: fill in "name"')
  let cls = words(name).map((w) => w[0].toUpperCase() + w.slice(1)).join('')
  if (!cls || /^\d/.test(cls)) throw new Error('Name the scenario with letters first, like "Kit menu"')
  if (!cls.endsWith('Test')) cls += 'Test'
  const method = 'test' + (words(scenario.test).map((w) => w[0].toUpperCase() + w.slice(1).toLowerCase()).join('') || 'Scenario')
  const golems = scenario.golems ?? []
  const names = golems.map((g) => (g.name ?? '').trim())
  for (const n of names) if (!/^[A-Za-z_][A-Za-z0-9_]{0,15}$/.test(n)) throw new Error(`"${n}" is not a golem name: a letter first, then letters, digits and _, 16 at most`)
  const v = (n) => '$' + n[0].toLowerCase() + n.slice(1)
  const uses = new Set(['Generator', 'Golem\\TestCase'])
  const lines = []
  if (names.length === 1) lines.push(`${v(names[0])} = yield $this->golem(${literal(names[0])});`)
  else if (names.length > 1) lines.push(`[${names.map(v).join(', ')}] = yield $this->golems([${names.map(literal).join(', ')}]);`)
  for (const g of golems) {
    if (g.op) lines.push(`${v(g.name)}->op();`)
    if (g.gamemode) { lines.push(`${v(g.name)}->gamemode(GameMode::${g.gamemode});`); uses.add('pocketmine\\player\\GameMode') }
  }
  lines.push('')
  ;(scenario.steps ?? []).forEach((step, index) => {
    const n = index + 1
    const golem = () => { if (!names.includes(step.golem)) throw new Error(`Step ${n}: pick one of the golems`); return v(step.golem) }
    const text = (required = true) => { const t = String(step.text ?? '').trim(); if (!t && required) throw new Error(`Step ${n}: fill in "text"`); return t }
    const num = (key, fallback = 0) => { const x = step[key] === '' || step[key] === undefined ? fallback : Number(step[key]); if (!Number.isFinite(x)) throw new Error(`Step ${n}: "${key}" must be a number`); return Math.trunc(x) }
    const item = () => { const i = String(step.item ?? '').trim().toLowerCase(); if (!/^[a-z0-9_:]{1,64}$/.test(i)) throw new Error(`Step ${n}: an item name looks like diamond_sword`); const c = num('count', 1); return c === 1 ? `$this->item(${literal(i)})` : `$this->item(${literal(i)}, ${c})` }
    const block = () => `${golem()}->position()->floor()->add(${num('dx')}, ${num('dy')}, ${num('dz')})`
    const optional = (method) => text(false) ? `$this->${method}(${golem()}, ${literal(text())});` : `$this->${method}(${golem()});`
    const code = {
      chat: () => `${golem()}->chat(${literal(text())});`,
      command: () => `${golem()}->command(${literal(text().replace(/^\/+/, ''))});`,
      walk: () => `yield ${golem()}->walk(${num('x')}, ${num('z')});`,
      wait: () => `yield $this->wait(${num('ticks', 1)});`,
      jump: () => `${golem()}->jump();`,
      clickButton: () => `${golem()}->clickButton(${/^\d+$/.test(text()) ? Number(text()) : literal(text())});`,
      clickSlot: () => `${golem()}->clickSlot(${num('slot')});`,
      closeWindow: () => `${golem()}->closeWindow();`,
      breakBlock: () => `${golem()}->breakBlock(${block()});`,
      interactBlock: () => `${golem()}->interactBlock(${block()});`,
      give: () => `${golem()}->give(${item()});`,
      attack: () => { if (!names.includes(step.target)) throw new Error(`Step ${n}: pick the golem to attack`); return `${golem()}->attack(${v(step.target)});` },
      quit: () => `${golem()}->quit();`,
      receivedMessage: () => `$this->assertReceivedMessage(${golem()}, ${literal(text())});`,
      notReceivedMessage: () => `$this->assertNotReceivedMessage(${golem()}, ${literal(text())});`,
      lastMessage: () => `$this->assertSame(${literal(text())}, ${golem()}->lastMessage());`,
      formOpen: () => optional('assertFormOpen'),
      noFormOpen: () => `$this->assertNoFormOpen(${golem()});`,
      windowOpen: () => `$this->assertWindowOpen(${golem()});`,
      noWindowOpen: () => `$this->assertNoWindowOpen(${golem()});`,
      hasItem: () => `$this->assertHasItem(${golem()}, ${item()});`,
      notHasItem: () => `$this->assertNotHasItem(${golem()}, ${item()});`,
      health: () => `$this->assertHealth(${golem()}, ${num('value').toFixed(1)});`,
      gamemode: () => { uses.add('pocketmine\\player\\GameMode'); return `$this->assertGamemode(${golem()}, GameMode::${(step.text || 'SURVIVAL').toUpperCase()});` },
      title: () => `$this->assertTitle(${golem()}, ${literal(text())});`,
      actionBar: () => `$this->assertActionBar(${golem()}, ${literal(text())});`,
      scoreboardContains: () => `$this->assertScoreboardContains(${golem()}, ${literal(text())});`,
      online: () => `$this->assertOnline(${golem()});`,
      offline: () => `$this->assertOffline(${golem()});`,
      kicked: () => optional('assertKicked'),
      hasPermission: () => `$this->assertHasPermission(${golem()}, ${literal(text())});`,
      tpsAbove: () => `$this->assertTpsAbove(${num('value', 1).toFixed(1)});`,
    }[step.type]
    if (!code) throw new Error(`Step ${n}: unknown step "${step.type}"`)
    lines.push(code())
  })
  while (lines.length && lines[lines.length - 1] === '') lines.pop()
  return `<?php\n\ndeclare(strict_types=1);\n\nnamespace Example\\HelloWorld\\Tests;\n\n${[...uses].sort().map((u) => `use ${u};\n`).join('')}\nfinal class ${cls} extends TestCase\n{\n    public function ${method}(): Generator\n    {\n${lines.map((l) => l === '' ? '\n' : `        ${l}\n`).join('')}    }\n}\n`
}
