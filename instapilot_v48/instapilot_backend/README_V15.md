# InstaPilot V15 — AI Mission Control

V15 unifies the Decision Engine, content drafting, scheduling handoff, real Meta performance sync, and approved publishing into a single Mission Control UI.

## Database
Run `database/migration_v15.sql` after V14 migrations.

## Frontend
`instapilot_final.html` keeps the 5-view navigation: Home, Content, AI, Growth, Management. Each navigation action swaps a view rather than scrolling the document.

## Publishing safety
V15 does not auto-publish without explicit user action. `instagram/publish` still requires a public media URL and a connected Instagram account.

## Design
All V15 controls use the existing Theme Engine variables (`--accent`, `--accent2`, `--glow`, `--surface`, etc.), including buttons, theme cards, bottom navigation and Mission Control cards.
