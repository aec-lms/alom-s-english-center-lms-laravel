# CMS Editorial Access Controls

## Purpose

The CMS editorial workspace is designed for a high-trust education platform. Content creation, editing, review and publication are separate capabilities. A user must not approve their own work, and CMS access must not replace or alter unrelated LMS roles.

## CMS roles

| Role | Capabilities | Restrictions |
|---|---|---|
| CMS Contributor | Create drafts, edit own/assigned drafts, submit for review | Cannot review or publish |
| CMS Editor | Edit all drafts, coordinate assignments, review and publish approved content | Cannot review content they created, authored, submitted, co-authored or are assigned to edit |
| CMS Reviewer | Review submissions assigned to them or to the unassigned review pool | Cannot edit content or review their own work |
| CMS Contributor Manager | Grant/revoke CMS roles and assign authors, editors and reviewers | Cannot edit content or approve/review unless separately granted an eligible role |

CMS roles are additive. Granting or revoking a CMS role must preserve Student, Teacher, Branch and other non-CMS roles. Administrator access is managed separately.

## Editorial lifecycle

1. Contributor/editor creates an unpublished draft.
2. Author or authorized editor submits the draft for review.
3. An independent reviewer approves or requests changes with a required explanation.
4. A user with publication permission publishes an approved post, immediately or through its approved schedule.
5. Any subsequent content edit invalidates the approval and returns the post to Draft, requiring a new review.

Allowed workflow states: Draft, In Review, Changes Requested, Approved and Published. Direct publication through the legacy post create/update forms is intentionally disabled.

## Reviewer independence

The reviewer must be different from:
- The primary author.
- The original creator.
- The person who submitted the current review.
- Every co-author.
- The assigned editor.
- The manager assigning the reviewer.

The server rechecks reviewer eligibility during each review transition and locks the post row while changing workflow state. Hiding buttons in the UI is not an authorization control; routes and controller methods independently enforce permissions.

## Assignment and access safety

- Assignments are accepted only for active users with the corresponding CMS role.
- Published posts have locked author/editor/reviewer assignments.
- A user with authored or co-authored posts cannot lose their last author-capable CMS role until those posts are reassigned.
- Assigned editors cannot lose editor access until their assignments are moved.
- Assigned reviewers cannot lose reviewer access while any post remains assigned to them.
- At least one other active CMS Contributor Manager must remain when management access is revoked.
- All role and assignment changes are audit logged.

## Acceptance-test checklist

- [ ] A contributor cannot view another contributor's private draft.
- [ ] A contributor cannot edit another author's draft.
- [ ] A contributor cannot approve or publish.
- [ ] A creator, author, submitter or co-author cannot review their post.
- [ ] An assigned editor cannot independently review the same post.
- [ ] A reviewer cannot approve a post assigned to a different reviewer.
- [ ] A reviewer cannot self-assign through contributor management.
- [ ] A reviewer cannot be removed while posts remain assigned.
- [ ] A last active contributor manager cannot remove their own management access.
- [ ] Role updates preserve unrelated LMS roles.
- [ ] Published posts cannot be overwritten by draft editing or revision restore.
- [ ] Editing an approved draft invalidates approval.
- [ ] Scheduled publication processes approved posts only.
- [ ] Unapproved drafts remain unpublished regardless of legacy publish form input.

## Deployment notes

Run the CMS plugin migrations before enabling contributor accounts. Configure at least two active CMS Contributor Managers before using delegated role management. The scheduled publishing task requires Laravel's scheduler to run every minute on the hosting account. Never deploy a build until the Laravel test suite, MySQL test suite, browser E2E, Composer security audit and release-package checks pass.
