'use strict';

const path = require('path');
const fs = require('fs');
const {
  scanMultiPlans,
  resolveAllDependencies,
  loadPlan,
  resolveDependencies,
  ParseError,
  DependencyError
} = require('../utils/multi-plan-loader');
const {
  executePhaseWave,
  executePlan: executePlanWithExecutor
} = require('../utils/wave-executor');

function detectInputType(inputPath) {
  const resolvedPath = path.resolve(process.cwd(), inputPath);

  if (!fs.existsSync(resolvedPath)) {
    throw new Error(`Path does not exist: ${inputPath}`);
  }

  const stat = fs.statSync(resolvedPath);

  if (stat.isFile() && resolvedPath.endsWith('PLAN.md')) {
    return {
      type: 'plan',
      path: resolvedPath,
      name: path.basename(resolvedPath),
      dir: path.dirname(resolvedPath)
    };
  }

  if (stat.isDirectory()) {
    const planFiles = fs.readdirSync(resolvedPath)
      .filter(f => f.match(/^\d+-\d+-PLAN\.md$/));

    if (planFiles.length > 0) {
      return {
        type: 'phase',
        path: resolvedPath,
        name: path.basename(resolvedPath),
        plans: planFiles.map(f => path.join(resolvedPath, f))
      };
    }

    const phasesDir = path.join(resolvedPath, 'phases');
    if (fs.existsSync(phasesDir) && fs.statSync(phasesDir).isDirectory()) {
      return {
        type: 'spec',
        path: resolvedPath,
        name: path.basename(resolvedPath),
        phasesPath: phasesDir
      };
    }
  }

  throw new Error(`Cannot detect input type for: ${inputPath}`);
}

function parseArgs(args) {
  const result = {
    inputPath: '',
    dryRun: false,
    resumeRunId: null,
    resumeInput: null,
    worktreePath: null,
    worktreeBase: null,
    maxRetries: 3,
  };

  const remaining = [];
  for (let i = 0; i < args.length; i += 1) {
    const arg = args[i];
    if (arg === '--dry-run') {
      result.dryRun = true;
      continue;
    }
    if (arg === '--resume') {
      result.resumeRunId = args[i + 1] || null;
      i += 1;
      continue;
    }
    if (arg === '--resume-input') {
      result.resumeInput = args[i + 1] || null;
      i += 1;
      continue;
    }
    if (arg === '--worktree') {
      result.worktreePath = args[i + 1] || null;
      i += 1;
      continue;
    }
    if (arg === '--worktree-base') {
      result.worktreeBase = args[i + 1] || null;
      i += 1;
      continue;
    }
    if (arg === '--max-retries') {
      const value = parseInt(args[i + 1], 10);
      if (!Number.isNaN(value)) {
        result.maxRetries = value;
      }
      i += 1;
      continue;
    }
    remaining.push(arg);
  }

  result.inputPath = remaining[0] || '';
  return result;
}

function createTaskRunner(options) {
  if (options.dryRun) {
    return async (task) => ({
      success: true,
      message: 'dry-run: task not executed',
      output: `dry-run: ${task.name}`,
      files: task.files ? String(task.files).split(',').map(s => s.trim()).filter(Boolean) : [],
      logs: [],
      evidence: { dryRun: true }
    });
  }

  const runnerPath = process.env.EXECUTOR_TASK_RUNNER;
  if (runnerPath) {
    const resolved = path.isAbsolute(runnerPath) ? runnerPath : path.resolve(process.cwd(), runnerPath);
    const runnerModule = require(resolved);
    const runnerFn = runnerModule.runTask || runnerModule.executeTask || runnerModule.default || runnerModule;
    if (typeof runnerFn === 'function') {
      return async (task, context) => runnerFn(task, context);
    }
  }

  return async () => ({
    success: false,
    message: 'Task runner not configured. Use --dry-run or set EXECUTOR_TASK_RUNNER.'
  });
}

function buildPlanFromFile(planPath) {
  const plan = loadPlan(planPath);
  const executionPlan = resolveDependencies(plan.tasks);
  const frontmatter = plan.frontmatter || {};
  const planId = frontmatter.phase && frontmatter.plan ? `${frontmatter.phase}-${frontmatter.plan}` : (frontmatter.plan || plan.plan);
  return {
    path: planPath,
    plan: planId,
    phase: frontmatter.phase || plan.phase,
    taskWaves: executionPlan.waves,
    totalTasks: executionPlan.totalTasks
  };
}

async function executeSinglePlan(planPath, options) {
  console.log(`\n📋 Executing: ${path.basename(planPath)}`);
  const plan = buildPlanFromFile(planPath);
  console.log(`   Phase: ${plan.phase} | Plan: ${plan.plan}`);

  const context = {
    repoPath: options.repoPath,
    worktreePath: options.worktreePath,
    worktreeBase: options.worktreeBase,
    dryRun: options.dryRun,
    resumeRunId: options.resumeRunId,
    resumeInput: options.resumeInput,
    maxRetries: options.maxRetries,
    taskRunner: options.taskRunner,
    specPath: options.specPath || null,
  };

  const result = await executePlanWithExecutor(plan, context);
  return {
    planName: `${plan.phase}-${plan.plan}`,
    totalTasks: plan.totalTasks,
    result,
  };
}

