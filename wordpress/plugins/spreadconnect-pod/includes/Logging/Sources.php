<?php
/**
 * Single Source of Truth for the six canonical Plugin log-source strings
 * (slice-42).
 *
 * Every `wc_get_logger()->log()` call placed by Plugin code MUST tag the
 * entry with `$context['source'] = self::FOO` (one of the six constants
 * below). The {@see WcLoggerAdapter} validates the value at runtime;
 * additional callers (slice-43 `PurgeOldLogsJob` if file-purge ever
 * lands, slice-42 `Hub\View\Logs` for the source-filter dropdown) read
 * {@see self::ALL} to enumerate the legal set.
 *
 * **Adding a new source means editing this constant list AND the
 * Sources table in slice-42's spec — both must agree.**
 *
 * @package SpreadconnectPod\Logging
 */

declare(strict_types=1);

namespace SpreadconnectPod\Logging;

/**
 * Constant-only class — no methods, no instantiation.
 *
 * `final` keeps callers from sub-classing in a way that masks the
 * whitelist; `abstract` would prevent instantiation but also make the
 * class-name look like an OO type, which it isn't.
 */
final class Sources
{
	/**
	 * Outbound HTTP client (slice-07/08/10) — every request, retry,
	 * rate-limit sleep, status-line and DTO-mapping warning.
	 */
	public const API_CLIENT = 'spreadconnect-api-client';

	/**
	 * Outbound order pipeline (slice-27/28/29/30/31) — submit, confirm,
	 * cancel, mirror, fetch-tracking, state-machine.
	 */
	public const ORDER_SERVICE = 'spreadconnect-order-service';

	/**
	 * Inbound webhook receiver (slice-15/16/17/25/30) — controller,
	 * signature verifier, event-id hasher, dispatcher and the per-event
	 * domain handlers.
	 */
	public const WEBHOOK_RECEIVER = 'spreadconnect-webhook-receiver';

	/**
	 * Catalog and stock sync jobs (slice-21-25/36) — sync-catalog,
	 * sync-article, article-removed, image-sideloader, product-mapper,
	 * stock-sync.
	 */
	public const SYNC_JOB = 'spreadconnect-sync-job';

	/**
	 * Failure-handling pipeline (slice-37-40) — failed-ops repository,
	 * retry-policy listener, failure notifier, bulk-resend coordinator.
	 */
	public const FAILURE = 'spreadconnect-failure';

	/**
	 * Optional WP-CLI adapter (post-MVP — Source is reserved so the
	 * whitelist need not change when slice-44+ ships the CLI).
	 */
	public const CLI = 'spreadconnect-cli';

	/**
	 * Whitelist for `WcLoggerAdapter::log()` source-validation and the
	 * `Hub\View\Logs` source-filter dropdown. Order is the canonical
	 * order from the slice-42 Sources table — DO NOT reorder.
	 *
	 * @var list<string>
	 */
	public const ALL = array(
		self::API_CLIENT,
		self::ORDER_SERVICE,
		self::WEBHOOK_RECEIVER,
		self::SYNC_JOB,
		self::FAILURE,
		self::CLI,
	);
}
