# Surge Admin UI Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a first-party Surge admin UI that uses Tangible UI correctly in WordPress, gives operators a useful dashboard and safe control surface, and leaves room for later settings/features without fighting the current PHP-first cache engine.

**Architecture:** Use Tangible UI directly in a small React admin app mounted from WordPress. Keep TUI pure and consumer-owned: Surge registers the admin page, enqueues the JS/CSS bundle, exposes server data via localized bootstrap data plus REST endpoints, and wraps the app in `.tui-interface` with the unlayered stylesheet. Phase 1 is a dashboard and action surface, not a full settings rewrite.

**Tech Stack:** WordPress plugin PHP, `@wordpress/scripts`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@tangible/ui`, SCSS, WordPress REST API

---

## Audit Summary

### What Tangible UI is

- `@tangible/ui` is a pure React and CSS design system for Tangible plugins, exported from NPM and intentionally kept free of WordPress-specific runtime concerns.
- Every consuming app must provide a `.tui-interface` wrapper so CSS variables and resets apply correctly.
- In WordPress plugin contexts, consumers should import `@tangible/ui/styles/unlayered`, not the default layered stylesheet, because unlayered host CSS beats layered component CSS regardless of specificity.
- TUI expects the consumer to own app composition, data fetching, localization, and asset enqueueing. LMS follows that model already.

### What LMS proves

- LMS uses `@wordpress/scripts` with a dedicated admin entrypoint at `client/admin.js`.
- LMS mounts a React root with `createRoot` from `@wordpress/element`, adds a `.tui-interface` class to the mount container, and imports `@tangible/ui/styles/unlayered` at the app level.
- LMS enqueues `build/admin.js` and `build/admin.css` from PHP using the generated `admin.asset.php` file, localizes bootstrap data, and layers plugin-specific SCSS on top of TUI tokens.
- LMS mixes TUI with WordPress packages where needed, but keeps TUI as the visual and interaction foundation.

### What Surge looks like today

- Surge has no admin page, no JS build, no REST API, and no settings UI.
- The plugin is small and intentionally operational: install, drop-in, filesystem cache, invalidation flags, Site Health, CLI.
- Current configuration is developer-facing and code-driven through defaults, constants, `WP_CACHE_CONFIG`, and filters.
- That means a direct jump to “editable settings UI for everything” would create ambiguity about which source of truth wins.

## Key Decisions

### Decision 1: Use TUI directly in Surge, not Fields or Object, for the first version

- Surge needs a bespoke operational dashboard more than a generic field-rendering layer.
- TUI already supports plugin admin UIs in practice through LMS.
- Object or Fields may become useful later if Surge grows into a large settings-heavy product, but adding them now would increase moving parts before the dashboard exists.

### Decision 2: Build a dedicated admin page, not a Gutenberg-style embedded panel

- LMS injects into the block editor because its UI belongs inside a course editing workflow.
- Surge needs an operator dashboard reachable from standard wp-admin navigation.
- Recommendation: register `Settings > Surge` as the first durable UI surface. It fits the product better than `Tools`.

### Decision 3: Phase 1 is dashboard + actions + explanation, not full configuration editing

- The current plugin already has useful operational data: install state, cache count/size, Site Health signals, flush actions, drop-in presence, cache directory status.
- Those can be surfaced safely without redefining config precedence.
- Phase 1 should help admins answer:
  - Is Surge installed correctly?
  - Is the cache working?
  - How large is the cache?
  - Can I flush or reinstall safely?
  - What config is active right now?

### Decision 4: When settings are added, they need explicit precedence rules

- Recommended precedence:
  1. Constants and explicit PHP config
  2. `WP_CACHE_CONFIG`
  3. UI-managed option values stored in WordPress options
  4. Core defaults
- The UI must clearly indicate when a value is locked or overridden by code-level configuration.
- Do not silently overwrite code-managed values from the UI.

### Decision 5: Use REST endpoints for live data and actions

- Localized bootstrap data is good for initial page render.
- Actions such as flush, reinstall, refresh metrics, and later settings save should go through authenticated REST routes with capability and nonce checks.
- This keeps the admin app simple and gives the plugin a reusable data surface for later features.

## Proposed Information Architecture

### Phase 1 page structure

- Header
  - Plugin status
  - Current mode summary
  - Primary actions
- Health section
  - `WP_CACHE` enabled
  - Surge install status
  - Drop-in present and owned by Surge
  - Cache directory writable
- Cache overview section
  - Cache file count
  - Cache size
  - TTL in effect
  - Last cleanup summary if available
- Action section
  - Flush cache
  - Flush and delete files
  - Reinstall drop-in
  - Each destructive action must use designed confirmation UI with consequence copy, not browser-native confirm dialogs
- Configuration section
  - Read-only view of effective config
  - Source badges such as `default`, `ui`, `constant`, `wp_cache_config`
- Help section
  - Short explanations of cache hits, misses, bypasses, invalidation, and how Surge config currently works

### Later sections, once the dashboard is stable

- Bypass/expiry reason telemetry
- Targeted purge controls
- URL exclusions and variants UI
- Warmup/preload tools
- Event log / recent invalidations

## Cross-Cutting Acceptance Criteria

### Destructive action UX

- `Flush cache`, `Flush and delete files`, and `Reinstall drop-in` must use explicit confirmation UI, ideally TUI `Modal`, with specific consequence copy and action labels.
- Action buttons must enter a deterministic busy state while requests are in flight.
- Duplicate submissions must be prevented through disabled controls and client-side in-flight guards.
- After a successful mutation, the dashboard must re-fetch and re-render the affected status/metrics instead of assuming local state stayed correct.
- After a failed mutation, the UI must preserve enough state for the user to retry without ambiguity.

### Accessibility

- The dashboard must be fully operable with keyboard only.
- Focus indicators must remain visible inside the WordPress admin shell.
- Dialogs, dropdowns, and other overlays must manage focus correctly: initial focus, trapped focus where appropriate, and focus return to the triggering control on close.
- Collapsed or hidden interactive content must not remain keyboard-focusable.
- Icon-only controls must have accessible labels.
- Action results and errors must be announced to assistive technology via appropriate live-region or notice behavior.
- If the page layout becomes complex, include skip or jump affordances so operators can move directly to the primary content or actions.

### Responsive behavior in wp-admin

- Responsive review must cover actual WordPress admin contexts, not just viewport width.
- Layout must be checked against the admin sidebar expanded/collapsed states and typical wp-admin content widths.
- Card and panel layouts must degrade cleanly when horizontal space gets tight; narrow-card behavior must be intentional, not accidental wrapping.
- Any sticky header, toolbar, or action area must be validated against WordPress admin chrome so offsets and overlap behavior remain correct.
- Breakpoint or container-query choices must be documented in implementation notes before CSS grows beyond trivial layout rules.

### State ownership and persistence

- Any non-trivial UI state must be classified before implementation as one of:
  - URL-backed
  - persisted locally
  - intentionally ephemeral
- Tabs, filters, expanded panels, and draft form state must not drift into ad hoc handling.
- Mutation-related state should survive data refreshes where preserving operator context is more important than resetting the whole page.

## File and Architecture Plan

### Task 1: Add the client build foundation

**Files:**
- Create: `package.json`
- Create: `webpack.config.js`
- Create: `client/admin.js`
- Create: `client/components/App.js`
- Create: `client/styles/base.scss`
- Modify: `.gitignore` if needed for build artifacts

**Step 1: Add a WordPress-friendly JS toolchain**

- Use `@wordpress/scripts` to match LMS and reduce webpack maintenance.
- Add dependencies for `@tangible/ui` and WordPress packages used by the client app.

**Step 2: Define a single admin entrypoint**

- Mirror the LMS pattern with `client/admin.js` compiled into `build/admin.js`.
- Mount into a container with class `tui-interface`.

**Step 3: Import the correct TUI stylesheet**

- Import `@tangible/ui/styles/unlayered` from the client app.
- Add plugin-owned SCSS in `client/styles/base.scss` for layout, spacing, and any wp-admin alignment fixes.

**Step 4: Keep the first shell small**

- Start with an app shell that renders loading, error, and ready states plus placeholder cards.

**Verification:**

- Run: `pnpm install` or `npm install`
- Run: `npm run build`
- Expected: `build/admin.js`, `build/admin.css`, and `build/admin.asset.php` are created

### Task 2: Register the WordPress admin page and enqueue the app

**Files:**
- Create: `include/admin.php`
- Modify: `surge.php`

**Step 1: Register a settings page**

- Add a dedicated admin page under `Settings`.
- Require `manage_options`.

**Step 2: Render a minimal mount point**

- Output an admin page wrapper with a unique root element, for example `#surge-admin-root`.
- Keep the PHP markup minimal; React owns the inside of the page.

