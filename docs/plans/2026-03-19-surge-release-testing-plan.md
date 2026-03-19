# Surge Release And Testing Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make `surge-pro` easy to test locally and in GitHub Actions by introducing a shared WordPress dev harness, deterministic plugin ZIP packaging, preview prereleases for QA, and stable tag-based GitHub Releases.

**Architecture:** Keep the plugin repo single-purpose and lightweight. Add one local/CI harness around it: Docker Compose boots WordPress and MySQL, WP-CLI installs either the working tree or a built ZIP, and shell scripts own packaging and smoke checks so GitHub Actions and local development call the same entrypoints. Use two release lanes: preview prereleases from `trunk` for testing, and stable releases from semver tags after version validation.

**Tech Stack:** WordPress plugin PHP, Docker Compose, WP-CLI, Bash scripts, GitHub Actions, GitHub Releases

---

## Current-State Summary

- `surge-pro` currently contains only plugin source files and docs.
- There is no local WordPress harness, no CI workflow, no packaging script, and no install-from-zip smoke test.
- The plugin version exists in both [surge.php](/Users/gabrielgallagher/Documents/repos/surge-pro/surge.php) and [readme.txt](/Users/gabrielgallagher/Documents/repos/surge-pro/readme.txt), so release automation should validate both before publishing.
- `lms-monorepo` already demonstrates the target pattern:
  - a Docker-based test workflow for local/CI parity
  - a dedicated release workflow that builds a production ZIP and publishes it as a GitHub Release

## Key Decisions

### Decision 1: Use Docker Compose as the shared local and CI runtime

- This matches the LMS workflow closely and avoids “works locally but not in CI” drift.
- Use official WordPress and MySQL images plus a WP-CLI service or one-off command execution.
- Keep the first environment minimal: one site, one database, one plugin mount, one smoke-test path.

### Decision 2: Treat ZIP packaging as a first-class build artifact

- Local testing and CI should both install the exact ZIP that will be attached to a release.
- Packaging must be script-driven, not embedded directly inside workflow YAML.
- The build script should create both stable and preview file names from a shared implementation.

### Decision 3: Split preview releases from stable releases

- Preview releases should happen on every push to `trunk` and on manual dispatch.
- Stable releases should happen only from semver tags after version validation.
- Preview artifacts should be marked prerelease and include the commit SHA in both filename and release name to keep QA artifacts unambiguous.

### Decision 4: Make smoke tests plugin-aware, not just “plugin activated”

- Minimum checks should cover the behaviors that matter for Surge:
  - plugin installs and activates from ZIP
  - `advanced-cache.php` is created or repaired correctly
  - cache directory/files are created by a real request
  - a cached response advertises the expected cache behavior
  - `wp surge flush` works when the plugin is active

## Release Model

### Preview prerelease lane

- Trigger on:
  - push to `trunk`
  - manual `workflow_dispatch`
- Build a preview ZIP named like `surge-1.1.0-dev+<shortsha>.zip`
- Smoke-test that ZIP in Docker before publishing
- Publish to a GitHub prerelease named like `Surge Preview <shortsha>`
- Update a predictable prerelease tag such as `preview` or create one prerelease per commit

### Stable release lane

- Trigger on tags like `v1.1.1`
- Validate:
  - tag version matches plugin header version
  - tag version matches `Stable tag` in `readme.txt`
- Build a clean ZIP named `surge-1.1.1.zip`
- Smoke-test that ZIP in Docker before publishing
- Publish a standard GitHub Release with generated release notes

### Recommendation

- Use one prerelease per commit, not a moving single asset, so QA can always point to an exact artifact.
- Keep stable release tags in standard semver format `vX.Y.Z`; do not overload preview tags with semantic meaning.

## File And Workflow Plan

### Task 1: Create the shared local WordPress harness

**Files:**
- Create: `docker-compose.yml`
- Create: `.env.example`
- Create: `.gitignore` updates for local env files and temp artifacts
- Create: `DEVELOPMENT.md`

**Step 1: Define the minimum services**

- Add services for:
  - `db` using MySQL or MariaDB
  - `wordpress` using the official WordPress image
  - `wpcli` using the official WP-CLI image or a `wordpress:cli`-compatible image
