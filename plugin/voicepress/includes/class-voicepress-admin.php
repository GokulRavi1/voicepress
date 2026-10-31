<?php
/**
 * VoicePress admin screens: menu, dashboard, calls, agents, automations and settings.
 *
 * Also enqueues the admin assets on WooCommerce order screens so the "Voice AI"
 * order-list badges and the order meta box (rendered by the calls module) are styled
 * and live.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI controller (static).
 */
class VoicePress_Admin {

	const CAP = 'manage_woocommerce';

	const PAGE_DASHBOARD   = 'voicepress';
	const PAGE_CALLS       = 'voicepress-calls';
	const PAGE_AGENTS      = 'voicepress-agents';
	const PAGE_AUTOMATIONS = 'voicepress-automations';
	const PAGE_SETTINGS    = 'voicepress-settings';

	const PER_PAGE = 25;

	/** Use cases offered by the "Try it live" card. */
	const TEST_USE_CASES = array( 'cod_confirmation', 'delivery_reminder', 'ndr_resolution', 'cart_recovery', 'feedback', 'support_callback' );

	/** Use cases that need an order. */
	const ORDER_USE_CASES = array( 'cod_confirmation', 'delivery_reminder', 'ndr_resolution', 'feedback' );

	/** Use cases with an automation row. */
	const AUTOMATION_USE_CASES = array( 'cod_confirmation', 'delivery_reminder', 'ndr_resolution', 'feedback', 'cart_recovery' );

	/** Fallbacks used only if another module is unavailable. */
	const DEFAULT_AGENT_KEYS = array( 'cod_confirmation', 'delivery', 'cart_recovery', 'feedback', 'support' );
	const DEFAULT_TERMINAL   = array( 'completed', 'no-answer', 'busy', 'failed', 'canceled', 'stopped', 'error', 'balance-low', 'skipped' );

	/** Statuses that are moving on their own (badge shows a blinking dot). */
	const PENDING_STATUSES = array( 'dispatching', 'prepared', 'queued', 'rescheduled', 'call-disconnected' );

	/** Statuses where the line is active (badge pulses green). */
	const LIVE_STATUSES = array( 'initiated', 'ringing', 'in-progress' );

