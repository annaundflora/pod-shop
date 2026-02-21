# Architecture Template: Feature

## Principles

- **No prose, only lists and tables**
- **Strict order** - brain knows where to find things
- **Everything included = MUST** (no prioritization needed)
- **Stack-agnostic** - works for any tech stack

---

# Feature: {Name}

**Epic:** {Link or "–"}
**Status:** Draft | Review | Ready
**Discovery:** `discovery.md` (same folder)
**Derived from:** Discovery constraints, NFRs, and risks

---

## Problem & Solution

**Problem:**
- (From Discovery - copied for context)

**Solution:**
- (From Discovery - copied for context)

**Business Value:**
- (From Discovery - copied for context)

---

## Scope & Boundaries

| In Scope |
|----------|
| (From Discovery - copied for context) |

| Out of Scope |
|--------------|
| (From Discovery - copied for context) |

---

## API Design

### Overview

| Aspect | Specification |
|--------|---------------|
| Style | {REST / GraphQL / tRPC / Custom} |
| Authentication | {How is auth handled?} |
| Rate Limiting | {Limits if any} |

### Endpoints

| Method | Path | Request | Response | Auth | Business Logic |
|--------|------|---------|----------|------|----------------|
| {POST} | {/api/resource} | {DTO schema} | {DTO schema} | {Required?} | {What happens?} |

### Data Transfer Objects (DTOs)

| DTO | Fields | Validation | Notes |
|-----|--------|------------|-------|
| {CreateRequest} | {field1, field2} | {rules} | {constraints} |

---

## Database Schema

### Entities

| Table | Purpose | Key Fields |
|-------|---------|------------|
| {table_name} | {What it stores} | {id, foreign_keys, indexes} |

### Schema Details

| Table | Column | Type | Constraints | Index |
|-------|--------|------|-------------|-------|
| {table_name} | {column_name} | {type} | {PK/FK/NOT NULL/UNIQUE} | {Yes/No} |

### Relationships

| From | To | Relationship | Cascade |
|------|-----|--------------|---------|
| {table_a} | {table_b} | {1:N / N:M / 1:1} | {DELETE/UPDATE rules} |

---

## Server Logic

### Services & Processing

| Service | Responsibility | Input | Output | Side Effects |
|---------|----------------|-------|--------|--------------|
| {ServiceName} | {What it does} | {Parameters} | {Returns} | {DB writes, events, etc.} |

### Business Logic Flow

```
{Request} → {Validation} → {Service} → {Processing} → {Response}
                                ↓
                         {Side Effects}
```

### Validation Rules

| Field | Rule | Error Message |
|-------|------|---------------|
| {field} | {validation} | {error} |

---

## Security

### Authentication & Authorization

| Area | Mechanism | Notes |
|------|-----------|-------|
| {API Auth} | {JWT/Session/API Key} | {How validated} |
| {Resource Access} | {RLS/ACL/Permissions} | {Who can do what} |

### Data Protection

| Data Type | Protection | Notes |
|-----------|------------|-------|
| {Sensitive} | {Encryption/Hashing/Anonymization} | {How implemented} |

### Input Validation & Sanitization

| Input | Validation | Sanitization |
|-------|------------|--------------|
| {user_input} | {Schema check} | {XSS/SQLi protection} |

### Rate Limiting & Abuse Prevention

| Resource | Limit | Window | Penalty |
|----------|-------|--------|---------|
| {endpoint} | {N requests} | {per X time} | {429 / ban} |

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Pattern |
|-------|----------------|---------|
| {Routes/Controllers} | {Handle HTTP, validation} | {Controller pattern} |
| {Services} | {Business logic, orchestration} | {Service pattern} |
| {Repositories/DAOs} | {Data access abstraction} | {Repository pattern} |
| {Jobs/Workers} | {Async processing, background tasks} | {Job queue pattern} |

### Data Flow

```
{Client} → {Route} → {Service} → {Repository} → {Database}
                     ↓
                  {Job Queue} → {Worker} → {Storage}
```

### Error Handling Strategy

| Error Type | Handling | User Response | Logging |
|------------|----------|---------------|---------|
| {Validation} | {400 with details} | {User-friendly message} | {Debug log} |
| {NotFound} | {404} | {Resource not found} | {Info log} |
| {Internal} | {500} | {Generic error} | {Error log + alert} |

---

## Constraints & Integrations

### Constraints

(From Discovery - technical implications)

| Constraint | Technical Implication | Solution |
|------------|----------------------|----------|
| {From Discovery} | {What this means technically} | {How we solve it} |

### Integrations

| Area | System / Capability | Interface | Notes |
|------|----------------------|-----------|-------|
| {From Discovery} | {Technical implementation} | {API/SDK/Protocol} | {Version, config} |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target (from Discovery) | Technical Approach | Measure / Verify |
|-----------|-------------------------|--------------------|------------------|
| {Performance} | {e.g. "95% < 60s"} | {Caching, async, etc.} | {Metrics, logging} |
| {Scalability} | {e.g. "1000 concurrent"} | {Horizontal scaling, queue} | {Load testing} |
| {Reliability} | {e.g. "99.9% uptime"} | {Retries, circuit breaker} | {Monitoring} |
| {Security} | {e.g. "No metadata leak"} | {Validation, stripping} | {Security testing} |

### Monitoring & Observability

| Metric | Type | Target | Alert |
|--------|------|--------|-------|
| {response_time} | {Gauge/Histogram} | {< X ms} | {if > Y ms} |

---

## Risks & Assumptions

### Assumptions

(From Discovery - technical validation)

| Assumption | Technical Validation | Impact if Wrong |
|------------|---------------------|-----------------|
| {From Discovery} | {How we verify/ensure} | {Fallback} |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|------|------------|--------|---------------------|----------|
| {From Discovery} | {Low/Med/High} | {Low/Med/High} | {Preventative measures} | {Recovery plan} |
| {New: Technical} | {Low/Med/High} | {Low/Med/High} | {How we prevent} | {What we do instead} |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Rationale |
|------|------------|-----------|
| {Storage} | {S3/R2/etc} | {Why this choice} |
| {Queue} | {SQS/Bull/etc} | {Why this choice} |
| {Cache} | {Redis/Memcached/etc} | {Why this choice} |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|----------|-----|-----|------------|
| {Choice A} | {Benefit} | {Drawback} | {How we address con} |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| 1 | {Technical question} | A) {Option} B) {Option} | {Recommendation} | {Decision or "–"} |

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
