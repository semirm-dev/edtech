# Course Discovery E2E

Playwright suite covering the browser behaviour PHPUnit cannot reach:
filtering with JavaScript disabled, and keyboard-only combobox operation.

This is a **standalone Node project**, isolated from the PHP project (no
dependency is added to the plugin's `composer.json`). It talks to an
already-running site over HTTP; there is no dev server for Playwright to
boot, only a `baseURL`.

## Prerequisites

- A running, seeded WordPress site with the Course Discovery plugin active
  and a page containing the `[course_discovery]` shortcode published at
  `/find-courses/` (created by the seed steps in the main README, or by hand:
  `wp post create --post_type=page --post_title="Find Courses"
  --post_name=find-courses --post_content='[course_discovery]'
  --post_status=publish`).
- Seed data with at least two published courses whose providers differ
  enough to make filtering narrow the result set (the specs assert relative
  behaviour -- a course appears/disappears -- not exact counts, but they do
  need at least one filter value that matches only a subset of the seeded
  courses).
- Node.js 18+.

## Install

```bash
cd e2e
npm install
npx playwright install --with-deps chromium
```

## Run

Point `CD_E2E_URL` at whichever environment is running. It defaults to the
DDEV URL if unset.

Against DDEV (`ddev start` first):

```bash
cd e2e
CD_E2E_URL=https://edtech.ddev.site npx playwright test
```

Against any other environment, point `CD_E2E_URL` at its host/port.

Run a single spec, or list specs without running them:

```bash
npx playwright test tests/keyboard.spec.ts
npx playwright test --list
```

View the HTML report after a run:

```bash
npx playwright show-report
```

## What each spec covers

- **`tests/no-js.spec.ts`** -- runs under the `js-disabled` project
  (`javaScriptEnabled: false`). Loads the discovery page, ticks a provider
  checkbox, submits the plain `<form method="get">` (a real page load), and
  asserts the resulting URL carries the provider query param and the result
  set narrowed accordingly (the matching course present, the non-matching
  one gone). Proves the plain form filters correctly with no JavaScript
  involved at all.

- **`tests/keyboard.spec.ts`** -- runs under the `js-enabled` project. Tabs
  (keyboard only, no mouse events anywhere in the file) to the location
  combobox trigger, opens it with Down, arrows to an option, selects it with
  Enter, and asserts `aria-selected` on the option and that the hidden
  native `<select>` is in sync. Then closes with Escape and asserts the
  popup is hidden and focus returned to the trigger.

## Two Playwright projects, one config

`playwright.config.ts` defines two projects: `js-enabled` (runs
`keyboard.spec.ts`) and `js-disabled` (runs `no-js.spec.ts` with
`javaScriptEnabled: false`). Running `npx playwright
test` runs both; filtering to one file also only runs its matching project.
