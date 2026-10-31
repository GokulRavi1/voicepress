/* global jQuery, VoicePressAdmin */
/**
 * VoicePress admin.
 *
 * REST calls (X-WP-Nonce), live polling (POST admin/tick every 10s, then refresh
 * visible non-terminal call badges via GET admin/call/{id}), "Call me now" test
 * calls, agent provisioning, inbound linking, Bolna connection test, abandoned
 * cart simulation, call sync, and the WooCommerce order meta box.
 */
(function ($, VP) {
	'use strict';

	if (!$ || !VP || !VP.rest) {
		return;
	}

	var I18N = VP.i18n || {};
	var STATUSES = VP.statuses || {};
	var OUTCOMES = VP.outcomes || {};
	var USE_CASES = VP.useCases || {};
	var USE_CASE_ICONS = VP.useCaseIcons || {};
	var TERMINAL = Array.isArray(VP.terminal) && VP.terminal.length ? VP.terminal : ['completed', 'no-answer', 'busy', 'failed', 'canceled', 'stopped', 'error', 'balance-low', 'skipped'];
	var FAILED = ['failed', 'error', 'skipped', 'balance-low', 'canceled', 'stopped'];
	var POLL_MS = Math.max(5000, parseInt(VP.pollInterval, 10) || 10000);

	/* ------------------------------------------------------------------
	 * Small helpers
	 * ---------------------------------------------------------------- */

	function t(key, fallback) {
		return (I18N && I18N[key]) || fallback || '';
	}

	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(str).replace(/%[sd]/g, function () {
			var value = args[i++];
			return value === undefined ? '' : String(value);
		});
	}

	function esc(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function humanize(value) {
		var s = String(value || '').replace(/[_-]+/g, ' ').trim();
		return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
	}

	function truncate(text, max) {
		text = String(text || '');
		return text.length > max ? text.slice(0, max - 1).replace(/\s+\S*$/, '') + '…' : text;
	}

	function repeat(str, n) {
		var out = '';
		for (var i = 0; i < n; i++) {
			out += str;
		}
		return out;
	}

	function safeId(id) {
		return String(id === undefined || id === null ? '' : id).replace(/[^0-9A-Za-z_-]/g, '');
	}

	function storage(op, key, value) {
		try {
			if (op === 'get') {
				return window.localStorage.getItem(key) || '';
			}
			window.localStorage.setItem(key, value);
		} catch (e) {
			// Storage blocked (private mode, policies): ignore.
		}
		return '';
	}

	function restUrl(path) {
		return VP.rest + String(path).replace(/^\/+/, '');
	}

	function callUrl(id) {
		var base = String(VP.callsUrl || '');
		return base + (base.indexOf('?') === -1 ? '?' : '&') + 'call=' + encodeURIComponent(id);
	}

	function orderUrl(id) {
		return VP.orderUrl ? String(VP.orderUrl).replace('%d', encodeURIComponent(id)) : '';
	}

	/* ------------------------------------------------------------------
	 * REST
	 * ---------------------------------------------------------------- */

	function makeError(message, status, data) {
		var err = new Error(message || t('genericError', 'Something went wrong. Please try again.'));
		err.status = status || 0;
		err.data = data || null;
		return err;
	}

	/**
	 * Call a voicepress/v1 route. Resolves with the JSON body; rejects with an Error
	 * whose message is safe to show (server message when available).
	 */
	function api(method, path, data) {
		return new Promise(function (resolve, reject) {
			var opts = {
				url: restUrl(path),
				method: method,
				dataType: 'json',
				cache: false,
				timeout: 90000,
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', VP.nonce);
				}
			};
			if (method !== 'GET') {
				opts.contentType = 'application/json; charset=utf-8';
				opts.data = JSON.stringify(data || {});
			}
			$.ajax(opts)
				.done(function (res) {
					if (res && typeof res === 'object' && res.ok === false) {
						reject(makeError(res.message, 200, res));
						return;
					}
					resolve(res);
				})
				.fail(function (xhr, textStatus) {
					var json = xhr && xhr.responseJSON;
					var status = xhr ? xhr.status : 0;
					var msg = json && json.message ? String(json.message) : '';
					if (json && json.code === 'rest_cookie_invalid_nonce') {
						msg = t('sessionExpired', 'Your session expired. Reload the page and try again.');
					}
					if (!msg) {
						if (status === 0 || textStatus === 'timeout') {
							msg = t('networkError', 'Could not reach your site. Check your connection and try again.');
						} else if (status === 401 || status === 403) {
							msg = t('sessionExpired', 'Your session expired. Reload the page and try again.');
						} else {
							msg = t('genericError', 'Something went wrong. Please try again.');
						}
					}
					reject(makeError(msg, status, json));
				});
		});
	}

	/** Find a call row in a REST response ({call}, {row}, {data}, or the row itself). */
	function extractCall(res) {
		if (!res || typeof res !== 'object') {
			return null;
		}
		var candidates = [res.call, res.row, res.result, res.data && res.data.call, res.data, res];
		for (var i = 0; i < candidates.length; i++) {
			var c = candidates[i];
			if (c && typeof c === 'object' && !Array.isArray(c) && c.id && (c.status !== undefined || c.use_case !== undefined)) {
				return c;
			}
		}
		return null;
	}

	/** Find a list of rows in a REST response. */
	function extractList(res) {
		if (Array.isArray(res)) {
			return res;
		}
		if (!res || typeof res !== 'object') {
			return [];
		}
		var keys = ['calls', 'items', 'rows', 'data', 'results'];
		for (var i = 0; i < keys.length; i++) {
			if (Array.isArray(res[keys[i]])) {
				return res[keys[i]];
			}
		}
		return [];
	}

	/* ------------------------------------------------------------------
	 * Formatting (mirrors PHP)
	 * ---------------------------------------------------------------- */

	function isTerminal(status) {
		return TERMINAL.indexOf(String(status || '')) !== -1;
	}

	function statusInfo(status) {
		var s = STATUSES[status] || {};
		return {
			label: s.label || humanize(status || 'scheduled'),
			tone: s.tone || 'neutral',
			pending: !!s.pending
		};
	}

	function outcomeInfo(outcome) {
		var o = OUTCOMES[outcome] || {};
		return { label: o.label || humanize(outcome), tone: o.tone || 'neutral' };
	}

	function useCaseLabel(useCase) {
		return USE_CASES[useCase] || humanize(useCase || 'call');
	}

	function badgeHtml(label, tone, extraClass, attrs) {
		return '<span class="vp-badge vp-badge--' + esc(tone || 'neutral') + (extraClass ? ' ' + esc(extraClass) : '') + '"' + (attrs || '') + '>' + esc(label) + '</span>';
	}

	function statusBadgeHtml(call) {
		var info = statusInfo(call.status || 'scheduled');
		return badgeHtml(call.status_label || info.label, info.tone, info.pending ? 'is-pending' : '', ' data-vp-field="status"');
	}

	function starsHtml(rating) {
		rating = Math.max(0, Math.min(5, parseInt(rating, 10) || 0));
		if (!rating) {
			return '';
		}
		return '<span class="vp-stars" role="img" aria-label="' + esc(sprintf(t('rated', 'Rated %d out of 5'), rating)) + '">' + repeat('★', rating) + '<span class="vp-stars__off">' + repeat('★', 5 - rating) + '</span></span>';
	}

	function outcomeInnerHtml(call) {
		var html = '';
		if (call.outcome) {
			var info = outcomeInfo(call.outcome);
			html += badgeHtml(call.outcome_label || info.label, info.tone);
		}
		if ((parseInt(call.rating, 10) || 0) > 0) {
			html += starsHtml(call.rating);
		}
		return html || '<span class="vp-muted">—</span>';
	}

	function useCaseHtml(useCase) {
		var icon = USE_CASE_ICONS[useCase] || 'dashicons-phone';
		return '<span class="vp-usecase"><span class="dashicons ' + esc(icon) + '" aria-hidden="true"></span><span>' + esc(useCaseLabel(useCase)) + '</span></span>';
	}

	function orderLinkHtml(orderId) {
		orderId = parseInt(orderId, 10) || 0;
		if (!orderId) {
			return '<span class="vp-muted">—</span>';
		}
		var url = orderUrl(orderId);
		return url ? '<a class="vp-order-link" href="' + esc(url) + '">#' + esc(orderId) + '</a>' : '#' + esc(orderId);
	}

	var LETTERS = (function () {
		try {
			return new RegExp('[^\\p{L}\\s]', 'gu');
		} catch (e) {
			return /[^A-Za-zÀ-ɏऀ-ॿ\s]/g;
		}
	}());

	function initialsOf(name) {
		var parts = String(name || '').replace(LETTERS, '').trim().split(/\s+/).filter(Boolean);
		return parts.slice(0, 2).map(function (p) {
			return p.charAt(0).toUpperCase();
		}).join('');
	}

	function personHtml(call) {
		var name = String(call.customer_name || '').trim();
		var phone = String(call.phone || '').trim();
		var display = name || phone || t('unknownCaller', 'Unknown caller');
		var initials = initialsOf(name);
		var avatar = '<span class="vp-avatar" aria-hidden="true">' + (initials ? esc(initials) : '<span class="dashicons dashicons-phone"></span>') + '</span>';
		return '<span class="vp-person">' + avatar + '<span class="vp-person__text"><span class="vp-person__name">' + esc(display) + '</span>' +
			(name && phone ? '<span class="vp-person__sub">' + esc(phone) + '</span>' : '') + '</span></span>';
	}

	function fmtNumber(value, decimals) {
		var n = Number(value) || 0;
		decimals = decimals || 0;
		try {
			return n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
		} catch (e) {
			return n.toFixed(decimals);
		}
	}

	function fmtMoney(amount, decimals) {
		var cur = VP.currency || { symbol: '₹', position: 'left' };
		var num = fmtNumber(amount, decimals || 0);
		switch (cur.position) {
			case 'right':
				return num + cur.symbol;
			case 'right_space':
				return num + ' ' + cur.symbol;
			case 'left_space':
				return cur.symbol + ' ' + num;
			default:
				return cur.symbol + num;
		}
	}

	/** Mirrors VoicePress_Helpers::format_duration(): 45s · 1m 49s · 2m · 1h 5m. */
	function fmtDuration(seconds) {
		var total = Math.max(0, Math.round(Number(seconds) || 0));
		if (total <= 0) {
			return '—';
		}
		if (total < 60) {
			return total + 's';
		}
		var h = Math.floor(total / 3600);
		var m = Math.floor((total % 3600) / 60);
		var s = total % 60;
		if (h > 0) {
			return h + 'h ' + m + 'm';
		}
		return s > 0 ? m + 'm ' + s + 's' : m + 'm';
	}

	function fmtCost(cents) {
		if (cents === null || cents === undefined || cents === '') {
			return '—';
		}
		return '$' + fmtNumber((Number(cents) || 0) / 100, 2);
	}

	function fmtKpi(format, value, stats) {
		var v = Number(value) || 0;
		switch (format) {
			case 'percent':
				return Math.round(v) + '%';
			case 'money':
				return fmtMoney(v, 0);
			case 'rating':
				return (Number(stats.feedback_count) > 0 && v > 0) ? fmtNumber(v, 1) + ' ★' : '—';
			case 'minutes':
				return fmtNumber(v, v < 100 ? 1 : 0);
			case 'usd_cents':
				return '$' + fmtNumber(v / 100, 2);
			default:
				return fmtNumber(Math.round(v), 0);
		}
	}

	function parseGmt(value) {
		if (!value) {
			return null;
		}
		var d = new Date(String(value).replace(' ', 'T') + 'Z');
		return isNaN(d.getTime()) ? null : d;
	}

	function relTime(value) {
		var d = parseGmt(value);
		if (!d) {
			return '';
		}
		var diff = Math.max(0, (Date.now() - d.getTime()) / 1000);
		if (diff < 60) {
			return t('justNow', 'just now');
		}
		if (diff < 3600) {
			return sprintf(t('minAgo', '%d min ago'), Math.floor(diff / 60));
		}
		if (diff < 86400) {
			return sprintf(t('hoursAgo', '%d h ago'), Math.floor(diff / 3600));
		}
		return sprintf(t('daysAgo', '%d d ago'), Math.floor(diff / 86400));
	}

	/* ------------------------------------------------------------------
	 * UI primitives
	 * ---------------------------------------------------------------- */

	function setLoading($btn, loading) {
		$btn.toggleClass('is-loading', !!loading)
			.prop('disabled', !!loading)
			.attr('aria-busy', loading ? 'true' : null);
	}

	/**
	 * Inline result. type: success | error | warning | info | loading. html must be pre-escaped.
	 */
	function showResult($el, type, html) {
		if (!$el || !$el.length) {
			return;
		}
		$el.removeClass('is-success is-error is-info is-warning');
		if (!html) {
			$el.empty();
			return;
		}
		var icons = {
			success: 'dashicons-yes-alt',
			error: 'dashicons-warning',
			warning: 'dashicons-flag',
			info: 'dashicons-info-outline'
		};
		var icon = type === 'loading' ?
			'<span class="vp-result__spinner" aria-hidden="true"></span>' :
			'<span class="vp-result__icon dashicons ' + (icons[type] || icons.info) + '" aria-hidden="true"></span>';
		$el.addClass('is-' + (type === 'loading' ? 'info' : type)).html(icon + '<span class="vp-result__text">' + html + '</span>');
	}

	function flash($el) {
		if (!$el.length) {
			return;
		}
		$el.removeClass('is-updated');
		void $el[0].offsetWidth; // Restart the animation.
		$el.addClass('is-updated');
	}

	function setIndicator(state, text) {
		var $ind = $('[data-vp-live-indicator]');
		if (!$ind.length) {
			return;
		}
		$ind.removeClass('is-paused is-offline');
		if (state === 'paused' || state === 'offline') {
			$ind.addClass('is-' + state);
		}
		var label = text || (state === 'paused' ? t('paused', 'Paused') : state === 'offline' ? t('offline', 'Reconnecting…') : t('autoRefresh', 'Live · auto-refreshing'));
		$ind.find('.vp-live__text').text(label);
	}

	/** Error text plus a link to the call row when the server created one (e.g. status failed/skipped). */
	function callErrorHtml(err) {
		var call = err && err.data ? extractCall(err.data) : null;
		var html = esc(err && err.message ? err.message : t('genericError', 'Something went wrong. Please try again.'));
		if (call && call.id) {
			html += ' <a href="' + esc(callUrl(call.id)) + '">' + esc(t('viewCall', 'View call')) + ' →</a>';
		}
		return html;
	}

	/** Render the result of placing a call (test call, simulated cart, order call). */
	function renderCallResult($out, call, res, lead) {
		var message = res && res.message ? String(res.message) : '';
		if (!call) {
			showResult($out, 'success', '<strong>' + esc(lead || message || t('callPlaced', 'Call placed!')) + '</strong>');
			return;
		}
		var status = String(call.status || '');
		var link = '<a href="' + esc(callUrl(call.id)) + '">' + esc(t('viewCall', 'View call')) + ' →</a>';
		if (FAILED.indexOf(status) !== -1) {
			showResult($out, 'error', '<strong>' + esc(t('callNotPlaced', 'The call could not be placed.')) + '</strong> ' + esc(call.error || message) + ' ' + link);
			return;
		}
		var title = lead || (status === 'scheduled' ? t('callScheduled', 'Call scheduled.') : t('callPlaced', 'Call placed!'));
		showResult($out, 'success',
			'<strong>' + esc(title) + '</strong> ' +
			'<span data-vp-call="' + esc(call.id) + '" data-vp-status="' + esc(status) + '">' + statusBadgeHtml(call) + '</span> ' + link);
	}

	/* ------------------------------------------------------------------
	 * Live updates
	 * ---------------------------------------------------------------- */

	var reloading = false;

	function reloadDetail() {
		if (reloading) {
			return;
		}
		reloading = true;
		showResult($('[data-vp-sync-result]'), 'loading', esc(t('callEnded', 'Call finished — loading the transcript…')));
		window.setTimeout(function () {
			window.location.reload();
		}, 1800);
	}

	function within($root, selector) {
		return $root.find(selector).addBack(selector);
	}

	/** Paint every element bound to this call with fresh data. */
	function updateCall(call) {
		if (!call || !call.id) {
			return;
		}
		$('[data-vp-call="' + safeId(call.id) + '"]').each(function () {
			var $root = $(this);
			var prev = String($root.attr('data-vp-status') || '');
			var status = String(call.status || prev);
			$root.attr('data-vp-status', status);
			within($root, '[data-vp-field="status"]').each(function () {
				var info = statusInfo(status);
				$(this).attr('class', 'vp-badge vp-badge--' + info.tone + (info.pending ? ' is-pending' : '')).text(call.status_label || info.label);
			});
			within($root, '[data-vp-field="outcome"]').each(function () {
				$(this).html(outcomeInnerHtml(call));
			});
			within($root, '[data-vp-field="duration"]').each(function () {
				$(this).text(call.duration_label || fmtDuration(call.duration));
			});
			within($root, '[data-vp-field="cost"]').each(function () {
				$(this).text(fmtCost(call.cost));
			});
			if ($root.is('[data-vp-detail]') && prev && !isTerminal(prev) && isTerminal(status)) {
				reloadDetail();
			}
		});
	}

	function liveCallIds() {
		var ids = [];
		$('[data-vp-call]').not('.vp-order-calls [data-vp-call]').each(function () {
			var $el = $(this);
			var id = safeId($el.attr('data-vp-call'));
			if (id && !isTerminal($el.attr('data-vp-status')) && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});
		return ids.slice(0, 12);
	}

	function refreshCalls() {
		var ids = liveCallIds();
		if (!ids.length) {
			return Promise.resolve();
		}
		return Promise.all(ids.map(function (id) {
			return api('GET', 'admin/call/' + id).then(function (res) {
				updateCall(extractCall(res));
			}, function () {
				// One failed lookup should not stop the others.
			});
		}));
	}

	/** Update KPI tiles, meters and the "N new calls" pill from tick stats. */
	function applyStats(stats) {
		if (!stats || typeof stats !== 'object') {
			return;
		}
		$('[data-vp-kpi]').each(function () {
			var $el = $(this);
			var key = $el.attr('data-vp-kpi');
			if (!Object.prototype.hasOwnProperty.call(stats, key)) {
				return;
			}
			var raw = String(stats[key]);
			if ($el.attr('data-vp-raw') === raw) {
				return;
			}
			$el.attr('data-vp-raw', raw);
			var text = fmtKpi($el.attr('data-vp-format') || 'int', stats[key], stats);
			if ($el.text() !== text) {
				$el.text(text);
				flash($el);
			}
		});
		$('[data-vp-meter]').each(function () {
			var key = $(this).attr('data-vp-meter');
			if (Object.prototype.hasOwnProperty.call(stats, key)) {
				$(this).css('width', Math.max(0, Math.min(100, Number(stats[key]) || 0)) + '%');
			}
		});
		var total = parseInt(stats.total_calls, 10);
		if (!isNaN(total)) {
			$('[data-vp-total]').each(function () {
				var $card = $(this);
				var diff = total - (parseInt($card.attr('data-vp-total'), 10) || 0);
				if (diff > 0) {
					$card.find('[data-vp-new-calls]').text(sprintf(t('newCalls', '%d new · refresh'), diff)).prop('hidden', false);
				}
			});
		}
	}

	var Live = {
		timer: null,
		busy: false,
		failures: 0,

		start: function () {
			var self = this;
			if (self.timer) {
				return;
			}
			self.timer = window.setInterval(function () {
				self.tick();
			}, POLL_MS);
			document.addEventListener('visibilitychange', function () {
				setIndicator(document.hidden ? 'paused' : 'live');
				if (!document.hidden) {
					self.tick();
				}
			});
			setIndicator('live');
			self.soon(1200);
		},

		stop: function () {
			if (this.timer) {
				window.clearInterval(this.timer);
				this.timer = null;
			}
		},

		soon: function (ms) {
			var self = this;
			window.setTimeout(function () {
				self.tick();
			}, ms || 1500);
		},

		tick: function () {
			var self = this;
			if (self.busy || document.hidden || !self.timer) {
				return;
			}
			self.busy = true;
			var done = function () {
				self.busy = false;
			};
			api('POST', 'admin/tick', {})
				.then(function (res) {
					self.failures = 0;
					setIndicator('live');
					applyStats(res && res.stats);
				}, function (err) {
					self.failures++;
					if (err && (err.status === 401 || err.status === 403)) {
						self.stop();
						setIndicator('offline', t('sessionExpired', 'Your session expired. Reload the page.'));
					} else if (self.failures >= 2) {
						setIndicator('offline');
					}
				})
				.then(refreshCalls)
				.then(function () {
					return refreshOrderBoxes(true);
				})
				.then(done, done);
		}
	};

	/* ------------------------------------------------------------------
	 * Dashboard: recent calls + "Try it live"
	 * ---------------------------------------------------------------- */

	function recentRowHtml(call) {
		var url = callUrl(call.id);
		return '<tr class="vp-row-new" data-vp-call="' + esc(call.id) + '" data-vp-status="' + esc(call.status || 'queued') + '" data-href="' + esc(url) + '">' +
			'<td><a class="vp-row-link" href="' + esc(url) + '">' + personHtml(call) + '</a></td>' +
			'<td>' + useCaseHtml(call.use_case) + '</td>' +
			'<td class="vp-hide-sm">' + orderLinkHtml(call.order_id) + '</td>' +
			'<td>' + statusBadgeHtml(call) + '</td>' +
			'<td><span class="vp-outcome" data-vp-field="outcome">' + outcomeInnerHtml(call) + '</span></td>' +
			'<td class="vp-num vp-hide-sm" data-vp-field="duration">' + esc(call.duration_label || fmtDuration(call.duration)) + '</td>' +
			'<td class="vp-num vp-nowrap">' + esc(call.time_ago || relTime(call.created_at) || t('justNow', 'just now')) + '</td>' +
			'</tr>';
	}

	function prependRecent(call) {
		var $tbody = $('[data-vp-recent]');
		if (!$tbody.length || !call || !call.id || $tbody.find('[data-vp-call="' + safeId(call.id) + '"]').length) {
			return;
		}
		$tbody.prepend(recentRowHtml(call));
		$tbody.children('tr').slice(10).remove();
		$('[data-vp-recent-table]').prop('hidden', false);
		$('[data-vp-recent-empty]').remove();
		var $card = $tbody.closest('[data-vp-total]');
		$card.attr('data-vp-total', (parseInt($card.attr('data-vp-total'), 10) || 0) + 1);
	}

	function initTestCall() {
		var $form = $('#vp-test-call-form');
		if (!$form.length) {
			return;
		}
		var $phone = $form.find('[name="phone"]');
		var $orderField = $form.find('[data-vp-order-field]');
		var saved = storage('get', 'vp_test_phone');
		if (saved && !$phone.val()) {
			$phone.val(saved);
		}

		function syncScenario() {
			var $checked = $form.find('[name="use_case"]:checked');
			$form.find('[data-vp-scenario-hint]').text($checked.attr('data-hint') || '');
			$orderField.prop('hidden', $checked.attr('data-needs-order') !== '1');
		}

		$form.on('change', '[name="use_case"]', syncScenario);
		syncScenario();

		$form.on('submit', function (e) {
			e.preventDefault();
			var $btn = $form.find('[type="submit"]');
			var $out = $form.find('.vp-result');
			var phone = String($phone.val() || '').trim();
			var useCase = $form.find('[name="use_case"]:checked').val() || 'cod_confirmation';
			var orderId = parseInt($form.find('[name="order_id"]').val(), 10) || 0;
			if (!phone) {
				showResult($out, 'error', esc(t('phoneRequired', 'Enter a phone number to call.')));
				$phone.trigger('focus');
				return;
			}
			storage('set', 'vp_test_phone', phone);
			var payload = { phone: phone, use_case: useCase };
			if (orderId && !$orderField.prop('hidden')) {
				payload.order_id = orderId;
			}
			setLoading($btn, true);
			showResult($out, 'loading', esc(t('calling', 'Placing your call…')));
			api('POST', 'admin/test-call', payload)
				.then(function (res) {
					var call = extractCall(res);
					renderCallResult($out, call, res);
					if (call) {
						prependRecent(call);
					}
					Live.soon(2500);
				}, function (err) {
					showResult($out, 'error', callErrorHtml(err));
					var failed = err && err.data ? extractCall(err.data) : null;
					if (failed) {
						prependRecent(failed);
					}
				})
				.then(function () {
					setLoading($btn, false);
				});
		});
	}

	/* ------------------------------------------------------------------
	 * Agents
	 * ---------------------------------------------------------------- */

	function normalizeAgentResult(v) {
		if (v === true) {
			return { ok: true, agent_id: '', action: 'updated' };
		}
		if (typeof v === 'string') {
			return { ok: false, message: v };
		}
		if (!v || typeof v !== 'object') {
			return { ok: false, message: t('genericError', 'Something went wrong.') };
		}
		if (v.errors && typeof v.errors === 'object') {
			var first = Object.keys(v.errors)[0];
			var msg = first && Array.isArray(v.errors[first]) ? v.errors[first][0] : '';
			return { ok: false, message: msg || t('genericError', 'Something went wrong.') };
		}
		var id = v.agent_id || v.id || '';
		if (v.ok === false || !id) {
			if (v.ok === true || v.action) {
				return { ok: true, agent_id: '', action: v.action || 'updated', extractions: v.extractions, message: v.message || '' };
			}
			return { ok: false, message: v.message || v.error || t('genericError', 'Something went wrong.') };
		}
		return { ok: true, agent_id: String(id), action: v.action || '', extractions: v.extractions, message: v.message || '' };
	}

	function isPlainObject(v) {
		return !!v && typeof v === 'object' && !Array.isArray(v);
	}

	/**
	 * Map a provision / sync response to { agent_key: {ok, agent_id, action, extractions, message} }.
	 * Single agent: { ok, agent_key, result: {...}, agents: {key: id}, message }.
	 * All / sync:   { ok, message, results: {key: {...} | "error"}, errors, agents: {key: id} }.
	 */
	function normalizeAgentResults(res, key) {
		var out = {};
		var keys = Array.isArray(VP.agentKeys) ? VP.agentKeys : [];
		var ids = res && isPlainObject(res.agents) ? res.agents : {};
		var withId = function (k, r) {
			if (r.ok && !r.agent_id && typeof ids[k] === 'string' && ids[k]) {
				r.agent_id = ids[k];
			}
			return r;
		};
		if (key) {
			var single = res;
			if (res && isPlainObject(res.result)) {
				single = res.result;
			} else if (res && isPlainObject(res.results) && res.results[key] !== undefined) {
				single = res.results[key];
			}
			out[key] = withId(key, normalizeAgentResult(single));
			return out;
		}
		var source = res && isPlainObject(res.results) ? res.results : (res || {});
		keys.forEach(function (k) {
			if (source[k] !== undefined) {
				out[k] = withId(k, normalizeAgentResult(source[k]));
			}
		});
		return out;
	}

	function paintAgent(key, r) {
		var $card = $('.vp-agent[data-agent="' + safeId(key) + '"]');
		if (!$card.length) {
			return !!r.ok;
		}
		var $result = $card.find('[data-vp-agent-result]');
		if (!r.ok) {
			showResult($result, 'error', esc(r.message));
			return false;
		}
		var id = r.agent_id || $card.attr('data-agent-id') || '';
		if (id) {
			$card.attr('data-agent-id', id);
			$card.find('[data-vp-agent-id]').text(id).prop('hidden', false);
			$card.find('[data-vp-agent-link]').attr('href', (VP.bolnaAgentUrl || 'https://platform.bolna.ai/agent/') + encodeURIComponent(id)).prop('hidden', false);
			$card.find('[data-vp-agent-none]').prop('hidden', true);
			$card.find('[data-vp-agent-status]').html(badgeHtml(t('live', 'Live'), 'success', 'vp-badge--dot'));
			var $btn = $card.find('.vp-provision');
			$btn.removeClass('vp-btn--primary').addClass('vp-btn--secondary');
			$btn.find('.dashicons').attr('class', 'dashicons dashicons-update');
			$btn.find('.vp-btn__label').text(t('updateAgent', 'Update agent'));
			VP.agents = VP.agents || {};
			VP.agents[key] = id;
			if (key === 'support') {
				$('#vp-inbound').attr('data-support-agent', id).find('.vp-link-inbound').prop('disabled', false);
			}
		}
		var type = 'success';
		var html = '<strong>' + esc(r.action === 'created' ? t('agentCreated', 'Agent created') : t('agentUpdated', 'Agent updated')) + '</strong>';
		if (r.extractions && r.extractions !== 'ok' && r.extractions !== true) {
			type = 'warning';
			html += ' ' + esc(t('extractionsFailed', 'Agent saved, but call analytics setup failed:')) + ' ' + esc(typeof r.extractions === 'string' ? r.extractions : JSON.stringify(r.extractions));
		}
		showResult($result, type, html);
		$card.removeClass('is-saved');
		void $card[0].offsetWidth;
		$card.addClass('is-saved');
		return true;
	}

	function runAgentAction($btn, route, key) {
		var $cards = key ? $('.vp-agent[data-agent="' + safeId(key) + '"]') : $('.vp-agent');
		var $global = $('[data-vp-agents-result]');
		var $buttons = key ? $btn : $btn.add($cards.find('.vp-provision'));
		$buttons.each(function () {
			setLoading($(this), true);
		});
		if (key) {
			showResult($cards.find('[data-vp-agent-result]'), 'loading', esc(t('working', 'Working…')));
		} else {
			showResult($global, 'loading', esc(t('working', 'Working…')));
			$cards.find('[data-vp-agent-result]').empty().removeClass('is-success is-error is-info is-warning');
		}
		var isSync = route === 'admin/sync-agents';
		var handle = function (res, errMessage) {
			var results = normalizeAgentResults(res, key);
			var keys = Object.keys(results);
			var failed = 0;
			keys.forEach(function (k) {
				if (!paintAgent(k, results[k])) {
					failed++;
				}
			});
			if (key) {
				return;
			}
			if (!keys.length) {
				showResult($global, errMessage ? 'error' : 'info', esc(errMessage || (res && res.message) || t('nothingToSync', 'No agents to sync yet — create them first.')));
			} else if (failed) {
				showResult($global, 'warning', esc(errMessage || t('someAgentsFailed', 'Some agents need attention — see the cards below.')));
			} else {
				showResult($global, 'success', '<strong>' + esc((res && res.message) || (isSync ? t('resynced', 'Agents re-synced with your latest settings.') : t('allAgentsDone', 'All agents are ready.'))) + '</strong>');
				if (!isSync) {
					$('.vp-page-actions .vp-provision').prop('hidden', true);
				}
			}
		};
		api('POST', route, key ? { agent_key: key } : {})
			.then(function (res) {
				handle(res, '');
			}, function (err) {
				// Bulk responses report ok:false when any agent failed but still carry per-agent results.
				if (err && err.data && isPlainObject(err.data.results)) {
					handle(err.data, err.message);
				} else if (key) {
					showResult($cards.find('[data-vp-agent-result]'), 'error', esc(err.message));
				} else {
					showResult($global, 'error', esc(err.message));
				}
			})
			.then(function () {
				$buttons.each(function () {
					setLoading($(this), false);
				});
			});
	}

	function initAgents() {
		$(document).on('click', '.vp-provision', function (e) {
			e.preventDefault();
			var $btn = $(this);
			runAgentAction($btn, 'admin/provision', String($btn.attr('data-agent') || ''));
		});
		$(document).on('click', '.vp-sync-agents', function (e) {
			e.preventDefault();
			runAgentAction($(this), 'admin/sync-agents', '');
		});
	}

	/* ------------------------------------------------------------------
	 * Bolna status: inbound line (Agents) + connection test (Settings)
	 * ---------------------------------------------------------------- */

	var statusPromise = null;

	function loadBolnaStatus(fresh) {
		if (!statusPromise || fresh) {
			statusPromise = api('GET', 'admin/bolna-status');
		}
		return statusPromise;
	}

	function phoneNumbers(res) {
		return res && Array.isArray(res.phone_numbers) ? res.phone_numbers.filter(function (n) {
			return n && n.phone_number;
		}) : [];
	}

	function agentNameMap(res) {
		var map = {};
		var local = {};
		try {
			local = JSON.parse($('#vp-inbound').attr('data-agent-map') || '{}') || {};
		} catch (e) {
			local = {};
		}
		if (res && Array.isArray(res.agents)) {
			res.agents.forEach(function (a) {
				if (a && a.id) {
					map[a.id] = a.agent_name || a.name || '';
				}
			});
		}
		Object.keys(local).forEach(function (id) {
			map[id] = map[id] || local[id];
		});
		return map;
	}

	function fillInbound(res) {
		var $card = $('#vp-inbound');
		var $select = $('#vp-inbound-number');
		var numbers = phoneNumbers(res);
		var current = String($card.attr('data-current') || '');
		var support = String($card.attr('data-support-agent') || '');
		var names = agentNameMap(res);
		if (!numbers.length) {
			$select.html('<option value="">' + esc(t('noNumbers', 'No phone numbers found in your Bolna account.')) + '</option>').prop('disabled', true);
			$card.find('[data-vp-mapping]').empty();
			return;
		}
		var selected = current;
		if (!selected && support) {
			numbers.some(function (n) {
				if (n.agent_id === support) {
					selected = String(n.id);
					return true;
				}
				return false;
			});
		}
		var options = numbers.map(function (n) {
			var label = n.phone_number + (n.telephony_provider ? ' · ' + humanize(n.telephony_provider) : '');
			if (n.agent_id) {
				label += ' · ' + (n.agent_id === support ? t('supportAgent', 'Support agent') : (names[n.agent_id] || t('otherAgent', 'Another agent')));
			}
			return '<option value="' + esc(n.id) + '"' + (String(n.id) === selected ? ' selected' : '') + '>' + esc(label) + '</option>';
		}).join('');
		$select.html(options).prop('disabled', false);

		var rows = numbers.map(function (n) {
			var agent;
			if (!n.agent_id) {
				agent = '<span class="vp-muted">' + esc(t('notLinked', 'No agent')) + '</span>';
			} else if (support && n.agent_id === support) {
				agent = badgeHtml(t('supportAgent', 'VoicePress Support agent'), 'success', 'vp-badge--dot');
			} else {
				agent = esc(names[n.agent_id] || t('otherAgent', 'Another agent'));
			}
			return '<tr><td class="vp-mono">' + esc(n.phone_number) + '</td><td>' + esc(humanize(n.telephony_provider || '')) + '</td><td>' + agent + '</td></tr>';
		}).join('');
		$card.find('[data-vp-mapping]').html(
			'<table class="vp-table vp-table--compact"><thead><tr><th scope="col">' + esc(t('number', 'Number')) + '</th><th scope="col">' +
			esc(t('provider', 'Provider')) + '</th><th scope="col">' + esc(t('agent', 'Inbound agent')) + '</th></tr></thead><tbody>' + rows + '</tbody></table>'
		);
		// Bolna is the source of truth: reflect a Support agent already attached to a number.
		var linked = support && numbers.some(function (n) {
			return n.agent_id === support;
		});
		if (linked) {
			$card.find('[data-vp-inbound-status]').html(badgeHtml(t('linkedBadge', 'Linked'), 'success', 'vp-badge--dot'));
		}
	}

	/** Flag saved agent IDs that no longer exist in the Bolna account. */
	function markMissingAgents(res) {
		if (!res || !Array.isArray(res.agents) || !res.agents.length) {
			return;
		}
		var remote = res.agents.map(function (a) {
			return a && a.id ? String(a.id) : '';
		});
		$('.vp-agent[data-agent-id]').each(function () {
			var $card = $(this);
			var id = String($card.attr('data-agent-id') || '');
			if (id && remote.indexOf(id) === -1) {
				$card.find('[data-vp-agent-status]').html(badgeHtml(t('notFoundInBolna', 'Missing in Bolna'), 'warning'));
			}
		});
	}

	function initInbound() {
		var $card = $('#vp-inbound');
		if (!$card.length) {
			return;
		}
		var $select = $('#vp-inbound-number');
		var $result = $card.find('[data-vp-inbound-result]');
		loadBolnaStatus(false).then(function (res) {
			fillInbound(res);
			markMissingAgents(res);
		}, function (err) {
			$select.html('<option value="">' + esc(t('noNumbers', 'No phone numbers found.')) + '</option>').prop('disabled', true);
			showResult($result, 'error', esc(err.message));
		});

		$card.on('click', '.vp-link-inbound', function () {
			var $btn = $(this);
			var id = $select.val();
			if (!id) {
				showResult($result, 'error', esc(t('pickNumber', 'Pick a phone number first.')));
				return;
			}
			setLoading($btn, true);
			showResult($result, 'loading', esc(t('working', 'Working…')));
			api('POST', 'admin/link-inbound', { phone_number_id: id })
				.then(function (res) {
					showResult($result, 'success', '<strong>' + esc((res && res.message) || t('linked', 'Linked! Calls to this number now reach your Support agent.')) + '</strong>');
					$card.attr('data-current', id);
					$card.find('[data-vp-inbound-status]').html(badgeHtml(t('linkedBadge', 'Linked'), 'success', 'vp-badge--dot'));
					return loadBolnaStatus(true).then(fillInbound, function () {});
				}, function (err) {
					showResult($result, 'error', esc(err.message));
				})
				.then(function () {
					setLoading($btn, false);
				});
		});
	}

	function initSettings() {
		var $btn = $('#vp-test-connection');
		if (!$btn.length) {
			return;
		}
		var $result = $('[data-vp-connection-result]');
		var $input = $('#vp-from-number');
		var $select = $('#vp-from-select');
		var $provider = $('#vp-telephony-provider');
		var $note = $('<p class="vp-help vp-from-note" hidden></p>');
		$select.after($note);

		function stat(value, label) {
			return '<span class="vp-conn-stat"><strong>' + esc(value) + '</strong> ' + esc(label) + '</span>';
		}

		function renderConnection(res, unsavedKey) {
			var wallet = res && res.wallet !== undefined && res.wallet !== null && res.wallet !== '' ? '$' + fmtNumber(res.wallet, 2) : '—';
			var conc = res ? res.concurrency : null;
			var concText = '—';
			if (conc && typeof conc === 'object') {
				concText = (conc.current !== undefined ? conc.current + ' / ' : '') + (conc.max !== undefined ? conc.max : '');
			} else if (conc !== undefined && conc !== null && conc !== '') {
				concText = String(conc);
			}
			var numbers = phoneNumbers(res).length;
			var agents = res && Array.isArray(res.agents) ? res.agents.length : 0;
			var who = res && res.account ? (res.account.name || res.account.email || '') : '';
			var html = '<strong>' + esc(t('connected', 'Connected to Bolna')) + (who ? ' · ' + esc(who) : '') + '</strong>' +
				'<span class="vp-conn-stats">' + stat(wallet, t('wallet', 'Wallet')) + stat(concText, t('concurrency', 'Concurrent calls')) +
				stat(numbers, t('phoneNumbers', 'Phone numbers')) + stat(agents, t('agentsLabel', 'Agents')) + '</span>';
			if (!numbers) {
				html += '<span>' + esc(t('noNumbers', 'No phone numbers found in your Bolna account.')) + '</span>';
			}
			if (res && Array.isArray(res.warnings) && res.warnings.length) {
				html += '<span>' + esc(res.warnings.join(' ')) + '</span>';
			}
			if (unsavedKey) {
				html += '<span>' + esc(t('unsavedKey', 'This tests the saved key. Save settings first to test the new key.')) + '</span>';
			}
			showResult($result, numbers ? 'success' : 'warning', html);
		}

		function fillFromNumbers(res) {
			var numbers = phoneNumbers(res);
			if (!numbers.length) {
				return;
			}
			var current = String($input.val() || '').trim();
			var found = false;
			var html = '<option value="">' + esc(t('chooseNumber', 'Choose a number…')) + '</option>';
			numbers.forEach(function (n) {
				var selected = n.phone_number === current;
				found = found || selected;
				html += '<option value="' + esc(n.phone_number) + '" data-provider="' + esc(n.telephony_provider || '') + '"' + (selected ? ' selected' : '') + '>' +
					esc(n.phone_number + (n.telephony_provider ? ' · ' + humanize(n.telephony_provider) : '')) + '</option>';
			});
			html += '<option value="__custom"' + (current && !found ? ' selected' : '') + '>' + esc(t('otherNumber', 'Other — type it in')) + '</option>';
			$select.html(html).prop('hidden', false);
			$input.prop('hidden', !(current && !found));
			if (!current && numbers.length === 1) {
				$select.val(numbers[0].phone_number).trigger('change');
			}
		}

		$select.on('change', function () {
			var value = $select.val();
			if (value === '__custom') {
				$input.prop('hidden', false).trigger('focus');
				$note.prop('hidden', true);
				return;
			}
			$input.val(value || '').prop('hidden', true);
			var provider = $select.find('option:selected').attr('data-provider');
			if (provider && $provider.find('option[value="' + safeId(provider) + '"]').length) {
				$provider.val(provider);
			}
			$note.text(t('saveReminder', 'Number selected. Remember to save your settings.')).prop('hidden', !value);
		});

		function run(fresh, manual) {
			setLoading($btn, true);
			if (manual) {
				showResult($result, 'loading', esc(t('working', 'Working…')));
			}
			loadBolnaStatus(fresh)
				.then(function (res) {
					renderConnection(res, manual && String($('#vp-api-key').val() || '').trim() !== '');
					fillFromNumbers(res);
				}, function (err) {
					showResult($result, 'error', esc(err.message));
				})
				.then(function () {
					setLoading($btn, false);
				});
		}

		$btn.on('click', function () {
			run(true, true);
		});
		if ($btn.attr('data-configured') === '1') {
			run(false, false);
		}
	}

	/* ------------------------------------------------------------------
	 * Automations: simulate abandoned cart
	 * ---------------------------------------------------------------- */

	function initSimulateCart() {
		var $form = $('#vp-simulate-cart-form');
		if (!$form.length) {
			return;
		}
		var $phone = $form.find('[name="phone"]');
		var saved = storage('get', 'vp_test_phone');
		if (saved && !$phone.val()) {
			$phone.val(saved);
		}
		$form.on('submit', function (e) {
			e.preventDefault();
			var $btn = $form.find('[type="submit"]');
			var $out = $form.find('.vp-result');
			var phone = String($phone.val() || '').trim();
			var name = String($form.find('[name="name"]').val() || '').trim();
			if (!phone) {
				showResult($out, 'error', esc(t('phoneRequired', 'Enter a phone number to call.')));
				$phone.trigger('focus');
				return;
			}
			storage('set', 'vp_test_phone', phone);
			setLoading($btn, true);
			showResult($out, 'loading', esc(t('calling', 'Placing your call…')));
			api('POST', 'admin/simulate-cart', { phone: phone, name: name })
				.then(function (res) {
					var lead = res && res.cart_id ? sprintf(t('cartCreated', 'Demo cart #%d created — calling now.'), res.cart_id) : '';
					renderCallResult($out, extractCall(res), res, lead);
					Live.soon(2500);
				}, function (err) {
					showResult($out, 'error', callErrorHtml(err));
				})
				.then(function () {
					setLoading($btn, false);
				});
		});

		$(document).on('change', '.vp-auto .vp-switch input', function () {
			$(this).closest('.vp-auto').toggleClass('is-on', this.checked);
		});
	}

	/* ------------------------------------------------------------------
	 * Call detail: sync now
	 * ---------------------------------------------------------------- */

	function initSyncCall() {
		$(document).on('click', '.vp-sync-call', function () {
			var $btn = $(this);
			var id = parseInt($btn.attr('data-call'), 10) || 0;
			var $out = $('[data-vp-sync-result]');
			if (!id) {
				return;
			}
			setLoading($btn, true);
			showResult($out, 'loading', esc(t('working', 'Working…')));
			api('POST', 'admin/sync-call', { id: id }).then(function () {
				showResult($out, 'success', esc(t('synced', 'Synced with Bolna — refreshing…')));
				window.setTimeout(function () {
					window.location.reload();
				}, 700);
			}, function (err) {
				showResult($out, 'error', esc(err.message));
				setLoading($btn, false);
			});
		});
	}

	/* ------------------------------------------------------------------
	 * WooCommerce order screens: meta box calls + "call now" buttons
	 * ---------------------------------------------------------------- */

	function renderOrderCalls($box, calls) {
		if (!calls.length) {
			$box.html('<p class="vp-order-calls__empty">' + esc(t('noOrderCalls', 'No voice calls for this order yet.')) + '</p>');
			return;
		}
		var html = '<ul class="vp-order-calls__list">';
		calls.slice(0, 10).forEach(function (c) {
			c = c || {};
			var meta = [];
			var when = c.time_ago || relTime(c.created_at);
			if (when) {
				meta.push(esc(when));
			}
			if ((parseInt(c.duration, 10) || 0) > 0) {
				meta.push(esc(c.duration_label || fmtDuration(c.duration)));
			}
			if ((parseInt(c.attempt, 10) || 1) > 1) {
				meta.push(esc(sprintf(t('attempt', 'attempt %d'), parseInt(c.attempt, 10))));
			}
			if (c.id) {
				meta.push('<a href="' + esc(callUrl(c.id)) + '">' + esc(t('view', 'View')) + ' →</a>');
			}
			var outcome = (c.outcome || (parseInt(c.rating, 10) || 0) > 0) ?
				'<div class="vp-order-call__outcome"><span class="vp-outcome" data-vp-field="outcome">' + outcomeInnerHtml(c) + '</span></div>' : '';
			var note = c.summary || (FAILED.indexOf(String(c.status || '')) !== -1 ? c.error : '');
			html += '<li class="vp-order-call" data-vp-call="' + esc(c.id) + '" data-vp-status="' + esc(c.status || '') + '">' +
				'<div class="vp-order-call__row"><span class="vp-order-call__title">' + esc(useCaseLabel(c.use_case)) + '</span>' + statusBadgeHtml(c) + '</div>' +
				'<div class="vp-order-call__meta">' + meta.join(' · ') + '</div>' + outcome +
				(note ? '<p class="vp-order-call__summary">' + esc(truncate(note, 180)) + '</p>' : '') +
				'</li>';
		});
		$box.html(html + '</ul>');
	}

	function refreshOrderBoxes(silent) {
		var $boxes = $('.vp-order-calls[data-order]');
		if (!$boxes.length) {
			return Promise.resolve();
		}
		return Promise.all($boxes.map(function () {
			var $box = $(this);
			var id = parseInt($box.attr('data-order'), 10) || 0;
			if (!id) {
				return Promise.resolve();
			}
			if (!silent && !String($box.text() || '').trim()) {
				$box.html('<div class="vp-order-calls__loading"><span class="vp-result__spinner" aria-hidden="true"></span>' + esc(t('loading', 'Loading calls…')) + '</div>');
			}
			return api('GET', 'admin/order-calls/' + id).then(function (res) {
				renderOrderCalls($box, extractList(res));
			}, function (err) {
				if (!silent) {
					$box.html('<p class="vp-order-calls__empty">' + esc(err.message) + '</p>');
				}
			});
		}).get());
	}

	function orderResultEl($btn) {
		var $scope = $btn.closest('.vp-metabox, .inside, .postbox');
		if (!$scope.length) {
			$scope = $btn.parent();
		}
		var $out = $scope.find('.vp-order-result').first();
		if (!$out.length) {
			$out = $('<div class="vp-result vp-order-result" role="status" aria-live="polite"></div>');
			var $list = $scope.find('.vp-order-calls').first();
			if ($list.length) {
				$list.before($out);
			} else {
				$scope.find('.vp-call-order').last().after($out);
			}
		}
		return $out;
	}

	function initOrderBoxes() {
		$(document).on('click', '.vp-call-order', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var orderId = parseInt($btn.attr('data-order'), 10) || 0;
			var useCase = String($btn.attr('data-use-case') || '');
			if (!orderId || !useCase || $btn.hasClass('is-loading')) {
				return;
			}
			if (!window.confirm(sprintf(t('confirmCall', 'Place a "%s" call to this customer now?'), useCaseLabel(useCase)))) {
				return;
			}
			var $out = orderResultEl($btn);
			$btn.addClass('is-loading').prop('disabled', true);
			showResult($out, 'loading', esc(t('calling', 'Placing your call…')));
			api('POST', 'admin/call-order', { order_id: orderId, use_case: useCase })
				.then(function (res) {
					renderCallResult($out, extractCall(res), res);
					return refreshOrderBoxes(true);
				}, function (err) {
					showResult($out, 'error', callErrorHtml(err));
					return refreshOrderBoxes(true);
				})
				.then(function () {
					$btn.removeClass('is-loading').prop('disabled', false);
				});
		});

		if (!$('.vp-order-calls[data-order]').length) {
			return false;
		}
		refreshOrderBoxes(false);
		return true;
	}

	/* ------------------------------------------------------------------
	 * Misc: clickable rows, copy buttons, "new calls" pill
	 * ---------------------------------------------------------------- */

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve) {
			var $tmp = $('<textarea readonly></textarea>').css({ position: 'fixed', top: '-1000px', opacity: 0 }).val(text).appendTo('body');
			$tmp[0].select();
			try {
				document.execCommand('copy');
			} catch (e) {
				// Ignore: nothing else to try.
			}
			$tmp.remove();
			resolve();
		});
	}

	function initMisc() {
		$(document).on('click', 'tr[data-href]', function (e) {
			if ($(e.target).closest('a, button, input, select, textarea, label').length) {
				return;
			}
			if (window.getSelection && String(window.getSelection())) {
				return; // Let people select text in rows.
			}
			var href = $(this).attr('data-href');
			if (!href) {
				return;
			}
			if (e.metaKey || e.ctrlKey) {
				window.open(href, '_blank', 'noopener');
			} else {
				window.location.href = href;
			}
		});

		$(document).on('click', '[data-vp-copy-target]', function () {
			var $btn = $(this);
			var $target = $(document.querySelector($btn.attr('data-vp-copy-target')));
			if (!$target.length) {
				return;
			}
			var text = $target.is('input, textarea') ? $target.val() : $target.text();
			copyText(String(text || '')).then(function () {
				var original = $btn.data('vpLabel') || $btn.text();
				$btn.data('vpLabel', original).text(t('copied', 'Copied!'));
				window.setTimeout(function () {
					$btn.text(original);
				}, 1500);
			}, function () {});
		});

		$(document).on('click', '[data-vp-new-calls]', function () {
			window.location.reload();
		});
	}

	/* ------------------------------------------------------------------
	 * Boot
	 * ---------------------------------------------------------------- */

	$(function () {
		initMisc();
		initTestCall();
		initAgents();
		initInbound();
		initSettings();
		initSimulateCart();
		initSyncCall();
		var hasOrderBox = initOrderBoxes();

		if (VP.screen === 'voicepress' || VP.page || hasOrderBox) {
			Live.start();
		}
	});
}(window.jQuery, window.VoicePressAdmin));
