---
name: tui-wordpress-admin-ui
description: Use when building or revising a WordPress admin UI with @tangible/ui, especially dashboards, settings pages, sidebars, editor-adjacent panels, or operational action surfaces.
---

# TUI WordPress Admin UI

## Overview
Build WordPress admin UIs with `@tangible/ui` as a consumer-owned design system, not as an app framework. The plugin owns page registration, enqueueing, data flow, permissions, localization, and runtime state. TUI owns visual primitives and interaction components.

This skill combines two things:
- the integration contract proven by LMS and clarified in the Surge admin UI plan
- the frontend quality bar visible in Julia's corrective follow-up work

## When to Use
- New wp-admin pages built with `@tangible/ui`
- Existing admin UIs that need cleanup, consistency, or TUI adoption
- Editor-adjacent sidebars, toolbars, inserters, inspectors, and dashboard surfaces
- Settings or action UIs where WordPress runtime concerns and TUI components must coexist

Do not use this for public-facing marketing pages or non-WordPress apps.

## Workflow
1. Choose the surface first.
   - Use a dedicated admin page for operational dashboards and control panels.
   - Use embedded editor UI only when the workflow belongs inside an existing editor.

2. Choose the abstraction level before coding.
   - Use plain TUI when the product needs a bespoke dashboard, action surface, or mixed read/write interface.
   - Consider higher-level form abstractions only when the problem is mostly settings rendering.
   - Do not introduce extra abstraction layers just to avoid writing composition code.

3. Keep TUI consumer-owned.
   - Register screens, enqueue assets, localize bootstrap data, and define REST routes in the plugin.
   - In WordPress plugin contexts, prefer `@wordpress/scripts` with a dedicated admin entry such as `client/admin.js`.
   - Enqueue built assets from `build/admin.asset.php` and scope `admin_enqueue_scripts` to the target screen.
   - Mount the app inside a `.tui-interface` container.
   - In WordPress contexts, import `@tangible/ui/styles/unlayered`.

4. Split data flow cleanly.
   - Use localized bootstrap data for first render.
   - Use authenticated REST endpoints for live reads, actions, and saves.
   - Keep response shapes stable and small; only return what the current UI renders.

5. Build the UI from existing primitives first.
   - Prefer TUI `Card`, `Button`, `Chip`, `Dropdown`, `Modal`, `Field`, `Toolbar`, `Tabs`, `Icon`, `Notice`-style patterns before custom components.
   - When repeated structure appears, extract shared app-level primitives instead of duplicating markup across features.

6. Apply the frontend quality bar before calling it done.
   - Replace browser-native confirmations with designed modal flows.
   - Add explicit loading, empty, success, error, locked, and busy states.
   - For destructive or mutating actions, require busy/disabled states, duplicate-click prevention, deterministic post-action refresh, and retry-safe failure handling.
   - Make the page keyboard-operable end to end, including dropdowns, dialogs, and other overlays.
   - Ensure overlays manage focus correctly: initial focus, trapped focus where appropriate, and focus return to the triggering control on close.
   - Ensure action results and errors are announced through notices or another appropriate live-region mechanism.
   - Add skip or jump affordances when the admin layout becomes dense enough that operators need a faster path to primary content or actions.
   - Make responsive behavior work inside real wp-admin constraints, not just in a blank viewport.
   - Validate layouts against expanded and collapsed wp-admin sidebar states, and explicitly check sticky header or toolbar offsets against admin chrome.
   - Use token-based, scoped styling; avoid ad hoc spacing and global admin overrides.
   - Classify non-trivial UI state before implementation as URL-backed, locally persisted, or intentionally ephemeral.
   - Remove legacy or duplicate interaction paths once the new flow is live.

## Non-Negotiable Rules
- Do not add editable settings until config precedence is explicit.
- Any UI-managed setting must declare its source of truth and lock behavior.
- Code-managed values must render as locked, not silently overwritten.
- Destructive actions must use confirmation UI with consequence copy, in-flight guards, and deterministic refresh after success.
- Icon-only controls need accessible labels and visible focus behavior.
- The UI must remain fully usable with keyboard only.
- Collapsed or hidden interactive content must not remain focusable.
- Overlay components must return focus on close.
- Action results and errors must be announced accessibly.
- Dense admin layouts must provide skip or jump affordances when primary actions or content would otherwise be tedious to reach.
- Tests, mocks, and lint rules must be updated with the UI contract.

## Review Checklist
- Is the page type correct for the workflow?
- Is TUI being used directly, without pushing WordPress runtime concerns into the design system?
- Does the integration follow the concrete WordPress pattern: `@wordpress/scripts`, screen-scoped enqueueing, `build/admin.asset.php`, localized bootstrap data, and a `.tui-interface` mount?
- Are bootstrap data and REST responsibilities separated cleanly?
- Are destructive actions confirmed and fully stateful?
- Are destructive flows retry-safe, with post-action refresh after success and preserved context after failure?
- Is the UI keyboard-operable, with correct focus handling and accessible announcements?
- Does the UI provide skip or jump affordances where layout density warrants them?
- Does the UI work in narrow widths, with both expanded and collapsed admin sidebar states, and with correct sticky offset behavior inside wp-admin chrome?
- Are repeated structures extracted into shared local components?
- Are styles token-based and scoped?
- Has each non-trivial UI state been classified as URL-backed, locally persisted, or intentionally ephemeral?
- Have old parallel flows been removed?
- Do tests cover loading, error, permission, lock-state, destructive-action confirmation flows, missing bootstrap data, and WP/TUI mock maintenance?

## Compatibility

Required tools:
- read
- write
- shell

Optional tools:
- edit
- plan
- mcp
- http
- vision
- audio

Backend notes:
- claude-code: use repo inspection, edit files directly, and verify with local commands
- codex-cli: use file reads, `apply_patch`, and shell verification
- openai-agents: map to available tools; if direct editing is missing, produce a patch

Fallbacks:
- If write/edit is unavailable, output a patch/diff and ask the user to apply it.
- If shell is unavailable, provide commands only and ask the user to run them.
- If vision is unavailable, rely on responsive and accessibility checklists plus code inspection.

## Registry Notes

summary: "Guides the architecture and implementation of WordPress admin UIs built with @tangible/ui, including integration boundaries, state handling, responsive behavior, and destructive-action safety."
tags: ["skills", "tui", "wordpress", "admin-ui", "frontend"]
backends: ["claude-code", "codex-cli", "openai-agents"]
source: "Derived from LMS frontend patterns and the Surge admin UI planning work."
