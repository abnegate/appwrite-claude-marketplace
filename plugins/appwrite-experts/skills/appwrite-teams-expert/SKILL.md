---
name: appwrite-teams-expert
description: Teams, memberships, roles, and the team-based permission model in Appwrite.
---

# Appwrite Teams Expert

## Module structure

`src/Appwrite/Platform/Modules/Teams/` — 1 service (Http), 14 actions.

## Core concepts

- **Team** — a group of users with a name and optional preferences
- **Membership** — a user's relationship to a team, with roles
- **Role** — string labels on memberships (e.g., `admin`, `editor`, `viewer`)

## Permission integration

Teams integrate with the database/storage permission model:

```php
Permission::read(Role::team('teamId'))           // Any team member
Permission::update(Role::team('teamId', 'admin'))  // Members with 'admin' role
```

When a user authenticates, their team memberships are resolved and added as authorization roles for the request scope.

## Membership lifecycle

1. **Invite** — `POST /v1/teams/{teamId}/memberships` with email/userId and roles
2. **Accept** — invited user clicks link or calls `PATCH /v1/teams/{teamId}/memberships/{membershipId}/status`
3. **Update roles** — `PATCH /v1/teams/{teamId}/memberships/{membershipId}` with new roles array
4. **Remove** — `DELETE /v1/teams/{teamId}/memberships/{membershipId}`

Invitations send an email via the Mails worker with a redirect URL containing the secret.

## Membership document

```php
[
    '$id' => 'unique_id',
    'teamId' => 'team_123',
    'userId' => 'user_456',
    'roles' => ['admin', 'editor'],
    'invited' => DateTime,
    'joined' => DateTime,     // null until accepted
    'confirm' => bool,        // true after acceptance
    'secret' => 'hashed',     // invitation secret
]
```

## Team preferences

Teams have a `prefs` attribute (JSON object) for arbitrary team-level settings. Accessed via:
- `GET /v1/teams/{teamId}/prefs`
- `PUT /v1/teams/{teamId}/prefs`

## Cascade operations

- Deleting a team deletes all memberships (via Deletes worker)
- Deleting a user removes their memberships from all teams
- In cloud: deleting a team can cascade to project deletion (`DELETE_TYPE_TEAM_PROJECTS` in Deletes worker)

## Cloud: organization model

In cloud, teams serve as **organizations** — the billing entity. The cloud repo extends team functionality with:
- Billing plan association
- Member limits based on plan
- Enterprise team flags
- Organization-level resource blocking

## Gotchas

- Roles are strings, not an enum — any string is valid as a role name
- A user can have multiple roles on the same team
- Team membership is confirmed only after the invited user accepts — `confirm: false` members have limited access
- The first member of a team (creator) gets the `owner` role automatically
- Removing the last `owner` from a team is blocked to prevent orphaned teams
- Team ID is used directly in permission strings — changing a team's ID would break all permissions referencing it (team IDs are immutable)

## Related skills

- `appwrite-auth-expert` — user roles and authorization model
- `appwrite-databases-expert` — how team roles map to document permissions
- `appwrite-cloud-expert` — organization/billing extensions
