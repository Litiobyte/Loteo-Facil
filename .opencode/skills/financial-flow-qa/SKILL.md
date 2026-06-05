---
name: financial-flow-qa
description: financial QA, edge cases, partial payment, overpayment, allocation consistency, statement validation. Use when validating financial flows manually or with automated tests.
---

# Financial Flow QA

Use this skill when validating correctness of financial flows and account outcomes.

## Objectives

- Validate behavior across full financial lifecycles.
- Catch edge-case defects before release.

## Test matrix

- Exact payment fully closes charge.
- Partial payment leaves correct remaining amount.
- Overpayment produces correct unapplied credit.
- One payment allocated across multiple charges.
- Allocation rejects amounts above payment availability.
- Allocation rejects amounts above charge remaining.
- Historical charge snapshots remain stable after hectare changes.

## Execution guidance

- Combine automated tests with manual scenario checks.
- Verify summary values against detailed movements.
- Test role-based access boundaries in sensitive views/actions.

## Deliverables

- Scenario list with expected outcomes.
- Defect list with severity and repro steps.
- Suggested automated tests for gaps.
