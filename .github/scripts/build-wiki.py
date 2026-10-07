"""Builds the GitHub wiki from docs/, so the documentation lives in one place.

usage: build-wiki.py <docs dir> <wiki checkout>

Every page of the website becomes a wiki page; links between pages are rewritten to
wiki links, links to the rest of the repository point at GitHub, and the wiki gets a
home page, a sidebar and a footer. Pages the docs no longer have are removed.
"""
import pathlib
import re
import sys

REPO = 'https://github.com/achedon12/golem'
SITE = 'https://achedon12.github.io/golem'

# docs file -> (wiki page, sidebar title), in sidebar order
PAGES = {
    'getting-started.md': ('Getting-Started', 'Getting started'),
    'writing-tests.md': ('Writing-Tests', 'Writing tests'),
    'golems.md': ('Golems', 'Golems'),
    'assertions.md': ('Assertions', 'Assertions'),
    'configuration.md': ('Configuration', 'Configuration'),
    'ci.md': ('Continuous-Integration', 'Continuous integration'),
    'how-it-works.md': ('How-It-Works', 'How it works'),
}
GUIDE = ['getting-started.md', 'writing-tests.md', 'golems.md', 'assertions.md']
REFERENCE = ['configuration.md', 'ci.md', 'how-it-works.md']

LINK = re.compile(r'\]\(([^)\s]+)\)')


def rewrite_link(target: str) -> str:
    if re.match(r'^[a-z]+:', target) or target.startswith('#'):
        return target
    path, _, anchor = target.partition('#')
    suffix = f'#{anchor}' if anchor else ''
    name = path.split('/')[-1]
    if name in PAGES:
        return PAGES[name][0] + suffix
    if path.startswith('/'):                       # a file of the website's public/ folder
        return f'{SITE}{path}{suffix}'
    clean = re.sub(r'^(\./)?(\.\./)+', '', path)  # relative to docs/: point at the repository
    return f'{REPO}/blob/main/{clean}{suffix}'


def convert(markdown: str) -> str:
    markdown = re.sub(r'\A---\n.*?\n---\n+', '', markdown, flags=re.S)   # website-only metadata
    markdown = re.sub(r'\A# .*\n+', '', markdown)   # the wiki shows the page name as its title
    return LINK.sub(lambda m: f']({rewrite_link(m.group(1))})', markdown)


def home() -> str:
    def items(files):
        return '\n'.join(f'- **[{PAGES[f][1]}]({PAGES[f][0]})**' for f in files)

    return f"""<p align="center">
  <img src="{REPO}/raw/main/.github/assets/banner.svg" alt="Golem: integration tests for PocketMine-MP plugins" width="100%">
</p>

**Golem** tests your PocketMine-MP plugin the way your players use it: one command boots a real
server, loads your plugin from source, spawns simulated players that join, chat, run commands,
click forms and break blocks, then checks what happened.

```bash
composer require --dev achedon12/golem
vendor/bin/golem init
vendor/bin/golem
```

## Guide

{items(GUIDE)}

## Reference

{items(REFERENCE)}

## Elsewhere

- 🌐 [Website]({SITE}/), the same documentation with search
- 📦 [Packagist](https://packagist.org/packages/achedon12/golem)
- ✅ [GitHub Action](https://github.com/marketplace/actions/golem-pocketmine-mp-tests)
- 📝 [Changelog]({REPO}/blob/main/CHANGELOG.md)
- 💬 [Discussions]({REPO}/discussions), for questions and ideas
"""


def sidebar() -> str:
    def items(files):
        return '\n'.join(f'- [{PAGES[f][1]}]({PAGES[f][0]})' for f in files)

    return f"""**[🏠 Home](Home)**

**Guide**

{items(GUIDE)}

**Reference**

{items(REFERENCE)}

**Links**

- [Website]({SITE}/)
- [Repository]({REPO})
- [Changelog]({REPO}/blob/main/CHANGELOG.md)
"""


FOOTER = f"""This wiki is generated from [`docs/`]({REPO}/tree/main/docs) on every push: edit the docs, not the wiki. · [Website]({SITE}/) · MIT License
"""


def main() -> None:
    docs, wiki = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
    expected = {'Home.md', '_Sidebar.md', '_Footer.md'}

    for source, (page, _) in PAGES.items():
        (wiki / f'{page}.md').write_text(convert((docs / source).read_text()))
        expected.add(f'{page}.md')

    (wiki / 'Home.md').write_text(home())
    (wiki / '_Sidebar.md').write_text(sidebar())
    (wiki / '_Footer.md').write_text(FOOTER)

    for stale in wiki.glob('*.md'):
        if stale.name not in expected:
            stale.unlink()


if __name__ == '__main__':
    main()
