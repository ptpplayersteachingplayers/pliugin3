=== PTP Training Platform ===
Contributors: ptpsoccercamps
Tags: soccer, training, booking, camps, marketplace
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 242.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete soccer training platform — camps, 1-on-1 training marketplace, booking, payments, and dashboards.

== Description ==

PTP Training Platform powers the Players Teaching Players ecosystem, connecting youth soccer players with MLS and D1 college athlete coaches for 1-on-1 training and summer camps across Pennsylvania, New Jersey, Delaware, Maryland, and New York.

Features include:

* Training marketplace with Stripe Connect payments
* Camp registration and checkout
* Trainer profiles, dashboards, and availability management
* Parent/player accounts with booking history
* Abandoned cart recovery emails (3-email sequence)
* Coupon and referral systems
* SMS and email notifications
* Admin CRM and analytics

== Changelog ==

= 237.0 =
* FIX: Mobile tab switching on trainer profile — Train tab now reliably shows content on tap
* FIX: Removed CSS contain:layout from tp210-layout on mobile (broke Safari panel repaint)
* FIX: Added forced layout recalc (offsetHeight) after panel display toggle for mobile Safari
* FIX: Tab bar uses touchend event on mobile for instant response (no 300ms click delay)
* NEW: Sticky tab bar on mobile — tabs stay visible when scrolling through panel content
* NEW: Auto-scroll to tab bar after switching tabs so parent sees content changed
* IMPROVED: All hidden panels get explicit display:none on switch to prevent phantom layouts

= 235.0 =
* NEW: Mentorship component library (mentorship-components.php) — shared bottom sheets across both dashboards
* NEW: Session Completion Sheet for trainers (notes, parent summary, action item, energy rating)
* NEW: Session Scheduling Sheet for trainers (date/time/duration picker, meeting link generation)
* NEW: Session Request Sheet for parents (preferred time, notes)
* NEW: Goal creation bottom sheet with goal types (Game/Mental/Identity/Life) and target dates
* NEW: Video upload sheet with file preview and progress bar
* NEW: Mentorship quick-view card on trainer Home tab
* NEW: Mentorship status widget on parent Home tab
* NEW: PTP_Mentorship_Ajax class — centralized AJAX handler for all mentorship actions
* IMPROVED: Active Mentees cards show "Complete" button for past-due sessions
* IMPROVED: All mentorship modals now use native bottom sheets instead of prompt() or DOM overlays
* IMPROVED: Goal completion awards milestone badges at 5, 10, and 20 goals
* FIX: Mentorship JS functions properly handled via shared component across dashboards

= 221.0 =
* Security: LIMIT/OFFSET parameters now use $wpdb->prepare() in camp-orders, camp-admin, camp-checkout
* Added: uninstall.php for clean plugin removal (drops tables, options, cron, roles, transients, user meta)
* Cleanup: Archived dead templates to templates/deprecated/ (parent-dashboard-v117, trainer-profile-v2, thank-you)
* Fixed: Template version mappings in admin-tools and fixes now reference active templates

= 220.0 =
* Fixed: Abandoned cart emails now stop when customer completes a booking
* Fixed: Cross-table email deduplication (camp + training sequences)
* Fixed: Booking-exists guard prevents recovery emails to confirmed customers
* Fixed: Email validation uses WordPress is_email() in cart capture
* Fixed: Thank-you page reads ?booking= param for free sessions
* Fixed: Thank-you page 3-tier data fallback for date/time/location
* Fixed: Free checkout preserves session data for thank-you page
* Fixed: Free checkout sends parent + trainer confirmation emails
* Fixed: Trainer email null-safe date formatting
* Fixed: FREETRAINING coupon hardcoded fallback and self-healing
* Added: License headers for WordPress plugin compliance

= 216.0 =
* Unified abandoned cart recovery for camps and training
* Admin restructuring (11→6, 9→5, 9→5, 7→5 column tables)
* OpenPhone Bridge integration

== Upgrade Notice ==

= 221.0 =
Security hardening: SQL injection fixes in camp-orders and camp-admin pagination queries. Added uninstall.php. Deprecated templates archived.

= 220.0 =
Critical fix: abandoned cart recovery emails were not stopping after successful checkout. Update immediately.
