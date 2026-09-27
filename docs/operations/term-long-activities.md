---
title: Term-long activities
sidebar_label: Activities
---

# Term-long activities

A chess club every Monday. Swimming lessons for group 5. Three mornings in the
woods. Each runs for a term, has a limited number of places, and needs enough
supervisors. Portaliq keeps them as activities: parents sign their child up in
the portal, and the school sees who has a place and who is waiting.

## What staff set

| Field | What it does |
| --- | --- |
| `title`, `kind`, `description`, `location` | What parents see. `kind` is club, sport, culture, trip, course or other. |
| `target` | Who may sign up: a school, groups, or specific children. |
| `termStart`, `termEnd`, `signupDeadline` | When it runs, and until when parents can sign up. |
| `capacity` | The most children with a place. |
| `supervisorRefs`, `childrenPerSupervisor` | Who supervises, and how many children one supervisor may take. |
| `waitlistEnabled` | When full, new sign-ups wait instead of being refused. |
| `sessions` | The meetings. Attendance is marked per session. |
| `paymentRequested` | A contribution is asked per place. Shillinq raises the payment request and holds the amount; portaliq stores only its reference. |

The number of places is the lower of `capacity` and supervisors times
`childrenPerSupervisor`. An activity without enough supervisors for one child
does not open.

## Staff endpoints (Nextcloud session)

| Method | Path | Does |
| --- | --- | --- |
| POST | `/apps/portaliq/api/activities` | Create a draft |
| PUT | `/apps/portaliq/api/activities/{id}/open` | Open for sign-ups (422 `no_places` without supervision) |
| PUT | `/apps/portaliq/api/activities/{id}/close` | Stop new sign-ups |
| PUT | `/apps/portaliq/api/activities/{id}/supervisors` | Change supervisors; new places go to the waiting list |
| GET | `/apps/portaliq/api/activities/{id}/roster` | Places, children with a place, the waiting list in order |
| PUT | `/apps/portaliq/api/activities/{id}/attendance` | Mark a child present, absent or excused for a session |

## Guardian endpoints (portal session)

| Method | Path | Does |
| --- | --- | --- |
| GET | `/apps/portaliq/api/activities/feed` | Activities in reach, with places left and your own children's sign-ups |
| POST | `/apps/portaliq/api/activities/{id}/signup` | Sign up one of your children: a place, a spot on the waiting list, or `activity_full` |
| POST | `/apps/portaliq/api/activities/{id}/withdraw` | Withdraw; a freed place goes to the child who waited longest |

A guardian can only sign up their own children, for an activity in their
audience. Anything else answers 404, the same as an activity that does not
exist.

Next: create a draft with two supervisors, open it, and sign up a child from
the portal as a test guardian.