async function executePhase(phasePath, options) {
  const phaseName = path.basename(phasePath);
  console.log(`\n📂 Executing Phase: ${phaseName}`);

  const planFiles = fs.readdirSync(phasePath)
    .filter(f => f.match(/^\d+-\d+-PLAN\.md$/))
    .map(f => path.join(phasePath, f));

  const phaseNumber = parseInt(phaseName.split('-')[0], 10);
  const plans = planFiles.map(buildPlanFromFile);

  const context = {
    repoPath: options.repoPath,
    worktreePath: options.worktreePath,
    worktreeBase: options.worktreeBase,
    dryRun: options.dryRun,
    resumeRunId: options.resumeRunId,
    resumeInput: options.resumeInput,
    maxRetries: options.maxRetries,
    taskRunner: options.taskRunner,
    specPath: options.specPath || null,
  };

  const phaseResult = await executePhaseWave(phaseNumber, plans, context);
  return {
    phaseName,
    totalPlans: plans.length,
    result: phaseResult,
  };
}

async function executeSpec(phasesPath, options) {
  const specName = path.basename(path.dirname(phasesPath));
  console.log(`\n📦 Executing Spec: ${specName}`);
  console.log(`   Phases directory: ${phasesPath}`);

  const scanResult = scanMultiPlans(phasesPath);
  const executionPlan = resolveAllDependencies(scanResult);

  let totalPlans = 0;
  let totalPhases = executionPlan.phaseWaves.length;
  const waveResults = [];

  for (let i = 0; i < executionPlan.phaseWaves.length; i += 1) {
    const wave = executionPlan.phaseWaves[i];
    console.log(`\n🌊 Wave ${i + 1}/${executionPlan.phaseWaves.length}`);
    for (const phase of wave.phases) {
      const phaseNumber = phase.phaseNumber;
      const plans = phase.plans.map(plan => ({
        ...plan,
        phase: phase.phaseNumber,
      }));
      totalPlans += plans.length;
      const phaseResult = await executePhaseWave(phaseNumber, plans, {
        repoPath: options.repoPath,
        worktreePath: options.worktreePath,
        worktreeBase: options.worktreeBase,
        dryRun: options.dryRun,
        resumeRunId: options.resumeRunId,
        resumeInput: options.resumeInput,
        maxRetries: options.maxRetries,
        taskRunner: options.taskRunner,
        specPath: options.specPath || null,
      });
      waveResults.push(phaseResult);
    }
  }

  return {
    specName,
    totalPhases,
    totalPlans,
    totalTasks: executionPlan.totalTasks,
    waveResults,
  };
}

async function run(args, overrides = {}) {
  const parsed = parseArgs(args);
  if (!parsed.inputPath) {
    console.log('Usage: /execute-spec <path> [--dry-run] [--resume <runId>] [--resume-input <text>] [--worktree <path>] [--worktree-base <path>]');
    return {
      success: false,
      message: 'No input path provided'
    };
  }

  const detected = detectInputType(parsed.inputPath);
  console.log(`📂 Input type: ${detected.type}`);
  console.log(`   Name: ${detected.name}`);

  const taskRunner = overrides.taskRunner || createTaskRunner({ dryRun: parsed.dryRun });
  const options = {
    repoPath: overrides.repoPath || process.cwd(),
    worktreePath: parsed.worktreePath || overrides.worktreePath,
    worktreeBase: parsed.worktreeBase || overrides.worktreeBase,
    dryRun: parsed.dryRun,
    resumeRunId: parsed.resumeRunId || overrides.resumeRunId,
    resumeInput: parsed.resumeInput || overrides.resumeInput,
    maxRetries: parsed.maxRetries || overrides.maxRetries,
    taskRunner,
    specPath: detected.type === 'spec' ? detected.path : overrides.specPath,
  };

  try {
    let result;
    if (detected.type === 'plan') {
      result = await executeSinglePlan(detected.path, options);
    } else if (detected.type === 'phase') {
      result = await executePhase(detected.path, options);
    } else if (detected.type === 'spec') {
      result = await executeSpec(detected.phasesPath, options);
    } else {
      throw new Error(`Unknown input type: ${detected.type}`);
    }

    return {
      success: true,
      message: 'Execution complete',
      result,
    };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`\n❌ Error: ${message}`);

    if (error instanceof ParseError) {
      console.error(`   Plan: ${error.filePath}`);
      console.error(`   Line: ${error.lineNumber}`);
    }

    if (error instanceof DependencyError) {
      console.error(`   Dependency issue: ${message}`);
    }

    return {
      success: false,
      message,
      error: String(error),
    };
  }
}

if (require.main === module) {
  run(process.argv.slice(2)).then(result => {
    if (!result.success) {
      process.exitCode = 1;
    }
  });
}

module.exports = {
  id: 'execute-spec',
  name: 'Execute Spec',
  description: 'Execute local GSD-compatible PLAN.md files with wave-based execution',
  version: '1.0.0',
  author: 'Custom',
  permissions: ['read', 'write'],
  parameters: [
    {
      name: 'path',
      type: 'string',
      required: true,
      description: 'Path to spec folder, phase folder, or PLAN.md file'
    }
  ],
  execute: async function execute(context) {
    const args = context?.args || [];
    return run(args, { repoPath: process.cwd() });
  },
};
