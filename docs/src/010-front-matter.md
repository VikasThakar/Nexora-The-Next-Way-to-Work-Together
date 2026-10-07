---
title: NEXORA
subtitle: The Next Way to Work Together
doctype: Complete System Documentation
version: Unversioned — see Document Control
date: 17 September 2026
commit: 30bfc83
status: Active development · Pre-1.0
---

<!-- cover -->

# Document Control

This document describes the Nexora platform **as it is actually implemented** in the
repository at the commit named below. It was produced by reading the source: routes,
configuration, models, migrations, services, policies, middleware, Livewire components,
Blade views, JavaScript, container configuration and the test suite.

Nothing here describes a feature that does not exist in the code. Where a capability is
present but incomplete it is marked **Partially Implemented**. Where a capability is
designed for but absent it is marked **Planned / Not Yet Implemented**. Section 38 collects
every such marking in one table.

## Revision identity

| Field | Value |
|---|---|
| Product name (documentation) | Nexora — The Next Way to Work Together |
| Package name (`composer.json`) | `aqueduct/workspace` |
| Runtime workspace name (`WORKSPACE_NAME`) | `Aqueduct Workspace` (deployment-configurable) |
| Version | **None declared.** No git tag, no `version` field in `composer.json`, no application version constant |
| Documentation basis | Commit `30bfc83` — *feat: migrate from wire:sort to x-sort for drag-and-drop functionality* |
| Commit date | 11 September 2026 |
| Repository age at time of writing | 17 commits, first commit 1 September 2026 |
| Licence | Proprietary |
| Documentation date | 17 September 2026 |
| System status | Active development, pre-1.0. No release tagging in place |

> [!IMPORTANT]
> **The product is white-labelled, and the names do not currently agree.** The shipped
> default for `WORKSPACE_NAME` is `Aqueduct Workspace`, and the Composer package is
> `aqueduct/workspace`. The name *Nexora* appears in the AI system prompt, in source
> comments and in one accessibility label, but it is not what the application renders by
> default. A deployment that should present as Nexora must set `WORKSPACE_NAME="Nexora"`
> and `WORKSPACE_SHORT_NAME="Nexora"`. See section 32.

## Technology stack

| Layer | Technology | Version constraint | Source |
|---|---|---|---|
| Language | PHP | `^8.2` (container runs 8.3) | `composer.json`, `Dockerfile` |
| Framework | Laravel | `^12.0` | `composer.json` |
| Interactive UI | Livewire | `^4.4` | `composer.json` |
| Styling | Tailwind CSS | `^4.0` | `package.json` |
| Build tool | Vite | `^7.0.7` | `package.json` |
| Rich text editor | TipTap (ProseMirror) | `^3.31.3` | `package.json` |
| Realtime server | Laravel Reverb | `^1.11` | `composer.json` |
| Realtime client | Laravel Echo + pusher-js | `^2.4.0` / `^8.6.0` | `package.json` |
| Database | MySQL (`pdo_mysql`) | — | `Dockerfile`, `config/database.php` |
| Test database | SQLite in-memory | — | `phpunit.xml` |
| Cache / queue (optional) | Redis via Predis | `^3.6` | `composer.json` |
| AI provider SDK | `anthropic-ai/sdk` | `^0.45` | `composer.json` |
| Second AI provider | OpenAI, over plain HTTP | — | `app/Services/AI/OpenAiService.php` |
| Object storage | `league/flysystem-aws-s3-v3` | `^3.35` | `composer.json` |
| HTML to Markdown | `league/html-to-markdown` | `^5.1` | `composer.json` |
| PDF text extraction | `smalot/pdfparser` | `^2.12` | `composer.json` |
| Code highlighting | `spatie/commonmark-highlighter` | `^3.0` | `composer.json` |
| Activity log | `spatie/laravel-activitylog` | `^4.12` | `composer.json` |
| Web server | nginx + PHP-FPM | Alpine packages | `docker/nginx.conf` |
| Process supervisor | Supervisor | Alpine package | `docker/supervisord.conf` |
| Container base | `php:8.3-fpm-alpine` | — | `Dockerfile` |
| Hosting platform | Railway | — | `railway*.json` |
| Code style | Laravel Pint | `^1.24` | `composer.json` |
| Testing | PHPUnit | `^11.5.50` | `composer.json` |

## Scale of the implementation

| Measure | Count |
|---|---|
| PHP classes under `app/` | 355 |
| Database migrations | 42 |
| Eloquent models | 25 |
| Livewire components | 36 |
| Authorization policies | 8 |
| Domain actions (`app/Actions`) | 44 |
| Enums | 27 |
| Blade views and components | 107 |
| Test files | 139, across Unit, Feature and a dedicated Security suite |
| Environment variables in `.env.example` | 183 |

<!-- pagebreak -->

# Executive Overview

## What Nexora is