	/** Telephony providers supported by Bolna for hosted numbers. */
	const PROVIDERS = array(
		'twilio' => 'Twilio',
		'plivo'  => 'Plivo',
		'exotel' => 'Exotel',
		'vobiz'  => 'Vobiz',
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_post_voicepress_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_voicepress_save_automations', array( __CLASS__, 'save_automations' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'admin_footer_text' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Menu, assets, chrome
	 * ------------------------------------------------------------------ */

	/**
	 * Admin pages (slug => label, icon, render callback).
	 *
	 * @return array
	 */
	private static function pages() {
		return array(
			self::PAGE_DASHBOARD   => array(
				'label' => __( 'Dashboard', 'voicepress' ),
				'icon'  => 'dashicons-chart-area',
				'cb'    => 'render_dashboard',
			),
			self::PAGE_CALLS       => array(
				'label' => __( 'Calls', 'voicepress' ),
				'icon'  => 'dashicons-phone',
				'cb'    => 'render_calls',
			),
			self::PAGE_AGENTS      => array(
				'label' => __( 'Agents', 'voicepress' ),
				'icon'  => 'dashicons-groups',
				'cb'    => 'render_agents',
			),
			self::PAGE_AUTOMATIONS => array(
				'label' => __( 'Automations', 'voicepress' ),
				'icon'  => 'dashicons-controls-repeat',
				'cb'    => 'render_automations',
			),
			self::PAGE_SETTINGS    => array(
				'label' => __( 'Settings', 'voicepress' ),
				'icon'  => 'dashicons-admin-generic',
				'cb'    => 'render_settings',
			),
		);
	}

	/**
	 * Register the VoicePress menu and its submenus.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'VoicePress', 'voicepress' ),
			__( 'VoicePress', 'voicepress' ),
			self::CAP,
			self::PAGE_DASHBOARD,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-microphone',
			56
		);

		foreach ( self::pages() as $slug => $page ) {
			add_submenu_page(
				self::PAGE_DASHBOARD,
				/* translators: %s: admin screen name, e.g. "Calls". */
				sprintf( __( '%s · VoicePress', 'voicepress' ), $page['label'] ),
				$page['label'],
				self::CAP,
				$slug,
				array( __CLASS__, $page['cb'] )
			);
		}
	}

	/**
	 * Current VoicePress page slug ('' when not on a VoicePress page).
	 *
	 * @return string
	 */
	public static function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return array_key_exists( $page, self::pages() ) ? $page : '';
	}

	/**
	 * Whether the current screen is a WooCommerce order list/edit screen (legacy or HPOS).
	 *
	 * @return bool
	 */
	public static function is_order_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		$ids = array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = (string) self::safe(
				function () {
					return wc_get_page_screen_id( 'shop-order' );
				},
				''
			);
		}
		return in_array( $screen->id, $ids, true );
	}

	/**
	 * Enqueue admin CSS/JS on VoicePress pages and WooCommerce order screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook = '' ) {
		$page     = self::current_page();
		$is_order = self::is_order_screen();
		if ( '' === $page && ! $is_order ) {
			return;
		}

		wp_enqueue_style( 'voicepress-admin', self::asset_url( 'assets/admin.css' ), array( 'dashicons' ), self::asset_version( 'assets/admin.css' ) );
		wp_enqueue_script( 'voicepress-admin', self::asset_url( 'assets/admin.js' ), array( 'jquery' ), self::asset_version( 'assets/admin.js' ), true );
		wp_localize_script( 'voicepress-admin', 'VoicePressAdmin', self::script_data( $page, $is_order ) );
	}

	/**
	 * Data handed to admin.js.
	 *
	 * @param string $page     VoicePress page slug or ''.
	 * @param bool   $is_order Whether this is a WooCommerce order screen.
	 * @return array
	 */
	private static function script_data( $page, $is_order ) {
		$statuses = array();
		foreach ( array( 'scheduled', 'dispatching', 'prepared', 'queued', 'rescheduled', 'initiated', 'ringing', 'in-progress', 'call-disconnected', 'completed', 'no-answer', 'busy', 'failed', 'canceled', 'stopped', 'error', 'balance-low', 'skipped' ) as $status ) {
			$statuses[ $status ] = array(
				'label'   => self::status_label( $status ),
				'tone'    => self::status_tone( $status ),
				'pending' => in_array( $status, self::PENDING_STATUSES, true ),
			);
		}
		$outcomes = array();
		foreach ( self::known_outcomes() as $outcome ) {
			$outcomes[ $outcome ] = array(
				'label' => self::outcome_label( $outcome ),
				'tone'  => self::outcome_tone( $outcome ),
			);
		}
		$agent_labels = array();
		foreach ( self::agent_labels() as $key => $info ) {
			$agent_labels[ $key ] = $info['label'];
		}
		$use_cases = self::use_case_labels();
		$icons     = array();
		foreach ( array_keys( $use_cases ) as $use_case ) {
			$icons[ $use_case ] = self::use_case_icon( $use_case );
		}
		$settings = self::settings();

		return array(
			'rest'          => esc_url_raw( rest_url( 'voicepress/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'adminUrl'      => admin_url(),
			'callsUrl'      => admin_url( 'admin.php?page=voicepress-calls' ),
			'page'          => $page,
			'screen'        => $is_order ? 'order' : ( '' !== $page ? 'voicepress' : '' ),
			'pollInterval'  => 10000,
			'terminal'      => self::terminal_statuses(),
			'statuses'      => $statuses,
			'outcomes'      => $outcomes,
			'useCases'      => $use_cases,
			'useCaseIcons'  => $icons,
			'agentKeys'     => self::agent_keys(),
			'agentLabels'   => $agent_labels,
			'agents'        => isset( $settings['agents'] ) && is_array( $settings['agents'] ) ? $settings['agents'] : array(),
			'bolnaAgentUrl' => 'https://platform.bolna.ai/agent/',
			'orderUrl'      => self::order_url_template(),
			'currency'      => self::currency_format(),
			'i18n'          => self::js_strings(),
		);
	}

	/**
	 * Translatable strings used by admin.js.
	 *
	 * @return array
	 */
	private static function js_strings() {
		return array(
			'calling'           => __( 'Placing your call…', 'voicepress' ),
			'callPlaced'        => __( 'Call placed! Your phone should ring in a few seconds.', 'voicepress' ),
			'callScheduled'     => __( 'Call scheduled.', 'voicepress' ),
			'callNotPlaced'     => __( 'The call could not be placed.', 'voicepress' ),
			'viewCall'          => __( 'View call', 'voicepress' ),
			'phoneRequired'     => __( 'Enter a phone number to call.', 'voicepress' ),
			'networkError'      => __( 'Could not reach your site. Check your connection and try again.', 'voicepress' ),
			'sessionExpired'    => __( 'Your session expired. Reload the page and try again.', 'voicepress' ),
			'genericError'      => __( 'Something went wrong. Please try again.', 'voicepress' ),
			'working'           => __( 'Working…', 'voicepress' ),
			'agentCreated'      => __( 'Agent created', 'voicepress' ),
			'agentUpdated'      => __( 'Agent updated', 'voicepress' ),
			'live'              => __( 'Live', 'voicepress' ),
			'notCreated'        => __( 'Not created', 'voicepress' ),
			'createAgent'       => __( 'Create agent', 'voicepress' ),
			'updateAgent'       => __( 'Update agent', 'voicepress' ),
			'openInBolna'       => __( 'Open in Bolna', 'voicepress' ),
			'extractionsFailed' => __( 'Agent saved, but call analytics setup failed:', 'voicepress' ),
			'allAgentsDone'     => __( 'All agents are ready.', 'voicepress' ),
			'someAgentsFailed'  => __( 'Some agents need attention — see the cards below.', 'voicepress' ),
			'resynced'          => __( 'Agents re-synced with your latest settings.', 'voicepress' ),
			'nothingToSync'     => __( 'No agents to sync yet — create them first.', 'voicepress' ),
			'notFoundInBolna'   => __( 'Missing in Bolna', 'voicepress' ),
			'connected'         => __( 'Connected to Bolna', 'voicepress' ),
			'wallet'            => __( 'Wallet', 'voicepress' ),
			'concurrency'       => __( 'Concurrent calls', 'voicepress' ),
			'phoneNumbers'      => __( 'Phone numbers', 'voicepress' ),
			'agentsLabel'       => __( 'Agents', 'voicepress' ),
			'noNumbers'         => __( 'No phone numbers found in your Bolna account. Buy or connect one at platform.bolna.ai.', 'voicepress' ),
			'chooseNumber'      => __( 'Choose a number…', 'voicepress' ),
			'otherNumber'       => __( 'Other — type it in', 'voicepress' ),
			'saveReminder'      => __( 'Number selected. Remember to save your settings.', 'voicepress' ),
			'unsavedKey'        => __( 'This tests the saved key. Save settings first to test the key you just typed.', 'voicepress' ),
			'linked'            => __( 'Linked! Calls to this number now reach your Support agent.', 'voicepress' ),
			'pickNumber'        => __( 'Pick a phone number first.', 'voicepress' ),
			'loadingNumbers'    => __( 'Loading your Bolna numbers…', 'voicepress' ),
			'notLinked'         => __( 'No agent', 'voicepress' ),
			'supportAgent'      => __( 'VoicePress Support agent', 'voicepress' ),
			'otherAgent'        => __( 'Another agent', 'voicepress' ),
			'number'            => __( 'Number', 'voicepress' ),
			'agent'             => __( 'Inbound agent', 'voicepress' ),
			'provider'          => __( 'Provider', 'voicepress' ),
			/* translators: %s: use case, e.g. "COD confirmation". */
			'confirmCall'       => __( 'Place a "%s" call to this customer now?', 'voicepress' ),
			'noOrderCalls'      => __( 'No voice calls for this order yet.', 'voicepress' ),
			'loading'           => __( 'Loading calls…', 'voicepress' ),
			'linkedBadge'       => __( 'Linked', 'voicepress' ),
			'unknownCaller'     => __( 'Unknown caller', 'voicepress' ),
			/* translators: %d: rating out of 5. */
			'rated'             => __( 'Rated %d out of 5', 'voicepress' ),
			'view'              => __( 'View', 'voicepress' ),
			/* translators: %d: attempt number. */
			'attempt'           => __( 'attempt %d', 'voicepress' ),
			'synced'            => __( 'Synced with Bolna — refreshing…', 'voicepress' ),
			'callEnded'         => __( 'Call finished — loading the transcript…', 'voicepress' ),
			/* translators: %d: number of new calls. */
			'newCalls'          => __( '%d new · refresh', 'voicepress' ),
			/* translators: %d: cart ID. */
			'cartCreated'       => __( 'Demo cart #%d created — calling now.', 'voicepress' ),
			'copied'            => __( 'Copied!', 'voicepress' ),
			'copy'              => __( 'Copy', 'voicepress' ),
			'autoRefresh'       => __( 'Live · auto-refreshing', 'voicepress' ),
			'paused'            => __( 'Paused', 'voicepress' ),
			'offline'           => __( 'Reconnecting…', 'voicepress' ),
			'justNow'           => __( 'just now', 'voicepress' ),
			/* translators: %d: minutes. */
			'minAgo'            => __( '%d min ago', 'voicepress' ),
			/* translators: %d: hours. */
			'hoursAgo'          => __( '%d h ago', 'voicepress' ),
			/* translators: %d: days. */
			'daysAgo'           => __( '%d d ago', 'voicepress' ),
		);
	}

	/**
	 * Add a body class on VoicePress screens (used for the page background).
	 *
	 * @param string $classes Space-separated classes.
	 * @return string
	 */
	public static function admin_body_class( $classes ) {
		if ( '' !== self::current_page() ) {
			$classes .= ' voicepress-admin';
		}
		return $classes;
	}

	/**
	 * Footer credit on VoicePress screens.
	 *
	 * @param string $text Default footer text.
	 * @return string
	 */
	public static function admin_footer_text( $text ) {
		if ( '' === self::current_page() ) {
			return $text;
		}
		$version = defined( 'VOICEPRESS_VERSION' ) ? VOICEPRESS_VERSION : '';
		return sprintf(
			/* translators: 1: plugin version, 2: link to Bolna. */
			esc_html__( 'VoicePress %1$s · AI voice agents for WooCommerce, powered by %2$s', 'voicepress' ),
			esc_html( $version ),
			'<a href="https://www.bolna.ai" target="_blank" rel="noopener noreferrer">Bolna</a>'
		);
	}

	/**
	 * Setup notices on non-VoicePress screens (VoicePress screens show inline guidance).
	 */
	public static function admin_notices() {
		if ( ! current_user_can( self::CAP ) || '' !== self::current_page() ) {
			return;
		}
		$state = self::setup_state();
		if ( ! $state['configured'] ) {
			self::print_notice(
				'warning',
				__( 'is almost ready. Add your Bolna API key to start calling customers with AI voice agents.', 'voicepress' ),
				self::page_url( self::PAGE_SETTINGS ),
				__( 'Connect Bolna', 'voicepress' )
			);
			return;
		}
		if ( $state['agents_created'] < $state['agents_total'] ) {
			self::print_notice(
				'info',
				sprintf(
					/* translators: 1: missing agent count, 2: total agent count. */
					__( 'has %1$d of %2$d AI voice agents still to create. Create them to start COD, delivery, cart recovery, feedback and support calls.', 'voicepress' ),
					$state['agents_total'] - $state['agents_created'],
					$state['agents_total']
				),
				self::page_url( self::PAGE_AGENTS ),
				__( 'Create agents', 'voicepress' )
			);
		}
	}

	/**
	 * Print a dismissible WP admin notice.
	 *
	 * @param string $type    notice type (warning|info|error|success).
	 * @param string $message Message (plain text).
	 * @param string $url     CTA URL.
	 * @param string $cta     CTA label.
	 */
	private static function print_notice( $type, $message, $url, $cta ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible vp-wp-notice"><p><strong>%2$s</strong> %3$s <a class="button button-primary" href="%4$s">%5$s</a></p></div>',
			esc_attr( $type ),
			esc_html__( 'VoicePress', 'voicepress' ),
			esc_html( $message ),
			esc_url( $url ),
			esc_html( $cta )
		);
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard screen.
	 */
	public static function render_dashboard() {
		self::guard();
		$state  = self::setup_state();
		$stats  = self::stats();
		$recent = self::list_calls( array( 'limit' => 10 ) );

		$actions  = self::live_indicator();
		$actions .= self::button(
			__( 'All calls', 'voicepress' ),
			array(
				'url'  => self::page_url( self::PAGE_CALLS ),
				'icon' => 'dashicons-list-view',
			)
		);

		self::render_header( self::PAGE_DASHBOARD, __( 'Dashboard', 'voicepress' ), __( 'Your AI voice agents at a glance: calls, outcomes and revenue protected.', 'voicepress' ), $actions );
		self::render_setup( $state );
		self::render_kpis( $stats );
		?>
		<div class="vp-grid vp-grid--main">
			<div class="vp-stack">
				<?php self::render_recent_calls( $recent, (int) $stats['total_calls'] ); ?>
			</div>
			<div class="vp-stack">
				<?php self::render_try_live( $state ); ?>
				<?php self::render_escalations(); ?>
			</div>
		</div>
		<div class="vp-grid vp-grid--3">
			<?php self::render_week_chart(); ?>
			<?php self::render_outcomes_chart( $stats ); ?>
			<?php self::render_use_case_chart( $stats ); ?>
		</div>
		<?php
		self::render_footer();
	}

	/**
	 * Setup checklist (full card while incomplete, slim banner when done).
	 *
	 * @param array $state Setup state.
	 */
	private static function render_setup( $state ) {
		$settings = self::settings();
		$host     = (string) wp_parse_url( self::public_url(), PHP_URL_HOST );
		$steps    = array(
			array(
				'done'  => $state['configured'],
				'icon'  => 'dashicons-admin-network',
				'title' => __( 'Connect Bolna', 'voicepress' ),
				/* translators: %s: masked API key. */
				'desc'  => $state['configured'] ? sprintf( __( 'API key saved (%s)', 'voicepress' ), self::mask_key( $settings['api_key'] ) ) : __( 'Add your Bolna API key to place calls.', 'voicepress' ),
				'url'   => self::page_url( self::PAGE_SETTINGS ),
				'cta'   => __( 'Add API key', 'voicepress' ),
			),
			array(
				'done'  => $state['agents_ready'],
				'icon'  => 'dashicons-groups',
				'title' => __( 'Create AI agents', 'voicepress' ),
				/* translators: 1: created agents, 2: total agents. */
				'desc'  => sprintf( __( '%1$d of %2$d agents live', 'voicepress' ), $state['agents_created'], $state['agents_total'] ),
				'url'   => self::page_url( self::PAGE_AGENTS ),
				'cta'   => __( 'Create agents', 'voicepress' ),
			),
			array(
				'done'  => $state['from_number'],
				'icon'  => 'dashicons-phone',
				'title' => __( 'Pick a caller number', 'voicepress' ),
				'desc'  => $state['from_number'] ? (string) $settings['from_number'] : __( 'Choose the Bolna number your calls come from.', 'voicepress' ),
				'url'   => self::page_url( self::PAGE_SETTINGS ) . '#vp-from-number',
				'cta'   => __( 'Choose number', 'voicepress' ),
			),
			array(
				'done'  => $state['public_url'],
				'icon'  => 'dashicons-admin-site-alt3',
				'title' => __( 'Public URL (tunnel)', 'voicepress' ),
				'desc'  => $state['public_url'] ? $host : __( 'Lets agents look up and update orders live.', 'voicepress' ),
				'url'   => self::page_url( self::PAGE_SETTINGS ) . '#vp-public-url',
				'cta'   => __( 'Add URL', 'voicepress' ),
			),
			array(
				'done'  => $state['inbound'],
				'icon'  => 'dashicons-format-chat',
				'title' => __( 'Link inbound line', 'voicepress' ),
				'desc'  => $state['inbound'] ? __( 'Customers can call your AI support line.', 'voicepress' ) : __( 'Let customers call your AI support agent 24×7.', 'voicepress' ),
				'url'   => self::page_url( self::PAGE_AGENTS ) . '#vp-inbound',
				'cta'   => __( 'Link number', 'voicepress' ),
			),
		);
		$total = count( $steps );
		$done  = 0;
		foreach ( $steps as $step ) {
			$done += $step['done'] ? 1 : 0;
		}

		if ( $done === $total ) {
			?>
			<div class="vp-setup vp-setup--done">
				<span class="vp-setup__badge dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<p><strong><?php esc_html_e( 'You’re live.', 'voicepress' ); ?></strong> <?php esc_html_e( 'All setup steps are complete — your agents are ready to call customers and answer your support line.', 'voicepress' ); ?></p>
			</div>
			<?php
			return;
		}
		$pct = round( $done / $total * 100 );
		?>
		<section class="vp-card vp-setup" aria-labelledby="vp-setup-title">
			<div class="vp-setup__head">
				<div>
					<h2 class="vp-card__title" id="vp-setup-title"><?php esc_html_e( 'Finish setting up VoicePress', 'voicepress' ); ?></h2>
					<p class="vp-card__sub">
						<?php
						/* translators: 1: completed steps, 2: total steps. */
						echo esc_html( sprintf( __( '%1$d of %2$d steps complete — about 5 minutes to go live.', 'voicepress' ), $done, $total ) );
						?>
					</p>
				</div>
				<div class="vp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $pct ); ?>">
					<span style="width:<?php echo esc_attr( $pct ); ?>%"></span>
				</div>
			</div>
			<ol class="vp-steps">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li class="vp-step<?php echo $step['done'] ? ' is-done' : ''; ?>">
						<span class="vp-step__icon" aria-hidden="true">
							<?php if ( $step['done'] ) : ?>
								<span class="dashicons dashicons-yes"></span>
							<?php else : ?>
								<span class="dashicons <?php echo esc_attr( $step['icon'] ); ?>"></span>
							<?php endif; ?>
						</span>
						<span class="vp-step__text">
							<span class="vp-step__title">
								<span class="screen-reader-text"><?php echo $step['done'] ? esc_html__( 'Done:', 'voicepress' ) : esc_html__( 'To do:', 'voicepress' ); ?></span>
								<?php echo esc_html( $step['title'] ); ?>
							</span>
							<span class="vp-step__desc"><?php echo esc_html( $step['desc'] ); ?></span>
						</span>
						<?php if ( ! $step['done'] ) : ?>
							<a class="vp-btn vp-btn--sm vp-btn--secondary vp-step__cta" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['cta'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	/**
	 * KPI stat tiles.
	 *
	 * @param array $stats DB stats.
	 */
	private static function render_kpis( $stats ) {
		$kpis = array(
			array(
				'key'      => 'calls_today',
				'label'    => __( 'Calls today', 'voicepress' ),
				'format'   => 'int',
				'icon'     => 'dashicons-phone',
				'tone'     => 'brand',
				'sub_key'  => 'total_calls',
				'sub_text' => __( 'calls all time', 'voicepress' ),
			),
			array(
				'key'      => 'connect_rate',
				'label'    => __( 'Connect rate', 'voicepress' ),
				'format'   => 'percent',
				'icon'     => 'dashicons-performance',
				'tone'     => 'brand',
				'sub_key'  => 'connected',
				'sub_text' => __( 'calls answered', 'voicepress' ),
				'meter'    => true,
			),
			array(
				'key'      => 'cod_confirmed',
				'label'    => __( 'COD confirmed', 'voicepress' ),
				'format'   => 'int',
				'icon'     => 'dashicons-yes-alt',
				'tone'     => 'success',
				'sub_key'  => 'cod_cancelled',
				'sub_text' => __( 'cancelled before shipping', 'voicepress' ),
			),
			array(
				'key'      => 'rto_saved_value',
				'label'    => __( 'RTO prevented', 'voicepress' ),
				'format'   => 'money',
				'icon'     => 'dashicons-shield-alt',
				'tone'     => 'success',
				'sub_key'  => '',
				'sub_text' => __( 'COD orders stopped before dispatch', 'voicepress' ),
			),
			array(
				'key'      => 'cart_recovered_value',
				'label'    => __( 'Carts recovered', 'voicepress' ),
				'format'   => 'money',
				'icon'     => 'dashicons-cart',
				'tone'     => 'success',
				'sub_key'  => 'carts_recovered',
				'sub_text' => __( 'carts won back by calls', 'voicepress' ),
			),
			array(
				'key'      => 'feedback_avg',
				'label'    => __( 'Avg rating', 'voicepress' ),
				'format'   => 'rating',
				'icon'     => 'dashicons-star-filled',
				'tone'     => 'amber',
				'sub_key'  => 'feedback_count',
				'sub_text' => __( 'ratings collected', 'voicepress' ),
			),
			array(
				'key'      => 'total_minutes',
				'label'    => __( 'Minutes', 'voicepress' ),
				'format'   => 'minutes',
				'icon'     => 'dashicons-clock',
				'tone'     => 'brand',
				'sub_key'  => '',
				'sub_text' => __( 'talk time with customers', 'voicepress' ),
			),
			array(
				'key'      => 'total_cost',
				'label'    => __( 'Spend', 'voicepress' ),
				'format'   => 'usd_cents',
				'icon'     => 'dashicons-money-alt',
				'tone'     => 'brand',
				'sub_key'  => '',
				'sub_text' => __( 'Bolna usage to date', 'voicepress' ),
			),
		);
		?>
		<div class="vp-grid vp-grid--kpis" data-vp-kpis>
			<?php foreach ( $kpis as $kpi ) : ?>
				<div class="vp-kpi">
					<div class="vp-kpi__top">
						<span class="vp-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></span>
						<span class="vp-kpi__icon vp-kpi__icon--<?php echo esc_attr( $kpi['tone'] ); ?>" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $kpi['icon'] ); ?>"></span></span>
					</div>
					<div class="vp-kpi__value" data-vp-kpi="<?php echo esc_attr( $kpi['key'] ); ?>" data-vp-format="<?php echo esc_attr( $kpi['format'] ); ?>" data-vp-raw="<?php echo esc_attr( self::raw_stat( $stats[ $kpi['key'] ] ) ); ?>"><?php echo esc_html( self::format_kpi( $kpi['format'], $stats[ $kpi['key'] ], $stats ) ); ?></div>
					<?php if ( ! empty( $kpi['meter'] ) ) : ?>
						<div class="vp-meter" aria-hidden="true"><span data-vp-meter="<?php echo esc_attr( $kpi['key'] ); ?>" style="width:<?php echo esc_attr( max( 0, min( 100, (int) $stats[ $kpi['key'] ] ) ) ); ?>%"></span></div>
					<?php endif; ?>
					<div class="vp-kpi__sub">
						<?php if ( '' !== $kpi['sub_key'] ) : ?>
							<span class="vp-kpi__subvalue" data-vp-kpi="<?php echo esc_attr( $kpi['sub_key'] ); ?>" data-vp-format="int" data-vp-raw="<?php echo esc_attr( self::raw_stat( $stats[ $kpi['sub_key'] ] ) ); ?>"><?php echo esc_html( self::format_kpi( 'int', $stats[ $kpi['sub_key'] ], $stats ) ); ?></span>
						<?php endif; ?>
						<?php echo esc_html( $kpi['sub_text'] ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Format a KPI value (mirrored in admin.js).
	 *
	 * @param string $format Format key.
	 * @param mixed  $value  Raw value.
	 * @param array  $stats  All stats.
	 * @return string
	 */
	private static function format_kpi( $format, $value, $stats ) {
		switch ( $format ) {
			case 'percent':
				return (int) round( (float) $value ) . '%';
			case 'money':
				return self::money( (float) $value );
			case 'rating':
				return ( (int) $stats['feedback_count'] > 0 && (float) $value > 0 ) ? number_format_i18n( (float) $value, 1 ) . ' ★' : '—';
			case 'minutes':
				return number_format_i18n( (float) $value, (float) $value < 100 ? 1 : 0 );
			case 'usd_cents':
				return '$' . number_format_i18n( (float) $value / 100, 2 );
			default:
				return number_format_i18n( (int) $value );
		}
	}

	/**
	 * Raw stat value as admin.js sees it (String() of the JSON value).
	 *
	 * @param mixed $value Stat value.
	 * @return string
	 */
	private static function raw_stat( $value ) {
		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < PHP_INT_MAX ) {
			return (string) (int) $value;
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Recent calls card.
	 *
	 * @param array $calls Call rows.
	 * @param int   $total Total calls (for "new calls" detection).
	 */
	private static function render_recent_calls( $calls, $total ) {
		?>
		<section class="vp-card" data-vp-total="<?php echo esc_attr( $total ); ?>">
			<div class="vp-card__head">
				<div>
					<h2 class="vp-card__title"><?php esc_html_e( 'Recent calls', 'voicepress' ); ?></h2>
					<p class="vp-card__sub"><?php esc_html_e( 'Statuses update live while this page is open.', 'voicepress' ); ?></p>
				</div>
				<div class="vp-card__tools">
					<button type="button" class="vp-btn vp-btn--sm vp-btn--soft" data-vp-new-calls hidden></button>
					<a class="vp-link" href="<?php echo esc_url( self::page_url( self::PAGE_CALLS ) ); ?>"><?php esc_html_e( 'View all', 'voicepress' ); ?> <span aria-hidden="true">→</span></a>
				</div>
			</div>
			<div class="vp-table-wrap" data-vp-recent-table<?php echo $calls ? '' : ' hidden'; ?>>
				<table class="vp-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Customer', 'voicepress' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Use case', 'voicepress' ); ?></th>
							<th scope="col" class="vp-hide-sm"><?php esc_html_e( 'Order', 'voicepress' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'voicepress' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Outcome', 'voicepress' ); ?></th>
							<th scope="col" class="vp-num vp-hide-sm"><?php esc_html_e( 'Duration', 'voicepress' ); ?></th>
							<th scope="col" class="vp-num"><?php esc_html_e( 'When', 'voicepress' ); ?></th>
						</tr>
					</thead>
					<tbody data-vp-recent>
						<?php
						foreach ( $calls as $call ) {
							self::render_recent_row( $call );
						}
						?>
					</tbody>
				</table>
			</div>
			<?php
			if ( ! $calls ) {
				self::empty_state(
					'dashicons-phone',
					__( 'No calls yet', 'voicepress' ),
					__( 'Place a test call from “Try it live” to hear your AI agent — every call shows up here with its status, outcome and transcript.', 'voicepress' ),
					array(),
					'data-vp-recent-empty'
				);
			}
			?>
		</section>
		<?php
	}

	/**
	 * One row of the dashboard "Recent calls" table (mirrored by admin.js renderRecentRow).
	 *
	 * @param array $call Call row.
	 */
	private static function render_recent_row( $call ) {
		$call = self::normalize_call( $call );
		$url  = self::call_url( $call['id'] );
		?>
		<tr data-vp-call="<?php echo esc_attr( $call['id'] ); ?>" data-vp-status="<?php echo esc_attr( $call['status'] ); ?>" data-href="<?php echo esc_url( $url ); ?>">
			<td><a class="vp-row-link" href="<?php echo esc_url( $url ); ?>"><?php echo self::person_html( $call ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></a></td>
			<td><?php echo self::use_case_html( $call['use_case'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
			<td class="vp-hide-sm"><?php echo self::order_link( $call['order_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
			<td><?php echo self::status_badge( $call['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
			<td><?php echo self::outcome_html( $call ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
			<td class="vp-num vp-hide-sm" data-vp-field="duration"><?php echo esc_html( self::duration( $call['duration'] ) ); ?></td>
			<td class="vp-num vp-nowrap" title="<?php echo esc_attr( self::local_time( $call['created_at'] ) ); ?>"><?php echo esc_html( self::human_time( $call['created_at'] ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * "Try it live" test-call card.
	 *
	 * @param array $state Setup state.
	 */
	private static function render_try_live( $state ) {
		$hints = array(
			'cod_confirmation'  => __( 'Calls you about your latest order (or the order ID below) to confirm cash on delivery.', 'voicepress' ),
			'delivery_reminder' => __( 'An “out for delivery today” call — the customer can reschedule or change the address.', 'voicepress' ),
			'ndr_resolution'    => __( 'A failed-delivery (NDR) rescue call to fix the address or pick a new slot.', 'voicepress' ),
			'cart_recovery'     => __( 'Creates a demo cart with 2 products and calls with a one-time discount code.', 'voicepress' ),
			'feedback'          => __( 'Asks how the order was and records a 1–5 star rating.', 'voicepress' ),
			'support_callback'  => __( 'Your 24×7 support agent calls you back and can look up and act on orders.', 'voicepress' ),
		);
		$first = self::TEST_USE_CASES[0];
		?>
		<section class="vp-card vp-try" id="vp-try">
			<div class="vp-card__head">
				<div>
					<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-microphone" aria-hidden="true"></span><?php esc_html_e( 'Try it live', 'voicepress' ); ?></h2>
					<p class="vp-card__sub"><?php esc_html_e( 'Get a real call from your AI agent in seconds.', 'voicepress' ); ?></p>
				</div>
			</div>
			<div class="vp-card__body">
				<?php
				if ( ! $state['configured'] ) {
					self::alert( 'warning', __( 'Connect your Bolna account first.', 'voicepress' ), self::page_url( self::PAGE_SETTINGS ), __( 'Open settings', 'voicepress' ) );
				} elseif ( $state['agents_created'] < 1 ) {
					self::alert( 'info', __( 'Create your AI agents to place test calls.', 'voicepress' ), self::page_url( self::PAGE_AGENTS ), __( 'Create agents', 'voicepress' ) );
				}
				?>
				<form id="vp-test-call-form" class="vp-form" novalidate>
					<fieldset class="vp-scenarios">
						<legend class="vp-label"><?php esc_html_e( 'Scenario', 'voicepress' ); ?></legend>
						<?php foreach ( self::TEST_USE_CASES as $use_case ) : ?>
							<label class="vp-scenario">
								<input type="radio" name="use_case" value="<?php echo esc_attr( $use_case ); ?>" data-hint="<?php echo esc_attr( $hints[ $use_case ] ); ?>" data-needs-order="<?php echo in_array( $use_case, self::ORDER_USE_CASES, true ) ? '1' : '0'; ?>"<?php checked( $first, $use_case ); ?>>
								<span class="vp-scenario__inner">
									<span class="dashicons <?php echo esc_attr( self::use_case_icon( $use_case ) ); ?>" aria-hidden="true"></span>
									<span class="vp-scenario__label"><?php echo esc_html( self::use_case_label( $use_case ) ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="vp-help vp-scenario-hint" data-vp-scenario-hint><?php echo esc_html( $hints[ $first ] ); ?></p>
					<div class="vp-form-grid">
						<div class="vp-field">
							<label class="vp-label" for="vp-test-phone"><?php esc_html_e( 'Your phone number', 'voicepress' ); ?></label>
							<input type="tel" id="vp-test-phone" name="phone" class="vp-input" placeholder="+91 98765 43210" autocomplete="tel" required>
						</div>
						<div class="vp-field" data-vp-order-field>
							<label class="vp-label" for="vp-test-order"><?php esc_html_e( 'Order ID', 'voicepress' ); ?> <span class="vp-optional"><?php esc_html_e( 'optional', 'voicepress' ); ?></span></label>
							<input type="number" id="vp-test-order" name="order_id" class="vp-input" min="1" step="1" placeholder="<?php esc_attr_e( 'Latest order', 'voicepress' ); ?>">
						</div>
					</div>
					<button type="submit" class="vp-btn vp-btn--primary vp-btn--lg vp-btn--block">
						<span class="dashicons dashicons-phone" aria-hidden="true"></span>
						<span class="vp-btn__label"><?php esc_html_e( 'Call me now', 'voicepress' ); ?></span>
					</button>
					<div class="vp-result" role="status" aria-live="polite"></div>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Escalations card: tickets raised on calls + orders flagged for follow-up.
	 */
	private static function render_escalations() {
		$tickets = get_option( 'voicepress_tickets', array() );
		$tickets = is_array( $tickets ) ? array_slice( $tickets, 0, 5 ) : array();
		$orders  = self::escalated_orders( 5 );
		$count   = count( $tickets ) + count( $orders );
		?>
		<section class="vp-card">
			<div class="vp-card__head">
				<div>
					<h2 class="vp-card__title"><?php esc_html_e( 'Escalations', 'voicepress' ); ?></h2>
					<p class="vp-card__sub"><?php esc_html_e( 'Tickets raised on calls and unhappy customers to follow up.', 'voicepress' ); ?></p>
				</div>
				<?php
				if ( $count ) {
					echo self::badge( number_format_i18n( $count ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
				}
				?>
			</div>
			<?php if ( ! $count ) : ?>
				<div class="vp-card__body">
					<div class="vp-empty vp-empty--compact">
						<span class="vp-empty__icon vp-empty__icon--success" aria-hidden="true"><span class="dashicons dashicons-thumbs-up"></span></span>
						<p class="vp-empty__title"><?php esc_html_e( 'All quiet', 'voicepress' ); ?></p>
						<p class="vp-empty__text"><?php esc_html_e( 'No tickets or low ratings to follow up. Escalations from support and feedback calls land here.', 'voicepress' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<ul class="vp-list">
					<?php
					foreach ( $tickets as $ticket ) {
						$t     = self::normalize_ticket( $ticket );
						$tone  = 'high' === $t['priority'] ? 'danger' : ( 'low' === $t['priority'] ? 'neutral' : 'info' );
						$meta  = array();
						$meta[] = self::badge( '' !== $t['priority'] ? ucfirst( $t['priority'] ) : __( 'Normal', 'voicepress' ), $tone );
						if ( $t['order_id'] ) {
							$meta[] = self::order_link( $t['order_id'] );
						}
						if ( '' !== $t['phone'] ) {
							$meta[] = '<span class="vp-mono">' . esc_html( $t['phone'] ) . '</span>';
						}
						if ( '' !== $t['when'] ) {
							$meta[] = '<span>' . esc_html( $t['when'] ) . '</span>';
						}
						?>
						<li class="vp-list__item">
							<span class="vp-list__icon vp-list__icon--danger" aria-hidden="true"><span class="dashicons dashicons-sos"></span></span>
							<div class="vp-list__main">
								<p class="vp-list__title"><?php echo esc_html( '' !== $t['summary'] ? $t['summary'] : __( 'Support ticket', 'voicepress' ) ); ?></p>
								<p class="vp-list__meta"><?php echo implode( '<span class="vp-dot-sep" aria-hidden="true">·</span>', $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></p>
							</div>
						</li>
						<?php
					}
					foreach ( $orders as $order ) {
						$rating = (int) $order->get_meta( '_voicepress_rating' );
						$reason = $order->get_meta( '_voicepress_escalation' );
						$reason = is_scalar( $reason ) ? trim( (string) $reason ) : '';
						if ( in_array( strtolower( $reason ), array( '1', 'yes', 'true' ), true ) ) {
							$reason = '';
						}
						$name    = trim( $order->get_formatted_billing_full_name() );
						$created = $order->get_date_created();
						?>
						<li class="vp-list__item">
							<span class="vp-list__icon vp-list__icon--amber" aria-hidden="true"><span class="dashicons dashicons-star-half"></span></span>
							<div class="vp-list__main">
								<p class="vp-list__title">
									<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
										<?php
										/* translators: %s: order number. */
										echo esc_html( sprintf( __( 'Order #%s', 'voicepress' ), $order->get_order_number() ) );
										?>
									</a>
									<?php echo '' !== $name ? ' · ' . esc_html( $name ) : ''; ?>
								</p>
								<p class="vp-list__meta">
									<?php
									echo $rating ? self::stars( $rating ) : self::badge( __( 'Needs follow-up', 'voicepress' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builders.
									if ( '' !== $reason ) {
										echo '<span class="vp-dot-sep" aria-hidden="true">·</span><span>' . esc_html( wp_trim_words( $reason, 18 ) ) . '</span>';
									}
									if ( $created ) {
										echo '<span class="vp-dot-sep" aria-hidden="true">·</span><span>' . esc_html( $created->date_i18n( 'j M' ) ) . '</span>';
									}
									?>
								</p>
							</div>
						</li>
						<?php
					}
					?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Calls per day for the last N days (local time).
	 *
	 * @param int $n Days.
	 * @return array[] Oldest first: label, full, count.
	 */
	private static function calls_by_day( $n = 7 ) {
		$days = array();
		try {
			$today = new DateTime( 'now', wp_timezone() );
			$today->setTime( 0, 0, 0 );
			for ( $i = $n - 1; $i >= 0; $i-- ) {
				$day = clone $today;
				if ( $i > 0 ) {
					$day->modify( '-' . $i . ' days' );
				}
				$days[ $day->format( 'Y-m-d' ) ] = array(
					'label' => wp_date( 'D', $day->getTimestamp() ),
					'full'  => wp_date( 'D, j M', $day->getTimestamp() ),
					'count' => 0,
				);
			}
			$start = clone $today;
			$start->modify( '-' . ( $n - 1 ) . ' days' );
			$start->setTimezone( new DateTimeZone( 'UTC' ) );
			$since = $start->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return array();
		}

		$rows = self::safe(
			function () use ( $since ) {
				global $wpdb;
				$table    = VoicePress_DB::calls_table();
				$suppress = $wpdb->suppress_errors( true );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is internal.
				$result = $wpdb->get_col( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE created_at >= %s ORDER BY id DESC LIMIT 5000", $since ) );
				$wpdb->suppress_errors( $suppress );
				return $result;
			},
			array()
		);
		foreach ( (array) $rows as $created ) {
			$key = get_date_from_gmt( (string) $created, 'Y-m-d' );
			if ( isset( $days[ $key ] ) ) {
				++$days[ $key ]['count'];
			}
		}
		return array_values( $days );
	}

	/**
	 * Column chart: calls in the last 7 days (today emphasised).
	 */
	private static function render_week_chart() {
		$days = self::calls_by_day( 7 );
		$max  = 0;
		$sum  = 0;
		foreach ( $days as $day ) {
			$max  = max( $max, $day['count'] );
			$sum += $day['count'];
		}
		$last = count( $days ) - 1;
		?>
		<section class="vp-card">
			<div class="vp-card__head">
				<div>
					<h2 class="vp-card__title"><?php esc_html_e( 'Calls · last 7 days', 'voicepress' ); ?></h2>
					<p class="vp-card__sub">
						<?php
						/* translators: %s: number of calls. */
						echo esc_html( sprintf( _n( '%s call this week', '%s calls this week', $sum, 'voicepress' ), number_format_i18n( $sum ) ) );
						?>
					</p>
				</div>
			</div>
			<div class="vp-card__body">
				<?php if ( ! $days ) : ?>
					<p class="vp-muted"><?php esc_html_e( 'No data yet.', 'voicepress' ); ?></p>
				<?php else : ?>
					<div class="vp-cols" role="list" aria-label="<?php esc_attr_e( 'Calls per day, last 7 days', 'voicepress' ); ?>">
						<?php
						foreach ( $days as $i => $day ) :
							$pct      = $max > 0 ? $day['count'] / $max * 100 : 0;
							$is_today = ( $i === $last );
							$show     = $day['count'] > 0 && ( $is_today || $day['count'] === $max );
							/* translators: 1: number of calls, 2: day. */
							$tip = sprintf( _n( '%1$s call · %2$s', '%1$s calls · %2$s', $day['count'], 'voicepress' ), number_format_i18n( $day['count'] ), $day['full'] );
							?>
							<div class="vp-cols__col<?php echo $is_today ? ' is-today' : ''; ?>" role="listitem" tabindex="0" aria-label="<?php echo esc_attr( $tip ); ?>" data-vp-tip="<?php echo esc_attr( $tip ); ?>">
								<div class="vp-cols__plot">
									<span class="vp-cols__bar<?php echo 0 === $day['count'] ? ' is-zero' : ''; ?>" style="height:<?php echo esc_attr( number_format( $pct, 1, '.', '' ) ); ?>%">
										<?php if ( $show ) : ?>
											<span class="vp-cols__value"><?php echo esc_html( number_format_i18n( $day['count'] ) ); ?></span>
										<?php endif; ?>
									</span>
								</div>
								<span class="vp-cols__label"><?php echo esc_html( $is_today ? __( 'Today', 'voicepress' ) : $day['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Outcomes breakdown (horizontal bars, coloured by meaning).
	 *
	 * @param array $stats DB stats.
	 */
	private static function render_outcomes_chart( $stats ) {
		$outcomes = is_array( $stats['outcomes'] ) ? $stats['outcomes'] : array();
		arsort( $outcomes );
		$items = array();
		$other = 0;
		foreach ( $outcomes as $key => $count ) {
			$count = (int) $count;
			if ( $count <= 0 ) {
				continue;
			}
			if ( count( $items ) < 6 ) {
				$items[] = array(
					'label' => self::outcome_label( $key ),
					'value' => $count,
					'tone'  => self::outcome_tone( $key ),
				);
			} else {
				$other += $count;
			}
		}
		if ( $other > 0 ) {
			$items[] = array(
				'label' => __( 'Other', 'voicepress' ),
				'value' => $other,
				'tone'  => 'neutral',
			);
		}
		?>
		<section class="vp-card">
			<div class="vp-card__head">
				<div>
					<h2 class="vp-card__title"><?php esc_html_e( 'Call outcomes', 'voicepress' ); ?></h2>
					<p class="vp-card__sub"><?php esc_html_e( 'What customers decided on the call.', 'voicepress' ); ?></p>
				</div>
			</div>
			<div class="vp-card__body">
				<?php
				if ( $items ) {
					self::bar_list( $items );
				} else {
					echo '<p class="vp-chart-empty">' . esc_html__( 'Outcomes appear after your first completed call.', 'voicepress' ) . '</p>';
				}
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Calls by use case (horizontal bars, single brand hue).
	 *
	 * @param array $stats DB stats.
	 */
	private static function render_use_case_chart( $stats ) {
		$by    = is_array( $stats['by_use_case'] ) ? $stats['by_use_case'] : array();
		$items = array();
		arsort( $by );
		foreach ( $by as $use_case => $count ) {
			if ( (int) $count > 0 ) {
				$items[] = array(
					'label' => self::use_case_label( $use_case ),
					'value' => (int) $count,
					'tone'  => 'brand',
				);
			}
		}
		?>
		<section class="vp-card">
			<div class="vp-card__head">
				<div>
					<h2 class="vp-card__title"><?php esc_html_e( 'Calls by use case', 'voicepress' ); ?></h2>
					<p class="vp-card__sub"><?php esc_html_e( 'Where your agents spend their time.', 'voicepress' ); ?></p>
				</div>
			</div>
			<div class="vp-card__body">
				<?php
				if ( $items ) {
					self::bar_list( array_slice( $items, 0, 8 ) );
				} else {
					echo '<p class="vp-chart-empty">' . esc_html__( 'No calls yet — automations and test calls show up here.', 'voicepress' ) . '</p>';
				}
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Horizontal bar list.
	 *
	 * @param array[] $items label, value, tone.
	 */
	private static function bar_list( $items ) {
		$max   = 0;
		$total = 0;
		foreach ( $items as $item ) {
			$max    = max( $max, $item['value'] );
			$total += $item['value'];
		}
		echo '<ul class="vp-bars">';
		foreach ( $items as $item ) {
			$pct   = $max > 0 ? $item['value'] / $max * 100 : 0;
			$share = $total > 0 ? round( $item['value'] / $total * 100 ) : 0;
			printf(
				'<li class="vp-bars__row"><span class="vp-bars__label">%1$s</span><span class="vp-bars__track"><span class="vp-bars__fill vp-fill--%2$s" style="width:%3$s%%"></span></span><span class="vp-bars__value">%4$s <span class="vp-bars__share">%5$s%%</span></span></li>',
				esc_html( $item['label'] ),
				esc_attr( $item['tone'] ),
				esc_attr( number_format( max( 2, $pct ), 1, '.', '' ) ),
				esc_html( number_format_i18n( $item['value'] ) ),
				esc_html( $share )
			);
		}
		echo '</ul>';
	}

	/* ---------------------------------------------------------------------
	 * Calls
	 * ------------------------------------------------------------------ */

	/**
	 * Calls screen (list or detail with &call=ID).
	 */
	public static function render_calls() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$call_id = isset( $_GET['call'] ) ? absint( $_GET['call'] ) : 0;
		if ( $call_id ) {
			self::render_call_detail( $call_id );
			return;
		}
		$use_case = isset( $_GET['use_case'] ) ? sanitize_key( wp_unslash( $_GET['use_case'] ) ) : '';
		$group    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$labels = self::use_case_labels();
		$groups = self::status_groups();
		$args   = array();
		if ( '' !== $use_case && isset( $labels[ $use_case ] ) ) {
			$args['use_case'] = $use_case;
		} else {
			$use_case = '';
		}
		if ( '' !== $group && isset( $groups[ $group ] ) ) {
			$args['status'] = $groups[ $group ]['statuses'];
		} else {
			$group = '';
		}
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		$filtered = ! empty( $args );
		$total    = self::count_calls( $args );
		$pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged    = min( $paged, $pages );
		$calls    = self::list_calls(
			array_merge(
				$args,
				array(
					'limit'  => self::PER_PAGE,
					'offset' => ( $paged - 1 ) * self::PER_PAGE,
				)
			)
		);
		$stats    = self::stats();

		$actions = self::live_indicator() . self::button(
			__( 'Place a test call', 'voicepress' ),
			array(
				'url'   => self::page_url( self::PAGE_DASHBOARD ) . '#vp-try',
				'icon'  => 'dashicons-phone',
				'class' => 'vp-btn--primary',
			)
		);
		self::render_header( self::PAGE_CALLS, __( 'Calls', 'voicepress' ), __( 'Every AI call with status, outcome, recording and transcript.', 'voicepress' ), $actions );
		?>
		<div class="vp-statline">
			<?php
			$statline = array(
				array( 'total_calls', 'int', __( 'calls', 'voicepress' ) ),
				array( 'connect_rate', 'percent', __( 'connect rate', 'voicepress' ) ),
				array( 'total_minutes', 'minutes', __( 'minutes', 'voicepress' ) ),
				array( 'total_cost', 'usd_cents', __( 'spend', 'voicepress' ) ),
			);
			foreach ( $statline as $item ) :
				?>
				<span class="vp-statline__item"><strong data-vp-kpi="<?php echo esc_attr( $item[0] ); ?>" data-vp-format="<?php echo esc_attr( $item[1] ); ?>" data-vp-raw="<?php echo esc_attr( self::raw_stat( $stats[ $item[0] ] ) ); ?>"><?php echo esc_html( self::format_kpi( $item[1], $stats[ $item[0] ], $stats ) ); ?></strong> <?php echo esc_html( $item[2] ); ?></span>
			<?php endforeach; ?>
		</div>

		<section class="vp-card" data-vp-total="<?php echo esc_attr( (int) $stats['total_calls'] ); ?>">
			<form class="vp-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_CALLS ); ?>">
				<label class="screen-reader-text" for="vp-filter-use-case"><?php esc_html_e( 'Use case', 'voicepress' ); ?></label>
				<select id="vp-filter-use-case" name="use_case" class="vp-input vp-input--sm">
					<option value=""><?php esc_html_e( 'All use cases', 'voicepress' ); ?></option>
					<?php foreach ( $labels as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $use_case, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="vp-filter-status"><?php esc_html_e( 'Status', 'voicepress' ); ?></label>
				<select id="vp-filter-status" name="status" class="vp-input vp-input--sm">
					<option value=""><?php esc_html_e( 'All statuses', 'voicepress' ); ?></option>
					<?php foreach ( $groups as $key => $info ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $group, $key ); ?>><?php echo esc_html( $info['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="vp-filter-search"><?php esc_html_e( 'Search calls', 'voicepress' ); ?></label>
				<span class="vp-search">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" id="vp-filter-search" name="search" class="vp-input vp-input--sm" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, phone or order #…', 'voicepress' ); ?>">
				</span>
				<button type="submit" class="vp-btn vp-btn--secondary vp-btn--sm"><?php esc_html_e( 'Filter', 'voicepress' ); ?></button>
				<?php if ( $filtered ) : ?>
					<a class="vp-link" href="<?php echo esc_url( self::page_url( self::PAGE_CALLS ) ); ?>"><?php esc_html_e( 'Clear', 'voicepress' ); ?></a>
				<?php endif; ?>
				<span class="vp-filters__spacer"></span>
				<button type="button" class="vp-btn vp-btn--sm vp-btn--soft" data-vp-new-calls hidden></button>
			</form>

			<?php if ( $calls ) : ?>
				<div class="vp-table-wrap">
					<table class="vp-table vp-table--calls">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Time', 'voicepress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Customer', 'voicepress' ); ?></th>
								<th scope="col" class="vp-hide-sm"><?php esc_html_e( 'Phone', 'voicepress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Use case', 'voicepress' ); ?></th>
								<th scope="col" class="vp-hide-sm"><?php esc_html_e( 'Order', 'voicepress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'voicepress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Outcome', 'voicepress' ); ?></th>
								<th scope="col" class="vp-num vp-hide-sm"><?php esc_html_e( 'Duration', 'voicepress' ); ?></th>
								<th scope="col" class="vp-num vp-hide-sm"><?php esc_html_e( 'Cost', 'voicepress' ); ?></th>
								<th scope="col" class="vp-col-go"><span class="screen-reader-text"><?php esc_html_e( 'Details', 'voicepress' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $calls as $call ) :
								$call = self::normalize_call( $call );
								$url  = self::call_url( $call['id'] );
								?>
								<tr data-vp-call="<?php echo esc_attr( $call['id'] ); ?>" data-vp-status="<?php echo esc_attr( $call['status'] ); ?>" data-href="<?php echo esc_url( $url ); ?>">
									<td class="vp-nowrap">
										<a class="vp-row-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( self::local_time( $call['created_at'], 'j M, g:i a' ) ); ?></a>
										<span class="vp-cell-sub"><?php echo esc_html( self::human_time( $call['created_at'] ) ); ?></span>
									</td>
									<td><?php echo self::person_html( $call, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
									<td class="vp-mono vp-nowrap vp-hide-sm"><?php echo esc_html( '' !== $call['phone'] ? $call['phone'] : '—' ); ?></td>
									<td><?php echo self::use_case_html( $call['use_case'], $call['direction'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
									<td class="vp-hide-sm"><?php echo self::order_link( $call['order_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
									<td><?php echo self::status_badge( $call['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
									<td><?php echo self::outcome_html( $call ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></td>
									<td class="vp-num vp-hide-sm" data-vp-field="duration"><?php echo esc_html( self::duration( $call['duration'] ) ); ?></td>
									<td class="vp-num vp-hide-sm" data-vp-field="cost"><?php echo esc_html( self::cost( $call['cost'] ) ); ?></td>
									<td class="vp-col-go"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="vp-pagination">
					<span class="vp-muted">
						<?php
						$from = ( $paged - 1 ) * self::PER_PAGE + 1;
						$to   = min( $total, $paged * self::PER_PAGE );
						/* translators: 1: first row, 2: last row, 3: total rows. */
						echo esc_html( sprintf( __( 'Showing %1$s–%2$s of %3$s', 'voicepress' ), number_format_i18n( $from ), number_format_i18n( $to ), number_format_i18n( $total ) ) );
						?>
					</span>
					<?php
					if ( $pages > 1 ) {
						$links = paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '‹ ' . __( 'Prev', 'voicepress' ),
								'next_text' => __( 'Next', 'voicepress' ) . ' ›',
								'mid_size'  => 1,
							)
						);
						echo '<nav class="vp-pages" aria-label="' . esc_attr__( 'Calls pagination', 'voicepress' ) . '">' . wp_kses_post( (string) $links ) . '</nav>';
					}
					?>
				</div>
			<?php elseif ( $filtered ) : ?>
				<?php
				self::empty_state(
					'dashicons-search',
					__( 'No calls match these filters', 'voicepress' ),
					__( 'Try another use case or status, or clear the search.', 'voicepress' ),
					array(
						array(
							'label' => __( 'Clear filters', 'voicepress' ),
							'url'   => self::page_url( self::PAGE_CALLS ),
							'class' => 'vp-btn--secondary',
						),
					)
				);
				?>
			<?php else : ?>
				<?php
				self::empty_state(
					'dashicons-phone',
					__( 'No calls yet', 'voicepress' ),
					__( 'Calls appear here as soon as automations run or you place a test call — with live status, recording, transcript and the outcome applied to the order.', 'voicepress' ),
					array(
						array(
							'label' => __( 'Place a test call', 'voicepress' ),
							'url'   => self::page_url( self::PAGE_DASHBOARD ) . '#vp-try',
							'class' => 'vp-btn--primary',
						),
						array(
							'label' => __( 'Set up automations', 'voicepress' ),
							'url'   => self::page_url( self::PAGE_AUTOMATIONS ),
							'class' => 'vp-btn--secondary',
						),
					)
				);
				?>
			<?php endif; ?>
		</section>
		<?php
		self::render_footer();
	}

	/**
	 * Single call detail view.
	 *
	 * @param int $id Call row ID.
	 */
	private static function render_call_detail( $id ) {
		$raw  = self::get_call( $id );
		$back = self::button(
			__( 'All calls', 'voicepress' ),
			array(
				'url'  => self::page_url( self::PAGE_CALLS ),
				'icon' => 'dashicons-arrow-left-alt',
			)
		);

		if ( ! $raw ) {
			self::render_header( self::PAGE_CALLS, __( 'Call not found', 'voicepress' ), '', $back );
			echo '<section class="vp-card">';
			self::empty_state(
				'dashicons-search',
				__( 'We couldn’t find that call', 'voicepress' ),
				__( 'It may have been removed. Head back to the call log to see every call.', 'voicepress' ),
				array(
					array(
						'label' => __( 'Back to calls', 'voicepress' ),
						'url'   => self::page_url( self::PAGE_CALLS ),
						'class' => 'vp-btn--primary',
					),
				)
			);
			echo '</section>';
			self::render_footer();
			return;
		}

		$call      = self::normalize_call( $raw );
		$settings  = self::settings();
		$name      = $call['customer_name'];
		$phone     = $call['phone'];
		/* translators: %d: call ID. */
		$title     = '' !== $name ? $name : ( '' !== $phone ? $phone : sprintf( __( 'Call #%d', 'voicepress' ), $call['id'] ) );
		$subtitle  = implode( ' · ', array_filter( array( self::use_case_label( $call['use_case'] ), self::direction_label( $call['direction'] ), self::local_time( $call['created_at'] ) ) ) );
		$terminal  = in_array( $call['status'], self::terminal_statuses(), true );
		$actions   = $back;
		if ( '' !== $call['execution_id'] ) {
			$actions .= self::button(
				__( 'Sync now', 'voicepress' ),
				array(
					'class' => 'vp-btn--primary vp-sync-call',
					'icon'  => 'dashicons-update',
					'attrs' => array( 'data-call' => $call['id'] ),
				)
			);
		}
		self::render_header( self::PAGE_CALLS, $title, $subtitle, $actions );

		$turns      = self::parse_transcript( $call['transcript'] );
		$persona    = (string) $settings['agent_persona'];
		$agent_name = self::agent_name_for_id( $call['agent_id'] );
		$summary    = trim( $call['summary'] );
		?>
		<div class="vp-call-detail" data-vp-call="<?php echo esc_attr( $call['id'] ); ?>" data-vp-status="<?php echo esc_attr( $call['status'] ); ?>" data-vp-detail="1">
			<div class="vp-result" data-vp-sync-result role="status" aria-live="polite"></div>
			<?php
			if ( '' !== trim( $call['error'] ) ) {
				self::alert( 'skipped' === $call['status'] ? 'info' : 'danger', $call['error'] );
			}
			?>
			<section class="vp-card vp-hero">
				<div class="vp-hero__main">
					<?php echo self::avatar_html( $title, 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
					<div class="vp-hero__who">
						<p class="vp-hero__name"><?php echo esc_html( $title ); ?></p>
						<p class="vp-hero__meta">
							<?php if ( '' !== $phone ) : ?>
								<a class="vp-mono" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
								<span class="vp-dot-sep" aria-hidden="true">·</span>
							<?php endif; ?>
							<?php echo self::use_case_html( $call['use_case'], $call['direction'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
						</p>
					</div>
					<div class="vp-hero__badges">
						<?php echo self::status_badge( $call['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
						<?php echo self::outcome_html( $call ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
					</div>
				</div>
				<dl class="vp-hero__facts">
					<div class="vp-fact"><dt><?php esc_html_e( 'Duration', 'voicepress' ); ?></dt><dd data-vp-field="duration"><?php echo esc_html( self::duration( $call['duration'] ) ); ?></dd></div>
					<div class="vp-fact"><dt><?php esc_html_e( 'Cost', 'voicepress' ); ?></dt><dd data-vp-field="cost"><?php echo esc_html( self::cost( $call['cost'] ) ); ?></dd></div>
					<div class="vp-fact"><dt><?php esc_html_e( 'Order', 'voicepress' ); ?></dt><dd><?php echo self::order_link( $call['order_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?></dd></div>
					<div class="vp-fact"><dt><?php esc_html_e( 'Attempt', 'voicepress' ); ?></dt><dd><?php echo esc_html( number_format_i18n( max( 1, $call['attempt'] ) ) ); ?></dd></div>
					<div class="vp-fact"><dt><?php esc_html_e( 'Started', 'voicepress' ); ?></dt><dd title="<?php echo esc_attr( self::local_time( $call['created_at'] ) ); ?>"><?php echo esc_html( self::human_time( $call['created_at'] ) ); ?></dd></div>
				</dl>
			</section>

			<div class="vp-grid vp-grid--detail">
				<div class="vp-stack">
					<section class="vp-card">
						<div class="vp-card__head"><h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-text-page" aria-hidden="true"></span><?php esc_html_e( 'Summary', 'voicepress' ); ?></h2></div>
						<div class="vp-card__body">
							<?php if ( '' !== $summary ) : ?>
								<p class="vp-summary"><?php echo esc_html( $summary ); ?></p>
							<?php else : ?>
								<p class="vp-muted"><?php echo $terminal ? esc_html__( 'No summary for this call.', 'voicepress' ) : esc_html__( 'The AI summary appears a few seconds after the call ends.', 'voicepress' ); ?></p>
							<?php endif; ?>
						</div>
					</section>

					<section class="vp-card">
						<div class="vp-card__head">
							<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-format-chat" aria-hidden="true"></span><?php esc_html_e( 'Transcript', 'voicepress' ); ?></h2>
							<?php if ( $turns ) : ?>
								<span class="vp-muted">
									<?php
									/* translators: %s: number of messages. */
									echo esc_html( sprintf( _n( '%s message', '%s messages', count( $turns ), 'voicepress' ), number_format_i18n( count( $turns ) ) ) );
									?>
								</span>
							<?php endif; ?>
						</div>
						<div class="vp-card__body">
							<?php if ( $turns ) : ?>
								<div class="vp-chat">
									<?php
									$prev = '';
									foreach ( $turns as $turn ) :
										$role      = $turn['role'];
										$continued = ( $role === $prev );
										$prev      = $role;
										if ( 'system' === $role ) :
											?>
											<div class="vp-msg vp-msg--system"><span class="vp-msg__bubble"><?php echo esc_html( $turn['text'] ); ?></span></div>
											<?php
											continue;
										endif;
										$who = 'assistant' === $role ? ( '' !== $persona ? $persona : __( 'AI agent', 'voicepress' ) ) : ( '' !== $name ? $name : __( 'Customer', 'voicepress' ) );
										?>
										<div class="vp-msg vp-msg--<?php echo esc_attr( $role ); ?><?php echo $continued ? ' is-continued' : ''; ?>">
											<span class="vp-msg__avatar" aria-hidden="true">
												<?php if ( 'assistant' === $role ) : ?>
													<span class="dashicons dashicons-microphone"></span>
												<?php else : ?>
													<?php echo esc_html( self::initials( $who ) ); ?>
												<?php endif; ?>
											</span>
											<div class="vp-msg__body">
												<?php if ( ! $continued ) : ?>
													<span class="vp-msg__name"><?php echo esc_html( 'assistant' === $role ? $who . ' · ' . __( 'AI agent', 'voicepress' ) : $who ); ?></span>
												<?php endif; ?>
												<p class="vp-msg__bubble"><?php echo esc_html( $turn['text'] ); ?></p>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<div class="vp-empty vp-empty--compact">
									<span class="vp-empty__icon" aria-hidden="true"><span class="dashicons dashicons-format-chat"></span></span>
									<p class="vp-empty__title"><?php echo $terminal ? esc_html__( 'No transcript', 'voicepress' ) : esc_html__( 'Waiting for the conversation', 'voicepress' ); ?></p>
									<p class="vp-empty__text"><?php echo $terminal ? esc_html__( 'This call has no recorded conversation — it may not have been answered.', 'voicepress' ) : esc_html__( 'The transcript appears here when the call ends. This page refreshes automatically.', 'voicepress' ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</div>

				<div class="vp-stack">
					<?php if ( '' !== $call['recording_url'] ) : ?>
						<section class="vp-card">
							<div class="vp-card__head"><h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-controls-volumeon" aria-hidden="true"></span><?php esc_html_e( 'Recording', 'voicepress' ); ?></h2></div>
							<div class="vp-card__body">
								<audio class="vp-audio" controls preload="none" src="<?php echo esc_url( self::recording_url( $call['id'] ) ); ?>">
									<?php esc_html_e( 'Your browser cannot play this recording.', 'voicepress' ); ?>
								</audio>
							</div>
						</section>
					<?php endif; ?>

					<section class="vp-card">
						<div class="vp-card__head"><h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e( 'Call details', 'voicepress' ); ?></h2></div>
						<div class="vp-card__body">
							<dl class="vp-kv">
								<div><dt><?php esc_html_e( 'Status', 'voicepress' ); ?></dt><dd><?php echo esc_html( self::status_label( $call['status'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Use case', 'voicepress' ); ?></dt><dd><?php echo esc_html( self::use_case_label( $call['use_case'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Direction', 'voicepress' ); ?></dt><dd><?php echo esc_html( self::direction_label( $call['direction'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Agent', 'voicepress' ); ?></dt><dd><?php echo esc_html( '' !== $agent_name ? $agent_name : '—' ); ?></dd></div>
								<?php if ( '' !== $call['scheduled_at'] ) : ?>
									<div><dt><?php esc_html_e( 'Scheduled for', 'voicepress' ); ?></dt><dd><?php echo esc_html( self::local_time( $call['scheduled_at'] ) ); ?></dd></div>
								<?php endif; ?>
								<div><dt><?php esc_html_e( 'Created', 'voicepress' ); ?></dt><dd><?php echo esc_html( self::local_time( $call['created_at'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Last update', 'voicepress' ); ?></dt><dd><?php echo esc_html( self::local_time( $call['updated_at'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Order updated', 'voicepress' ); ?></dt><dd><?php echo $call['outcome_applied'] ? esc_html__( 'Yes — outcome applied', 'voicepress' ) : esc_html__( 'Not yet', 'voicepress' ); ?></dd></div>
								<?php if ( '' !== $call['execution_id'] ) : ?>
									<div><dt><?php esc_html_e( 'Execution ID', 'voicepress' ); ?></dt><dd><code class="vp-code vp-code--wrap"><?php echo esc_html( $call['execution_id'] ); ?></code></dd></div>
								<?php endif; ?>
							</dl>
						</div>
					</section>

					<?php self::render_extracted( $call['extracted'], $summary ); ?>

					<?php if ( $call['user_data'] ) : ?>
						<section class="vp-card">
							<details class="vp-details">
								<summary>
									<span class="vp-title-icon dashicons dashicons-database" aria-hidden="true"></span>
									<?php
									/* translators: %d: number of fields. */
									echo esc_html( sprintf( _n( 'Data sent to the agent (%d field)', 'Data sent to the agent (%d fields)', count( $call['user_data'] ), 'voicepress' ), count( $call['user_data'] ) ) );
									?>
								</summary>
								<dl class="vp-kv vp-kv--stack">
									<?php foreach ( $call['user_data'] as $key => $value ) : ?>
										<div>
											<dt class="vp-mono"><?php echo esc_html( (string) $key ); ?></dt>
											<dd><?php echo nl2br( esc_html( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ) ); ?></dd>
										</div>
									<?php endforeach; ?>
								</dl>
							</details>
						</section>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		self::render_footer();
	}

	/**
	 * Extracted data (category → field → objective/subjective/confidence).
	 *
	 * @param array  $extracted Extracted data.
	 * @param string $summary   Call summary (skip duplicates).
	 */
	private static function render_extracted( $extracted, $summary ) {
		$rows = array();
		foreach ( (array) $extracted as $category => $fields ) {
			if ( ! is_array( $fields ) ) {
				$rows[] = array( (string) $category, '', (string) ( is_scalar( $fields ) ? $fields : wp_json_encode( $fields ) ), null, '', false );
				continue;
			}
			foreach ( $fields as $field => $value ) {
				$objective  = null;
				$subjective = null;
				$confidence = null;
				$reasoning  = '';
				if ( is_array( $value ) ) {
					$objective  = isset( $value['objective'] ) && is_scalar( $value['objective'] ) && '' !== (string) $value['objective'] ? (string) $value['objective'] : null;
					$subjective = isset( $value['subjective'] ) && is_scalar( $value['subjective'] ) && '' !== (string) $value['subjective'] ? (string) $value['subjective'] : null;
					$confidence = isset( $value['confidence'] ) && is_numeric( $value['confidence'] ) ? (float) $value['confidence'] : null;
					foreach ( array( 'reasoning_objective', 'reasoning_subjective' ) as $rk ) {
						if ( '' === $reasoning && ! empty( $value[ $rk ] ) && is_string( $value[ $rk ] ) ) {
							$reasoning = $value[ $rk ];
						}
					}
				} elseif ( is_scalar( $value ) ) {
					$subjective = (string) $value;
				}
				if ( null === $objective && null === $subjective ) {
					continue;
				}
				if ( null === $objective && '' !== $summary && trim( (string) $subjective ) === $summary ) {
					continue; // Same as the call summary shown above.
				}
				$is_objective = null !== $objective;
				$rows[]       = array( (string) $category, (string) $field, $is_objective ? $objective : (string) $subjective, $confidence, $reasoning, $is_objective );
			}
		}
		if ( ! $rows ) {
			return;
		}
		?>
		<section class="vp-card">
			<div class="vp-card__head"><h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'What the AI understood', 'voicepress' ); ?></h2></div>
			<div class="vp-table-wrap">
				<table class="vp-table vp-table--compact">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Field', 'voicepress' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Value', 'voicepress' ); ?></th>
							<th scope="col" class="vp-num"><?php esc_html_e( 'Confidence', 'voicepress' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<span class="vp-strong"><?php echo esc_html( '' !== $row[1] ? $row[1] : $row[0] ); ?></span>
									<?php if ( '' !== $row[1] ) : ?>
										<span class="vp-cell-sub"><?php echo esc_html( $row[0] ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $row[5] ) {
										echo self::badge( self::outcome_label( $row[2] ), self::outcome_tone( $row[2] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
									} else {
										echo '<span class="vp-extracted-text">' . esc_html( $row[2] ) . '</span>';
									}
									if ( '' !== $row[4] ) {
										echo '<span class="vp-cell-sub">' . esc_html( wp_trim_words( $row[4], 30 ) ) . '</span>';
									}
									?>
								</td>
								<td class="vp-num">
									<?php if ( null !== $row[3] ) : ?>
										<?php $conf = (int) round( ( $row[3] <= 1 ? $row[3] * 100 : $row[3] ) ); ?>
										<span class="vp-confidence" title="<?php echo esc_attr( $conf . '%' ); ?>"><span class="vp-confidence__bar"><span style="width:<?php echo esc_attr( max( 0, min( 100, $conf ) ) ); ?>%"></span></span><?php echo esc_html( $conf . '%' ); ?></span>
									<?php else : ?>
										<span class="vp-muted">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Split a Bolna transcript ("assistant: …\nuser: …") into chat turns.
	 *
	 * @param string $text Transcript.
	 * @return array[] role (assistant|user|system), text.
	 */
	private static function parse_transcript( $text ) {
		$turns = array();
		$text  = trim( (string) $text );
		if ( '' === $text ) {
			return $turns;
		}
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^(assistant|agent|bot|ai|user|customer|human|caller|system|tool|function)\s*:\s*(.*)$/i', $line, $m ) ) {
				$label = strtolower( $m[1] );
				$body  = trim( $m[2] );
				if ( '' === $body ) {
					continue;
				}
				if ( in_array( $label, array( 'user', 'customer', 'human', 'caller' ), true ) ) {
					$role = 'user';
				} elseif ( in_array( $label, array( 'system', 'tool', 'function' ), true ) ) {
					$role = 'system';
				} else {
					$role = 'assistant';
				}
				$turns[] = array(
					'role' => $role,
					'text' => $body,
				);
			} elseif ( $turns ) {
				$turns[ count( $turns ) - 1 ]['text'] .= "\n" . $line;
			} else {
				$turns[] = array(
					'role' => 'system',
					'text' => $line,
				);
			}
		}
		return $turns;
	}

	/* ---------------------------------------------------------------------
	 * Agents
	 * ------------------------------------------------------------------ */

	/**
	 * Agents screen.
	 */
	public static function render_agents() {
		self::guard();
		$state    = self::setup_state();
		$settings = self::settings();
		$labels   = self::agent_labels();
		$agents   = $settings['agents'];
		$public   = self::public_url();
		$tools_on = self::tools_enabled();
		$missing  = $state['agents_total'] - $state['agents_created'];

		$actions = '';
		if ( $state['configured'] ) {
			if ( $state['agents_created'] > 0 ) {
				$actions .= self::button(
					__( 'Re-sync all agents', 'voicepress' ),
					array(
						'class' => 'vp-btn--secondary vp-sync-agents',
						'icon'  => 'dashicons-update',
					)
				);
			}
			if ( $missing > 0 ) {
				$actions .= self::button(
					__( 'Create all agents', 'voicepress' ),
					array(
						'class' => 'vp-btn--primary vp-provision',
						'icon'  => 'dashicons-plus-alt2',
						'attrs' => array( 'data-agent' => '' ),
					)
				);
			}
		}
		self::render_header( self::PAGE_AGENTS, __( 'Agents', 'voicepress' ), __( 'Five specialised AI voice agents, created and kept in sync in your Bolna account.', 'voicepress' ), $actions );

		if ( ! $state['configured'] ) {
			echo '<section class="vp-card">';
			self::empty_state(
				'dashicons-admin-network',
				__( 'Connect Bolna first', 'voicepress' ),
				__( 'Add your Bolna API key in Settings, then come back to create all five agents in one click.', 'voicepress' ),
				array(
					array(
						'label' => __( 'Open settings', 'voicepress' ),
						'url'   => self::page_url( self::PAGE_SETTINGS ),
						'class' => 'vp-btn--primary',
					),
				)
			);
			echo '</section>';
			self::render_footer();
			return;
		}
		?>
		<div class="vp-result" data-vp-agents-result role="status" aria-live="polite"></div>
		<?php
		if ( $tools_on ) {
			self::alert(
				'success',
				/* translators: %s: public host name. */
				sprintf( __( 'Live order tools are on — agents can look up, confirm, cancel and reschedule orders during the call through %s. Re-sync agents after changing your Public URL or store details.', 'voicepress' ), (string) wp_parse_url( $public, PHP_URL_HOST ) )
			);
		} else {
			self::alert(
				'warning',
				__( 'After-call mode: no Public URL is set, so agents can’t reach your store during calls. Decisions are applied to orders after each call. Add a tunnel URL in Settings, then re-sync agents to switch on live order tools.', 'voicepress' ),
				self::page_url( self::PAGE_SETTINGS ) . '#vp-public-url',
				__( 'Add Public URL', 'voicepress' )
			);
		}
		?>
		<div class="vp-grid vp-grid--agents">
			<?php
			foreach ( $labels as $key => $info ) :
				$agent_id = isset( $agents[ $key ] ) ? (string) $agents[ $key ] : '';
				$tools    = self::tool_names( $key );
				?>
				<section class="vp-card vp-agent" data-agent="<?php echo esc_attr( $key ); ?>" data-agent-id="<?php echo esc_attr( $agent_id ); ?>">
					<div class="vp-agent__head">
						<span class="vp-agent__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $info['icon'] ); ?>"></span></span>
						<div class="vp-agent__titles">
							<h2 class="vp-agent__title"><?php echo esc_html( $info['label'] ); ?></h2>
							<p class="vp-agent__desc"><?php echo esc_html( $info['description'] ); ?></p>
						</div>
						<span data-vp-agent-status>
							<?php echo '' !== $agent_id ? self::badge( __( 'Live', 'voicepress' ), 'success', array(), 'vp-badge--dot' ) : self::badge( __( 'Not created', 'voicepress' ), 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
						</span>
					</div>
					<div class="vp-agent__body">
						<div class="vp-chips" aria-label="<?php esc_attr_e( 'Handles', 'voicepress' ); ?>">
							<?php foreach ( (array) $info['use_cases'] as $use_case ) : ?>
								<span class="vp-chip"><span class="dashicons <?php echo esc_attr( self::use_case_icon( $use_case ) ); ?>" aria-hidden="true"></span><?php echo esc_html( self::use_case_label( $use_case ) ); ?></span>
							<?php endforeach; ?>
						</div>
						<dl class="vp-kv vp-kv--tight">
							<div>
								<dt><?php esc_html_e( 'Agent ID', 'voicepress' ); ?></dt>
								<dd>
									<code class="vp-code" data-vp-agent-id<?php echo '' === $agent_id ? ' hidden' : ''; ?>><?php echo esc_html( $agent_id ); ?></code>
									<a class="vp-link vp-link--external" data-vp-agent-link href="<?php echo esc_url( 'https://platform.bolna.ai/agent/' . rawurlencode( $agent_id ) ); ?>" target="_blank" rel="noopener noreferrer"<?php echo '' === $agent_id ? ' hidden' : ''; ?>><?php esc_html_e( 'Open in Bolna', 'voicepress' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
									<span class="vp-muted" data-vp-agent-none<?php echo '' !== $agent_id ? ' hidden' : ''; ?>><?php esc_html_e( 'Not created yet', 'voicepress' ); ?></span>
								</dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Voice', 'voicepress' ); ?></dt>
								<dd>
									<?php
									/* translators: %s: agent persona name. */
									echo esc_html( sprintf( __( '%s · English · natural female voice', 'voicepress' ), '' !== (string) $settings['agent_persona'] ? (string) $settings['agent_persona'] : 'Riya' ) );
									?>
								</dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Live tools', 'voicepress' ); ?></dt>
								<dd>
									<?php
									if ( ! $tools ) {
										echo '<span class="vp-muted">' . esc_html__( 'None needed', 'voicepress' ) . '</span>';
									} elseif ( $tools_on ) {
										/* translators: %d: number of tools. */
										echo self::badge( sprintf( _n( 'On · %d tool', 'On · %d tools', count( $tools ), 'voicepress' ), count( $tools ) ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
									} else {
										echo self::badge( __( 'Off · needs Public URL', 'voicepress' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
									}
									?>
								</dd>
							</div>
						</dl>
						<?php if ( $tools ) : ?>
							<div class="vp-chips vp-chips--tools<?php echo $tools_on ? '' : ' is-off'; ?>">
								<?php foreach ( $tools as $tool ) : ?>
									<span class="vp-chip vp-chip--mono"><?php echo esc_html( $tool ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="vp-agent__foot">
						<button type="button" class="vp-btn <?php echo '' === $agent_id ? 'vp-btn--primary' : 'vp-btn--secondary'; ?> vp-btn--sm vp-provision" data-agent="<?php echo esc_attr( $key ); ?>">
							<span class="dashicons <?php echo '' === $agent_id ? 'dashicons-plus-alt2' : 'dashicons-update'; ?>" aria-hidden="true"></span>
							<span class="vp-btn__label"><?php echo '' === $agent_id ? esc_html__( 'Create agent', 'voicepress' ) : esc_html__( 'Update agent', 'voicepress' ); ?></span>
						</button>
						<div class="vp-result" data-vp-agent-result role="status" aria-live="polite"></div>
					</div>
				</section>
			<?php endforeach; ?>
		</div>

		<?php self::render_inbound_card( $state, $settings, $labels ); ?>
		<?php
		self::render_footer();
	}

	/**
	 * Inbound line card (link a Bolna number to the Support agent).
	 *
	 * @param array $state    Setup state.
	 * @param array $settings Settings.
	 * @param array $labels   Agent labels.
	 */
	private static function render_inbound_card( $state, $settings, $labels ) {
		$support_id = isset( $settings['agents']['support'] ) ? (string) $settings['agents']['support'] : '';
		$agent_map  = array();
		foreach ( (array) $settings['agents'] as $key => $id ) {
			if ( '' !== (string) $id && isset( $labels[ $key ] ) ) {
				$agent_map[ (string) $id ] = $labels[ $key ]['label'];
			}
		}
		?>
		<section class="vp-card vp-inbound" id="vp-inbound" data-current="<?php echo esc_attr( (string) $settings['phone_number_id'] ); ?>" data-support-agent="<?php echo esc_attr( $support_id ); ?>" data-agent-map="<?php echo esc_attr( (string) wp_json_encode( (object) $agent_map ) ); ?>">
			<div class="vp-card__head">
				<div>
					<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-phone" aria-hidden="true"></span><?php esc_html_e( 'Inbound support line', 'voicepress' ); ?></h2>
					<p class="vp-card__sub"><?php esc_html_e( 'Let customers call in 24×7 — the Support agent recognises them by phone number and can check, cancel, reschedule or return orders.', 'voicepress' ); ?></p>
				</div>
				<span data-vp-inbound-status>
					<?php echo $state['inbound'] ? self::badge( __( 'Linked', 'voicepress' ), 'success', array(), 'vp-badge--dot' ) : self::badge( __( 'Not linked', 'voicepress' ), 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
				</span>
			</div>
			<div class="vp-card__body">
				<?php
				if ( '' === $support_id ) {
					self::alert( 'info', __( 'Create the Support agent above first, then link a number to it here.', 'voicepress' ) );
				}
				?>
				<div class="vp-inline-form">
					<label class="screen-reader-text" for="vp-inbound-number"><?php esc_html_e( 'Bolna phone number', 'voicepress' ); ?></label>
					<select id="vp-inbound-number" class="vp-input" disabled>
						<option value=""><?php esc_html_e( 'Loading your Bolna numbers…', 'voicepress' ); ?></option>
					</select>
					<button type="button" class="vp-btn vp-btn--primary vp-link-inbound"<?php disabled( '' === $support_id ); ?>>
						<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
						<span class="vp-btn__label"><?php esc_html_e( 'Link Support agent', 'voicepress' ); ?></span>
					</button>
				</div>
				<div class="vp-result" data-vp-inbound-result role="status" aria-live="polite"></div>
				<div class="vp-mapping" data-vp-mapping></div>
			</div>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Automations
	 * ------------------------------------------------------------------ */

	/**
	 * Automations screen.
	 */
	public static function render_automations() {
		self::guard();
		$settings = self::settings();
		$triggers = array(
			'cod_confirmation'  => __( 'A cash-on-delivery order is placed', 'voicepress' ),
			'delivery_reminder' => __( 'Order status changes to “Out for delivery”', 'voicepress' ),
			'ndr_resolution'    => __( 'Order status changes to “Delivery failed”', 'voicepress' ),
			'feedback'          => __( 'Order status changes to “Completed”', 'voicepress' ),
			'cart_recovery'     => __( 'A shopper leaves checkout without ordering (phone captured)', 'voicepress' ),
		);
		$notes    = array(
			'cod_confirmation'  => __( 'Confirms or cancels before you ship — cuts RTO on fake COD orders.', 'voicepress' ),
			'delivery_reminder' => __( 'Makes sure someone is home, with cash ready for COD.', 'voicepress' ),
			'ndr_resolution'    => __( 'Rescues failed deliveries with a new slot or corrected address.', 'voicepress' ),
			'feedback'          => __( 'Demo default is 2 minutes; real stores usually wait ~2 days (2880).', 'voicepress' ),
			'cart_recovery'     => __( 'Offers a one-time discount code to complete the purchase.', 'voicepress' ),
		);
		$carts    = self::list_carts( 20 );
		$updated  = self::flag( 'updated' );

		self::render_header( self::PAGE_AUTOMATIONS, __( 'Automations', 'voicepress' ), __( 'Choose which moments in the order journey trigger an AI call.', 'voicepress' ) );
		if ( $updated ) {
			self::alert( 'success', __( 'Automations saved.', 'voicepress' ) );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vp-form">
			<input type="hidden" name="action" value="voicepress_save_automations">
			<?php wp_nonce_field( 'voicepress_save_automations' ); ?>

			<section class="vp-card">
				<div class="vp-card__head">
					<div>
						<h2 class="vp-card__title"><?php esc_html_e( 'Call triggers', 'voicepress' ); ?></h2>
						<p class="vp-card__sub"><?php esc_html_e( 'Automations run every minute via WP-Cron, and live while a VoicePress screen is open.', 'voicepress' ); ?></p>
					</div>
				</div>
				<div class="vp-autos">
					<?php
					foreach ( self::AUTOMATION_USE_CASES as $use_case ) :
						$auto      = self::automation( $use_case );
						$agent_key = self::use_case_agent_key( $use_case );
						$has_agent = '' !== $agent_key && ! empty( $settings['agents'][ $agent_key ] );
						$field     = 'automations[' . $use_case . ']';
						?>
						<div class="vp-auto<?php echo $auto['enabled'] ? ' is-on' : ''; ?>">
							<span class="vp-auto__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( self::use_case_icon( $use_case ) ); ?>"></span></span>
							<div class="vp-auto__main">
								<h3 class="vp-auto__title"><?php echo esc_html( self::use_case_label( $use_case ) ); ?></h3>
								<p class="vp-auto__trigger"><span class="vp-auto__when"><?php esc_html_e( 'When', 'voicepress' ); ?></span> <?php echo esc_html( $triggers[ $use_case ] ); ?></p>
								<p class="vp-auto__note">
									<?php echo esc_html( $notes[ $use_case ] ); ?>
									<?php
									if ( ! $has_agent ) {
										echo ' ' . self::badge( __( 'Agent not created', 'voicepress' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder.
									}
									?>
								</p>
							</div>
							<div class="vp-auto__delay">
								<label class="vp-label" for="vp-delay-<?php echo esc_attr( $use_case ); ?>"><?php esc_html_e( 'Call after', 'voicepress' ); ?></label>
								<span class="vp-input-group">
									<input type="number" id="vp-delay-<?php echo esc_attr( $use_case ); ?>" class="vp-input" name="<?php echo esc_attr( $field . '[delay]' ); ?>" value="<?php echo esc_attr( $auto['delay'] ); ?>" min="0" max="43200" step="1">
									<span class="vp-input-group__suffix"><?php esc_html_e( 'min', 'voicepress' ); ?></span>
								</span>
							</div>
							<div class="vp-auto__toggle">
								<?php self::switch_field( $field . '[enabled]', $auto['enabled'], __( 'Enabled', 'voicepress' ), 'vp-auto-' . $use_case ); ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<div class="vp-grid vp-grid--2">
				<section class="vp-card">
					<div class="vp-card__head"><h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-backup" aria-hidden="true"></span><?php esc_html_e( 'Retries', 'voicepress' ); ?></h2></div>
					<div class="vp-card__body">
						<p class="vp-help"><?php esc_html_e( 'When a customer doesn’t pick up, VoicePress tries again.', 'voicepress' ); ?></p>
						<div class="vp-form-grid">
							<div class="vp-field">
								<label class="vp-label" for="vp-max-attempts"><?php esc_html_e( 'Max attempts', 'voicepress' ); ?></label>
								<input type="number" id="vp-max-attempts" class="vp-input" name="max_attempts" value="<?php echo esc_attr( (int) $settings['max_attempts'] ); ?>" min="1" max="5" step="1">
							</div>
							<div class="vp-field">
								<label class="vp-label" for="vp-retry-after"><?php esc_html_e( 'Retry after', 'voicepress' ); ?></label>
								<span class="vp-input-group">
									<input type="number" id="vp-retry-after" class="vp-input" name="retry_after" value="<?php echo esc_attr( (int) $settings['retry_after'] ); ?>" min="1" max="1440" step="1">
									<span class="vp-input-group__suffix"><?php esc_html_e( 'min', 'voicepress' ); ?></span>
								</span>
							</div>
						</div>
					</div>
				</section>
				<section class="vp-card">
					<div class="vp-card__head"><h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e( 'Calling hours', 'voicepress' ); ?></h2></div>
					<div class="vp-card__body">
						<?php self::switch_field( 'respect_calling_hours', ! empty( $settings['respect_calling_hours'] ), __( 'Only call during these hours (store time)', 'voicepress' ), 'vp-respect-hours' ); ?>
						<div class="vp-form-grid vp-form-grid--spaced">
							<div class="vp-field">
								<label class="vp-label" for="vp-hours-start"><?php esc_html_e( 'From', 'voicepress' ); ?></label>
								<select id="vp-hours-start" class="vp-input" name="calling_hours_start">
									<?php self::hour_options( (int) $settings['calling_hours_start'] ); ?>
								</select>
							</div>
							<div class="vp-field">
								<label class="vp-label" for="vp-hours-end"><?php esc_html_e( 'Until', 'voicepress' ); ?></label>
								<select id="vp-hours-end" class="vp-input" name="calling_hours_end">
									<?php self::hour_options( (int) $settings['calling_hours_end'] ); ?>
								</select>
							</div>
						</div>
						<p class="vp-help"><?php esc_html_e( 'Calls due outside the window wait for the next opening instead of being skipped.', 'voicepress' ); ?></p>
					</div>
				</section>
			</div>

			<div class="vp-savebar">
				<button type="submit" class="vp-btn vp-btn--primary"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span class="vp-btn__label"><?php esc_html_e( 'Save automations', 'voicepress' ); ?></span></button>
			</div>
		</form>

		<div class="vp-grid vp-grid--main">
			<section class="vp-card">
				<div class="vp-card__head">
					<div>
						<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-cart" aria-hidden="true"></span><?php esc_html_e( 'Abandoned carts', 'voicepress' ); ?></h2>
						<p class="vp-card__sub"><?php esc_html_e( 'Captured at checkout when a shopper enters a phone or email.', 'voicepress' ); ?></p>
					</div>
				</div>
				<?php if ( $carts ) : ?>
					<div class="vp-table-wrap">
						<table class="vp-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Shopper', 'voicepress' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Items', 'voicepress' ); ?></th>
									<th scope="col" class="vp-num"><?php esc_html_e( 'Total', 'voicepress' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Status', 'voicepress' ); ?></th>
									<th scope="col" class="vp-num"><?php esc_html_e( 'Captured', 'voicepress' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $carts as $cart ) : ?>
									<?php self::render_cart_row( $cart ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<?php
					self::empty_state(
						'dashicons-cart',
						__( 'No abandoned carts yet', 'voicepress' ),
						__( 'When a shopper types their phone at checkout and leaves, the cart shows up here and the recovery call is placed automatically. Simulate one to see it in action.', 'voicepress' ),
						array()
					);
					?>
				<?php endif; ?>
			</section>

			<section class="vp-card vp-try">
				<div class="vp-card__head">
					<div>
						<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-randomize" aria-hidden="true"></span><?php esc_html_e( 'Simulate an abandoned cart', 'voicepress' ); ?></h2>
						<p class="vp-card__sub"><?php esc_html_e( 'Creates a demo cart with 2 products and immediately places the recovery call.', 'voicepress' ); ?></p>
					</div>
				</div>
				<div class="vp-card__body">
					<form id="vp-simulate-cart-form" class="vp-form" novalidate>
						<div class="vp-field">
							<label class="vp-label" for="vp-sim-name"><?php esc_html_e( 'Shopper name', 'voicepress' ); ?></label>
							<input type="text" id="vp-sim-name" name="name" class="vp-input" placeholder="<?php esc_attr_e( 'Asha', 'voicepress' ); ?>" autocomplete="name">
						</div>
						<div class="vp-field">
							<label class="vp-label" for="vp-sim-phone"><?php esc_html_e( 'Phone to call', 'voicepress' ); ?></label>
							<input type="tel" id="vp-sim-phone" name="phone" class="vp-input" placeholder="+91 98765 43210" autocomplete="tel" required>
						</div>
						<button type="submit" class="vp-btn vp-btn--primary vp-btn--block">
							<span class="dashicons dashicons-phone" aria-hidden="true"></span>
							<span class="vp-btn__label"><?php esc_html_e( 'Simulate abandoned cart & call', 'voicepress' ); ?></span>
						</button>
						<div class="vp-result" role="status" aria-live="polite"></div>
					</form>
				</div>
			</section>
		</div>
		<?php
		self::render_footer();
	}

	/**
	 * One abandoned-cart row.
	 *
	 * @param array $cart Cart row.
	 */
	private static function render_cart_row( $cart ) {
		$cart   = is_array( $cart ) ? $cart : array();
		$name   = isset( $cart['customer_name'] ) ? trim( (string) $cart['customer_name'] ) : '';
		$phone  = isset( $cart['phone'] ) ? (string) $cart['phone'] : '';
		$email  = isset( $cart['email'] ) ? (string) $cart['email'] : '';
		$status = isset( $cart['status'] ) ? (string) $cart['status'] : 'captured';
		$items  = isset( $cart['cart'] ) ? $cart['cart'] : array();
		if ( is_string( $items ) ) {
			$items = json_decode( $items, true );
		}
		$items = is_array( $items ) ? $items : array();
		if ( isset( $items['items'] ) && is_array( $items['items'] ) ) {
			$items = $items['items'];
		}
		$parts = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_name = isset( $item['name'] ) ? (string) $item['name'] : '';
			$qty       = isset( $item['qty'] ) ? (int) $item['qty'] : ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
			if ( '' !== $item_name ) {
				$parts[] = $item_name . ( $qty > 1 ? ' × ' . $qty : '' );
			}
		}
		$tones   = array(
			'captured'  => array( __( 'Abandoned', 'voicepress' ), 'warning' ),
			'calling'   => array( __( 'Calling…', 'voicepress' ), 'live' ),
			'called'    => array( __( 'Called', 'voicepress' ), 'info' ),
			'recovered' => array( __( 'Recovered', 'voicepress' ), 'success' ),
			'ignored'   => array( __( 'Not interested', 'voicepress' ), 'neutral' ),
		);
		$badge   = isset( $tones[ $status ] ) ? $tones[ $status ] : array( self::humanize( $status ), 'neutral' );
		$display = '' !== $name ? $name : ( '' !== $phone ? $phone : ( '' !== $email ? $email : __( 'Guest', 'voicepress' ) ) );
		$sub     = array_filter( array( '' !== $name ? $phone : '', $email !== $display ? $email : '' ) );
		$call_id = ! empty( $cart['call_id'] ) ? (int) $cart['call_id'] : 0;
		$total   = isset( $cart['cart_total'] ) && '' !== (string) $cart['cart_total'] ? self::money( (float) $cart['cart_total'], 2, isset( $cart['currency'] ) ? (string) $cart['currency'] : '' ) : '—';
		?>
		<tr>
			<td>
				<span class="vp-strong"><?php echo esc_html( $display ); ?></span>
				<?php if ( $sub ) : ?>
					<span class="vp-cell-sub"><?php echo esc_html( implode( ' · ', $sub ) ); ?></span>
				<?php endif; ?>
			</td>
			<td class="vp-cell-items"><?php echo esc_html( $parts ? implode( ', ', $parts ) : '—' ); ?></td>
			<td class="vp-num vp-nowrap"><?php echo esc_html( $total ); ?></td>
			<td class="vp-nowrap">
				<?php echo self::badge( $badge[0], $badge[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
				<?php if ( $call_id ) : ?>
					<a class="vp-link vp-link--small" href="<?php echo esc_url( self::call_url( $call_id ) ); ?>"><?php esc_html_e( 'Call', 'voicepress' ); ?> →</a>
				<?php endif; ?>
			</td>
			<td class="vp-num vp-nowrap"><?php echo esc_html( self::human_time( isset( $cart['created_at'] ) ? (string) $cart['created_at'] : '' ) ); ?></td>
		</tr>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Settings screen.
	 */
	public static function render_settings() {
		self::guard();
		$s          = self::settings();
		$state      = self::setup_state();
		$public     = self::public_url();
		$webhook    = (string) self::safe(
			function () {
				return VoicePress_REST::webhook_url();
			},
			''
		);
		$provider   = (string) $s['telephony_provider'];
		$providers  = self::PROVIDERS;
		if ( '' !== $provider && ! isset( $providers[ $provider ] ) ) {
			$providers[ $provider ] = ucfirst( $provider );
		}

		self::render_header( self::PAGE_SETTINGS, __( 'Settings', 'voicepress' ), __( 'Connect Bolna, choose your caller number and teach your agents about your store.', 'voicepress' ) );

		if ( self::flag( 'updated' ) ) {
			self::alert( 'success', __( 'Settings saved.', 'voicepress' ) );
		}
		if ( self::flag( 'resync' ) ) {
			?>
			<div class="vp-alert vp-alert--info">
				<span class="vp-alert__icon dashicons dashicons-update" aria-hidden="true"></span>
				<div class="vp-alert__body"><?php esc_html_e( 'Your store details or Public URL changed. Re-sync your agents so calls use the new information.', 'voicepress' ); ?></div>
				<div class="vp-alert__actions">
					<button type="button" class="vp-btn vp-btn--sm vp-btn--primary vp-sync-agents"><span class="dashicons dashicons-update" aria-hidden="true"></span><span class="vp-btn__label"><?php esc_html_e( 'Re-sync agents now', 'voicepress' ); ?></span></button>
				</div>
			</div>
			<div class="vp-result" data-vp-agents-result role="status" aria-live="polite"></div>
			<?php
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vp-form" autocomplete="off">
			<input type="hidden" name="action" value="voicepress_save_settings">
			<?php wp_nonce_field( 'voicepress_save_settings' ); ?>

			<div class="vp-grid vp-grid--settings">
				<div class="vp-stack">
					<section class="vp-card" id="vp-connection">
						<div class="vp-card__head">
							<div>
								<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-admin-network" aria-hidden="true"></span><?php esc_html_e( 'Bolna connection', 'voicepress' ); ?></h2>
								<p class="vp-card__sub"><?php esc_html_e( 'VoicePress uses your Bolna account to run voice agents and place calls.', 'voicepress' ); ?></p>
							</div>
							<?php echo $state['configured'] ? self::badge( __( 'Connected', 'voicepress' ), 'success', array(), 'vp-badge--dot' ) : self::badge( __( 'Not connected', 'voicepress' ), 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
						</div>
						<div class="vp-card__body">
							<div class="vp-form-grid">
								<div class="vp-field vp-field--full">
									<label class="vp-label" for="vp-api-key"><?php esc_html_e( 'Bolna API key', 'voicepress' ); ?></label>
									<input type="password" id="vp-api-key" name="api_key" class="vp-input vp-mono" value="" placeholder="<?php echo esc_attr( $state['configured'] ? self::mask_key( (string) $s['api_key'] ) : 'bn-…' ); ?>" autocomplete="new-password" spellcheck="false" data-lpignore="true">
									<p class="vp-help">
										<?php
										if ( $state['configured'] ) {
											esc_html_e( 'A key is saved. Leave this empty to keep it, or paste a new key to replace it.', 'voicepress' );
										} else {
											esc_html_e( 'Find it at platform.bolna.ai → Developers → API keys. It is stored in your WordPress database and never shown again.', 'voicepress' );
										}
										?>
									</p>
								</div>
								<div class="vp-field vp-field--full">
									<div class="vp-connection">
										<button type="button" id="vp-test-connection" class="vp-btn vp-btn--secondary" data-configured="<?php echo $state['configured'] ? '1' : '0'; ?>">
											<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
											<span class="vp-btn__label"><?php esc_html_e( 'Test connection', 'voicepress' ); ?></span>
										</button>
										<div class="vp-result" data-vp-connection-result role="status" aria-live="polite"></div>
									</div>
								</div>
								<div class="vp-field" id="vp-from-number-field">
									<label class="vp-label" for="vp-from-number"><?php esc_html_e( 'Caller number (from)', 'voicepress' ); ?></label>
									<select id="vp-from-select" class="vp-input" aria-label="<?php esc_attr_e( 'Choose one of your Bolna numbers', 'voicepress' ); ?>" hidden></select>
									<input type="text" id="vp-from-number" name="from_number" class="vp-input vp-mono" value="<?php echo esc_attr( (string) $s['from_number'] ); ?>" placeholder="+19787974241" inputmode="tel" autocomplete="off">
									<p class="vp-help"><?php esc_html_e( 'The Bolna number your calls come from. Test the connection to pick from your numbers.', 'voicepress' ); ?></p>
								</div>
								<div class="vp-field">
									<label class="vp-label" for="vp-telephony-provider"><?php esc_html_e( 'Telephony provider', 'voicepress' ); ?></label>
									<select id="vp-telephony-provider" name="telephony_provider" class="vp-input">
										<?php foreach ( $providers as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $provider, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="vp-help"><?php esc_html_e( 'Must match the provider of your caller number.', 'voicepress' ); ?></p>
								</div>
							</div>
						</div>
					</section>

					<section class="vp-card" id="vp-public-url-card">
						<div class="vp-card__head">
							<div>
								<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><?php esc_html_e( 'Public URL & live tools', 'voicepress' ); ?></h2>
								<p class="vp-card__sub"><?php esc_html_e( 'Bolna must reach your store to run live order tools and send call webhooks.', 'voicepress' ); ?></p>
							</div>
							<?php echo '' !== $public ? self::badge( __( 'Live tools on', 'voicepress' ), 'success', array(), 'vp-badge--dot' ) : self::badge( __( 'After-call mode', 'voicepress' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in builder. ?>
						</div>
						<div class="vp-card__body">
							<div class="vp-field">
								<label class="vp-label" for="vp-public-url"><?php esc_html_e( 'Public URL', 'voicepress' ); ?></label>
								<input type="url" id="vp-public-url" name="public_url" class="vp-input vp-mono" value="<?php echo esc_attr( (string) $s['public_url'] ); ?>" placeholder="https://your-tunnel.trycloudflare.com" spellcheck="false">
								<p class="vp-help"><?php esc_html_e( 'Running locally? Start a free tunnel and paste the https URL it prints:', 'voicepress' ); ?></p>
								<div class="vp-copy">
									<code class="vp-code vp-code--block" id="vp-tunnel-cmd">cloudflared tunnel --url http://127.0.0.1:8080</code>
									<button type="button" class="vp-btn vp-btn--secondary vp-btn--sm" data-vp-copy-target="#vp-tunnel-cmd"><?php esc_html_e( 'Copy', 'voicepress' ); ?></button>
								</div>
								<p class="vp-help"><?php esc_html_e( 'Without it, VoicePress still works: calls sync by polling and decisions are applied after each call. Re-sync agents after changing this.', 'voicepress' ); ?></p>
							</div>
							<div class="vp-field">
								<label class="vp-label" for="vp-webhook-url"><?php esc_html_e( 'Webhook URL (set automatically on agents)', 'voicepress' ); ?></label>
								<div class="vp-copy">
									<input type="text" id="vp-webhook-url" class="vp-input vp-mono" value="<?php echo esc_attr( $webhook ); ?>" readonly>
									<button type="button" class="vp-btn vp-btn--secondary vp-btn--sm" data-vp-copy-target="#vp-webhook-url"><?php esc_html_e( 'Copy', 'voicepress' ); ?></button>
								</div>
							</div>
							<div class="vp-field">
								<label class="vp-label" for="vp-secret"><?php esc_html_e( 'Webhook & tool secret', 'voicepress' ); ?></label>
								<div class="vp-copy">
									<input type="text" id="vp-secret" class="vp-input vp-mono" value="<?php echo esc_attr( self::secret() ); ?>" readonly>
									<button type="button" class="vp-btn vp-btn--secondary vp-btn--sm" data-vp-copy-target="#vp-secret"><?php esc_html_e( 'Copy', 'voicepress' ); ?></button>
								</div>
								<p class="vp-help"><?php esc_html_e( 'Bolna sends this as a Bearer token on every tool call and webhook. Generated once for your store.', 'voicepress' ); ?></p>
							</div>
						</div>
					</section>
				</div>

				<div class="vp-stack">
					<section class="vp-card">
						<div class="vp-card__head">
							<div>
								<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-store" aria-hidden="true"></span><?php esc_html_e( 'Store persona', 'voicepress' ); ?></h2>
								<p class="vp-card__sub"><?php esc_html_e( 'What your agents say about your store on every call.', 'voicepress' ); ?></p>
							</div>
						</div>
						<div class="vp-card__body">
							<div class="vp-form-grid">
								<div class="vp-field">
									<label class="vp-label" for="vp-store-name"><?php esc_html_e( 'Store name', 'voicepress' ); ?></label>
									<input type="text" id="vp-store-name" name="store_name" class="vp-input" value="<?php echo esc_attr( (string) $s['store_name'] ); ?>">
								</div>
								<div class="vp-field">
									<label class="vp-label" for="vp-agent-persona"><?php esc_html_e( 'Agent name', 'voicepress' ); ?></label>
									<input type="text" id="vp-agent-persona" name="agent_persona" class="vp-input" value="<?php echo esc_attr( (string) $s['agent_persona'] ); ?>" placeholder="Riya">
								</div>
								<div class="vp-field">
									<label class="vp-label" for="vp-country-code"><?php esc_html_e( 'Default country code', 'voicepress' ); ?></label>
									<span class="vp-input-group vp-input-group--prefix">
										<span class="vp-input-group__prefix">+</span>
										<input type="text" id="vp-country-code" name="default_country_code" class="vp-input" value="<?php echo esc_attr( (string) $s['default_country_code'] ); ?>" inputmode="numeric" pattern="[0-9]{1,4}" maxlength="4">
									</span>
								</div>
								<div class="vp-field">
									<label class="vp-label" for="vp-return-window"><?php esc_html_e( 'Return window', 'voicepress' ); ?></label>
									<span class="vp-input-group">
										<input type="number" id="vp-return-window" name="return_window_days" class="vp-input" value="<?php echo esc_attr( (int) $s['return_window_days'] ); ?>" min="0" max="365" step="1">
										<span class="vp-input-group__suffix"><?php esc_html_e( 'days', 'voicepress' ); ?></span>
									</span>
								</div>
								<div class="vp-field">
									<label class="vp-label" for="vp-delivery-eta"><?php esc_html_e( 'Delivery time', 'voicepress' ); ?></label>
									<input type="text" id="vp-delivery-eta" name="delivery_eta" class="vp-input" value="<?php echo esc_attr( (string) $s['delivery_eta'] ); ?>">
								</div>
								<div class="vp-field">
									<label class="vp-label" for="vp-support-hours"><?php esc_html_e( 'Support hours', 'voicepress' ); ?></label>
									<input type="text" id="vp-support-hours" name="support_hours" class="vp-input" value="<?php echo esc_attr( (string) $s['support_hours'] ); ?>">
								</div>
								<div class="vp-field vp-field--full">
									<label class="vp-label" for="vp-store-policies"><?php esc_html_e( 'Store policies', 'voicepress' ); ?></label>
									<textarea id="vp-store-policies" name="store_policies" class="vp-input" rows="4"><?php echo esc_textarea( (string) $s['store_policies'] ); ?></textarea>
									<p class="vp-help"><?php esc_html_e( 'Shipping, COD, returns — in plain sentences. Agents quote this when customers ask.', 'voicepress' ); ?></p>
								</div>
							</div>
						</div>
					</section>

					<section class="vp-card">
						<div class="vp-card__head">
							<div>
								<h2 class="vp-card__title"><span class="vp-title-icon dashicons dashicons-microphone" aria-hidden="true"></span><?php esc_html_e( 'Website voice widget & offers', 'voicepress' ); ?></h2>
								<p class="vp-card__sub"><?php esc_html_e( 'A “Talk to us” button on your store that starts an in-browser voice call with your Support agent.', 'voicepress' ); ?></p>
							</div>
						</div>
						<div class="vp-card__body">
							<?php self::switch_field( 'widget_enabled', ! empty( $s['widget_enabled'] ), __( 'Show the voice widget on my store', 'voicepress' ), 'vp-widget-enabled' ); ?>
							<div class="vp-form-grid vp-form-grid--spaced">
								<div class="vp-field">
									<label class="vp-label" for="vp-widget-label"><?php esc_html_e( 'Button label', 'voicepress' ); ?></label>
									<input type="text" id="vp-widget-label" name="widget_label" class="vp-input" value="<?php echo esc_attr( (string) $s['widget_label'] ); ?>" placeholder="<?php esc_attr_e( 'Talk to us', 'voicepress' ); ?>">
								</div>
								<div class="vp-field">
									<label class="vp-label" for="vp-cart-discount"><?php esc_html_e( 'Cart recovery discount', 'voicepress' ); ?></label>
									<span class="vp-input-group">
										<input type="number" id="vp-cart-discount" name="cart_discount_percent" class="vp-input" value="<?php echo esc_attr( (int) $s['cart_discount_percent'] ); ?>" min="0" max="90" step="1">
										<span class="vp-input-group__suffix">%</span>
									</span>
								</div>
							</div>
							<p class="vp-help"><?php esc_html_e( 'Cart recovery calls offer a one-time coupon for this percentage, valid for 3 days.', 'voicepress' ); ?></p>
						</div>
					</section>
				</div>
			</div>

			<div class="vp-savebar">
				<button type="submit" class="vp-btn vp-btn--primary"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span class="vp-btn__label"><?php esc_html_e( 'Save settings', 'voicepress' ); ?></span></button>
			</div>
		</form>
		<?php
		self::render_footer();
	}

	/* ---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Save the settings form (admin-post: voicepress_save_settings).
	 */
	public static function save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change VoicePress settings.', 'voicepress' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'voicepress_save_settings' );

		$old    = self::settings();
		$values = array();

		$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		if ( '' !== $api_key ) {
			$values['api_key'] = $api_key;
		}

		$from                  = isset( $_POST['from_number'] ) ? sanitize_text_field( wp_unslash( $_POST['from_number'] ) ) : '';
		$values['from_number'] = (string) preg_replace( '/(?!^\+)[^0-9]/', '', trim( $from ) );

		$provider = isset( $_POST['telephony_provider'] ) ? sanitize_key( wp_unslash( $_POST['telephony_provider'] ) ) : '';
		if ( array_key_exists( $provider, self::PROVIDERS ) || ( '' !== $provider && $provider === $old['telephony_provider'] ) ) {
			$values['telephony_provider'] = $provider;
		}

		$public = isset( $_POST['public_url'] ) ? trim( (string) wp_unslash( $_POST['public_url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by esc_url_raw below.
		if ( '' !== $public && ! preg_match( '#^https?://#i', $public ) ) {
			$public = 'https://' . $public;
		}
		$values['public_url'] = '' === $public ? '' : untrailingslashit( esc_url_raw( $public, array( 'http', 'https' ) ) );

		foreach ( array( 'store_name', 'agent_persona', 'delivery_eta', 'support_hours', 'widget_label' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$values[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		if ( isset( $_POST['store_policies'] ) ) {
			$values['store_policies'] = sanitize_textarea_field( wp_unslash( $_POST['store_policies'] ) );
		}

		$cc = isset( $_POST['default_country_code'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['default_country_code'] ) ) ) : '';
		if ( '' !== $cc ) {
			$values['default_country_code'] = substr( $cc, 0, 4 );
		}
		if ( isset( $_POST['return_window_days'] ) ) {
			$values['return_window_days'] = min( 365, absint( $_POST['return_window_days'] ) );
		}
		if ( isset( $_POST['cart_discount_percent'] ) ) {
			$values['cart_discount_percent'] = min( 90, absint( $_POST['cart_discount_percent'] ) );
		}
		$values['widget_enabled'] = empty( $_POST['widget_enabled'] ) ? 0 : 1;

		self::safe(
			function () use ( $values ) {
				VoicePress_Settings::update( $values );
				return true;
			},
			false
		);

		// Agents carry store info, provider and tool URLs: suggest a re-sync when those change.
		$resync = false;
		$state  = self::setup_state( true );
		if ( $state['agents_created'] > 0 ) {
			foreach ( array( 'public_url', 'store_name', 'agent_persona', 'delivery_eta', 'support_hours', 'store_policies', 'return_window_days', 'telephony_provider', 'cart_discount_percent' ) as $key ) {
				if ( array_key_exists( $key, $values ) && (string) $values[ $key ] !== (string) ( isset( $old[ $key ] ) ? $old[ $key ] : '' ) ) {
					$resync = true;
					break;
				}
			}
		}

		$args = array( 'updated' => '1' );
		if ( $resync ) {
			$args['resync'] = '1';
		}
		wp_safe_redirect( self::page_url( self::PAGE_SETTINGS, $args ) );
		exit;
	}

	/**
	 * Save the automations form (admin-post: voicepress_save_automations).
	 */
	public static function save_automations() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change VoicePress automations.', 'voicepress' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'voicepress_save_automations' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cast below.
		$input       = isset( $_POST['automations'] ) && is_array( $_POST['automations'] ) ? wp_unslash( $_POST['automations'] ) : array();
		$automations = array();
		foreach ( self::AUTOMATION_USE_CASES as $use_case ) {
			$row                      = isset( $input[ $use_case ] ) && is_array( $input[ $use_case ] ) ? $input[ $use_case ] : array();
			$automations[ $use_case ] = array(
				'enabled' => empty( $row['enabled'] ) ? 0 : 1,
				'delay'   => min( 43200, absint( isset( $row['delay'] ) ? $row['delay'] : 0 ) ),
			);
		}

		$values = array(
			'automations'           => $automations,
			'max_attempts'          => max( 1, min( 5, absint( isset( $_POST['max_attempts'] ) ? wp_unslash( $_POST['max_attempts'] ) : 2 ) ) ),
			'retry_after'           => max( 1, min( 1440, absint( isset( $_POST['retry_after'] ) ? wp_unslash( $_POST['retry_after'] ) : 30 ) ) ),
			'respect_calling_hours' => empty( $_POST['respect_calling_hours'] ) ? 0 : 1,
			'calling_hours_start'   => min( 23, absint( isset( $_POST['calling_hours_start'] ) ? wp_unslash( $_POST['calling_hours_start'] ) : 9 ) ),
			'calling_hours_end'     => min( 23, absint( isset( $_POST['calling_hours_end'] ) ? wp_unslash( $_POST['calling_hours_end'] ) : 21 ) ),
		);

		self::safe(
			function () use ( $values ) {
				VoicePress_Settings::update( $values );
				return true;
			},
			false
		);

		wp_safe_redirect( self::page_url( self::PAGE_AUTOMATIONS, array( 'updated' => '1' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Layout pieces
	 * ------------------------------------------------------------------ */

	/**
	 * Open the page: wrap, branded header bar, tabs, title row and setup hints.
	 *
	 * @param string $page     Current page slug.
	 * @param string $title    Page title.
	 * @param string $subtitle Subtitle.
	 * @param string $actions  Pre-escaped action buttons HTML.
	 */
	private static function render_header( $page, $title, $subtitle = '', $actions = '' ) {
		$state  = self::setup_state();
		$store  = (string) self::setting( 'store_name', get_bloginfo( 'name' ) );
		$groups = self::status_groups();
		$live   = $state['configured'] ? self::count_calls( array( 'status' => $groups['live']['statuses'] ) ) : 0;

		if ( ! $state['configured'] ) {
			$status_class = 'off';
			$status_text  = __( 'Not connected', 'voicepress' );
		} elseif ( $state['agents_created'] < 1 || ! $state['from_number'] ) {
			$status_class = 'setup';
			/* translators: 1: completed steps, 2: total steps. */
			$status_text = sprintf( __( 'Setup %1$d/%2$d', 'voicepress' ), $state['done'], 5 );
		} else {
			$status_class = 'live';
			/* translators: %d: number of agents. */
			$status_text = sprintf( _n( '%d agent live', '%d agents live', $state['agents_created'], 'voicepress' ), $state['agents_created'] );
		}
		?>
		<div class="wrap vp-wrap">
			<div class="vp-app" data-vp-page="<?php echo esc_attr( $page ); ?>">
				<header class="vp-topbar">
					<div class="vp-topbar__row">
						<a class="vp-brand" href="<?php echo esc_url( self::page_url( self::PAGE_DASHBOARD ) ); ?>">
							<?php self::logo_svg(); ?>
							<span class="vp-brand__name">VoicePress</span>
						</a>
						<span class="vp-pill"><?php esc_html_e( 'powered by', 'voicepress' ); ?> <strong>Bolna</strong></span>
						<span class="vp-topbar__spacer"></span>
						<a class="vp-store-status vp-store-status--<?php echo esc_attr( $status_class ); ?>" href="<?php echo esc_url( self::page_url( 'live' === $status_class ? self::PAGE_AGENTS : self::PAGE_SETTINGS ) ); ?>">
							<span class="vp-store-status__dot" aria-hidden="true"></span>
							<span class="vp-store-status__text">
								<span class="vp-store-status__name"><?php echo esc_html( '' !== $store ? $store : __( 'Your store', 'voicepress' ) ); ?></span>
								<span class="vp-store-status__state"><?php echo esc_html( $status_text ); ?></span>
							</span>
						</a>
					</div>
					<nav class="vp-nav" aria-label="<?php esc_attr_e( 'VoicePress sections', 'voicepress' ); ?>">
						<?php
						foreach ( self::pages() as $slug => $item ) :
							$active = ( $slug === $page );
							?>
							<a class="vp-nav__item<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::page_url( $slug ) ); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
								<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
								<span><?php echo esc_html( $item['label'] ); ?></span>
								<?php if ( self::PAGE_CALLS === $slug && $live > 0 ) : ?>
									<span class="vp-nav__count" title="<?php esc_attr_e( 'Calls in progress', 'voicepress' ); ?>"><?php echo esc_html( number_format_i18n( $live ) ); ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</nav>
				</header>
				<hr class="wp-header-end">
				<div class="vp-page-head">
					<div class="vp-page-head__text">
						<h1 class="vp-page-title"><?php echo esc_html( $title ); ?></h1>
						<?php if ( '' !== $subtitle ) : ?>
							<p class="vp-page-sub"><?php echo esc_html( $subtitle ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( '' !== $actions ) : ?>
						<div class="vp-page-actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts. ?></div>
					<?php endif; ?>
				</div>
				<?php
				if ( ! $state['configured'] && ! in_array( $page, array( self::PAGE_DASHBOARD, self::PAGE_SETTINGS, self::PAGE_AGENTS ), true ) ) {
					self::alert( 'warning', __( 'Connect your Bolna account to start placing AI calls.', 'voicepress' ), self::page_url( self::PAGE_SETTINGS ), __( 'Connect Bolna', 'voicepress' ) );
				} elseif ( $state['configured'] && $state['agents_created'] < $state['agents_total'] && ! in_array( $page, array( self::PAGE_DASHBOARD, self::PAGE_AGENTS ), true ) ) {
					self::alert(
						'info',
						/* translators: 1: missing agents, 2: total agents. */
						sprintf( __( '%1$d of %2$d AI agents still need to be created before every automation can call.', 'voicepress' ), $state['agents_total'] - $state['agents_created'], $state['agents_total'] ),
						self::page_url( self::PAGE_AGENTS ),
						__( 'Create agents', 'voicepress' )
					);
				}
	}

	/**
	 * Close the page wrappers.
	 */
	private static function render_footer() {
		echo '</div></div>';
	}

	/**
	 * Inline SVG logo mark (sound-wave bars on a white tile).
	 */
	private static function logo_svg() {
		?>
		<svg class="vp-brand__mark" width="34" height="34" viewBox="0 0 34 34" aria-hidden="true" focusable="false">
			<defs>
				<linearGradient id="vp-logo-grad" x1="0" y1="0" x2="1" y2="1">
					<stop offset="0" stop-color="#8b6cff"/>
					<stop offset="1" stop-color="#5b3df5"/>
				</linearGradient>
			</defs>
			<rect width="34" height="34" rx="10" fill="#ffffff"/>
			<g fill="url(#vp-logo-grad)">
				<rect x="8" y="14" width="3" height="6" rx="1.5"/>
				<rect x="13" y="10" width="3" height="14" rx="1.5"/>
				<rect x="18" y="7" width="3" height="20" rx="1.5"/>
				<rect x="23" y="12" width="3" height="10" rx="1.5"/>
			</g>
		</svg>
		<?php
	}

	/**
	 * "Live · auto-refreshing" indicator (state toggled by admin.js).
	 *
	 * @return string
	 */
	private static function live_indicator() {
		return '<span class="vp-live" data-vp-live-indicator><span class="vp-live__dot" aria-hidden="true"></span><span class="vp-live__text">' . esc_html__( 'Live · auto-refreshing', 'voicepress' ) . '</span></span>';
	}

	/**
	 * Escaped button/link HTML.
	 *
	 * @param string $label Label.
	 * @param array  $args  url, class, icon, attrs.
	 * @return string
	 */
	private static function button( $label, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'url'   => '',
				'class' => 'vp-btn--secondary',
				'icon'  => '',
				'attrs' => array(),
			)
		);
		$tag  = '' !== $args['url'] ? 'a' : 'button';
		$html = '<' . $tag . ' class="vp-btn ' . esc_attr( $args['class'] ) . '"';
		$html .= 'a' === $tag ? ' href="' . esc_url( $args['url'] ) . '"' : ' type="button"';
		foreach ( (array) $args['attrs'] as $name => $value ) {
			$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		$html .= '>';
		if ( '' !== $args['icon'] ) {
			$html .= '<span class="dashicons ' . esc_attr( $args['icon'] ) . '" aria-hidden="true"></span>';
		}
		$html .= '<span class="vp-btn__label">' . esc_html( $label ) . '</span></' . $tag . '>';
		return $html;
	}

	/**
	 * Print an alert box.
	 *
	 * @param string $tone    success|warning|danger|info.
	 * @param string $message Plain-text message.
	 * @param string $url     Optional CTA URL.
	 * @param string $cta     Optional CTA label.
	 */
	private static function alert( $tone, $message, $url = '', $cta = '' ) {
		$icons = array(
			'success' => 'dashicons-yes-alt',
			'warning' => 'dashicons-warning',
			'danger'  => 'dashicons-dismiss',
			'info'    => 'dashicons-info-outline',
		);
		$icon  = isset( $icons[ $tone ] ) ? $icons[ $tone ] : $icons['info'];
		?>
		<div class="vp-alert vp-alert--<?php echo esc_attr( $tone ); ?>" role="<?php echo 'danger' === $tone ? 'alert' : 'status'; ?>">
			<span class="vp-alert__icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<div class="vp-alert__body"><?php echo esc_html( $message ); ?></div>
			<?php if ( '' !== $url && '' !== $cta ) : ?>
				<div class="vp-alert__actions"><a class="vp-btn vp-btn--sm vp-btn--secondary" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $cta ); ?></a></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Print an empty state.
	 *
	 * @param string $icon    Dashicon class.
	 * @param string $title   Title.
	 * @param string $text    Text.
	 * @param array  $buttons Each: label, url, class.
	 * @param string $attr    Extra attribute name (flag) for JS hooks.
	 */
	private static function empty_state( $icon, $title, $text, $buttons = array(), $attr = '' ) {
		?>
		<div class="vp-empty"<?php echo '' !== $attr ? ' ' . esc_attr( $attr ) : ''; ?>>
			<span class="vp-empty__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span></span>
			<p class="vp-empty__title"><?php echo esc_html( $title ); ?></p>
			<p class="vp-empty__text"><?php echo esc_html( $text ); ?></p>
			<?php if ( $buttons ) : ?>
				<div class="vp-empty__actions">
					<?php foreach ( $buttons as $button ) : ?>
						<a class="vp-btn <?php echo esc_attr( $button['class'] ); ?>" href="<?php echo esc_url( $button['url'] ); ?>"><?php echo esc_html( $button['label'] ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Print a toggle switch (checkbox).
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Checked.
	 * @param string $label   Label.
	 * @param string $id      Input ID.
	 */
	private static function switch_field( $name, $checked, $label, $id ) {
		?>
		<label class="vp-switch" for="<?php echo esc_attr( $id ); ?>">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1"<?php checked( (bool) $checked ); ?>>
			<span class="vp-switch__track" aria-hidden="true"></span>
			<span class="vp-switch__label"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * Print hour <option>s (0–23).
	 *
	 * @param int $selected Selected hour.
	 */
	private static function hour_options( $selected ) {
		for ( $h = 0; $h < 24; $h++ ) {
			$label = gmdate( 'g A', $h * HOUR_IN_SECONDS );
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $h, selected( $selected, $h, false ), esc_html( $label ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Badges, cells and formatting
	 * ------------------------------------------------------------------ */

	/**
	 * Badge HTML. Tones: success, warning, danger, info, neutral, live, brand.
	 *
	 * @param string $label Label.
	 * @param string $tone  Tone.
	 * @param array  $attrs Extra attributes.
	 * @param string $class Extra classes.
	 * @return string
	 */
	public static function badge( $label, $tone = 'neutral', $attrs = array(), $class = '' ) {
		$html = '<span class="' . esc_attr( trim( 'vp-badge vp-badge--' . sanitize_html_class( $tone ) . ' ' . $class ) ) . '"';
		foreach ( (array) $attrs as $name => $value ) {
			$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		return $html . '>' . esc_html( $label ) . '</span>';
	}

	/**
	 * Badge tone for a call status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_tone( $status ) {
		$status = strtolower( (string) $status );
		if ( in_array( $status, self::LIVE_STATUSES, true ) ) {
			return 'live';
		}
		$map = array(
			'scheduled'         => 'neutral',
			'dispatching'       => 'info',
			'prepared'          => 'info',
			'queued'            => 'info',
			'rescheduled'       => 'info',
			'call-disconnected' => 'info',
			'completed'         => 'success',
			'no-answer'         => 'warning',
			'busy'              => 'warning',
			'balance-low'       => 'danger',
			'failed'            => 'danger',
			'error'             => 'danger',
			'canceled'          => 'neutral',
			'stopped'           => 'neutral',
			'skipped'           => 'neutral',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : 'neutral';
	}

	/**
	 * Badge tone for a call outcome.
	 *
	 * @param string $outcome Outcome.
	 * @return string
	 */
	public static function outcome_tone( $outcome ) {
		$outcome = strtolower( (string) $outcome );
		$map     = array(
			'confirmed'        => 'success',
			'available'        => 'success',
			'will_purchase'    => 'success',
			'positive'         => 'success',
			'yes'              => 'success',
			'resolved'         => 'success',
			'reschedule'       => 'warning',
			'address_change'   => 'warning',
			'needs_help'       => 'warning',
			'price_concern'    => 'warning',
			'unreachable'      => 'warning',
			'no'               => 'warning',
			'complaint'        => 'danger',
			'cancelled'        => 'danger',
			'cancel'           => 'danger',
			'not_interested'   => 'danger',
			'negative'         => 'danger',
			'wants_prepaid'    => 'info',
			'call_later'       => 'info',
			'order_status'     => 'info',
			'return_refund'    => 'info',
			'cancellation'     => 'info',
			'delivery_change'  => 'info',
			'product_question' => 'info',
			'neutral'          => 'neutral',
			'other'            => 'neutral',
			'no_decision'      => 'neutral',
		);
		return isset( $map[ $outcome ] ) ? $map[ $outcome ] : 'neutral';
	}

	/**
	 * Outcomes known to the dispositions (for JS labels/tones).
	 *
	 * @return string[]
	 */
	private static function known_outcomes() {
		return array( 'confirmed', 'cancelled', 'reschedule', 'address_change', 'wants_prepaid', 'no_decision', 'available', 'cancel', 'will_purchase', 'needs_help', 'price_concern', 'not_interested', 'call_later', 'positive', 'neutral', 'negative', 'order_status', 'return_refund', 'cancellation', 'delivery_change', 'product_question', 'complaint', 'other', 'yes', 'no', 'unreachable' );
	}

	/**
	 * Status badge for a call status (JS-updatable).
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_badge( $status ) {
		$status = (string) $status;
		$class  = in_array( $status, self::PENDING_STATUSES, true ) ? 'is-pending' : '';
		return self::badge( self::status_label( $status ), self::status_tone( $status ), array( 'data-vp-field' => 'status' ), $class );
	}

	/**
	 * Outcome badge + rating stars (JS-updatable wrapper).
	 *
	 * @param array $call Normalized call.
	 * @return string
	 */
	private static function outcome_html( $call ) {
		$html = '';
		if ( '' !== $call['outcome'] ) {
			$html .= self::badge( self::outcome_label( $call['outcome'] ), self::outcome_tone( $call['outcome'] ) );
		}
		if ( $call['rating'] > 0 ) {
			$html .= self::stars( $call['rating'] );
		}
		if ( '' === $html ) {
			$html = '<span class="vp-muted">—</span>';
		}
		return '<span class="vp-outcome" data-vp-field="outcome">' . $html . '</span>';
	}

	/**
	 * Star rating HTML.
	 *
	 * @param int $rating 1–5.
	 * @return string
	 */
	private static function stars( $rating ) {
		$rating = max( 0, min( 5, (int) $rating ) );
		if ( ! $rating ) {
			return '';
		}
		/* translators: %d: rating out of 5. */
		$label = sprintf( __( 'Rated %d out of 5', 'voicepress' ), $rating );
		return '<span class="vp-stars" role="img" aria-label="' . esc_attr( $label ) . '">' . str_repeat( '★', $rating ) . '<span class="vp-stars__off">' . str_repeat( '★', 5 - $rating ) . '</span></span>';
	}

	/**
	 * Avatar with initials (phone icon when there are no letters).
	 *
	 * @param string $name Display name.
	 * @param string $size '' or 'lg'.
	 * @return string
	 */
	private static function avatar_html( $name, $size = '' ) {
		$initials = self::initials( $name );
		$class    = 'vp-avatar' . ( '' !== $size ? ' vp-avatar--' . sanitize_html_class( $size ) : '' );
		$inner    = '' !== $initials ? esc_html( $initials ) : '<span class="dashicons dashicons-phone"></span>';
		return '<span class="' . esc_attr( $class ) . '" aria-hidden="true">' . $inner . '</span>';
	}

	/**
	 * Avatar + name (+ phone) cell.
	 *
	 * @param array $call       Normalized call.
	 * @param bool  $show_phone Show phone under the name.
	 * @return string
	 */
	private static function person_html( $call, $show_phone = true ) {
		$name    = $call['customer_name'];
		$phone   = $call['phone'];
		$display = '' !== $name ? $name : ( '' !== $phone ? $phone : __( 'Unknown caller', 'voicepress' ) );
		$html    = '<span class="vp-person">' . self::avatar_html( '' !== $name ? $name : '' );
		$html   .= '<span class="vp-person__text"><span class="vp-person__name">' . esc_html( $display ) . '</span>';
		if ( $show_phone && '' !== $name && '' !== $phone ) {
			$html .= '<span class="vp-person__sub">' . esc_html( $phone ) . '</span>';
		}
		return $html . '</span></span>';
	}

	/**
	 * Use case with icon.
	 *
	 * @param string $use_case  Use case.
	 * @param string $direction Direction (optional, shown as tooltip).
	 * @return string
	 */
	private static function use_case_html( $use_case, $direction = '' ) {
		$title = '' !== $direction ? ' title="' . esc_attr( self::direction_label( $direction ) ) . '"' : '';
		return '<span class="vp-usecase"' . $title . '><span class="dashicons ' . esc_attr( self::use_case_icon( $use_case ) ) . '" aria-hidden="true"></span><span>' . esc_html( self::use_case_label( $use_case ) ) . '</span></span>';
	}

	/**
	 * Order link (#number → edit screen), HPOS-safe.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function order_link( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return '<span class="vp-muted">—</span>';
		}
		$order = self::get_order( $order_id );
		if ( ! $order ) {
			return '<span class="vp-muted">#' . esc_html( $order_id ) . '</span>';
		}
		return '<a class="vp-order-link" href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
	}

	/**
	 * Initials from a name ('' when the name has no letters).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function initials( $name ) {
		$clean = trim( (string) preg_replace( '/[^\p{L}\s]/u', '', (string) $name ) );
		if ( '' === $clean ) {
			return '';
		}
		$out = '';
		foreach ( array_slice( (array) preg_split( '/\s+/u', $clean ), 0, 2 ) as $part ) {
			$out .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 );
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $out ) : strtoupper( $out );
	}

	/**
	 * Dashicon for a use case.
	 *
	 * @param string $use_case Use case.
	 * @return string
	 */
	private static function use_case_icon( $use_case ) {
		$map = array(
			'cod_confirmation'  => 'dashicons-money-alt',
			'delivery_reminder' => 'dashicons-car',
			'ndr_resolution'    => 'dashicons-location',
			'cart_recovery'     => 'dashicons-cart',
			'feedback'          => 'dashicons-star-filled',
			'support_callback'  => 'dashicons-phone',
			'web_call'          => 'dashicons-microphone',
			'inbound'           => 'dashicons-format-chat',
			'test'              => 'dashicons-admin-tools',
		);
		return isset( $map[ $use_case ] ) ? $map[ $use_case ] : 'dashicons-phone';
	}

	/**
	 * Human direction label.
	 *
	 * @param string $direction outbound|inbound|web.
	 * @return string
	 */
	private static function direction_label( $direction ) {
		switch ( (string) $direction ) {
			case 'inbound':
				return __( 'Inbound call', 'voicepress' );
			case 'web':
				return __( 'Web voice call', 'voicepress' );
			case 'outbound':
				return __( 'Outbound call', 'voicepress' );
			default:
				return self::humanize( $direction );
		}
	}

	/**
	 * Store-currency money string (mirrored in admin.js).
	 *
	 * @param float  $amount   Amount.
	 * @param int    $decimals Decimals.
	 * @param string $currency Currency code ('' = store currency).
	 * @return string
	 */
	private static function money( $amount, $decimals = 0, $currency = '' ) {
		$format = self::currency_format( $currency );
		$number = number_format_i18n( (float) $amount, $decimals );
		switch ( $format['position'] ) {
			case 'right':
				return $number . $format['symbol'];
			case 'right_space':
				return $number . ' ' . $format['symbol'];
			case 'left_space':
				return $format['symbol'] . ' ' . $number;
			default:
				return $format['symbol'] . $number;
		}
	}

	/**
	 * Currency symbol + position.
	 *
	 * @param string $currency Currency code ('' = store currency).
	 * @return array
	 */
	private static function currency_format( $currency = '' ) {
		$symbol   = '₹';
		$position = 'left';
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol   = html_entity_decode( get_woocommerce_currency_symbol( (string) $currency ), ENT_QUOTES, 'UTF-8' );
			$position = (string) get_option( 'woocommerce_currency_pos', 'left' );
		}
		return array(
			'symbol'   => $symbol,
			'position' => $position,
		);
	}

	/**
	 * Duration string ('1m 49s').
	 *
	 * @param mixed $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return '—';
		}
		if ( class_exists( 'VoicePress_Helpers' ) && method_exists( 'VoicePress_Helpers', 'format_duration' ) ) {
			return (string) VoicePress_Helpers::format_duration( $seconds );
		}
		$minutes = (int) floor( $seconds / 60 );
		return $minutes ? sprintf( '%dm %ds', $minutes, $seconds % 60 ) : sprintf( '%ds', $seconds );
	}

	/**
	 * Cost string from Bolna cents.
	 *
	 * @param mixed $cents Cost in cents.
	 * @return string
	 */
	private static function cost( $cents ) {
		if ( null === $cents || '' === $cents ) {
			return '—';
		}
		return '$' . number_format_i18n( (float) $cents / 100, 2 );
	}

	/**
	 * Local date/time from a UTC MySQL datetime.
	 *
	 * @param string $gmt    UTC datetime.
	 * @param string $format Format ('' = site date + time format).
	 * @return string
	 */
	private static function local_time( $gmt, $format = '' ) {
		$gmt = (string) $gmt;
		if ( '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
			return '';
		}
		if ( '' === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}
		return get_date_from_gmt( $gmt, $format );
	}

	/**
	 * Relative time ("5 min ago").
	 *
	 * @param string $gmt UTC datetime.
	 * @return string
	 */
	private static function human_time( $gmt ) {
		$gmt = (string) $gmt;
		if ( '' === $gmt ) {
			return '';
		}
		if ( class_exists( 'VoicePress_Helpers' ) && method_exists( 'VoicePress_Helpers', 'human_time' ) ) {
			return (string) VoicePress_Helpers::human_time( $gmt );
		}
		$ts = strtotime( $gmt . ' UTC' );
		/* translators: %s: human time difference. */
		return $ts ? sprintf( __( '%s ago', 'voicepress' ), human_time_diff( $ts, time() ) ) : '';
	}

	/**
	 * 'in-progress' → 'In progress'.
	 *
	 * @param string $value Slug.
	 * @return string
	 */
	private static function humanize( $value ) {
		return ucfirst( str_replace( array( '_', '-' ), ' ', (string) $value ) );
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function page_url( $page = self::PAGE_DASHBOARD, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), (array) $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Call detail URL.
	 *
	 * @param int $id Call ID.
	 * @return string
	 */
	public static function call_url( $id ) {
		return self::page_url( self::PAGE_CALLS, array( 'call' => absint( $id ) ) );
	}

	/**
	 * Recording proxy URL (REST, cookie + nonce auth).
	 *
	 * @param int $id Call ID.
	 * @return string
	 */
	private static function recording_url( $id ) {
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'voicepress/v1/admin/recording/' . absint( $id ) ) );
	}

	/**
	 * Order edit URL template with %d (for JS-rendered rows).
	 *
	 * @return string
	 */
	private static function order_url_template() {
		$hpos = (bool) self::safe(
			function () {
				return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			},
			false
		);
		return $hpos ? admin_url( 'admin.php?page=wc-orders&action=edit&id=%d' ) : admin_url( 'post.php?post=%d&action=edit' );
	}

	/* ---------------------------------------------------------------------
	 * Data access (defensive wrappers around other modules)
	 * ------------------------------------------------------------------ */

	/**
	 * Run a callback that touches another module; never let it fatal a screen.
	 *
	 * @param callable $callback Callback.
	 * @param mixed    $fallback Value on error or null.
	 * @return mixed
	 */
	private static function safe( $callback, $fallback = null ) {
		try {
			$result = call_user_func( $callback );
			return null === $result ? $fallback : $result;
		} catch ( \Throwable $e ) {
			if ( class_exists( 'VoicePress_Helpers' ) && method_exists( 'VoicePress_Helpers', 'log' ) ) {
				VoicePress_Helpers::log( 'Admin UI: ' . $e->getMessage() );
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[VoicePress] Admin UI: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $fallback;
		}
	}

	/**
	 * Abort unless the user may manage the store.
	 */
	private static function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access VoicePress.', 'voicepress' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Whether a "1" flag is in the query string (post-redirect notices).
	 *
	 * @param string $key Query key.
	 * @return bool
	 */
	private static function flag( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		return isset( $_GET[ $key ] ) && '1' === sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Full settings array with every key present.
	 *
	 * @return array
	 */
	private static function settings() {
		$defaults = array(
			'api_key'               => '',
			'from_number'           => '',
			'phone_number_id'       => '',
			'telephony_provider'    => 'twilio',
			'public_url'            => '',
			'secret'                => '',
			'store_name'            => get_bloginfo( 'name' ),
			'agent_persona'         => 'Riya',
			'default_country_code'  => '91',
			'return_window_days'    => 7,
			'delivery_eta'          => '3 to 5 working days',
			'support_hours'         => '9 AM to 9 PM, all days',
			'store_policies'        => '',
			'agents'                => array(),
			'automations'           => array(),
			'respect_calling_hours' => 0,
			'calling_hours_start'   => 9,
			'calling_hours_end'     => 21,
			'max_attempts'          => 2,
			'retry_after'           => 30,
			'widget_enabled'        => 1,
			'widget_label'          => 'Talk to us',
			'cart_discount_percent' => 10,
		);
		$settings = self::safe(
			function () {
				return VoicePress_Settings::get();
			},
			array()
		);
		$settings = array_merge( $defaults, is_array( $settings ) ? $settings : array() );
		if ( ! is_array( $settings['agents'] ) ) {
			$settings['agents'] = array();
		}
		if ( ! is_array( $settings['automations'] ) ) {
			$settings['automations'] = array();
		}
		return $settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private static function setting( $key, $default = null ) {
		$settings = self::settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Whether an API key is saved.
	 *
	 * @return bool
	 */
	private static function is_configured() {
		return (bool) self::safe(
			function () {
				return VoicePress_Settings::is_configured();
			},
			false
		);
	}

	/**
	 * Public URL ('' when unset).
	 *
	 * @return string
	 */
	private static function public_url() {
		return (string) self::safe(
			function () {
				return VoicePress_Settings::public_url();
			},
			''
		);
	}

	/**
	 * Whether agents get live order tools (needs a public URL Bolna can reach).
	 *
	 * @return bool
	 */
	private static function tools_enabled() {
		return (bool) self::safe(
			function () {
				return method_exists( 'VoicePress_Agents', 'tools_enabled' ) ? VoicePress_Agents::tools_enabled() : '' !== VoicePress_Settings::public_url();
			},
			false
		);
	}

	/**
	 * Webhook/tool secret.
	 *
	 * @return string
	 */
	private static function secret() {
		return (string) self::safe(
			function () {
				return VoicePress_Settings::secret();
			},
			''
		);
	}

	/**
	 * Masked API key.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	private static function mask_key( $key ) {
		$key    = (string) $key;
		$masked = self::safe(
			function () use ( $key ) {
				return VoicePress_Settings::mask_key( $key );
			},
			''
		);
		if ( is_string( $masked ) && '' !== $masked ) {
			return $masked;
		}
		return strlen( $key ) > 8 ? substr( $key, 0, 3 ) . '…' . substr( $key, -5 ) : '••••';
	}

	/**
	 * Automation config for a use case.
	 *
	 * @param string $use_case Use case.
	 * @return array enabled (bool), delay (int).
	 */
	private static function automation( $use_case ) {
		$auto = self::safe(
			function () use ( $use_case ) {
				return VoicePress_Settings::automation( $use_case );
			},
			array()
		);
		$auto = is_array( $auto ) ? $auto : array();
		return array(
			'enabled' => ! empty( $auto['enabled'] ),
			'delay'   => isset( $auto['delay'] ) ? max( 0, (int) $auto['delay'] ) : 0,
		);
	}

	/**
	 * Setup progress.
	 *
	 * @param bool $fresh Recompute (after saving).
	 * @return array
	 */
	private static function setup_state( $fresh = false ) {
		static $state = null;
		if ( null !== $state && ! $fresh ) {
			return $state;
		}
		$settings = self::settings();
		$keys     = self::agent_keys();
		$created  = 0;
		foreach ( $keys as $key ) {
			if ( ! empty( $settings['agents'][ $key ] ) ) {
				++$created;
			}
		}
		$state                 = array(
			'configured'     => self::is_configured(),
			'agents_total'   => count( $keys ),
			'agents_created' => $created,
			'agents_ready'   => count( $keys ) > 0 && $created >= count( $keys ),
			'from_number'    => '' !== trim( (string) $settings['from_number'] ),
			'public_url'     => '' !== self::public_url(),
			'inbound'        => '' !== trim( (string) $settings['phone_number_id'] ),
		);
		$state['done']         = (int) $state['configured'] + (int) $state['agents_ready'] + (int) $state['from_number'] + (int) $state['public_url'] + (int) $state['inbound'];
		return $state;
	}

	/**
	 * DB stats with every key present.
	 *
	 * @return array
	 */
	private static function stats() {
		$defaults = array(
			'total_calls'          => 0,
			'calls_today'          => 0,
			'connected'            => 0,
			'connect_rate'         => 0,
			'total_minutes'        => 0,
			'total_cost'           => 0,
			'cod_confirmed'        => 0,
			'cod_cancelled'        => 0,
			'rto_saved_value'      => 0,
			'carts_recovered'      => 0,
			'cart_recovered_value' => 0,
			'feedback_avg'         => 0,
			'feedback_count'       => 0,
			'by_use_case'          => array(),
			'outcomes'             => array(),
		);
		$stats    = self::safe(
			function () {
				return VoicePress_DB::stats();
			},
			array()
		);
		return array_merge( $defaults, is_array( $stats ) ? $stats : array() );
	}

	/**
	 * Call rows.
	 *
	 * @param array $args list_calls args.
	 * @return array[]
	 */
	private static function list_calls( $args ) {
		$rows = self::safe(
			function () use ( $args ) {
				return VoicePress_DB::list_calls( $args );
			},
			array()
		);
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Count call rows.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	private static function count_calls( $args ) {
		return (int) self::safe(
			function () use ( $args ) {
				return VoicePress_DB::count_calls( $args );
			},
			0
		);
	}

	/**
	 * One call row.
	 *
	 * @param int $id Call ID.
	 * @return array|null
	 */
	private static function get_call( $id ) {
		$row = self::safe(
			function () use ( $id ) {
				return VoicePress_DB::get_call( (int) $id );
			},
			null
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Recent carts.
	 *
	 * @param int $limit Limit.
	 * @return array[]
	 */
	private static function list_carts( $limit ) {
		$rows = self::safe(
			function () use ( $limit ) {
				return VoicePress_DB::list_carts( (int) $limit );
			},
			array()
		);
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Call row with every field present and typed for display.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function normalize_call( $row ) {
		$row  = is_array( $row ) ? $row : array();
		$text = function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
		};
		$json = function ( $key ) use ( $row ) {
			if ( ! isset( $row[ $key ] ) ) {
				return array();
			}
			if ( is_array( $row[ $key ] ) ) {
				return $row[ $key ];
			}
			$decoded = json_decode( (string) $row[ $key ], true );
			return is_array( $decoded ) ? $decoded : array();
		};
		return array(
			'id'              => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'execution_id'    => $text( 'execution_id' ),
			'use_case'        => $text( 'use_case' ),
			'direction'       => $text( 'direction' ),
			'agent_id'        => $text( 'agent_id' ),
			'order_id'        => isset( $row['order_id'] ) ? (int) $row['order_id'] : 0,
			'customer_name'   => $text( 'customer_name' ),
			'phone'           => $text( 'phone' ),
			'status'          => '' !== $text( 'status' ) ? $text( 'status' ) : 'scheduled',
			'outcome'         => $text( 'outcome' ),
			'outcome_applied' => ! empty( $row['outcome_applied'] ),
			'rating'          => isset( $row['rating'] ) ? (int) $row['rating'] : 0,
			'summary'         => $text( 'summary' ),
			'transcript'      => isset( $row['transcript'] ) && is_string( $row['transcript'] ) ? $row['transcript'] : '',
			'recording_url'   => $text( 'recording_url' ),
			'duration'        => isset( $row['duration'] ) ? (int) $row['duration'] : 0,
			'cost'            => isset( $row['cost'] ) && '' !== (string) $row['cost'] ? (float) $row['cost'] : null,
			'extracted'       => $json( 'extracted' ),
			'user_data'       => $json( 'user_data' ),
			'attempt'         => isset( $row['attempt'] ) ? (int) $row['attempt'] : 1,
			'error'           => $text( 'error' ),
			'scheduled_at'    => $text( 'scheduled_at' ),
			'created_at'      => $text( 'created_at' ),
			'updated_at'      => $text( 'updated_at' ),
		);
	}

	/**
	 * Ticket entry normalised from the voicepress_tickets option.
	 *
	 * @param mixed $ticket Ticket (assoc or list).
	 * @return array
	 */
	private static function normalize_ticket( $ticket ) {
		$ticket = is_array( $ticket ) ? $ticket : array( 'summary' => (string) $ticket );
		$keys   = array( 'time', 'summary', 'priority', 'order_id', 'phone', 'source' );
		$out    = array();
		foreach ( $keys as $i => $key ) {
			$value       = isset( $ticket[ $key ] ) ? $ticket[ $key ] : ( isset( $ticket[ $i ] ) ? $ticket[ $i ] : '' );
			$out[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		$out['priority'] = strtolower( $out['priority'] );
		$out['order_id'] = absint( $out['order_id'] );
		$out['when']     = '';
		if ( '' !== $out['time'] ) {
			$out['when'] = is_numeric( $out['time'] )
				/* translators: %s: human time difference. */
				? sprintf( __( '%s ago', 'voicepress' ), human_time_diff( (int) $out['time'], time() ) )
				: self::human_time( $out['time'] );
		}
		return $out;
	}

	/**
	 * WC order (or null), never throwing.
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order|null
	 */
	private static function get_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = self::safe(
			function () use ( $order_id ) {
				return wc_get_order( $order_id );
			},
			null
		);
		return ( $order instanceof WC_Order ) ? $order : null;
	}

	/**
	 * Orders flagged with _voicepress_escalation (HPOS + legacy safe).
	 *
	 * @param int $limit Limit.
	 * @return WC_Order[]
	 */
	private static function escalated_orders( $limit ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = self::safe(
			function () use ( $limit ) {
				return wc_get_orders(
					array(
						'limit'        => (int) $limit,
						'type'         => 'shop_order',
						'orderby'      => 'date',
						'order'        => 'DESC',
						'meta_key'     => '_voicepress_escalation', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_compare' => 'EXISTS',
					)
				);
			},
			array()
		);
		return is_array( $orders ) ? array_values(
			array_filter(
				$orders,
				function ( $order ) {
					return $order instanceof WC_Order;
				}
			)
		) : array();
	}

	/**
	 * Agent keys.
	 *
	 * @return string[]
	 */
	private static function agent_keys() {
		$raw = defined( 'VoicePress_Agents::AGENT_KEYS' ) ? constant( 'VoicePress_Agents::AGENT_KEYS' ) : self::DEFAULT_AGENT_KEYS;
		$out = array();
		foreach ( (array) $raw as $key => $value ) {
			$out[] = is_string( $key ) ? $key : (string) $value;
		}
		return $out ? $out : self::DEFAULT_AGENT_KEYS;
	}

	/**
	 * Agent labels (agent_key => label, description, use_cases, icon).
	 *
	 * @return array
	 */
	private static function agent_labels() {
		$fallback = array(
			'cod_confirmation' => array( __( 'COD confirmation', 'voicepress' ), __( 'Calls new cash-on-delivery orders to confirm before you ship — fewer fake orders and RTO.', 'voicepress' ), array( 'cod_confirmation' ), 'dashicons-money-alt' ),
			'delivery'         => array( __( 'Delivery', 'voicepress' ), __( 'Out-for-delivery reminders and failed-delivery (NDR) rescue calls.', 'voicepress' ), array( 'delivery_reminder', 'ndr_resolution' ), 'dashicons-car' ),
			'cart_recovery'    => array( __( 'Cart recovery', 'voicepress' ), __( 'Calls shoppers who left checkout, answers questions and offers a one-time discount.', 'voicepress' ), array( 'cart_recovery' ), 'dashicons-cart' ),
			'feedback'         => array( __( 'Feedback & reviews', 'voicepress' ), __( 'Checks in after delivery, collects a 1–5 rating and flags unhappy customers.', 'voicepress' ), array( 'feedback' ), 'dashicons-star-filled' ),
			'support'          => array( __( 'Support', 'voicepress' ), __( 'Answers inbound calls and website voice chats 24×7 — order status, returns, cancellations, address changes.', 'voicepress' ), array( 'support_callback', 'web_call', 'inbound' ), 'dashicons-format-chat' ),
		);
		$raw      = self::safe(
			function () {
				return VoicePress_Agents::labels();
			},
			array()
		);
		$raw      = is_array( $raw ) ? $raw : array();
		$out      = array();
		foreach ( self::agent_keys() as $key ) {
			$info = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
			$def  = isset( $fallback[ $key ] ) ? $fallback[ $key ] : array( self::humanize( $key ), '', array(), 'dashicons-microphone' );
			$icon = (string) self::pick( $info, array( 'icon', 3 ), $def[3] );
			if ( 0 !== strpos( $icon, 'dashicons-' ) ) {
				$icon = 'dashicons-' . $icon;
			}
			$out[ $key ] = array(
				'label'       => (string) self::pick( $info, array( 'label', 0 ), $def[0] ),
				'description' => (string) self::pick( $info, array( 'description', 1 ), $def[1] ),
				'use_cases'   => (array) self::pick( $info, array( 'use_cases', 2 ), $def[2] ),
				'icon'        => sanitize_html_class( $icon ),
			);
		}
		return $out;
	}

	/**
	 * First non-empty value among keys.
	 *
	 * @param array $arr      Array.
	 * @param array $keys     Keys to try.
	 * @param mixed $fallback Fallback.
	 * @return mixed
	 */
	private static function pick( $arr, $keys, $fallback ) {
		foreach ( $keys as $key ) {
			if ( isset( $arr[ $key ] ) && '' !== $arr[ $key ] && array() !== $arr[ $key ] ) {
				return $arr[ $key ];
			}
		}
		return $fallback;
	}

	/**
	 * Agent display name for a Bolna agent ID.
	 *
	 * @param string $agent_id Agent ID.
	 * @return string
	 */
	private static function agent_name_for_id( $agent_id ) {
		if ( '' === (string) $agent_id ) {
			return '';
		}
		$settings = self::settings();
		$labels   = self::agent_labels();
		foreach ( $settings['agents'] as $key => $id ) {
			if ( (string) $id === (string) $agent_id && isset( $labels[ $key ] ) ) {
				return $labels[ $key ]['label'];
			}
		}
		return (string) $agent_id;
	}

	/**
	 * Tool names wired to an agent.
	 *
	 * @param string $agent_key Agent key.
	 * @return string[]
	 */
	private static function tool_names( $agent_key ) {
		$tools = self::safe(
			function () use ( $agent_key ) {
				return VoicePress_Agents::tool_names( $agent_key );
			},
			array()
		);
		return is_array( $tools ) ? array_values( array_filter( array_map( 'strval', $tools ) ) ) : array();
	}

	/**
	 * Agent key for a use case.
	 *
	 * @param string $use_case Use case.
	 * @return string
	 */
	private static function use_case_agent_key( $use_case ) {
		$fallback = array(
			'cod_confirmation'  => 'cod_confirmation',
			'delivery_reminder' => 'delivery',
			'ndr_resolution'    => 'delivery',
			'cart_recovery'     => 'cart_recovery',
			'feedback'          => 'feedback',
			'support_callback'  => 'support',
			'web_call'          => 'support',
			'inbound'           => 'support',
		);
		$key      = self::safe(
			function () use ( $use_case ) {
				return VoicePress_Agents::use_case_agent_key( $use_case );
			},
			''
		);
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}
		return isset( $fallback[ $use_case ] ) ? $fallback[ $use_case ] : '';
	}

	/**
	 * Use case labels.
	 *
	 * @return array use_case => label.
	 */
	private static function use_case_labels() {
		$fallback = array(
			'cod_confirmation'  => __( 'COD confirmation', 'voicepress' ),
			'delivery_reminder' => __( 'Delivery reminder', 'voicepress' ),
			'ndr_resolution'    => __( 'Failed delivery (NDR)', 'voicepress' ),
			'cart_recovery'     => __( 'Cart recovery', 'voicepress' ),
			'feedback'          => __( 'Feedback & review', 'voicepress' ),
			'support_callback'  => __( 'Support callback', 'voicepress' ),
			'web_call'          => __( 'Web voice call', 'voicepress' ),
			'inbound'           => __( 'Inbound call', 'voicepress' ),
			'test'              => __( 'Test call', 'voicepress' ),
		);
		$labels   = self::safe(
			function () {
				return VoicePress_Agents::use_case_labels();
			},
			array()
		);
		return array_merge( $fallback, is_array( $labels ) ? array_filter( $labels, 'is_string' ) : array() );
	}

	/**
	 * Use case label.
	 *
	 * @param string $use_case Use case.
	 * @return string
	 */
	private static function use_case_label( $use_case ) {
		$labels = self::use_case_labels();
		return isset( $labels[ $use_case ] ) ? $labels[ $use_case ] : ( '' !== (string) $use_case ? self::humanize( $use_case ) : __( 'Call', 'voicepress' ) );
	}

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$label = self::safe(
			function () use ( $status ) {
				return VoicePress_Calls::status_label( (string) $status );
			},
			''
		);
		return is_string( $label ) && '' !== $label ? $label : self::humanize( $status );
	}

	/**
	 * Outcome label.
	 *
	 * @param string $outcome Outcome.
	 * @return string
	 */
	private static function outcome_label( $outcome ) {
		$label = self::safe(
			function () use ( $outcome ) {
				return VoicePress_Calls::outcome_label( (string) $outcome );
			},
			''
		);
		return is_string( $label ) && '' !== $label ? $label : self::humanize( $outcome );
	}

	/**
	 * Terminal call statuses.
	 *
	 * @return string[]
	 */
	private static function terminal_statuses() {
		$terminal = defined( 'VoicePress_Calls::TERMINAL' ) ? constant( 'VoicePress_Calls::TERMINAL' ) : self::DEFAULT_TERMINAL;
		return is_array( $terminal ) && $terminal ? array_values( $terminal ) : self::DEFAULT_TERMINAL;
	}

	/**
	 * Status filter groups for the calls screen.
	 *
	 * @return array key => label, statuses.
	 */
	private static function status_groups() {
		return array(
			'live'        => array(
				'label'    => __( 'Live & queued', 'voicepress' ),
				'statuses' => array( 'dispatching', 'prepared', 'queued', 'rescheduled', 'initiated', 'ringing', 'in-progress', 'call-disconnected' ),
			),
			'completed'   => array(
				'label'    => __( 'Completed', 'voicepress' ),
				'statuses' => array( 'completed' ),
			),
			'unreachable' => array(
				'label'    => __( 'Not answered', 'voicepress' ),
				'statuses' => array( 'no-answer', 'busy' ),
			),
			'failed'      => array(
				'label'    => __( 'Failed', 'voicepress' ),
				'statuses' => array( 'failed', 'error', 'balance-low', 'canceled', 'stopped' ),
			),
			'scheduled'   => array(
				'label'    => __( 'Scheduled', 'voicepress' ),
				'statuses' => array( 'scheduled' ),
			),
			'skipped'     => array(
				'label'    => __( 'Skipped', 'voicepress' ),
				'statuses' => array( 'skipped' ),
			),
		);
	}

	/**
	 * Plugin asset URL.
	 *
	 * @param string $rel Path relative to the plugin root.
	 * @return string
	 */
	private static function asset_url( $rel ) {
		$base = defined( 'VOICEPRESS_URL' ) ? VOICEPRESS_URL : plugin_dir_url( dirname( __FILE__ ) );
		return $base . $rel;
	}

	/**
	 * Cache-busting asset version (plugin version + file mtime).
	 *
	 * @param string $rel Path relative to the plugin root.
	 * @return string
	 */
	private static function asset_version( $rel ) {
		$version = defined( 'VOICEPRESS_VERSION' ) ? VOICEPRESS_VERSION : '0.1.0';
		$dir     = defined( 'VOICEPRESS_DIR' ) ? VOICEPRESS_DIR : plugin_dir_path( dirname( __FILE__ ) );
		$file    = $dir . $rel;
		return file_exists( $file ) ? $version . '.' . filemtime( $file ) : $version;
	}
}