**Step 3: Enqueue build assets only on the Surge screen**

- Read `build/admin.asset.php` for dependencies and version.
- Enqueue `build/admin.js` and `build/admin.css` only for the Surge admin page.
- Enqueue any core WP styles needed by mixed WordPress/TUI components.

**Step 4: Localize bootstrap data**

- Pass initial health/status data, REST nonce, REST namespace, and any URLs needed by the app.

**Verification:**

- Load the Surge admin page in wp-admin.
- Expected: no script errors, the app root renders, and no assets load on unrelated admin screens.

### Task 3: Create a small server-side UI data layer

**Files:**
- Create: `include/rest.php`
- Modify: `surge.php`
- Modify: `include/common.php` only if shared read helpers are needed
- Reuse: `include/cli.php`, `include/health.php`, `include/install.php`, `include/invalidate.php`

**Step 1: Add read endpoints for dashboard data**

- Expose a read endpoint that returns:
  - install state
  - `WP_CACHE` state
  - drop-in presence/ownership
  - cache directory writability
  - cache file count and size
  - effective TTL
  - effective config sources where determinable

**Step 2: Add action endpoints**

- Add authenticated endpoints for:
  - flush cache
  - flush and delete cache files
  - reinstall/repair install if safe
- Return explicit result payloads that support deterministic confirmation, notice, and refresh handling in the client.

