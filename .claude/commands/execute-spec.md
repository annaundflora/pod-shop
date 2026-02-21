---
description: "Execute GSD-compatible PLAN.md files with atomic commits, multi-plan scanning, and wave-based parallel execution. Supports spec/phase/plan inputs."
---

# GSD Execute-Spec Command

Execute GSD-compatible plans with atomic commits, wave-based parallelization, and autonomous error recovery.

**Usage:**
```
/execute-spec <path>
/execute-spec specs/2025-01-23-gsd-executer          # Execute all phases in spec
/execute-spec specs/2025-01-23-gsd-executer/phases/1-executer-core  # Execute single phase
/execute-spec specs/.../1-01-PLAN.md                 # Execute single plan
```

**Arguments:** `$ARGUMENTS` - Path to spec folder, phase folder, or PLAN.md file

**Local CLI (same logic as /execute-spec):**
```
node .claude/skills/execute-spec.js <path> [--dry-run] [--resume <runId>] [--resume-input <text>] [--worktree <path>] [--worktree-base <path>] [--max-retries N]
```

**Preconditions:**
- `.claude/settings.local.json` erlaubt `Bash(node:*)` und `Bash(git:*)`
- Für echte Ausführung ohne Simulation: Task-Runner/Sub-Agent Integration muss konfiguriert sein
  - Optional: `EXECUTOR_TASK_RUNNER` Environment-Variable auf ein Node-Modul setzen, das `runTask(task, context)` exportiert

---

## 1. Input Type Detection

First, detect what type of input was provided:

```javascript
const path = require('path');
const fs = require('fs');

function detectInputType(inputPath) {
  const resolvedPath = path.resolve(process.cwd(), inputPath);

  // Check if exists
  if (!fs.existsSync(resolvedPath)) {
    throw new Error(`Path does not exist: ${inputPath}`);
  }

  const stat = fs.statSync(resolvedPath);

  // CASE 1: Single PLAN.md file
  if (stat.isFile() && resolvedPath.endsWith('PLAN.md')) {
    return {
      type: 'plan',
      path: resolvedPath,
      name: path.basename(resolvedPath),
      dir: path.dirname(resolvedPath)
    };
  }

  // CASE 2: Directory - check if it's a phase folder (contains *-PLAN.md files)
  if (stat.isDirectory()) {
    const planFiles = fs.readdirSync(resolvedPath)
      .filter(f => f.match(/\d+-\d+-PLAN\.md$/));

    if (planFiles.length > 0) {
      // It's a phase folder
      return {
        type: 'phase',
        path: resolvedPath,
        name: path.basename(resolvedPath),
        plans: planFiles.map(f => path.join(resolvedPath, f))
      };
    }

    // Check if it's a spec folder (has phases/ subdirectory)
    const phasesDir = path.join(resolvedPath, 'phases');
    if (fs.existsSync(phasesDir) && fs.statSync(phasesDir).isDirectory()) {
      return {
        type: 'spec',
        path: phasesDir,
        name: path.basename(resolvedPath),
        phasesPath: phasesDir
      };
    }
  }

  throw new Error(`Cannot detect input type for: ${inputPath}\n` +
    `Expected: PLAN.md file, phase folder (with *-PLAN.md), or spec folder (with phases/ subdirectory)`);
}
```

---

## 2. Load Multi-Plan Loader

```javascript
const {
  scanMultiPlans,
  resolvePhaseDependencies,
  resolveAllDependencies,
  ParseError,
  DependencyError
} = require('./.claude/utils/multi-plan-loader');
```

---

## 3. Execution Flow

### Step 1: Detect Input Type

```bash
INPUT_TYPE=$(node -e "
const input = '$ARGUMENTS';
const fs = require('fs');
const path = require('path');

const resolved = path.resolve(process.cwd(), input);
if (!fs.existsSync(resolved)) {
  console.log('ERROR');
  process.exit(1);
}

const stat = fs.statSync(resolved);
if (stat.isFile() && resolved.endsWith('PLAN.md')) {
  console.log('plan');
} else if (stat.isDirectory()) {
  const plans = fs.readdirSync(resolved).filter(f => f.match(/\d+-\d+-PLAN\.md\$/));
  if (plans.length > 0) {
    console.log('phase');
  } else {
    const phasesDir = path.join(resolved, 'phases');
    if (fs.existsSync(phasesDir)) {
      console.log('spec');
    } else {
      console.log('ERROR');
    }
  }
} else {
  console.log('ERROR');
}
")

if [ "$INPUT_TYPE" = "ERROR" ]; then
  echo "❌ Cannot detect input type: $ARGUMENTS"
  echo "Expected: PLAN.md file, phase folder, or spec folder"
  exit 1
fi

echo "📂 Input type: $INPUT_TYPE"
```

### Step 2: Route to Appropriate Execution

**If `plan`:** Execute single plan
```bash
PLAN_PATH="$ARGUMENTS"

echo "📋 Executing single plan: $(basename $PLAN_PATH)"
echo "   Path: $PLAN_PATH"

# Execute via local orchestrator
node .claude/skills/execute-spec.js "$PLAN_PATH" --dry-run
```

