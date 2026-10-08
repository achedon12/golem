// Turns the colours of Golem's console output into HTML.

const styles = { 1: 'b', 2: 'dim', 31: 'red', 32: 'green', 33: 'yellow', 34: 'blue', 35: 'magenta', 36: 'cyan', 90: 'gray', 41: 'bg-red', 42: 'bg-green', 43: 'bg-yellow' }

function escape (text) {
  return text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
}

export function ansiToHtml (text) {
  let html = ''
  let open = 0
  for (const part of text.split(/(\x1b\[[0-9;]*m)/)) {
    const match = part.match(/^\x1b\[([0-9;]*)m$/)
    if (!match) {
      html += escape(part.replace(/\x1b\[[0-9;]*[A-Za-z]/g, ''))
      continue
    }
    const codes = match[1].split(';').filter(Boolean).map(Number)
    if (codes.length === 0 || codes.includes(0)) {
      html += '</span>'.repeat(open)
      open = 0
      continue
    }
    const classes = codes.map((code) => styles[code]).filter(Boolean)
    if (classes.length > 0) {
      html += `<span class="${classes.join(' ')}">`
      open++
    }
  }
  return html + '</span>'.repeat(open)
}