**Step 3: Keep capabilities and nonces strict**

- Require `manage_options`.
- Use standard REST permission callbacks and nonce validation.

**Step 4: Normalize server responses**

- Return stable shapes for success, error, warning, and action results so the client app can render notices consistently.

**Verification:**

- Call the endpoints as an admin in a local environment.
- Expected: valid JSON, correct permissions, and safe failure messages when install state is broken.

### Task 4: Build the Phase 1 TUI dashboard

**Files:**
- Modify: `client/components/App.js`
- Create: `client/components/StatusCard.js`
- Create: `client/components/HealthChecklist.js`
- Create: `client/components/ConfigSummary.js`
- Create: `client/components/ActionPanel.js`
- Create: `client/components/HelpPanel.js`
- Modify: `client/styles/base.scss`

**Step 1: Compose the page from existing TUI building blocks**

- Prefer `Card`, `Notice`, `Button`, `Chip`, `Icon`, `Progress`, `Field`, `Tabs`, and `Toolbar` before inventing custom UI primitives.
- Keep markup semantic and lean on native elements where possible.

**Step 2: Establish clear state handling**

- Implement loading, empty, success, and error states explicitly.
- Show action progress, confirmation flows, and post-action refresh clearly.
- Decide up front which dashboard state is URL-backed, locally persisted, or ephemeral.

**Step 3: Make config visibility a first-class feature**

- Show effective values and whether they come from defaults or code overrides.
- Mark code-managed values as read-only in the UI.

**Step 4: Make destructive actions safe**

- Use TUI `Modal` or equivalent designed overlays for destructive confirmations.
- Provide clear consequence copy, explicit confirm labels, and cancel paths.
- Prevent duplicate clicks while actions are pending and refresh data deterministically after completion.

**Step 5: Add explicit accessibility behavior**

- Validate keyboard order, focus visibility, focus return, non-focusable collapsed UI, and accessible labelling for non-text controls.
- Add skip or jump affordances if the page structure becomes dense enough to justify them.

**Step 6: Keep the first layout boring in the right way**

- No custom charts, no bespoke drag-and-drop, no heavy data table until the core dashboard is proven.
- Validate the layout inside real WordPress admin shell constraints, including collapsed admin sidebar and narrow content widths.

**Verification:**

- Manual QA in wp-admin across desktop, collapsed-sidebar, and narrow-width states.
- Expected: page is usable, readable, keyboard-operable, and consistent with TUI styling inside `.tui-interface`.