**If `phase`:** Execute all plans in phase
```bash
PHASE_DIR="$ARGUMENTS"

echo "📂 Executing phase: $(basename $PHASE_DIR)"

# Scan for plans in phase
PLAN_COUNT=$(ls "$PHASE_DIR"/*-PLAN.md 2>/dev/null | wc -l)
echo "   Found $PLAN_COUNT plan(s)"

# Load and execute phase plans (wave-based)
```

**If `spec`:** Execute all phases with dependency resolution
```bash
SPEC_PATH="$ARGUMENTS"
PHASES_DIR="$SPEC_PATH/phases"

echo "📦 Executing spec: $(basename $SPEC_PATH)"
echo "   Phases directory: $PHASES_DIR"

# Use multi-plan-loader to scan all phases
echo "🔍 Scanning phases..."
node -e "
const { scanMultiPlans, resolvePhaseDependencies } = require('./.claude/utils/multi-plan-loader');

try {
  // Scan all phases
  const scanResult = scanMultiPlans('$PHASES_DIR');
  console.log(\`Found \${scanResult.totalPhases} phases, \${scanResult.totalPlans} plans\`);

  // Resolve phase dependencies
  const resolved = resolvePhaseDependencies(scanResult);
  console.log(\`Execution order: \${resolved.executionOrder.length} waves\`);

  // Display execution plan
  console.log('\\n📊 Execution Plan:');
  resolved.executionOrder.forEach((wave, i) => {
    console.log(\`  Wave \${i + 1}: Phase \${wave.join(', ')}\`);
  });

} catch (error) {
  console.error('Error:', error.message);
  process.exit(1);
}
"
```

### Step 3: Execute Waves (for spec/phase types)

For each wave:
1. Spawn executor agents in parallel (one per plan)
2. Wait for all plans in wave to complete
3. Collect results
4. Proceed to next wave

```bash
# Wave execution pseudocode
for WAVE in "${WAVES[@]}"; do
  echo "🌊 Executing Wave $WAVE..."

  # Execute all plans in this wave in parallel
  for PLAN in "${WAVE_PLANS[@]}"; do
    # Spawn executor agent for this plan
    # (via Task tool or equivalent)
  done

  # Wait for all to complete
  wait

  echo "✅ Wave $WAVE complete"
done
```

---

## 4. Output Format

### For single plan:
```
📋 Executing: 1-01-PLAN.md
   Phase: 1 | Plan: 01
   Objective: PLAN.md Parser und Dependency Resolution

⏳ Loading plan...
   Found 3 tasks in 1 wave

🌊 Wave 1/1:
   [1/3] Task 1... ✅ [COMMIT abc1234]
   [2/3] Task 2... ✅ [COMMIT def5678]
   [3/3] Task 3... ✅ [COMMIT ghi9012]

✅ Plan complete!
   3/3 tasks done
   3 commits created
   Duration: 5m 23s
```

### For phase:
```
📂 Executing Phase: 1-executer-core
   Found 4 plans

📊 Execution Plan:
   Wave 1: 1-01, 1-02, 1-03 (parallel)
   Wave 2: 1-04 (depends on 1-03)

🌊 Wave 1/2: Executing 3 plans in parallel...
   [1-01] ✅ Complete (3 commits)
   [1-02] ✅ Complete (2 commits)
   [1-03] ✅ Complete (4 commits)

🌊 Wave 2/2: Executing 1 plan...
   [1-04] ✅ Complete (1 commit)

✅ Phase complete!
   4/4 plans done
   10 commits created
   Duration: 18m 45s
```

### For spec:
```
📦 Executing Spec: 2025-01-23-gsd-executer

🔍 Scanning phases...
   Found 3 phases
   Found 7 plans

📊 Execution Plan:
   Wave 1: Phase 1 (3 plans)
   Wave 2: Phase 2 (3 plans) [depends on Phase 1]
   Wave 3: Phase 3 (1 plan) [depends on Phase 2]

🌊 Wave 1/3: Phase 1 - Executer Core
   [1-01] ✅ Complete
   [1-02] ✅ Complete
   [1-03] ✅ Complete

🌊 Wave 2/3: Phase 2 - Hooks System
   [2-01] ✅ Complete
   [2-02] ✅ Complete
   [2-03] ✅ Complete

🌊 Wave 3/3: Phase 3 - Skills System
   [3-01] ✅ Complete

✅ Spec complete!
   3/3 phases done
   7/7 plans done
   Total commits: 42
   Duration: 1h 23m
```

---

## 5. Error Handling

| Error | Action |
|-------|--------|
| Path not found | Show error, exit |
| Cannot detect type | Show error with examples |
| Parse error | Show line number + context |
| Circular dependencies | Show cycle path, abort |
| Task failed | Retry (max 3) with auto-debug |
| All tasks in wave fail | Abort wave, show summary |

---

## Success Criteria

- [ ] Input type detected correctly (spec/phase/plan)
- [ ] Plans scanned and loaded
- [ ] Dependencies resolved (for multi-plan)
- [ ] Waves executed in order
- [ ] Each task committed atomically
- [ ] Summary report shown
- [ ] User can verify commits
