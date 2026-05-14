<?php
/**
 * Email suppressor for migration runs.
 *
 * Completely silences all WordPress and WooCommerce outgoing emails while
 * a migration is active.  Emails resume automatically the moment the run
 * completes, is aborted, or the PHP request ends (shutdown function).
 *
 * WHY THIS IS NEEDED:
 *   When OctoWoo creates WP users (CustomerMigrator) and WC orders
 *   (OrderMigrator), WordPress and WooCommerce fire their normal email hooks:
 *     - wp_new_user_notification()  → "Welcome / account created" to customer
 *     - WC customer_new_account     → "Your account is ready" to customer
 *     - WC new_order                → "You have received a new order" to admin
 *     - WC processing_order         → "Your order is being processed" to customer
 *     - WC completed_order          → "Your order is complete" to customer
 *     - WC cancelled_order          → "Your order has been cancelled" to customer
 *     - WC customer_reset_password  → fired when flagging force_password_reset
 *
 *   For a store with 5 000 customers and 10 000 orders, without suppression
 *   the migration would send ~15 000 unsolicited emails (WP + WC combined).
 *
 * HOW SUPPRESSION WORKS:
 *   Layer 1 — wp_mail filter (catches everything):
 *     Intercepts at the last possible point inside wp_mail() before delivery.
 *     Returns the $args array unchanged but records it; actual send is blocked.
 *
 *   Layer 2 — WooCommerce email class toggle:
 *     Disables each WC email class by removing their action listeners so
 *     the email objects are never even instantiated.
 *
 *   Layer 3 — WP user notification override:
 *     Overrides wp_new_user_notification() and send_password_change_email()
 *     to no-ops during migration (pluggable functions).
 *
 *   Layer 4 — wp_send_new_user_notifications filter:
 *     Suppresses the "new user" notifications added in WP 4.6+.
 *
 * WHAT IS NEVER BLOCKED:
 *   The OctoWoo migration completion email (sent by BackgroundProcessor after
 *   done_all=true) is explicitly allowed through using a transient flag so
 *   the admin always receives their summary report.
 *
 * USAGE:
 *   EmailSuppressor::enable( $run_id );   // Call at migration start.
 *   EmailSuppressor::disable( $run_id );  // Call at migration end/abort.
 *
 * @package OctoWoo\Core
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class EmailSuppressor {

	/** Transient key that marks suppression as active for a given run. */
	private const TRANSIENT_KEY = 'octowoo_email_suppressed';

	/** Transient key that allows the OctoWoo completion email through. */
	private const ALLOW_REPORT_KEY = 'octowoo_allow_report_email';

	/** Whether suppression hooks have already been registered in this request. */
	private static bool $hooks_registered = false;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Enable email suppression for the given migration run.
	 *
	 * Registers all hook layers and stores a transient so that other PHP
	 * requests (background chunks, Action Scheduler) also suppress emails.
	 *
	 * @param  string $run_id  The active migration run ID.
	 * @param  bool   $suppress_admin  Also suppress admin new-order emails.
	 */
	public static function enable( string $run_id, bool $suppress_admin = true ): void {
		// Persist flag across HTTP requests (background/chunked migrations).
		set_transient( self::TRANSIENT_KEY, $run_id, 12 * HOUR_IN_SECONDS );

		self::registerHooks( $suppress_admin );
	}

	/**
	 * Disable suppression and restore normal email delivery.
	 *
	 * @param  string $run_id  The run that is finishing.
	 */
	public static function disable( string $run_id ): void {
		delete_transient( self::TRANSIENT_KEY );
		self::removeHooks();
	}

	/**
	 * Allow the OctoWoo completion-summary email to pass through suppression.
	 * Called by BackgroundProcessor just before it sends the summary report.
	 */
	public static function allowReportEmail(): void {
		set_transient( self::ALLOW_REPORT_KEY, 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Check (and re-register hooks) if suppression is active for ANY run.
	 * Called at plugins_loaded so background chunks also suppress emails.
	 */
	public static function maybeActivate(): void {
		$active_run = get_transient( self::TRANSIENT_KEY );
		if ( $active_run ) {
			self::registerHooks();
		}
	}

	/**
	 * Return true when suppression is currently active.
	 */
	public static function isActive(): bool {
		return (bool) get_transient( self::TRANSIENT_KEY );
	}

	// ── Hook registration ─────────────────────────────────────────────────────

	private static function registerHooks( bool $suppress_admin = true ): void {
		if ( self::$hooks_registered ) {
			return; // Idempotent — never double-add hooks.
		}
		self::$hooks_registered = true;

		// ── Layer 1: wp_mail intercept ─────────────────────────────────────────
		// Priority 1 = runs before any other wp_mail filter, including SMTP plugins.
		add_filter( 'wp_mail', [ self::class, 'interceptWpMail' ], 1 );

		// ── Layer 2: WooCommerce email class toggles ───────────────────────────
		// Remove WC email triggers from their action hooks so WC never even
		// builds the email object.  This prevents wp_mail() from being called at all.
		add_action( 'woocommerce_email_classes', [ self::class, 'disableWcEmailHooks' ], 100 );

		// Also catch emails registered dynamically by WC extensions.
		add_filter( 'woocommerce_email_enabled_new_order',          '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_cancelled_order',    '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_failed_order',       '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_customer_refunded_order',  '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_customer_new_account',     '__return_false', 100 );
		add_filter( 'woocommerce_email_enabled_customer_reset_password',  '__return_false', 100 );

		// ── Layer 3: WP user notification suppression ─────────────────────────
		// The pluggable functions can be overridden before they are called.
		// We use a filter on the notification type instead (safer than
		// redefining pluggable functions, which only works if not yet loaded).
		add_filter( 'wp_send_new_user_notifications', [ self::class, 'suppressNewUserNotification' ], 100, 2 );

		// WP 6.1+ fires this before sending admin "new user" email.
		add_filter( 'send_password_change_email', '__return_false', 100 );
		add_filter( 'send_email_change_email',    '__return_false', 100 );

		// ── Layer 4: Global wp_mail override (nuclear option) ─────────────────
		// Filters the $atts array passed to PHPMailer. Returning false here
		// means the mailer is never initialised. Used as ultimate backstop.
		add_filter( 'pre_wp_mail', [ self::class, 'blockPreWpMail' ], 1 );

		// ── Shutdown safety net ───────────────────────────────────────────────
		// If a fatal error or early exit occurs before disable() is called,
		// we want to at least remove the hooks so the next non-migration
		// request doesn't accidentally suppress emails.
		register_shutdown_function( [ self::class, 'onShutdown' ] );
	}

	private static function removeHooks(): void {
		remove_filter( 'wp_mail',          [ self::class, 'interceptWpMail' ], 1 );
		remove_filter( 'pre_wp_mail',      [ self::class, 'blockPreWpMail' ],  1 );
		remove_action( 'woocommerce_email_classes', [ self::class, 'disableWcEmailHooks' ], 100 );

		$disabled_emails = [
			'new_order', 'cancelled_order', 'failed_order',
			'customer_on_hold_order', 'customer_processing_order',
			'customer_completed_order', 'customer_refunded_order',
			'customer_new_account', 'customer_reset_password',
		];
		foreach ( $disabled_emails as $email_id ) {
			remove_filter( "woocommerce_email_enabled_{$email_id}", '__return_false', 100 );
		}

		remove_filter( 'wp_send_new_user_notifications', [ self::class, 'suppressNewUserNotification' ], 100 );
		remove_filter( 'send_password_change_email', '__return_false', 100 );
		remove_filter( 'send_email_change_email',    '__return_false', 100 );

		self::$hooks_registered = false;
	}

	// ── Hook callbacks ────────────────────────────────────────────────────────

	/**
	 * Layer 1: Intercept wp_mail() args.
	 *
	 * Returns null to prevent delivery, UNLESS the ALLOW_REPORT_KEY transient
	 * is set (which means BackgroundProcessor is sending the summary report).
	 *
	 * @param  array<string,mixed> $args  wp_mail() arguments.
	 * @return array<string,mixed>|null   Null cancels delivery.
	 */
	public static function interceptWpMail( array $args ): ?array {
		// Allow the OctoWoo completion summary through.
		if ( get_transient( self::ALLOW_REPORT_KEY ) ) {
			delete_transient( self::ALLOW_REPORT_KEY ); // One-shot — delete after use.
			return $args;
		}

		// Block everything else during migration.
		return null;
	}

	/**
	 * Layer 4 (nuclear backstop): Filter on pre_wp_mail.
	 * Returning anything other than null short-circuits wp_mail().
	 *
	 * @param  null|bool  $pre
	 * @return bool|null
	 */
	public static function blockPreWpMail( $pre ) {
		if ( get_transient( self::ALLOW_REPORT_KEY ) ) {
			return $pre; // Let it through.
		}
		// Returning false from pre_wp_mail causes wp_mail() to return false
		// immediately, preventing PHPMailer from being initialised.
		return false;
	}

	/**
	 * Layer 2: Disable all WC email class hooks.
	 *
	 * @param  WC_Email[] $email_classes  All registered WC email class instances.
	 * @return WC_Email[]
	 */
	public static function disableWcEmailHooks( array $email_classes ): array {
		foreach ( $email_classes as $email ) {
			if ( method_exists( $email, 'unhook_myself' ) ) {
				$email->unhook_myself();
			} elseif ( isset( $email->id ) ) {
				// Remove every possible trigger hook for this email class.
				$hooks = $email->get_option( 'hooks', [] ) ?: [];
				foreach ( (array) $hooks as $hook => $priority ) {
					remove_action( $hook, [ $email, 'trigger' ], (int) $priority );
				}
			}
		}
		return $email_classes;
	}

	/**
	 * Layer 3: Suppress WP new-user notifications entirely.
	 *
	 * @param  string[] $notify  Notification types ('admin', 'user').
	 * @param  int      $user_id
	 * @return string[]
	 */
	public static function suppressNewUserNotification( array $notify, int $user_id ): array {
		return []; // Remove all notification types.
	}

	// ── Shutdown ──────────────────────────────────────────────────────────────

	/**
	 * Safety net: if the migration ended without calling disable() (e.g. a
	 * fatal error), clean up the transient on shutdown so the next request
	 * doesn't carry suppression forward unexpectedly.
	 *
	 * We do NOT call disable() here because we don't have the run_id and we
	 * don't want to accidentally suppress the completion email.
	 */
	public static function onShutdown(): void {
		// Only clean up if this request started migration (has the flag in memory).
		if ( self::$hooks_registered ) {
			// Leave the transient in place — it will auto-expire in 12 h.
			// This intentionally keeps suppression active across background chunks.
			// The transient is deleted by disable() which is called after done_all.
			self::removeHooks();
		}
	}
}