### Task 5: Add a settings model only for values Surge can safely own

**Files:**
- Create: `include/options.php`
- Modify: `include/common.php`
- Modify: `include/rest.php`
- Modify: `client/components/App.js`
- Create: `client/components/SettingsForm.js`

**Step 1: Define which settings are UI-owned**

- Start with a narrow list, for example:
  - TTL
  - explicit path exclusions
  - optional query-var exclusions
- Do not expose everything the PHP config system can technically affect.

**Step 2: Merge UI options into effective config with clear precedence**

- UI-managed options should flow into `config()` only when they are not overridden by constants or `WP_CACHE_CONFIG`.

**Step 3: Expose lock state to the client**

- If a value is overridden by code, the UI should render it as locked with an explanation.

**Step 4: Save through REST, not ad hoc admin-post handlers**

- Keep one client/server interaction model.

**Verification:**

- Save a UI-owned value and confirm it changes runtime behavior.
- Define the same value in code and confirm the UI shows the override instead of fighting it.

### Task 6: Add minimal automated coverage around the admin app

**Files:**
- Create: `client/components/__tests__/App.test.js`
- Create: `client/components/__tests__/ActionPanel.test.js`
- Create: `client/components/__tests__/ConfigSummary.test.js`
- Modify: `package.json`
- Create: `jest.config.js` only if `wp-scripts` defaults are not enough

**Step 1: Add JS tests for client behavior**

- Cover loading, error, success, locked config state, and action state changes.
- Cover destructive-action confirmation flows, busy/disabled states, duplicate-click prevention, and post-action refresh behavior.
- Cover permission-denied and other structured REST error responses.
- Cover missing or malformed bootstrap data so the app fails predictably instead of crashing.
- Update WordPress component mocks as needed when mixing TUI and WP packages.

**Step 2: Keep server verification practical**

- For this slice, rely on manual WordPress integration checks rather than inventing a large PHP test harness immediately.
- Capture manual verification steps in `docs/README.md` or a dedicated admin UI testing doc.
- Manual verification must include keyboard-only navigation, focus return from overlays, and action-result announcements.

**Verification:**

- Run: `npm test`
- Run: `npm run build`
- Expected: JS tests pass and the admin bundle still builds.

## Guardrails For A Future “Surge TUI” Skill

If this process becomes a reusable skill, it should enforce these rules:

- Always wrap the app in `.tui-interface`.
- Always use `@tangible/ui/styles/unlayered` in WordPress plugin contexts.
- Prefer existing TUI components before creating bespoke admin UI primitives.
- Destructive actions must use designed confirmation UI, explicit consequence copy, and deterministic refresh behavior.
- Accessibility requirements are first-class: keyboard reachability, focus management, visible focus, and announced action results.
- Responsive review must happen inside real wp-admin layout constraints, not only generic browser widths.
- Non-trivial UI state must declare whether it is URL-backed, locally persisted, or intentionally ephemeral.
- Keep TUI usage consumer-owned: WordPress concerns stay in Surge, not in TUI.
- Do not add editable settings until config precedence is explicitly designed.
- Any UI setting must declare its source of truth and lock behavior.
- New actions must go through authenticated REST endpoints with capability checks.
- Plugin-specific styling should be token-based and scoped, not global wp-admin overrides.
- Start with dashboard and action workflows before advanced analytics or charting.

## Recommended Implementation Order

1. Task 1: client build foundation
2. Task 2: admin page registration and enqueueing
3. Task 3: read/action REST layer
4. Task 4: Phase 1 dashboard UI
5. Task 6: JS coverage for the new client app
6. Task 5: UI-owned settings only after the dashboard proves useful

## Notes For Implementation

- Prefer `@wordpress/element` and `@wordpress/i18n` in the consuming app, as LMS already does.
- Use TUI’s existing status-oriented building blocks rather than recreating cards, buttons, notices, or form fields.
- Keep the first data contract small; expose only what the first dashboard actually renders.
- Resist the urge to reproduce all of Site Health inside the UI. Link to Site Health where it already answers the question well.
- Resist the urge to mirror every internal cache detail on day one. The first version should improve operator confidence, not exhaust the engine surface.
