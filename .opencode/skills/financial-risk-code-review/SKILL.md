---
name: financial-risk-code-review
description: code review, financial risk, transaction safety, authorization, test gaps, invariants. Use ONLY when reviewing existing code for defects and risk, not when implementing features.
---

# Financial Risk Code Review

Use this skill for review-only tasks focused on correctness and risk in financial and permission-sensitive code.

## Objectives

- Surface high-impact defects early.
- Separate correctness issues from style preferences.
- Provide actionable fixes with clear evidence.

## Review checklist

- Financial invariants are preserved.
- Transactions wrap multi-write financial operations.
- No over-allocation or negative residual flows.
- Authorization boundaries are enforced.
- Tests cover critical domain paths and edge cases.

## Output format

- Severity: critical, high, medium, low.
- For each finding: issue, impact, evidence (file), recommendation.
- Include missing tests for critical/high findings.

## Scope rule

- Do not refactor broadly unless requested.
- Prefer minimal corrective actions for high-risk issues.
