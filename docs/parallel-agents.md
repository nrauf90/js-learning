# Running several agents on this repo at once

One source tree, several agents, no lost work and no crossed wires.

```bash
npm run wt new khata-fixes     # create and provision a worktree
npm run wt:list                # who is working where, and on which ports
npm run wt rm khata-fixes      # remove it (refuses if dirty or unmerged)
```

Point each agent at the directory the first command prints. That is the whole
workflow; the rest of this file is what it is protecting you from.

---

## What actually collides, and what does not

Worth knowing, because the intuition ("they'll overwrite each other's files") is
not the real risk here.

| | Isolated? | Why |
|---|---|---|
| Source files, branch, git index | **Yes** — by the worktree | Each worktree is its own checkout with its own HEAD |
| Dev database | **Yes**, once provisioned | `DB_CONNECTION=sqlite`, and `backend/database/database.sqlite` is gitignored — so it is a per-checkout file, not a shared server |
| Backend unit tests | **Yes**, always | `phpunit.xml` sets `DB_DATABASE=:memory:` — nothing on disk to share |
| **Ports 3000 / 3100 / 8000** | **No** | The real collision. Two agents starting servers means the second fails to bind — or worse, silently uses the first's |
| `node_modules`, `backend/vendor` | Shared by design | Linked, not copied. Hundreds of megabytes, and identical across worktrees |
| `backend/.env` | Copied per worktree | Each agent must be able to change it without touching anyone else's |

So the danger is **not** agents overwriting files. It is two agents quietly
sharing a server and each believing it tested its own code.

### The silent-failure case this prevents

Before this tooling, an agent in a second checkout would:

1. Start its API — fail to bind :8000, because the main checkout already had it.
2. Run its QA suite — the harness sees :8000 answering, reports
   `✓ API already up`, and proceeds.
3. Pass. **Against the other checkout's backend.**

Nothing errors. The agent reports green on code the server never loaded. Port
slots exist to make that impossible.

---

## Port slots

Every checkout gets a slot. The main checkout is always slot 0 and keeps the
historic ports, so nothing about working there changes.

| Slot | web | mobile | api |
|---|---|---|---|
| 0 — main checkout | 3000 | 3100 | 8000 |
| 1 | 3001 | 3101 | 8001 |
| 2 | 3002 | 3102 | 8002 |

`npm start`, `npm run dev:api`, `npm run mobile:serve` and every `qa:*` script
follow the slot with no flags. `npm run ports` prints the current one.

The slot lives in `.agent.json` at the checkout root. It is **gitignored on
purpose** — it describes this working copy, not the project, and committing it
would hand every checkout the same ports again.

`js/api.js` derives the API port from the page's own port on localhost
(3000+N → 8000+N), so a browser opened on a worktree's frontend talks to that
worktree's backend. Without it the page would call :8000 and the mistake would
be invisible.

---

## Conflict-prone files

Worktrees prevent lost work; they do not prevent merge conflicts. These are
touched by nearly every task, so expect to resolve them and keep edits small
and localised:

| File | Why it collides |
|---|---|
| `css/styles.css` | ~6,400 lines, appended to by every UI change |
| `docs/CONTEXT.md` | Every task updates **Current status** |
| `package.json` | Dependency and script additions |
| `.gitignore` | Anything adding build output |

If two agents must both touch `css/styles.css`, have them append to the end in
clearly-commented blocks rather than editing shared rules — appends conflict
far more cleanly than interleaved edits.

---

## Merging back

```bash
node scripts/worktree.mjs list        # confirm the branch and that it is clean
git merge agent/<name>               # from the main checkout
npm run lint && npm test             # then the QA suite for the touched area
npm run wt rm <name>
```

`rm` **refuses** when the worktree has uncommitted changes or commits not on
`main`, and prints them. That refusal is the point — it is the last thing
standing between an abandoned worktree and silently discarded work. Commit or
merge first; there is no `--force`.

---

## Provisioning: what `wt new` does that `git worktree add` does not

`git worktree add` gives you tracked files and nothing else. Four things this
project needs to run are gitignored, so a bare worktree cannot install, migrate,
serve or test:

- `node_modules/` — linked from the main checkout (junction on Windows, no admin needed)
- `backend/vendor/` — linked the same way
- `backend/.env` — copied, with `APP_URL` rewritten to this slot's API port
- `backend/database/database.sqlite` — copied, so the agent starts with working data

Plus the slot assignment and `.agent.json`.

---

## Limits

- **The linked `node_modules` is shared.** An agent that runs `npm install` for a
  new dependency changes it for **every** checkout. That is usually what you
  want (they should agree on dependencies), but it means a version bump in one
  worktree silently reaches the others. Install deliberately, and re-run the
  other agents' tests afterwards.
- **The database is copied, not shared** — so an agent that migrates or seeds is
  working on its own data. Nothing propagates back; that is intended, but do not
  expect to see another agent's test records.
- **Slots are assigned at creation.** Removing worktree 1 while 2 exists frees
  slot 1 for the next `new`; slots are not renumbered.
- **Native mobile builds** (`android/`, `ios/`) are tracked, so each worktree has
  its own copy. Two agents running `cap sync` do not conflict, but both will
  produce diffs in those directories.
