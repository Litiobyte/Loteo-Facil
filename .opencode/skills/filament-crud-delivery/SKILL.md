---
name: filament-crud-delivery
description: Filament Resource, form, table, action, relation manager, policy. Use when building or updating admin CRUD and operational workflows.
---

# Filament CRUD Delivery

Use this skill when implementing or modifying Filament resources, forms, tables, filters, actions, or relation managers.

## Objectives

- Deliver operationally clear admin interfaces.
- Keep business logic in services.
- Enforce authorization and safe interactions.

## Workflow

1. Confirm target resource and user action flow.
2. Implement form and table with explicit validation.
3. Add filters and actions needed by operations.
4. Add confirmations for destructive actions.
5. Wire service-layer calls for business operations.
6. Verify permissions and visibility rules.

## Required safeguards

- Do not place complex financial calculations inside resources.
- Require confirmation for risky state-changing actions.
- Ensure role boundaries are explicit and testable.

## Deliverables

- Resource updates with concise, consistent UX.
- Permission checks and validation rules.
- Tests for behavior-heavy actions when applicable.
