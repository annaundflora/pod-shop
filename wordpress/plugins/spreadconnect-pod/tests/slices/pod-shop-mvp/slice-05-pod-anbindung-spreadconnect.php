<?php
/**
 * Slice 05: POD-Anbindung (Spreadconnect) -- Test Suite Entry Point.
 *
 * This file aggregates all test classes for Slice 05. PHPUnit 11 requires
 * filenames to match class names, so the actual test classes are in:
 *
 *   - SpreadconnectApiClientTest.php      (4 tests: AC-1, AC-3, AC-4 + JSON robustness)
 *   - SpreadconnectOrderServiceTest.php   (5 tests: AC-1, AC-2, AC-5, AC-6 + idempotency)
 *   - SpreadconnectTrackingServiceTest.php (4 tests: AC-7/8, AC-9, AC-10 + idempotency)
 *
 * Total: 13 tests covering all 10 Acceptance Criteria.
 *
 * Spec: docs/features/pod-shop-mvp/slices/slice-05-pod-anbindung-spreadconnect.md
 *
 * @package SpreadconnectPod\Tests
 */

require_once __DIR__ . '/SpreadconnectApiClientTest.php';
require_once __DIR__ . '/SpreadconnectOrderServiceTest.php';
require_once __DIR__ . '/SpreadconnectTrackingServiceTest.php';