- Bind-mount the repo into `wp-content/plugins/surge`
- Expose one local site port and document it in `.env.example`

**Step 2: Make the environment configurable**

- Add `.env.example` with:
  - `WORDPRESS_PORT`
  - `WORDPRESS_DB_NAME`
  - `WORDPRESS_DB_USER`
  - `WORDPRESS_DB_PASSWORD`
  - `WORDPRESS_DB_ROOT_PASSWORD`
- Keep defaults simple and local-safe

**Step 3: Document the environment lifecycle**

- In `DEVELOPMENT.md`, document:
  - `docker compose up -d`
  - `docker compose down`
  - how to open WP Admin
  - how to run WP-CLI commands against the mounted plugin

**Step 4: Verify the environment manually**

- Run: `cp .env.example .env`
- Run: `docker compose up -d --wait`
- Run: `docker compose ps`
- Expected: database and WordPress containers are healthy and reachable

### Task 2: Add deterministic packaging scripts for preview and stable ZIPs

**Files:**
- Create: `bin/build-plugin-zip.sh`
- Create: `bin/lib/version.sh`
- Create: `bin/lib/package.sh`
- Modify: `.gitignore`

**Step 1: Centralize version parsing**

- Read the plugin version from `surge.php`
- Read the stable tag from `readme.txt`
- Add a helper that fails if the values diverge during stable builds

**Step 2: Define production file inclusion**

- Package only production files:
  - `surge.php`
  - `uninstall.php`
  - `include/`
  - `readme.txt`
  - `LICENSE`
  - `.wporg/` only if intentionally needed for GitHub release ZIPs, otherwise exclude it
- Exclude non-runtime files and local artifacts:
  - `.git/`
  - `docs/`
  - `.agents/`
  - local env files
  - test outputs

**Step 3: Support two build modes**

- Stable mode:
  - output `dist/surge-<version>.zip`
- Preview mode:
  - output `dist/surge-<version>-dev+<shortsha>.zip`
- Emit the final zip path to stdout for reuse in scripts and workflows

**Step 4: Verify package contents**

- Run: `bash bin/build-plugin-zip.sh stable`
- Run: `unzip -l dist/surge-*.zip`
- Expected: archive root directory is `surge/` and only runtime files are included

### Task 3: Add reusable local commands for build, install, activate, and smoke-test

**Files:**
- Create: `composer.json`
- Create: `bin/install-plugin-from-zip.sh`
- Create: `bin/install-plugin-from-worktree.sh`
- Create: `bin/smoke-test.sh`
- Create: `TESTING.md`

**Step 1: Create a lightweight command surface**

- Add Composer scripts such as:
  - `dev:up`
  - `dev:down`
  - `plugin:build`
  - `plugin:build:preview`
  - `plugin:install:zip`
  - `plugin:install:worktree`
  - `test:smoke`
- Keep these as wrappers around shell scripts so CI and local usage remain identical

**Step 2: Support two install modes**

- Worktree mode for fast local iteration:
  - activate the bind-mounted `surge` plugin directly
- ZIP mode for release validation:
  - install the built archive through WP-CLI
  - ensure the ZIP install path replaces any existing copy cleanly

**Step 3: Implement plugin-aware smoke checks**

- In `bin/smoke-test.sh`, perform:
  - site install/bootstrap if needed
  - plugin activation
  - request to the front page to prime cache
  - assertion that `wp-content/advanced-cache.php` exists and contains Surge ownership markers
  - assertion that cache files or cache directory entries exist after a request
  - assertion that `wp surge flush` succeeds
  - assertion that cleanup removes cache output as expected

**Step 4: Verify scripts locally**

- Run: `composer dev:up`
- Run: `composer plugin:build:preview`
- Run: `composer plugin:install:zip`
- Run: `composer test:smoke`
- Expected: the site is installable and the smoke-test exits `0`

### Task 4: Add the pull request and trunk CI workflow

**Files:**
- Create: `.github/workflows/ci.yml`

**Step 1: Trigger on the right changes**

- Run on:
  - pull requests
  - pushes to `trunk`
- Restrict paths to:
  - plugin source
  - scripts
  - Docker files
  - workflow files
  - docs if workflow docs should be validated

