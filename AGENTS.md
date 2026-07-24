# AGENTS.md

## Cursor Cloud specific instructions

- This repository is currently a placeholder named "JS LEARNING". As of this writing it contains only `README.md` with no application code, no `package.json`, no dependency manifests, no services, and no build/lint/test tooling.
- The VM already provides Node.js v22 and npm 10 (via nvm), plus Python 3.12. There is nothing to install until project code is added.
- No update script is configured because there are no dependencies to refresh. Once a `package.json` (or other manifest) is added, set up a minimal update script (e.g. `npm install`, guarded so it is a no-op when the manifest is absent) and document how to lint/test/build/run here.
- To sanity-check the runtime with no project code present: `node -e "console.log(process.version)"`.
