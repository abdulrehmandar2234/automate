# Team Ops Platform — Product Blueprint

> **Status:** Master planning doc for a **from-scratch** build. This defines *what* we're building,
> *how*, in *what order*, the *tech stack*, and the *feature set*. Working name:
> "Team Ops Platform" (rename later). Copy this file into the new repo once it's scaffolded.

An **AI-powered team operations platform** that plugs into a company's Slack, Jira, and GitHub and
automates the busywork: **PR reviews, weekly visibility reports, meeting notes, auto ticket
creation, task assignment, and reporting** — with an AI (Claude) doing the heavy lifting.

**Product model:** **self-hosted, single-tenant, licensed.** Every customer deploys their **own**
instance on their **own** infrastructure. Data isolation is automatic (separate deployments). We
**sell the product** (a license); we do **not** run a multi-tenant SaaS.

> **Built from scratch.** This is a brand-new Laravel + Filament repo. Nothing depends on any
> existing/prior codebase — every connector, service, and job below is built fresh.

---

## Table of contents

1. [Why this shape](#1-why-this-shape)
2. [Starting point (fresh scaffold)](#2-starting-point-fresh-scaffold)
3. [Tech stack (decided)](#3-tech-stack-decided)
4. [Architecture](#4-architecture)
5. [How agentic PR review works](#5-how-agentic-pr-review-works)
6. [Claude auth: now vs product](#6-claude-auth-now-vs-product)
7. [Feature catalogue](#7-feature-catalogue)
8. [Phase-wise roadmap](#8-phase-wise-roadmap)
9. [Proposed repo structure](#9-proposed-repo-structure)
10. [Config & secrets](#10-config--secrets)
11. [Security & licensing](#11-security--licensing)
12. [Open decisions](#12-open-decisions)

---

## 1. Why this shape

- **Self-hosted, single-tenant** removes the hardest SaaS problems: no multi-tenancy code, no
  central billing infra, and — critically — **no reading customer code on our servers**. It runs on
  the customer's own machines, so their private code never leaves their infrastructure. A massive
  trust advantage for a tool that reads source code.
- **Single stack (Laravel + Filament)** because Laravel can call the `claude` CLI as a subprocess
  and do git/GitHub over HTTP — so we get **agentic AI reviews without a separate Node worker**.
  One language, one deployment, simple to ship and support.
- **Native async, no bridge.** Because it's one stack, we use Laravel's **native queue + jobs** —
  no Redis JSON bridge, no cross-language serialization. The only external process is the `claude`
  CLI binary.

---

## 2. Starting point (fresh scaffold)

You will create and install the repo yourself. For reference, the intended baseline is:

```bash
composer create-project laravel/laravel team-ops-platform
cd team-ops-platform
composer require filament/filament
php artisan filament:install --panels
```

Everything in this document is then built on top of that clean scaffold. No prior code is reused.

---

## 3. Tech stack (decided)

| Layer | Choice | Notes |
|-------|--------|-------|
| Framework | **Laravel 12/13** | the whole product lives here |
| Admin / UI | **Filament (latest)** (Livewire) | dashboards, CRUD, settings, reporting — fast to build |
| Language | **PHP 8.3+** | single language across the product |
| AI (agentic) | **`claude` CLI via `Process`** | shell-out gives Read/Grep/Glob/Bash agentic reviews |
| AI (lightweight) | **Anthropic API** (HTTP) | summaries/notes where full agentics isn't needed |
| DB | **MySQL** (SQLite for dev) | one DB per deployment |
| Async | **Laravel queue + Jobs** | native — no cross-language bridge |
| PDF | **dompdf** or **Browsershot** | reports |
| Integrations | **Laravel HTTP client** | Jira, Slack, GitHub — all built fresh |
| Packaging | **Docker Compose** | one-command deploy for customers |

> The `claude` CLI is itself a Node program, so the server needs Node **installed** to run it — but
> we write **zero Node code**. All logic is PHP.

---

## 4. Architecture

```
┌───────────────────────────────────────────────────────────────┐
│  FILAMENT UI (Livewire)                                        │
│  dashboards • PR reviews • reports • settings • integrations   │
└───────────────────────────────┬───────────────────────────────┘
                                 │
┌────────────────────────────────▼──────────────────────────────┐
│  LARAVEL APP (PHP)                                            │
│                                                               │
│  Connectors:  GitHub • Jira • Slack   (all built fresh)       │
│  Services:    ReviewService • AiService • ReportService       │
│  Jobs (queue): ReviewPrJob • ReportJob • ...                  │
└───────┬───────────────────────────────────────────┬──────────┘
        │ Process::run('claude -p ...')               │ HTTP
        ▼                                             ▼
┌───────────────┐                          ┌────────────────────┐
│  claude CLI    │  agentic review          │  GitHub / Jira /   │
│  (+ git)       │  in a git worktree       │  Slack REST APIs   │
└───────────────┘                          └────────────────────┘
                         │
                         ▼
                 ┌───────────────┐
                 │  MySQL         │  reviews • reports • config • jobs
                 └───────────────┘
```

One Laravel deployment. `claude` CLI and `git` are subprocesses; integrations are HTTP.

---

## 5. How agentic PR review works

A queued `ReviewPrJob` does, in PHP:

1. **Prepare an isolated checkout** — `git fetch origin pull/<n>/head` into a server-side clone,
   then `git worktree add` into a throwaway dir **outside** the repo (via `Process`).
2. **Run the agentic review** — `Process::run('claude -p "<prompt>" --model sonnet --allowedTools
   "Read Grep Glob Bash(gh pr diff:*)" --output-format json')` with the worktree as CWD.
   (Close stdin / pass `< /dev/null` equivalent so the CLI doesn't wait for input.)
3. **Parse** the strict JSON `{ summary, verdict, findings }`; **derive the verdict from severity**
   (any `blocker` → request changes; else approve — minor issues → "approved with comments").
4. **Post to GitHub** via the HTTP client (`GitHubConnector`): a review + inline comments, using
   the deployment's GitHub token.
5. **Persist** a `reviews` row (verdict, findings, cost, duration) and **notify Slack** (thread
   reply / DM).
6. **Clean up** the worktree.

Review rules to implement: skip self-authored PRs, skip already-reviewed PRs (unless an explicit
"review again"), Jira ticket as read-only context, multiple PRs per request, and idempotent dedup.

> A working reference for this exact flow exists (a Node prototype). It's **not a dependency** —
> just a place to copy the prompt, JSON contract, and rule logic from when porting to PHP.

---

## 6. Claude auth: now vs product

| Phase | Auth | How |
|-------|------|-----|
| **Now (local testing)** | **Claude Code team-plan login** | `claude` + `/login` once; Laravel shells out to the CLI. No API key. Re-login when it expires. |
| **Product (selling)** | **BYOK — customer's own** `ANTHROPIC_API_KEY` | Each customer sets `ANTHROPIC_API_KEY`; `claude -p` runs headless with it. We never ship our key. |

The switch is env-only — no code change.

---

## 7. Feature catalogue

Everything is built fresh. Legend: 🔜 near-term · 💡 later.

| Feature | State | Component |
|---------|-------|-----------|
| Agentic PR review (claude CLI) | 🔜 | `ReviewService` + `ReviewPrJob` |
| GitHub connector (diff, reviews, inline comments) | 🔜 | `GitHubConnector` |
| Jira connector (search, issues, context) | 🔜 | `JiraConnector` |
| Slack connector (read + post) | 🔜 | `SlackConnector` |
| Filament UI: trigger review + history/results | 🔜 | Filament page/resource |
| Slack trigger for reviews (mention / DM) | 🔜 | Events API or polling |
| Weekly visibility report (Slack+Jira → AI) | 💡 | `ReportService` + job |
| Auto ticket creation (Slack/meeting/PR → Jira) | 💡 | `JiraConnector` write side |
| Task assignment (AI suggests + assigns) | 💡 | Jira + rules |
| Meeting notes / integrations (Zoom/Meet/Fireflies) | 💡 | new connector + `AiService` |
| Reporting dashboards (delivery health, cost, trends) | 💡 | Filament widgets |
| Cost & usage tracking (Claude tokens) | 🔜 | stamp cost on each `reviews` row |
| Licensing (key validation) | 💡 | product hardening |

---

## 8. Phase-wise roadmap

Each phase is independently shippable.

- **Phase 0 — Scaffold + core PR review (CLI-only).**
  Fresh Laravel + Filament install. Build `GitHubConnector` + `ReviewService` + `ReviewPrJob`;
  shell out to the `claude` CLI; implement the review rules. Trigger via
  `php artisan review:pr <url>`. Local testing on the team-plan CLI login. **No UI, no Node.**

- **Phase 1 — Filament UI for reviews.**
  A "Reviews" page/resource: paste a PR link → queue a review; list history (verdict, findings,
  cost, duration); re-review button; dry-run toggle.

- **Phase 2 — Slack trigger.**
  A Slack mention / DM enqueues a review; verdict reply in the thread. (Decide Events API vs
  polling — see open decisions.)

- **Phase 3 — Jira context + reporting.**
  Jira connector for review context; first reporting/visibility views in Filament.

- **Phase 4 — More automations.**
  Auto ticket creation, AI task assignment, meeting notes/integrations, richer dashboards.

- **Phase 5 — Productization (before selling).**
  BYOK `ANTHROPIC_API_KEY`, license-key validation, Docker Compose packaging, first-run onboarding,
  install + admin docs, upgrade path.

---

## 9. Proposed repo structure

On top of a standard Laravel + Filament layout:

```
app/
  Services/
    Connectors/
      Connector.php          # interface: key(), configured()
      GitHubConnector.php     # fetch diff, post review + inline comments
      JiraConnector.php       # search/issues, ticket context
      SlackConnector.php      # read + post messages
    Review/
      ReviewService.php       # orchestration + rules (skip/self/already-reviewed)
      ClaudeRunner.php        # Process wrapper around `claude -p`, JSON parse
      GitWorktree.php         # fetch PR ref + external worktree + cleanup
      Verdict.php             # severity → verdict derivation
    AiService.php             # lightweight Anthropic/OpenAI HTTP (summaries)
  Jobs/
    ReviewPrJob.php           # queued review pipeline
  Console/Commands/
    ReviewPr.php              # `php artisan review:pr <url>`
  Filament/
    Resources/ReviewResource  # history + results (Phase 1)
    Pages/...                 # trigger, settings
  Models/
    Review.php  Finding.php  RepoConfig.php  ChannelConfig.php
config/
  services.php                # jira / slack / github / anthropic keys (env-backed)
database/migrations/          # reviews, findings, configs, jobs
```

---

## 10. Config & secrets

- **Per-deployment `.env`** is the source of truth for each install (Jira/Slack/GitHub creds, AI
  auth, feature flags). Never commit secrets.
- **Config as code** for non-secret behaviour: channels, repos to watch, review focus, automation
  toggles — editable in Filament, backed by DB models.
- **AI auth switch:** team-plan CLI login (dev) → `ANTHROPIC_API_KEY` (product), env-only.

Example `.env`:
```bash
GITHUB_TOKEN=...             # posts reviews (deployment's account / bot)
GITHUB_WEBHOOK_SECRET=...    # if review-on-PR-open is added later
JIRA_BASE_URL=...            # https://org.atlassian.net
JIRA_USER=...                # email
JIRA_API_TOKEN=...
SLACK_TOKEN=...              # bot token
SLACK_CHANNELS=...           # comma-separated ids
REVIEW_MODEL=sonnet
REVIEW_POST_ENABLED=true     # false = dry-run (no GitHub post)
# ANTHROPIC_API_KEY=...      # product only; blank in dev (uses claude CLI login)
```

---

## 11. Security & licensing

- **Data stays on the customer's infra** — self-hosted; we never receive their code or messages.
- **Token security** — integration tokens live in the customer's `.env` / secret store; the UI
  shows "set / not set", never raw values.
- **Least privilege** — GitHub token scoped to what review posting needs; Jira/Slack similarly.
- **Licensing** — a signed **license key** validated on boot (offline check, optional periodic
  phone-home), with expiry, so the product can't be freely copied. Design in Phase 5.
- **Audit** — record who triggered what (reviews, config changes) for traceability.

---

## 12. Open decisions

1. **Slack transport for triggers:** **polling** (simple) vs **Events API** (real-time, needs a
   public URL) vs **Socket Mode** (real-time, no URL — but that's Node-land). For a self-hosted PHP
   app, recommend **Events API** or polling; avoid Socket Mode.
2. **GitHub trigger:** manual + Slack first; **review-on-PR-open** via webhook later.
3. **DB:** MySQL for real deployments; SQLite fine for dev.
4. **Repo access on the server:** clone-on-demand with the GitHub token vs pre-cloned mirrors.
5. **Product name & license model** (perpetual + updates vs annual).

---

### Immediate next step

After you scaffold the fresh Laravel + Filament repo, **Phase 0, first slice:** build a
`GitHubConnector` + a `php artisan review:pr <url>` command that runs the agentic review via the
`claude` CLI and posts to GitHub. That proves the whole flow inside Laravel, locally, on the
team-plan login — no UI, no API key, no Node.