**Step 2: Reuse local scripts**

- In workflow steps:
  - checkout
  - `cp .env.example .env`
  - `docker compose up -d --wait`
  - `composer plugin:build:preview`
  - `composer plugin:install:zip`
  - `composer test:smoke`

**Step 3: Preserve artifacts on failure**

- Upload:
  - built ZIP from `dist/`
  - container logs
  - any smoke-test output files if created

**Step 4: Verify in GitHub Actions**

- Expected: PRs fail on broken packaging or broken runtime behavior, not only on syntax issues

### Task 5: Add preview prerelease publishing for QA

**Files:**
- Create: `.github/workflows/preview-release.yml`

**Step 1: Define preview release triggers**

- Trigger on:
  - push to `trunk`
  - `workflow_dispatch`

**Step 2: Build and validate before publish**

- Reuse the same sequence as CI:
  - boot Docker
  - build preview ZIP
  - install preview ZIP
  - run smoke tests

**Step 3: Publish a GitHub prerelease**

- Use `softprops/action-gh-release@v2`
- Mark the release as prerelease
- Name it using the short SHA and version
- Attach the preview ZIP from `dist/`

**Step 4: Make the artifact easy to find**

- Add a short release body that includes:
  - commit SHA
  - plugin base version
  - branch name
  - install instructions for QA

### Task 6: Add stable tag-based GitHub Releases

**Files:**
- Create: `.github/workflows/release.yml`

**Step 1: Parse and validate the release tag**

- Trigger on tags: `v*`
- Strip the `v` prefix and compare to:
  - `Version:` in `surge.php`
  - `Stable tag:` in `readme.txt`

**Step 2: Build the production ZIP**

- Run: `bash bin/build-plugin-zip.sh stable`
- Fail if version metadata is inconsistent

**Step 3: Smoke-test the production ZIP**

- Boot Docker
- Install the stable ZIP
- Run the same smoke-test path used in CI and preview publishing

**Step 4: Publish the final release**

- Create a standard GitHub Release
- Attach `dist/surge-<version>.zip`
- Enable generated release notes

### Task 7: Document the testing and release workflow for humans

**Files:**
- Modify: `DEVELOPMENT.md`
- Modify: `TESTING.md`
- Modify: `docs/README.md` if the repo uses it as an index

**Step 1: Document local fast paths**

- Add clear sections for:
  - booting WordPress locally
  - installing the plugin from the worktree
  - installing the plugin from a built ZIP
  - running smoke tests

**Step 2: Document release expectations**

- Explain:
  - preview prereleases come from `trunk`
  - stable releases come from tags
  - how version mismatches fail the workflow

**Step 3: Document QA usage**

- Include:
  - where prerelease ZIPs appear in GitHub Releases
  - how to install the ZIP in WordPress Admin or WP-CLI
  - what smoke-test coverage does and does not prove

**Step 4: Verify docs against actual commands**

- Re-run every documented command exactly as written
- Expected: docs are executable, not aspirational

## Acceptance Criteria

- A developer can boot a local WordPress instance from this repo with one documented command sequence.
- A developer can build an installable plugin ZIP locally without editing workflow files.
- A developer can install and smoke-test the built ZIP locally using the same scripts CI uses.
- Pull requests and `trunk` pushes run packaging plus smoke tests automatically in GitHub Actions.
- Every `trunk` push can produce a GitHub prerelease with an installable ZIP for QA.
- Stable semver tags publish a final GitHub Release with an installable ZIP only after version validation and smoke-test success.
- The ZIP archive contains only runtime files and extracts into a top-level `surge/` directory.

## Risks And Notes

- The biggest risk is under-specifying the smoke test. A green workflow that only checks activation is not enough for a caching plugin.
- Surge writes runtime files into `wp-content`, so the smoke-test must validate filesystem side effects, not just HTTP 200s.
- Preview release volume may become noisy if every `trunk` push creates a prerelease forever. If that becomes operationally messy, switch to a retention policy or a moving “latest preview” workflow after the first version proves useful.
- If WordPress.org packaging rules are later required, keep GitHub ZIP packaging logic separate from any WordPress.org deploy action so QA distribution does not inherit repository SVN concerns.
