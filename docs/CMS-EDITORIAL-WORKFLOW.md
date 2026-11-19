# CMS Editorial Approval Workflow

## Editorial states

- **Draft** — editable, unpublished content.
- **In Review** — submitted content awaiting an independent reviewer.
- **Changes Requested** — reviewer feedback is recorded; the author may revise and resubmit.
- **Approved** — reviewed content is eligible for publication.
- **Published** — public content, published immediately or by the scheduler after approval.

## Non-bypassable publication policy

The legacy CMS create and update endpoints always save content as unpublished. A request parameter such as `is_published=1` is not a publication authorization. Publication requires the explicit approved-publication transition or the scheduled publisher, which selects only posts in the Approved state.

Editing an approved draft clears its previous approval and review metadata and returns it to Draft. Published content cannot be overwritten by the draft editor or by revision restore.

## Separation of duties

A reviewer must not be the original creator, primary author, current submitter or co-author. A reviewer also cannot be the assigned editor. The same restrictions are enforced in controller authorization checks, not just in the interface.

Review actions are state-checked and performed inside a transaction with a row lock. Review decisions and editorial state transitions are written to the audit log. Change requests require a reviewer note between 5 and 2,000 characters.

## Scheduled publication

A scheduled post must already be Approved. The scheduler checks the approval state, publication time, published flag and recycle-bin state before publishing. Configure the hosting scheduler to run Laravel's `schedule:run` command every minute.

## Security verification

The feature test suite covers:
- Direct publish flag bypass on create.
- Self-approval rejection.
- Creator/author separation.
- Reviewer feedback validation.
- Approval invalidation after editing.
- Scheduled publication eligibility and recycle-bin exclusion.
- Published-content protection.

Do not merge or deploy until the Laravel SQLite suite, MySQL 8 suite, browser E2E and Composer security audit have all completed successfully on the final commit.
