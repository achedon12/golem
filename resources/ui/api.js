// Talks to golem ui's server. The token comes from the link golem ui printed: it is kept for
// the browser tab only, and sent with every call so other websites cannot use the API.

const params = new URLSearchParams(location.search)
if (params.has('token')) {
  sessionStorage.setItem('golem-token', params.get('token'))
  history.replaceState(null, '', location.pathname)
}
const token = sessionStorage.getItem('golem-token') ?? ''

export async function api (path, body) {
  // the website's demo has no server: it replays recorded runs
  if (window.GOLEM_DEMO) return (await import('./demo.js')).api(path, body)
  const response = await fetch(`/api/${path}`, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { 'X-Golem-Token': token, 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const data = await response.json().catch(() => ({ error: `HTTP ${response.status}` }))
  if (!response.ok) throw new Error(data.error ?? `HTTP ${response.status}`)
  return data
}

// Starts a golem run and calls onUpdate with each new piece of output and events until it ends.
export async function run (kind, options, onUpdate) {
  const { id, command } = await api('runs', { kind, options })
  let output = 0
  let events = 0
  const handle = {
    id,
    command,
    stopped: false,
    stop: () => api(`runs/${id}/stop`, {}),
    done: (async () => {
      for (;;) {
        const state = await api(`runs/${id}?output=${output}&events=${events}`)
        output = state.outputOffset
        events = state.eventsOffset
        onUpdate(state)
        if (!state.running) return state
        await new Promise((resolve) => setTimeout(resolve, 400))
      }
    })(),
  }
  return handle
}

// Small helpers to build the page.
export function h (tag, attributes = {}, ...children) {
  const element = document.createElement(tag)
  for (const [name, value] of Object.entries(attributes)) {
    if (value === false || value === null || value === undefined) continue
    if (name.startsWith('on')) element.addEventListener(name.slice(2), value)
    else if (name === 'class') element.className = value
    else element.setAttribute(name, value === true ? '' : value)
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue
    element.append(child instanceof Node ? child : document.createTextNode(String(child)))
  }
  return element
}

export function seconds (value) {
  return value >= 1 ? `${value.toFixed(2)}s` : `${Math.round(value * 1000)}ms`
}
