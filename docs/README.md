# Surge Documentation

This directory contains the initial documentation set for evaluating and planning a pro version of Surge.

- `codebase-audit.md`: technical walkthrough of the current plugin architecture, lifecycle, cache model, invalidation behavior, operator surfaces, and implementation constraints.
- `pro-opportunities.md`: product and UX gap analysis derived from the current implementation, with a recommended priority stack for Surge Pro.
- `plans/2026-03-19-surge-admin-ui-plan.md`: implementation plan for adding a first-party Surge admin UI with Tangible UI.

Project-local agent guidance:

- `.agents/skills/tui-wordpress-admin-ui/SKILL.md`: use this when building or revising a WordPress admin UI in Surge with `@tangible/ui`.

Suggested reading order:

1. Start with `codebase-audit.md` to understand the existing engine.
2. Continue to `pro-opportunities.md` to turn those implementation realities into product priorities.
3. Use `plans/2026-03-19-surge-admin-ui-plan.md` and `.agents/skills/tui-wordpress-admin-ui/SKILL.md` when starting admin UI implementation work.