Nexora is a **shared delivery workspace for a software agency and the customers it builds
for**. It is one application in which the delivery team plans and executes work and the
customer watches that work happen — on the same tickets, at the same time, but seeing a
deliberately different slice of it.

It combines four things that are normally four separate products:

1. **Kanban boards and tickets** — the work itself.
2. **A documentation workspace** — a nested page tree, per board, with a live editor.
3. **An AI assistant with real access to the workspace** — one that reads tickets,
   documentation, statistics and code activity through the asking person's own
   permissions, and can draft changes for a human to confirm.
4. **Integrations with the tools engineering already lives in** — GitHub, Slack and SMS.

## The problem it solves

An agency running several customer projects usually ends up with the work in one tool, the
documentation in a second, the customer conversation in a third, and the engineering trail
in a fourth. Three failures follow from that split, and Nexora is built around all three.

**Customers cannot see progress without a person exporting it.** Status meetings exist
because nobody outside the team can read the board. Nexora gives the customer a real login
to the real board — and answers the obvious objection by making *internal* the default for
everything, with publication a deliberate, audited, staff-only act.

**Internal candour and customer communication collide.** A team that shares its board stops
writing frankly on it. Nexora gives every ticket **two conversation streams** — one the
customer takes part in, one the customer cannot see, cannot count and cannot detect — so
the team keeps its working notes without keeping a second system.

**Knowing what is happening costs time.** Somebody has to read forty tickets to answer
"what is overdue?", or open GitHub to answer "did that ship?". Nexora's assistant answers
those from the live workspace, using retrieval tools scoped to the asker, and will draft
the ticket that should follow.

## Who uses it

| Audience | Role | What they are there for |
|---|---|---|
| Agency administrators | `admin` | Creating boards and accounts, holding AI credentials, setting how far automation is trusted, seeing every board |
| Delivery team members | `team` | Daily work: tickets, documentation, the assistant, integrations, and board configuration for boards they belong to |
| Customer stakeholders | `customer` | Raising requests, following progress, reading published documentation, asking a read-only assistant about their own project |

There is **no self-service sign-up by default**. `ALLOW_PUBLIC_REGISTRATION` is `false` and
an administrator creates accounts. When enabled, a self-registered account receives the
least-privileged role, `customer` — never staff.

## Core workflows

```diagram
   CUSTOMER RAISES WORK                      TEAM DELIVERS WORK
   ────────────────────                      ──────────────────
   Customer files a ticket                   Team picks it up from the board
            │                                          │
            ▼                                          ▼
   Always customer-visible                   Moves it across columns
   Always the first column                   Writes internal notes the
   Never pre-assigned                        customer never sees
            │                                          │
            ├──► Slack: "customer raised"              ├──► Branch  NL-42-fix
            └──► SMS if priority = critical            ├──► Commits mention NL-42
                                                       └──► Draft PR opened
                                                                   │
   Customer sees:                                      GitHub webhook (HMAC signed)
     • their own ticket                                            │
     • the customer conversation                                   ▼
     • published documentation                        Ticket shows branch, commits,
     • their own read-only assistant                  pull request and CI status
```

## Major capabilities, at a glance

| Capability | Status |
|---|---|
| Kanban boards with drag-and-drop columns and cards | Implemented |
| Tickets: type, priority, assignee, estimate, due date, labels, checklist, links | Implemented |
| Two-stream ticket conversation (customer / internal) | Implemented |
| Nested documentation workspace, WYSIWYG editing, autosave drafts | Implemented |
| Workspace AI assistant — panel and full page — with retrieval tools | Implemented |
| AI proposals: create/update tickets and pages, comment, delete, start a code run | Implemented |
| AI ticket runs: suggest (analysis note) and apply (draft pull request) | Implemented |
| AI-generated charts, tables and KPI rows, with CSV and PNG export | Implemented |
| Spoken conversation (speech-to-text and text-to-speech) | Implemented |
| AI file attachments: text, Markdown, CSV, PDF, images, audio, Office documents | Implemented |
| GitHub inbound webhooks: branches, commits, pull requests, check suites, statuses | Implemented |
| GitHub outbound: push a branch, open a draft pull request | Implemented |
| Slack notifications, per board and per event | Implemented |
| SMS alerts for critical tickets | Implemented |
| Statistics: team and customer screens, flow metrics, CSV and PNG export | Implemented |
| Realtime board, conversation and notification updates | Implemented |
| Command palette global search | Implemented |
| Light / dark / system theme | Implemented |
| Workspace activity feed | Implemented |
| AI Standup Generator | **Not implemented — see section 32** |
| Live external knowledge lookup for the assistant | Partially implemented — interface only, no driver ships |
| Apply-mode code generation | Partially implemented — driver defaults to `unavailable` |
| REST / JSON API for third parties | Not implemented |

<!-- pagebreak -->
