# Wireframe Template v2

**Status:** Active
**Date:** 2026-01-21

---

## Principles

### Language

- Always English

### Focus: Visual Validation
- Show layout and positioning
- No technical details (belong in Tech Spec)
- No design details (belong in Design System)

### Clear Separation
- **Discovery** = WHAT works (Logic, States, Rules)
- **Wireframe** = HOW it looks (Layout, Placement)

### Target Audience
- Stakeholders (quick understanding)
- PM (for presentations)
- Designers (as starting point)

---

## Template

```markdown
# Wireframes: {Feature Name}

**Discovery:** `discovery.md` (same folder)
**Status:** Draft | Validated

---

## Component Coverage

| UI Component (from Discovery) | Screen |
|-------------------------------|--------|
| `{element_id}` | {Screen Name} |

---

## User Flow Overview

```
[State A] ──action──► [State B] ──action──► [State C]
    │
    └──action──► [State D]
```

---

## Screen: {Screen Name}

**Context:** {Position in app, surrounding elements, relevant state from Discovery}

### Wireframe

```
{ASCII representation - any UX/UI pattern appropriate for the feature}
```

**Annotations:**
- ① `{element_id}`: {Description}

### State Variations (if applicable)

| State | Visual Change |
|-------|---------------|
| `{state}` | {How it differs} |

---

## Completeness Check

| Check | Status |
|-------|--------|
| All UI Components from Discovery covered | ✅/❌ |
| All relevant states visualized | ✅/❌ |
```

---

## Conventions

### Annotations
- ①②③④⑤⑥⑦⑧⑨⑩⑪⑫⑬⑭⑮⑯⑰⑱⑲⑳ (max 20 per wireframe)
- Numbering restarts at ① for each wireframe
- Each annotation references an element from Discovery UI Components

### Show Context
- `[... existing content ...]` for existing areas
- Separator lines `═══` for section boundaries
- Always specify position in the app

### ASCII Elements

| Element | ASCII | Usage |
|---------|-------|-------|
| Page/Modal | `┌─┐ │ └─┘` | Frame for screens |
| Section | `───` | Heading separator |
| Card | `┌──┐ └──┘` | Enclosed areas |
| Dashed Card | `┌─ ─┐ └─ ─┘` | Add buttons, placeholders |
| Separator | `═══` | Between main sections |

### State Variations
- Not every state needs a full wireframe
- Table with short description usually sufficient
- Full wireframe only when layout changes significantly

---

## What Does NOT Belong in Wireframe Doc

| Belongs in | Not here |
|------------|----------|
| Discovery | States, Transitions, Business Rules, Data Fields |
| Tech Spec | API Endpoints, Database, Code Structure |
| Design System | Colors, Fonts, Spacing values, Shadows |