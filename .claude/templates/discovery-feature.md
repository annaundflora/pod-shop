# Discovery Template: Feature

## Principles

- **No prose, only lists and tables**
- **Strict order** - brain knows where to find things
- **Everything included = MUST** (no prioritization needed)

### Delta Principle

**Code is the documentation of current state. Discovery documents ONLY changes.**

- Existing behavior: Reference in "Current State Reference", don't repeat in detail sections
- Changed behavior: Document only the change
- New behavior: Document completely
- Greenfield: Document everything (no existing code)

### Explicitness Principle

**Abstract terms force interpretation errors. Concrete terms enable checklists.**

| Abstract (avoid) | Explicit (do this) |
|-------------------|---------------------|
| "all fields are updated" | "Fields updated: Name, Email, Phone, Role" |
| "shows summary" | "Shows: Item count, Subtotal, Tax, Total" |
| "user sees details" | "User sees: ID, Status, Created Date, Owner" |
| "error is shown" | "Error toast: 'Save failed. Please try again.'" |

**Rule:** If a section describes what the user SEES, list ALL visible elements. No "all fields", "details", or "data" -- enumerate.

---

# Feature: {Name}

**Epic:** {Link or "--"}
**Status:** Draft | Review | Ready
**Wireframes:** `wireframes.md` (optional, same folder)

---

## Problem & Solution

**Problem:**
- {Concrete pain point, quantifiable if possible}

**Solution:**
- {Core solution in 1-2 points}

**Business Value:**
- {Metric or strategic value}

---

## Scope & Boundaries

| In Scope |
|----------|
| {Must-have 1} |
| {Must-have 2} |

| Out of Scope |
|--------------|
| {Explicitly excluded} |

---

## Current State Reference

> Existing functionality that will be reused (unchanged). NOT documented again in detail sections below.

- {Existing component/feature 1}
- {Existing behavior/validation}
- {Existing pattern that will be reused}

> If Greenfield (nothing exists): Write "Greenfield -- no existing functionality."

---

## UI Patterns

### Reused Patterns

| Pattern Type | Component | Usage in this Feature |
|--------------|-----------|----------------------|
| {Modal/Card/Form/etc.} | `{component-path}` | {How it's used here} |

### New Patterns

| Pattern Type | Description | Rationale |
|--------------|-------------|-----------|
| {Pattern} | {What it is} | {Why new, not reused} |

> Agent: Before writing, search codebase for existing patterns:
> `components/**/*Modal*`, `components/**/*Form*`, `components/**/*Card*`, etc.

---

## User Flow

1. {Step 1: Action -> System Response}
2. {Step 2: Action -> System Response}
...

**Error Paths:**
- {Error case 1} -> {System Response}

---

## UI Layout & Context

### Screen: {Screen Name}
**Position:** {Where in the app?}
**When:** {When is this screen shown?}

**Layout:**
- {Area 1: e.g. "Header with title and actions"}
- {Area 2: e.g. "Grid with cards"}
- {Area 3: e.g. "Footer with CTA"}

### Screen: {Next Screen}
...

---

## UI Components & States

| Element | Type | Location | States | Behavior |
|---------|------|----------|--------|----------|
| `{element_id}` | {Button/Form/Modal/Card} | {Where?} | `{state1}`, `{state2}` | {What happens on interaction?} |

---

## Feature State Machine

### States Overview

| State | UI | Available Actions |
|-------|----|--------------------|
| `{state_id}` | {What user sees} | {Comma-separated actions} |

> **Rule:** Different UI or different actions = separate state

### Transitions

| Current State | Trigger | UI Feedback | Next State | Business Rules |
|---------------|---------|-------------|------------|----------------|
| `{state_id}` | `{element}` -> {action} | {What user sees} | `{next_state}` | {Constraint or "--"} |

---

## Business Rules

- {Rule 1: e.g. "Max 10 items per list"}
- {Rule 2: e.g. "Only one default item"}

---

## Data

| Field | Required | Validation | Notes |
|-------|----------|------------|-------|
| `{field_name}` | Yes/No | {Rule or "--"} | {Special notes} |

---

## Implementation Slices

> Testable, deployable increments. Each slice delivers user-value.

### Dependencies

```
Slice 1 -> Slice 2 -> Slice 3
   |
Slice 4
```

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|------|-------|-------------|--------------|
| 1 | {Slice-Name} | {What's included?} | {How to test?} | {-- or Slice #} |
| 2 | {Slice-Name} | {What's included?} | {How to test?} | {-- or Slice #} |
| 3 | {Slice-Name} | {What's included?} | {How to test?} | {-- or Slice #} |

### Recommended Order

1. **Slice 1:** {Name} -- {Why first}
2. **Slice 2:** {Name} -- {Rationale}
3. **Slice 3:** {Name} -- {Rationale}

---

## Context & Research

### Similar Patterns in Codebase
| Feature | Location | Relevant because |
|---------|----------|------------------|
| {Similar feature} | {Path} | {Why relevant} |

### Web Research
| Source | Finding |
|--------|---------|
| {Source} | {What was learned} |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| 1 | {Question} | A) {Option} B) {Option} | {Recommendation} | {Decision or "--"} |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| {date} | {Codebase/Web} | {What was learned?} |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | {Full question as asked} | {Answer with context} |
