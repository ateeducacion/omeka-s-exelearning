---
id: ADR-NNN-01
title: "Short decision title"
status: Proposed
date: YYYY-MM-DD
tracking_issue: NNN   # this repository's pull-request number
related:
  issues: []          # upstream exelearning/exelearning issues, if any
  prs: []
  changes: []
  adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: ""
  model: ""
---

<!--
How to use this template:

1. Find the change's GitHub tracking NUMBER. In this repository that is always
   the PULL REQUEST number: GitHub Issues are disabled here, and numbers from
   the upstream exelearning/exelearning tracker come from a different sequence,
   so they must never be used as an identifier. Link those under
   `related.issues` instead. NEVER open an issue anywhere just to get a number.
   The PR number only exists once the PR is open: open it first, then add the
   ADR in a follow-up commit on the same branch.
2. Copy this file to `ADR-<number>-<NN>-<decision-slug>.md`, where <NN> is the
   next free two-digit sequence FOR THAT TRACKING NUMBER ONLY (`01` if it is the
   first). The slug names the decision, not the topic.
3. Set `id` to `ADR-<number>-<NN>` and `tracking_issue` to that number.
   They must match the filename; CI enforces this.
4. Make the H1 below `# <id>: <title>`.
5. Fill every section. Delete these guidance comments before submitting.
6. Keep the file at `status: Proposed` until reviewers accept it. Status lives
   in the frontmatter only — do not add a `## Status` section.
7. Cite a verifiable source for each technical claim (repo path + commit,
   Omeka S / Laminas documentation, benchmark, experiment, issue, PR, change
   document, or prior ADR).
8. Record AI assistance in `ai_assistance` (values, or `none` if not used).
9. Use issue/PR links for attribution — do not add people's names.
10. Run `make architecture-check` to validate.

See ./README.md for the full policy.
-->

# ADR-NNN-01: Short decision title

## Context

<!-- The situation that forces a decision. What is happening, what constraints
apply (Omeka S version support, PHP version, security model), and why now.
State facts, not opinions. -->

## Problem

<!-- The specific question this ADR answers, phrased so a chosen option resolves
it. -->

## Decision drivers

<!-- The forces that matter: security, backward compatibility, performance,
accessibility, internationalization, maintainability, Omeka S / PHP
compatibility, coding standards, implementation effort. -->

- Driver 1
- Driver 2

## Alternatives considered

### Option 1: ...

<!-- Describe the option, then its pros and cons. -->

### Option 2: ...

### Option 3: ...

## Evidence

<!-- The verifiable basis for the decision. Prefer:
- repository path + commit (e.g. `src/Controller/ContentController.php` @ `abc1234`)
- official Omeka S / Laminas documentation or a specification (link)
- a benchmark or reproducible experiment (numbers + how to reproduce)
- a linked issue, PR, change document, or prior ADR
No technical claim without a source. -->

## Decision

<!-- The option that was chosen, stated plainly. "We will ...". -->

## Consequences

### Positive

- ...

### Negative

- ...

### Neutral

- ...

## Risks

<!-- What could go wrong, and how likely / severe. -->

## Validation

<!-- How we will know the decision was correct: PHPUnit tests, coverage,
metrics, a follow-up review date, an experiment to run. -->

## Follow-up work

<!-- Concrete next steps this decision creates. Link issues/PRs when they exist. -->

## References

<!-- All sources cited above, plus related issues, PRs, change documents and
ADRs. -->
