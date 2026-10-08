"""Builds the website with every version of the documentation.

usage: build-docs.py <output dir>

The latest release is built at the root of the site, main under next/ (when it has changed
since), and every older release under its tag (v0.3.0/). Each version is built from its own
docs/, with today's VitePress configuration and theme, so all of them share the version switcher.
Run it from the repository, with the docs' dependencies installed (npm ci in docs/).
"""
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile

BASE = '/golem/'
ROOT = pathlib.Path(__file__).resolve().parents[2]
DOCS = ROOT / 'docs'


def git(*args: str) -> str:
    return subprocess.run(['git', *args], cwd=ROOT, check=True, capture_output=True, text=True).stdout.strip()


def releases() -> list[str]:
    """Release tags that have documentation, newest first."""
    tags = [t for t in git('tag', '--list', 'v*').split() if re.fullmatch(r'v\d+\.\d+\.\d+', t)]
    tags = [t for t in tags if git('ls-tree', '--name-only', f'{t}:docs').strip()]
    return sorted(tags, key=lambda t: tuple(int(n) for n in t[1:].split('.')), reverse=True)


def index_page(version: str, docs: pathlib.Path) -> str:
    """A home page for the first releases, whose docs had none."""
    order = ['getting-started', 'writing-tests', 'golems', 'assertions', 'configuration', 'ci', 'how-it-works']
    pages = sorted(docs.glob('*.md'), key=lambda p: order.index(p.stem) if p.stem in order else len(order))
    titles = []
    for page in pages:
        match = re.search(r'^# (.+)$', page.read_text(), re.M)
        titles.append(f'- [{match.group(1) if match else page.stem}](./{page.stem})')
    return f'# Golem {version}\n\nThe documentation of Golem {version}:\n\n' + '\n'.join(titles) + '\n'


def prepare(tag: str, work: pathlib.Path) -> pathlib.Path:
    """A checkout of the tag (git history included, for "last updated" dates) whose docs/ gets
    today's configuration, theme and public files."""
    checkout = work / tag
    git('worktree', 'add', '--detach', str(checkout), tag)
    docs = checkout / 'docs'
    shutil.rmtree(docs / '.vitepress', ignore_errors=True)
    shutil.copytree(DOCS / '.vitepress', docs / '.vitepress', ignore=shutil.ignore_patterns('dist', 'cache'))
    shutil.copytree(DOCS / 'public', docs / 'public', dirs_exist_ok=True)
    if not (docs / 'index.md').exists():
        (docs / 'index.md').write_text(index_page(tag, docs))
    if not (docs / 'node_modules').exists():
        (docs / 'node_modules').symlink_to(DOCS / 'node_modules')
    return docs


def build(docs: pathlib.Path, out: pathlib.Path, version: str, latest: str, base: str, versions: list, archived: bool) -> None:
    env = os.environ | {
        'GOLEM_DOCS_VERSION': version,
        'GOLEM_DOCS_LATEST': latest,
        'GOLEM_DOCS_BASE': base,
        'GOLEM_DOCS_VERSIONS': json.dumps(versions),
        'GOLEM_DOCS_ARCHIVED': '1' if archived else '0',
    }
    print(f'Building {version} at {base}', flush=True)
    subprocess.run([str(DOCS / 'node_modules' / '.bin' / 'vitepress'), 'build', str(docs), '--outDir', str(out)], check=True, env=env)


def prepare_demo() -> None:
    """The dashboard of golem ui, replaying runs recorded on the example plugin, at demo/.
    It goes in public/ so that every version of the docs links to the same, current demo."""
    demo = DOCS / 'public' / 'demo'
    shutil.rmtree(demo, ignore_errors=True)
    shutil.copytree(ROOT / 'resources' / 'ui', demo)
    (demo / 'demo.html').replace(demo / 'index.html')
    shutil.copy(DOCS / 'demo' / 'fixtures.json', demo / 'fixtures.json')


def main() -> None:
    out = pathlib.Path(sys.argv[1]).resolve()
    shutil.rmtree(out, ignore_errors=True)
    prepare_demo()

    tags = releases()
    latest = tags[0]
    # main gets its own version once its docs differ from the latest release's
    has_next = subprocess.run(['git', 'diff', '--quiet', latest, 'HEAD', '--', ':(glob)docs/**/*.md', 'docs/public'], cwd=ROOT).returncode != 0

    versions = [{'version': latest, 'base': BASE}]
    if has_next:
        versions.append({'version': 'next', 'base': f'{BASE}next/'})
    versions += [{'version': tag, 'base': f'{BASE}{tag}/'} for tag in tags[1:]]

    with tempfile.TemporaryDirectory() as temporary:
        work = pathlib.Path(temporary)
        try:
            # the latest first: VitePress empties its output folder, the root holds the others
            for tag in tags:
                base = BASE if tag == latest else f'{BASE}{tag}/'
                target = out if tag == latest else out / tag
                build(prepare(tag, work), target, tag, latest, base, versions, archived=tag != latest)
            if has_next:
                build(DOCS, out / 'next', 'next', latest, f'{BASE}next/', versions, archived=False)
        finally:
            git('worktree', 'prune')
            for tag in tags:
                subprocess.run(['git', 'worktree', 'remove', '--force', str(work / tag)], cwd=ROOT, capture_output=True)

    print('Versions:', ', '.join(v['version'] for v in versions))


if __name__ == '__main__':
    main()
