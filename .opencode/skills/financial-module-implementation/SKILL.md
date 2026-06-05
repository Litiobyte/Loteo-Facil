---
name: financial-module-implementation
description: financial module, ExpenseDistributionService, PaymentApplicationService, PartnerBalanceService, PartnerCharge, PaymentAllocation. Use when implementing or changing core financial logic and invariants.
---

# Financial Module Implementation

Use this skill when a task touches financial rules, including distribution, charge generation, payment allocation, balances, statements, or financial state transitions.

## Objectives

- Keep financial rules deterministic and testable.
- Preserve traceability from expenses to charges to payment allocations.
- Prevent double counting, over-allocation, and historical drift.

## Workflow

1. Identify affected domain entities and services.
2. Define invariants before coding.
3. Implement changes in domain services, not UI layers.
4. Add transactions to multi-write operations.
5. Add tests for exact, partial, and excess payment scenarios.
6. Verify snapshots keep historical calculations stable.

## Required safeguards

- Do not allow applying more than payment available balance.
- Do not allow applying more than charge remaining balance.
- Do not mutate historical calculation context for already generated charges.
- Keep computed balances based on movements, not manual aggregate fields.

## Deliverables

- Scoped code changes in services/models.
- Test coverage for normal and edge cases.
- Brief risk notes for any rule or data migration changes.
