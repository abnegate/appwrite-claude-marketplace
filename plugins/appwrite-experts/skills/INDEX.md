# Appwrite Experts — Skill Index

10 per-service expert skills covering the Appwrite backend codebase.
The `appwrite-router` agent reads this file to pick 1-3 relevant skills.

## Product services

| Skill | Description |
|---|---|
| `appwrite-auth-expert` | Authentication, sessions, MFA, OAuth, tokens, user admin. Covers account.php (41 routes) and users.php (44 routes). |
| `appwrite-databases-expert` | DocumentsDB and TablesDB — collections/tables, attributes/columns, documents/rows, queries, indexes, relationships, permission model. Largest domain (228 actions). |
| `appwrite-functions-expert` | Serverless functions — deployments, builds, executions, runtimes, variables, executor service, VCS integration. |
| `appwrite-storage-expert` | Buckets, files, image previews, compression, antivirus, device abstraction (S3/DO Spaces/local). |
| `appwrite-messaging-expert` | Providers, topics, subscribers, message delivery via SMS/email/push with adapter pattern. 48 routes. |
| `appwrite-teams-expert` | Teams, memberships, roles, team-based permission model, organization extensions. |

## Infrastructure

| Skill | Description |
|---|---|
| `appwrite-realtime-expert` | Realtime subscriptions, channels, event publishing, WebSocket server, PubSub (not queued). |
| `appwrite-workers-expert` | Worker architecture, queue system (Redis/AMQP), event publishing, error handling, 14 base workers. Cross-cutting async layer. |
| `appwrite-tasks-expert` | CLI task system — maintenance, migrations, scheduling (ScheduleBase), health checks, SDK generation. 19 base tasks. |
| `appwrite-cloud-expert` | Cloud-specific: multi-region, edge (Fastly), billing/plans, patches, dedicated databases, resource blocking. 18 cloud workers + 58 cloud tasks. |

## Composition notes for the router

Some questions span multiple skills. Known pairings:

- **Execution pipeline** — `appwrite-functions-expert` + `appwrite-workers-expert`
- **Event flow** — `appwrite-workers-expert` + `appwrite-realtime-expert`
- **Auth + permissions** — `appwrite-auth-expert` + `appwrite-databases-expert`
- **Messaging delivery** — `appwrite-messaging-expert` + `appwrite-workers-expert`
- **Schema operations** — `appwrite-databases-expert` + `appwrite-workers-expert`
- **Cloud deployment** — `appwrite-cloud-expert` + service-specific expert
- **Maintenance pipeline** — `appwrite-tasks-expert` + `appwrite-workers-expert`
- **Team permissions** — `appwrite-teams-expert` + `appwrite-databases-expert`

When a question matches a pairing, load all relevant skills rather than picking just one.
