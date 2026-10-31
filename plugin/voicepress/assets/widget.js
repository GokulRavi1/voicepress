/*!
 * VoicePress – storefront voice widget.
 *
 * Vanilla JS (no jQuery, no build step). The in-browser call uses window.BolnaWebCall
 * (assets/vendor/bolna-web-call.min.js); call sessions are minted by the store's own REST API
 * (voicepress/v1/web-session), so the Bolna API key never reaches the browser.
 *
 * Also runs the checkout contact capture used by abandoned-cart recovery calls.
 * Configuration comes from window.VoicePressWidget (wp_localize_script).
 */
(function (window, document) {
	'use strict';

	var cfg = window.VoicePressWidget;
	if (!cfg || typeof cfg !== 'object' || window.VoicePressWidgetBooted) {
		return;
	}
	window.VoicePressWidgetBooted = true;

	var STRINGS = cfg.i18n && typeof cfg.i18n === 'object' ? cfg.i18n : {};
	var CONTEXT = cfg.context && typeof cfg.context === 'object' ? cfg.context : { page: 'other' };
	var REDUCED_MOTION = false;
	try {
		REDUCED_MOTION = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	} catch (e) { /* ignore */ }

	/* ------------------------------------------------------------------ utils */

	function noop() {}

	function truthy(value) {
		return value === true || value === 1 || value === '1' || value === 'true' || value === 'yes';
	}

	function t(key, fallback) {
		var value = STRINGS[key];
		return typeof value === 'string' && value !== '' ? value : (fallback || '');
	}

	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function matches(node, selector) {
		if (!node || node.nodeType !== 1) {
			return false;
		}
		var fn = node.matches || node.msMatchesSelector || node.webkitMatchesSelector;
		try {
			return fn ? fn.call(node, selector) : false;
		} catch (e) {
			return false;
		}
	}

	function closest(node, selector) {
		while (node && node.nodeType === 1) {
			if (matches(node, selector)) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function toggleClass(node, name, on) {
		if (node && node.classList) {
			node.classList[on ? 'add' : 'remove'](name);
		}
	}

	function log(label, detail) {
		if (window.console && typeof window.console.warn === 'function') {
			window.console.warn('[VoicePress] ' + label, detail === undefined ? '' : detail);
		}
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function clock(seconds) {
		var s = Math.max(0, Math.floor(seconds || 0));
		return pad(Math.floor(s / 60)) + ':' + pad(s % 60);
	}

	function digitsOf(value) {
		return String(value || '').replace(/\D+/g, '');
	}

	function focusLater(node) {
		window.setTimeout(function () {
			if (!node || typeof node.focus !== 'function') {
				return;
			}
			try {
				node.focus({ preventScroll: true });
			} catch (e) {
				node.focus();
			}
		}, 40);
	}

	/* ------------------------------------------------------------------- REST */

	/**
	 * POST JSON to voicepress/v1/{route}. Resolves { ok, status, data } (never rejects on HTTP
	 * errors; rejects only on network failure).
	 */
	function api(route, payload, opts) {
		opts = opts || {};
		if (!window.fetch) {
			return Promise.reject(new Error('fetch unavailable'));
		}
		var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
		if (cfg.nonce && !opts.noNonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		var init = {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(payload || {})
		};
		if (opts.keepalive) {
			init.keepalive = true;
		}
		return window.fetch(String(cfg.rest || '') + route, init).then(function (res) {
			return res.text().then(function (text) {
				var data = null;
				try {
					data = text ? JSON.parse(text) : {};
				} catch (e) {
					data = null;
				}
				if (res.status === 403 && !opts.noNonce && data && data.code === 'rest_cookie_invalid_nonce') {
					// The page (and so its nonce) came from a cache: retry as a plain public request.
					return api(route, payload, { noNonce: true, keepalive: opts.keepalive });
				}
				return { ok: res.ok, status: res.status, data: data && typeof data === 'object' ? data : {} };
			});
		});
	}

	/* ------------------------------------------------------ checkout capture */

	var PHONE_SEL = '#billing_phone, #billing-phone, #shipping-phone, input[type="tel"]';
	var EMAIL_SEL = '#billing_email, #email, input[type="email"]';
	var NAME_SEL = '#billing_first_name, #billing-first_name, #shipping-first_name';
	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

	/**
	 * Watches the checkout contact fields (classic and block checkout) and sends phone / email /
	 * first name to cart-capture once per distinct value, debounced 1.5s.
	 */
	function initCapture() {
		if (!window.fetch) {
			return;
		}
		var timer = null;
		var sent = {};
		var sends = 0;
		var MAX_SENDS = 12;

		function outsideWidget(node) {
			return !closest(node, '#voicepress-widget');
		}

		function valueOf(selector) {
			var nodes = document.querySelectorAll(selector);
			for (var i = 0; i < nodes.length; i++) {
				if (!outsideWidget(nodes[i])) {
					continue;
				}
				var value = String(nodes[i].value || '').trim();
				if (value) {
					return value;
				}
			}
			return '';
		}

		function collect() {
			var phone = valueOf(PHONE_SEL);
			var email = valueOf(EMAIL_SEL);
			var name = valueOf(NAME_SEL);
			var digits = digitsOf(phone);
			if (digits.length < 10 || digits.length > 15) {
				phone = '';
			}
			if (!EMAIL_RE.test(email)) {
				email = '';
			}
			if (!phone && !email) {
				return null;
			}
			return { phone: phone.slice(0, 32), email: email.slice(0, 191), name: name.slice(0, 80) };
		}

		function flush(keepalive) {
			if (timer) {
				window.clearTimeout(timer);
				timer = null;
			}
			var data = collect();
			if (!data || sends >= MAX_SENDS) {
				return;
			}
			var key = digitsOf(data.phone) + '|' + data.email.toLowerCase() + '|' + data.name.toLowerCase();
			if (sent[key]) {
				return;
			}
			sent[key] = true;
			sends++;
			api('cart-capture', data, { keepalive: !!keepalive }).then(noop, function () {
				delete sent[key]; // Network hiccup: allow a retry on the next change.
			});
		}

		function schedule() {
			if (timer) {
				window.clearTimeout(timer);
			}
			timer = window.setTimeout(function () {
				flush(false);
			}, 1500);
		}

		function onField(event) {
			var node = event.target;
			if (!node || node.nodeType !== 1 || !outsideWidget(node)) {
				return;
			}
			if (matches(node, PHONE_SEL) || matches(node, EMAIL_SEL) || matches(node, NAME_SEL)) {
				schedule();
			}
		}

		// Capture phase + delegation: works for the classic form and the React block checkout.
		document.addEventListener('input', onField, true);
		document.addEventListener('change', onField, true);
		document.addEventListener('focusout', onField, true);
		window.addEventListener('pagehide', function () {
			if (timer) {
				flush(true);
			}
		});
		// Fields pre-filled for signed-in customers or by browser autofill.
		window.setTimeout(function () {
			flush(false);
		}, 2500);
	}

	/* ------------------------------------------------------------------ icons */

	var SVG_ATTRS = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
	var HANDSET = 'M6.6 3.5h2.6l1.5 3.9-1.9 1.3a10.5 10.5 0 0 0 6.5 6.5l1.3-1.9 3.9 1.5v2.6a2 2 0 0 1-2.2 2A16 16 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2Z';
	var MIC = '<rect x="9" y="3" width="6" height="11.5" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7"/>';
	var ICONS = {
		mic: '<svg ' + SVG_ATTRS + '>' + MIC + '</svg>',
		micOff: '<svg ' + SVG_ATTRS + '>' + MIC + '<path d="M4 4l16 16"/></svg>',
		phone: '<svg ' + SVG_ATTRS + '><path d="' + HANDSET + '"/></svg>',
		hangup: '<svg ' + SVG_ATTRS + '><path transform="rotate(135 12 12)" d="' + HANDSET + '"/></svg>',
		chevron: '<svg ' + SVG_ATTRS + '><path d="m6 9 6 6 6-6"/></svg>',
		retry: '<svg ' + SVG_ATTRS + '><path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v5h-5"/></svg>',
		alert: '<svg ' + SVG_ATTRS + '><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>',
		sound: '<svg ' + SVG_ATTRS + '><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/></svg>',
		lock: '<svg ' + SVG_ATTRS + '><rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/></svg>',
		check: '<svg ' + SVG_ATTRS + '><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>'
	};

	/* ------------------------------------------------------------------ widget */

	/** Tiny element builder. "html" is only ever used with the static ICONS markup. */
	function h(tag, attrs, children) {
		var node = document.createElement(tag);
		var key;
		var value;
		if (attrs) {
			for (key in attrs) {
				if (!Object.prototype.hasOwnProperty.call(attrs, key)) {
					continue;
				}
				value = attrs[key];
				if (value === null || value === undefined || value === false) {
					continue;
				}
				if (key === 'text') {
					node.textContent = value;
				} else if (key === 'html') {
					node.innerHTML = value;
				} else if (key === 'class') {
					node.className = value;
				} else {
					node.setAttribute(key, value === true ? '' : String(value));
				}
			}
		}
		if (children) {
			for (var i = 0; i < children.length; i++) {
				if (children[i]) {
					node.appendChild(typeof children[i] === 'string' ? document.createTextNode(children[i]) : children[i]);
				}
			}
		}
		return node;
	}

	function icon(name, extraClass) {
		return h('span', { 'class': 'vpw-icon' + (extraClass ? ' ' + extraClass : ''), 'aria-hidden': 'true', html: ICONS[name] || '' });
	}

	function setIcon(node, name) {
		if (node.getAttribute('data-icon') !== name) {
			node.setAttribute('data-icon', name);
			node.innerHTML = ICONS[name] || '';
		}
	}

	function initWidget() {
		var root = document.getElementById('voicepress-widget');
		if (!root) {
			root = h('div', { id: 'voicepress-widget', 'data-nosnippet': true });
			document.body.appendChild(root);
		}
		while (root.firstChild) {
			root.removeChild(root.firstChild);
		}
		root.className = 'vpw-root';

		var agent = String(cfg.agentName || 'Riya');
		var label = String(cfg.label || t('label', 'Talk to us'));
		var prefill = cfg.prefill && typeof cfg.prefill === 'object' ? cfg.prefill : {};
		var BAR_WEIGHTS = [0.55, 0.85, 1, 0.8, 0.6];

		var st = {
			view: 'idle',        // idle | connecting | ringing | active | ended | error
			pane: 'call',        // call | callback | done
			open: false,
			call: null,
			attempt: null,
			connected: false,
			micReady: false,
			muted: false,
			startedAt: 0,
			duration: 0,
			endReason: '',
			errorCode: '',
			timer: null,
			raf: 0,
			level: 0,
			target: 0,
			speaking: false,
			speakingAt: 0,
			cbBusy: false
		};

		/* ---- launcher ---- */
		var launcherIcon = icon('mic', 'vpw-launcher__icon');
		var launcherTime = h('span', { 'class': 'vpw-launcher__time', text: '00:00' });
		var launcher = h('button', {
			type: 'button',
			'class': 'vpw-launcher',
			'aria-expanded': 'false',
			'aria-controls': 'vpw-panel',
			'aria-haspopup': 'dialog'
		}, [
			launcherIcon,
			h('span', { 'class': 'vpw-launcher__label', text: label }),
			h('span', { 'class': 'vpw-launcher__live' }, [
				h('span', { 'class': 'vpw-dot', 'aria-hidden': 'true' }),
				h('span', { text: t('live', 'Live') }),
				launcherTime
			])
		]);

		/* ---- header ---- */
		var closeBtn = h('button', { type: 'button', 'class': 'vpw-close', 'aria-label': t('minimize', 'Minimize') }, [icon('chevron')]);
		var initial = agent.charAt(0).toUpperCase();
		try {
			initial = Array.from(agent)[0].toUpperCase();
		} catch (e) { /* keep charAt */ }
		var header = h('div', { 'class': 'vpw-header' }, [
			h('span', { 'class': 'vpw-avatar', 'aria-hidden': 'true' }, [
				h('span', { 'class': 'vpw-avatar__letter', text: initial }),
				h('span', { 'class': 'vpw-avatar__dot' })
			]),
			h('div', { 'class': 'vpw-heading' }, [
				h('h2', { 'class': 'vpw-title', id: 'vpw-title', text: t('title', 'Talk to us') }),
				h('p', { 'class': 'vpw-subtitle', text: t('subtitle', agent + ' · AI voice assistant · English') })
			]),
			closeBtn
		]);

		/* ---- call view ---- */
		var bars = [];
		for (var b = 0; b < BAR_WEIGHTS.length; b++) {
			bars.push(h('span', { 'class': 'vpw-bar' }));
		}
		var orbIcon = icon('mic', 'vpw-orb__icon');
		var orb = h('button', { type: 'button', 'class': 'vpw-orb', 'aria-label': t('start', 'Start voice call') }, [
			orbIcon,
			h('span', { 'class': 'vpw-bars', 'aria-hidden': 'true' }, bars)
		]);
		var stage = h('div', { 'class': 'vpw-stage' }, [
			h('span', { 'class': 'vpw-ring vpw-ring--3', 'aria-hidden': 'true' }),
			h('span', { 'class': 'vpw-ring vpw-ring--2', 'aria-hidden': 'true' }),
			h('span', { 'class': 'vpw-ring vpw-ring--1', 'aria-hidden': 'true' }),
			orb
		]);

		var statusTitle = h('p', { 'class': 'vpw-status__title' });
		var statusText = h('p', { 'class': 'vpw-status__text' });
		var status = h('div', { 'class': 'vpw-status', role: 'status', 'aria-live': 'polite' }, [statusTitle, statusText]);

		var timerText = h('span', { 'class': 'vpw-timer__time', text: '00:00' });
		var chip = h('span', { 'class': 'vpw-chip', 'aria-hidden': 'true', text: t('listening', 'Listening…') });
		var liveRow = h('div', { 'class': 'vpw-live', hidden: true }, [
			h('span', { 'class': 'vpw-timer' }, [h('span', { 'class': 'vpw-dot', 'aria-hidden': 'true' }), timerText]),
			chip
		]);

		var soundBtn = h('button', { type: 'button', 'class': 'vpw-btn vpw-btn--soft vpw-sound', hidden: true }, [
			icon('sound'),
			h('span', { text: t('enableSound', 'Tap to hear the assistant') })
		]);

		var muteIcon = icon('mic');
		var muteLabel = h('span', { text: t('mute', 'Mute') });
		var muteBtn = h('button', { type: 'button', 'class': 'vpw-btn vpw-btn--soft vpw-mute' }, [muteIcon, muteLabel]);
		var endLabel = h('span', { text: t('end', 'End call') });
		var endBtn = h('button', { type: 'button', 'class': 'vpw-btn vpw-btn--danger vpw-end' }, [icon('hangup'), endLabel]);
		var controls = h('div', { 'class': 'vpw-controls', hidden: true }, [muteBtn, endBtn]);

		var primaryIcon = icon('phone');
		var primaryLabel = h('span');
		var primaryBtn = h('button', { type: 'button', 'class': 'vpw-btn vpw-btn--primary' }, [primaryIcon, primaryLabel]);
		var altBtn = h('button', { type: 'button', 'class': 'vpw-btn vpw-btn--soft' }, [
			icon('phone'),
			h('span', { text: t('callMeInstead', 'Call my phone instead') })
		]);
		var actions = h('div', { 'class': 'vpw-actions', hidden: true }, [primaryBtn, altBtn]);

		var noteIcon = icon('lock');
		var noteText = h('span');
		var note = h('p', { 'class': 'vpw-note' }, [noteIcon, noteText]);

		var callView = h('div', { 'class': 'vpw-view vpw-view--call' }, [stage, status, liveRow, soundBtn, controls, actions, note]);

		/* ---- call-back form ---- */
		function field(id, text, input) {
			return h('div', { 'class': 'vpw-field' }, [h('label', { 'class': 'vpw-field__label', 'for': id, text: text }), input]);
		}
		var cbName = h('input', { 'class': 'vpw-input', id: 'vpw-cb-name', type: 'text', name: 'vpw_name', autocomplete: 'given-name', maxlength: '80' });
		var cbPhone = h('input', {
			'class': 'vpw-input',
			id: 'vpw-cb-phone',
			type: 'tel',
			name: 'vpw_phone',
			autocomplete: 'tel',
			inputmode: 'tel',
			maxlength: '24',
			required: true,
			placeholder: t('cbPhoneHint', ''),
			'aria-describedby': 'vpw-cb-msg'
		});
		var cbOrder = h('input', { 'class': 'vpw-input', id: 'vpw-cb-order', type: 'text', name: 'vpw_order', autocomplete: 'off', maxlength: '20' });
		cbName.value = String(prefill.name || '');
		cbPhone.value = String(prefill.phone || '');
		var cbMsg = h('p', { 'class': 'vpw-form-msg', id: 'vpw-cb-msg', role: 'alert' });
		var cbSubmitLabel = h('span', { text: t('cbSubmit', 'Call me now') });
		var cbSubmit = h('button', { type: 'submit', 'class': 'vpw-btn vpw-btn--primary vpw-btn--block' }, [icon('phone'), cbSubmitLabel]);
		var cbForm = h('form', { 'class': 'vpw-view vpw-view--callback', hidden: true, novalidate: true }, [
			h('p', { 'class': 'vpw-view__title', text: t('cbTitle', 'We’ll call you') }),
			h('p', { 'class': 'vpw-view__text', text: t('cbText', 'Leave your number and we will call you right away.') }),
			field('vpw-cb-name', t('cbName', 'Your name'), cbName),
			field('vpw-cb-phone', t('cbPhone', 'Phone number'), cbPhone),
			field('vpw-cb-order', t('cbOrder', 'Order number (optional)'), cbOrder),
			cbMsg,
			cbSubmit
		]);

		/* ---- call-back requested ---- */
		var doneText = h('p', { 'class': 'vpw-view__text' });
		var doneBtn = h('button', { type: 'button', 'class': 'vpw-btn vpw-btn--soft vpw-btn--block' }, [h('span', { text: t('done', 'Done') })]);
		var doneView = h('div', { 'class': 'vpw-view vpw-view--done', hidden: true }, [
			h('span', { 'class': 'vpw-done-badge', 'aria-hidden': 'true' }, [icon('phone')]),
			h('p', { 'class': 'vpw-view__title', text: t('cbDoneTitle', 'Calling you now…') }),
			doneText,
			doneBtn
		]);

		/* ---- footer ---- */
		var switchIcon = icon('phone');
		var switchLabel = h('span', { text: t('preferPhone', 'Prefer a phone call?') });
		var switchBtn = h('button', { type: 'button', 'class': 'vpw-link' }, [switchIcon, switchLabel]);
		var footer = h('div', { 'class': 'vpw-footer' }, [
			switchBtn,
			h('span', { 'class': 'vpw-brand', text: t('poweredBy', 'Voice AI by VoicePress') })
		]);

		/* ---- assemble ---- */
		var panel = h('div', {
			'class': 'vpw-panel',
			id: 'vpw-panel',
			role: 'dialog',
			'aria-modal': 'false',
			'aria-labelledby': 'vpw-title',
			hidden: true
		}, [header, h('div', { 'class': 'vpw-body' }, [callView, cbForm, doneView]), footer]);
		var backdrop = h('div', { 'class': 'vpw-backdrop', 'aria-hidden': 'true', hidden: true });
		// Agent audio plays through our own element so a blocked autoplay can be resumed by a tap.
		var audioEl = h('audio', { 'class': 'vpw-audio', autoplay: true });
		audioEl.autoplay = true;

		root.appendChild(backdrop);
		root.appendChild(panel);
		root.appendChild(launcher);
		root.appendChild(audioEl);

		/* ---------------------------------------------------------- rendering */

		function isInCall() {
			return st.view === 'connecting' || st.view === 'ringing' || st.view === 'active';
		}

		// [title, text, retry allowed]
		function errorCopy(code) {
			switch (code) {
				case 'microphone_denied':
					return [t('errMicTitle', 'Microphone is blocked'), t('errMicText', 'Allow microphone access and try again.'), true];
				case 'at_capacity':
					return [t('errBusyTitle', 'All lines are busy'), t('errBusyText', 'Please try again in a minute.'), true];
				case 'rate_limited':
					return [t('errLimitTitle', 'Too many attempts'), t('errLimitText', 'Please wait a little and try again.'), true];
				case 'unavailable':
					return [t('errUnavailableTitle', 'Voice calls are unavailable'), t('errUnavailableText', 'Leave your number and we will call you.'), false];
				case 'unsupported':
					return [t('errUnsupportedTitle', 'Voice calls aren’t supported here'), t('errUnsupportedText', 'Leave your number and we will call you instead.'), false];
				case 'connect_failed':
					return [t('errConnectTitle', 'Connection problem'), t('errConnectText', 'Check your internet connection and try again.'), true];
				case 'call_rejected':
					return [t('errRejectedTitle', 'The call didn’t go through'), t('errRejectedText', 'Please try again in a moment.'), true];
				case 'dropped':
					return [t('errDroppedTitle', 'The call dropped'), t('errDroppedText', 'Please try again.'), true];
				default:
					return [t('errMintTitle', 'Couldn’t start the call'), t('errMintText', 'Please try again in a moment.'), true];
			}
		}

		function render() {
			var hadFocus = st.open && panel.contains(document.activeElement);
			var view = st.view;
			var inCall = isInCall();
			var title = '';
			var text = '';
			var orbName = 'mic';
			var orbLabel = t('start', 'Start voice call');
			var orbEnabled = !inCall;
			var primary = '';
			var alt = false;
			var noteName = 'lock';
			var noteMsg = '';

			root.setAttribute('data-vpw-state', view);
			toggleClass(root, 'vpw-in-call', inCall);
			toggleClass(root, 'vpw-is-muted', view === 'active' && st.muted);

			switch (view) {
				case 'connecting':
					title = t('connectingTitle', 'Connecting…');
					text = st.micReady ? t('connectingMic', 'Microphone ready. Connecting you now…') : t('connectingText', 'Allow microphone access if your browser asks.');
					break;
				case 'ringing':
					title = t('ringingTitle', 'Ringing…');
					text = t('ringingText', agent + ' will pick up in a moment.');
					break;
				case 'active':
					title = t('liveTitle', 'You’re talking to ' + agent);
					text = st.muted ? t('mutedText', 'You’re muted. Unmute to keep talking.') : t('liveText', 'Speak naturally.');
					noteName = 'alert';
					noteMsg = t('stayHint', 'Keep this page open during the call.');
					break;
				case 'ended':
					title = t('endedTitle', 'Thanks for calling!');
					text = st.endReason === 'remote-hangup' ? t('endedRemote', agent + ' ended the call.') : t('endedText', 'Need anything else?');
					orbName = 'phone';
					orbLabel = t('callAgain', 'Call again');
					primary = 'again';
					noteName = 'check';
					noteMsg = st.duration >= 1 ? t('callLength', 'Call length') + ' ' + clock(st.duration) : '';
					break;
				case 'error':
					var copy = errorCopy(st.errorCode);
					title = copy[0];
					text = copy[1];
					orbName = copy[2] ? 'retry' : 'alert';
					orbLabel = copy[2] ? t('tryAgain', 'Try again') : t('callMeInstead', 'Call my phone instead');
					primary = copy[2] ? 'retry' : '';
					alt = true;
					break;
				default:
					title = t('idleTitle', 'Start voice call');
					text = t('idleText', 'Ask about your orders, delivery, returns or products.');
					noteMsg = t('privacy', 'Uses your microphone. Calls may be recorded.');
			}

			statusTitle.textContent = title;
			statusText.textContent = text;
			setIcon(orbIcon, orbName);
			orb.setAttribute('aria-label', orbLabel);
			if (orbEnabled) {
				orb.removeAttribute('aria-disabled');
			} else {
				orb.setAttribute('aria-disabled', 'true');
			}

			liveRow.hidden = view !== 'active';
			controls.hidden = !inCall;
			muteBtn.hidden = view !== 'active';
			endLabel.textContent = view === 'active' ? t('end', 'End call') : t('cancel', 'Cancel');
			if (view !== 'active') {
				soundBtn.hidden = true;
			}

			actions.hidden = !primary && !alt;
			primaryBtn.hidden = !primary;
			if (primary) {
				primaryLabel.textContent = primary === 'again' ? t('callAgain', 'Call again') : t('tryAgain', 'Try again');
				setIcon(primaryIcon, primary === 'again' ? 'phone' : 'retry');
			}
			altBtn.hidden = !alt;
			toggleClass(altBtn, 'vpw-btn--primary', alt && !primary);
			toggleClass(altBtn, 'vpw-btn--soft', !(alt && !primary));

			note.hidden = !noteMsg;
			noteText.textContent = noteMsg;
			setIcon(noteIcon, noteName);

			renderMute();
			renderChip();
			renderLauncher();
			renderFooter();
			keepFocus(hadFocus);
		}

		/** If the focused control was just hidden (e.g. "End call"), move focus to the next action. */
		function keepFocus(hadFocus) {
			var active = document.activeElement;
			if (!hadFocus || !st.open || st.pane !== 'call') {
				return;
			}
			if (active && active !== document.body && panel.contains(active) && active.offsetParent !== null) {
				return;
			}
			var target = isInCall() ? endBtn : (!primaryBtn.hidden && !actions.hidden ? primaryBtn : orb);
			try {
				target.focus({ preventScroll: true });
			} catch (e) {
				target.focus();
			}
		}

		function setView(view) {
			st.view = view;
			render();
		}

		function renderMute() {
			toggleClass(muteBtn, 'vpw-is-on', st.muted);
			muteLabel.textContent = st.muted ? t('unmute', 'Unmute') : t('mute', 'Mute');
			setIcon(muteIcon, st.muted ? 'micOff' : 'mic');
		}

		function renderChip() {
			toggleClass(root, 'vpw-is-speaking', st.view === 'active' && st.speaking && !st.muted);
			if (st.muted) {
				chip.textContent = t('muted', 'Muted');
			} else {
				chip.textContent = st.speaking ? t('speaking', agent + ' is speaking') : t('listening', 'Listening…');
			}
		}

		function renderLauncher() {
			var inCall = isInCall();
			setIcon(launcherIcon, st.open ? 'chevron' : 'mic');
			launcher.setAttribute('aria-expanded', st.open ? 'true' : 'false');
			if (st.open) {
				launcher.setAttribute('aria-label', t('minimize', 'Minimize'));
			} else if (inCall) {
				launcher.setAttribute('aria-label', t('liveCall', 'Voice call in progress. Open the call panel'));
			} else {
				launcher.setAttribute('aria-label', label);
			}
		}

		function renderFooter() {
			var onCallPane = st.pane === 'call';
			// Hidden during a call, and on the error view (it already offers "Call my phone instead").
			switchBtn.hidden = isInCall() || (onCallPane && st.view === 'error');
			switchLabel.textContent = onCallPane ? t('preferPhone', 'Prefer a phone call?') : t('backToVoice', 'Back to voice call');
			setIcon(switchIcon, onCallPane ? 'phone' : 'mic');
		}

		function showPane(pane) {
			st.pane = pane;
			callView.hidden = pane !== 'call';
			cbForm.hidden = pane !== 'callback';
			doneView.hidden = pane !== 'done';
			renderFooter();
		}

		function showCallback() {
			showPane('callback');
			focusLater(cbPhone.value ? cbSubmit : cbPhone);
		}

		/* ------------------------------------------------------- open / close */

		function openPanel() {
			if (st.open) {
				return;
			}
			st.open = true;
			panel.hidden = false;
			backdrop.hidden = false;
			toggleClass(root, 'vpw-is-open', true);
			renderLauncher();
			if (st.pane === 'callback') {
				focusLater(cbPhone.value ? cbSubmit : cbPhone);
			} else if (st.pane === 'done') {
				focusLater(doneBtn);
			} else {
				focusLater(isInCall() ? endBtn : orb);
			}
		}

		function closePanel(restoreFocus) {
			if (!st.open) {
				return;
			}
			st.open = false;
			panel.hidden = true;
			backdrop.hidden = true;
			toggleClass(root, 'vpw-is-open', false);
			renderLauncher();
			if (restoreFocus !== false) {
				focusLater(launcher);
			}
		}

		/* ---------------------------------------------------------- the call */

		function isSupported() {
			return typeof window.BolnaWebCall === 'function' &&
				!!window.fetch &&
				!!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function') &&
				typeof window.RTCPeerConnection !== 'undefined' &&
				window.isSecureContext !== false;
		}

		/** getSession for BolnaWebCall: the mint JSON from our REST API, returned unchanged. */
		function mintSession(attempt) {
			return api('web-session', { context: CONTEXT }).then(function (res) {
				var data = res.data || {};
				if (res.ok && data.run_id && data.wss_url && data.sip_username) {
					return data;
				}
				attempt.status = res.status || 0;
				var err = new Error(data.message || 'Could not start call');
				err.status = res.status;
				throw err;
			});
		}

		function startCall() {
			if (isInCall()) {
				return;
			}
			showPane('call');
			if (!isSupported()) {
				fail('unsupported');
				return;
			}

			var attempt = { status: 0 };
			var call;
			try {
				call = new window.BolnaWebCall({
					getSession: function () {
						return mintSession(attempt);
					},
					audioElement: audioEl
				});
			} catch (err) {
				log('could not create the call', err);
				fail('unsupported');
				return;
			}

			st.call = call;
			st.attempt = attempt;
			st.connected = false;
			st.micReady = false;
			st.muted = false;
			st.errorCode = '';
			st.endReason = '';
			st.duration = 0;
			bindCall(call);
			setView('connecting');

			var started;
			try {
				started = call.start();
			} catch (err2) {
				log('start failed', err2);
				fail('connect_failed');
				return;
			}
			if (started && typeof started.then === 'function') {
				started.then(noop, function (err3) {
					// Normally surfaced through the "error" event already; this is a safety net.
					if (call === st.call && isInCall() && !st.connected) {
						log('start rejected', err3);
						fail('connect_failed');
					}
				});
			}
		}

		function bindCall(call) {
			function mine(fn) {
				return function (payload) {
					if (call !== st.call) {
						return; // Event from an earlier call instance.
					}
					try {
						fn(payload);
					} catch (e) {
						log('handler error', e);
					}
				};
			}
			call.on('state-change', mine(function (state) {
				if (state === 'connecting' || state === 'ringing') {
					setView(state);
				} else if (state === 'active') {
					st.connected = true;
					setView('active');
				} else if (state === 'ended' && isInCall()) {
					// "call-end" (with the reason) is emitted right after; let it win.
					window.setTimeout(function () {
						if (call === st.call && isInCall()) {
							finish('');
						}
					}, 0);
				}
			}));
			call.on('media-permission', mine(function () {
				st.micReady = true;
				if (st.view === 'connecting') {
					render();
				}
			}));
			call.on('call-start', mine(function () {
				st.connected = true;
				st.startedAt = Date.now();
				startTimer();
				startMeter();
				setView('active');
			}));
			call.on('call-end', mine(function (payload) {
				finish(payload && payload.reason ? String(payload.reason) : '');
			}));
			call.on('error', mine(onCallError));
			call.on('volume-level', mine(onVolume));
		}

		function onCallError(err) {
			var code = (err && err.code) || 'connect_failed';
			if (code === 'autoplay_blocked') {
				if (isInCall()) {
					soundBtn.hidden = false;
				}
				return;
			}
			if (code === 'already_active') {
				return;
			}
			log('voice call error: ' + code, err && err.message ? err.message : '');
			if (code === 'mint_failed') {
				var httpStatus = st.attempt ? st.attempt.status : 0;
				if (httpStatus === 429) {
					code = 'rate_limited';
				} else if (httpStatus === 401 || httpStatus === 403 || httpStatus === 404) {
					code = 'unavailable';
				} else if (navigator.onLine === false) {
					code = 'connect_failed';
				}
			} else if (code === 'at_capacity' && err && err.scope === 'not_enabled') {
				code = 'unavailable';
			}
			fail(code);
		}

		function fail(code) {
			stopTimer();
			stopMeter();
			resetAudio();
			st.startedAt = 0;
			st.connected = false;
			st.errorCode = code;
			setView('error');
		}

		function finish(reason) {
			var wasConnected = st.connected;
			var duration = st.startedAt ? (Date.now() - st.startedAt) / 1000 : 0;
			stopTimer();
			stopMeter();
			resetAudio();
			st.startedAt = 0;
			st.connected = false;
			st.muted = false;
			if (st.view === 'error') {
				render();
				return;
			}
			if (reason === 'failed') {
				fail(wasConnected ? 'dropped' : 'connect_failed');
				return;
			}
			if (!wasConnected) {
				setView('idle'); // Cancelled before the assistant answered.
				return;
			}
			st.endReason = reason;
			st.duration = duration;
			setView('ended');
		}

		function hangUp() {
			var call = st.call;
			if (!call || !isInCall()) {
				return;
			}
			try {
				var stopped = call.stop();
				if (stopped && typeof stopped.then === 'function') {
					stopped.then(noop, noop);
				}
			} catch (e) {
				log('stop failed', e);
			}
			// Safety net in case the SDK does not report the end.
			window.setTimeout(function () {
				if (call === st.call && isInCall()) {
					finish('local-hangup');
				}
			}, 2500);
		}

		function toggleMute() {
			if (!st.call || st.view !== 'active') {
				return;
			}
			st.muted = !st.muted;
			try {
				st.call.setMuted(st.muted);
			} catch (e) {
				log('mute failed', e);
			}
			render();
		}

		function resetAudio() {
			soundBtn.hidden = true;
			try {
				audioEl.pause();
				audioEl.srcObject = null;
			} catch (e) { /* ignore */ }
		}

		function resumeAudio() {
			var played;
			try {
				played = audioEl.play();
			} catch (e) {
				played = null;
			}
			if (played && typeof played.then === 'function') {
				played.then(function () {
					soundBtn.hidden = true;
				}, noop);
			} else {
				soundBtn.hidden = true;
			}
		}

		/* -------------------------------------------------- timer & visuals */

		function startTimer() {
			stopTimer();
			var tick = function () {
				var text = clock(st.startedAt ? (Date.now() - st.startedAt) / 1000 : 0);
				timerText.textContent = text;
				launcherTime.textContent = text;
			};
			tick();
			st.timer = window.setInterval(tick, 1000);
		}

		function stopTimer() {
			if (st.timer) {
				window.clearInterval(st.timer);
			}
			st.timer = null;
		}

		function onVolume(value) {
			var n = typeof value === 'number' ? value : parseFloat(value);
			// Speech rarely exceeds ~0.45 on the SDK's scale: add gain for a lively meter.
			st.target = isFinite(n) ? Math.max(0, Math.min(1, n * 2.2)) : 0;
			if (REDUCED_MOTION || !window.requestAnimationFrame) {
				paint(st.target, 0);
			}
		}

		function startMeter() {
			if (REDUCED_MOTION || st.raf || !window.requestAnimationFrame) {
				return;
			}
			var loop = function (ts) {
				// Fast attack, slower release.
				st.level += (st.target - st.level) * (st.target > st.level ? 0.35 : 0.12);
				if (st.level < 0.002) {
					st.level = 0;
				}
				paint(st.level, ts);
				st.raf = window.requestAnimationFrame(loop);
			};
			st.raf = window.requestAnimationFrame(loop);
		}

		function stopMeter() {
			if (st.raf && window.cancelAnimationFrame) {
				window.cancelAnimationFrame(st.raf);
			}
			st.raf = 0;
			st.level = 0;
			st.target = 0;
			st.speaking = false;
			paint(0, 0);
		}

		function paint(level, ts) {
			var value = level.toFixed(3);
			stage.style.setProperty('--vpw-level', value);
			launcher.style.setProperty('--vpw-level', value);
			var time = (ts || 0) / 1000;
			for (var i = 0; i < bars.length; i++) {
				var wobble = REDUCED_MOTION ? 1 : 0.7 + 0.3 * Math.sin(time * (6 + i * 1.9) + i * 1.7);
				var rest = REDUCED_MOTION ? 0.22 : 0.2 + 0.06 * Math.sin(time * 2.4 + i * 0.9);
				var scale = Math.min(1, rest + level * BAR_WEIGHTS[i] * wobble);
				bars[i].style.transform = 'scaleY(' + scale.toFixed(3) + ')';
			}
			updateSpeaking(level > 0.12);
		}

		function updateSpeaking(on) {
			var now = Date.now();
			if (on) {
				st.speakingAt = now;
			}
			if (on === st.speaking) {
				return;
			}
			if (!on && now - st.speakingAt < 700) {
				return; // Hold "speaking" briefly between words to avoid flicker.
			}
			st.speaking = on;
			renderChip();
		}

		/* ---------------------------------------------------- call-back form */

		function setFormMessage(text, isError) {
			cbMsg.textContent = text || '';
			toggleClass(cbMsg, 'vpw-is-error', !!isError);
		}

		function submitCallback(event) {
			if (event) {
				event.preventDefault();
			}
			if (st.cbBusy) {
				return;
			}
			var phone = cbPhone.value.trim();
			var digits = digitsOf(phone);
			setFormMessage('', false);
			cbPhone.removeAttribute('aria-invalid');
			if (digits.length < 10 || digits.length > 15) {
				cbPhone.setAttribute('aria-invalid', 'true');
				setFormMessage(t('cbInvalid', 'Please enter a valid phone number (at least 10 digits).'), true);
				cbPhone.focus();
				return;
			}

			st.cbBusy = true;
			cbSubmit.disabled = true;
			cbSubmitLabel.textContent = t('cbSending', 'Requesting…');

			var payload = {
				phone: phone,
				name: cbName.value.trim().slice(0, 80),
				order_id: cbOrder.value.replace(/^\s*#/, '').trim().slice(0, 20)
			};

			api('callback-request', payload).then(function (res) {
				var data = res.data || {};
				if (res.ok && data.ok !== false) {
					doneText.textContent = data.message ? String(data.message) : t('cbDoneText', 'Pick up the call in a few seconds.');
					showPane('done');
					focusLater(doneBtn);
					return;
				}
				var message = t('cbError', 'We couldn’t place the call right now. Please try again shortly.');
				if (data.code === 'rest_invalid_param' || data.code === 'rest_missing_callback_param') {
					message = t('cbInvalid', 'Please enter a valid phone number (at least 10 digits).');
				} else if (res.status && res.status < 500 && data.message) {
					message = String(data.message);
				}
				setFormMessage(message, true);
			}, function () {
				setFormMessage(t('cbError', 'We couldn’t place the call right now. Please try again shortly.'), true);
			}).then(function () {
				st.cbBusy = false;
				cbSubmit.disabled = false;
				cbSubmitLabel.textContent = t('cbSubmit', 'Call me now');
			});
		}

		/* ------------------------------------------------------------- events */

		function onPrimaryAction() {
			if (isInCall()) {
				return;
			}
			if (st.view === 'error' && !errorCopy(st.errorCode)[2]) {
				showCallback();
				return;
			}
			startCall();
		}

		launcher.addEventListener('click', function () {
			if (st.open) {
				closePanel();
			} else {
				openPanel();
			}
		});
		closeBtn.addEventListener('click', function () {
			closePanel();
		});
		backdrop.addEventListener('click', function () {
			closePanel(false);
		});
		orb.addEventListener('click', onPrimaryAction);
		primaryBtn.addEventListener('click', onPrimaryAction);
		altBtn.addEventListener('click', showCallback);
		endBtn.addEventListener('click', hangUp);
		muteBtn.addEventListener('click', toggleMute);
		soundBtn.addEventListener('click', resumeAudio);
		switchBtn.addEventListener('click', function () {
			if (st.pane === 'call') {
				showCallback();
			} else {
				showPane('call');
				focusLater(orb);
			}
		});
		cbForm.addEventListener('submit', submitCallback);
		cbPhone.addEventListener('input', function () {
			if (cbPhone.getAttribute('aria-invalid')) {
				cbPhone.removeAttribute('aria-invalid');
				setFormMessage('', false);
			}
		});
		doneBtn.addEventListener('click', function () {
			showPane('call');
			if (!isInCall()) {
				setView('idle');
			}
			focusLater(orb);
		});
		root.addEventListener('keydown', function (event) {
			if ((event.key === 'Escape' || event.key === 'Esc') && st.open) {
				event.stopPropagation();
				closePanel();
			}
		});

		// [voicepress_call_button] and any element with data-vpw-open="open|start".
		document.addEventListener('click', function (event) {
			var trigger = closest(event.target, '[data-vpw-open]');
			if (!trigger || closest(trigger, '#voicepress-widget')) {
				return;
			}
			event.preventDefault();
			openPanel();
			if (trigger.getAttribute('data-vpw-open') === 'start' && !isInCall()) {
				startCall(); // Still inside the click, so the browser allows audio.
			}
		});

		// Small API for themes: VoicePress.open(), .close(), .startCall() (from a click), .endCall().
		var api_ = window.VoicePress && typeof window.VoicePress === 'object' ? window.VoicePress : {};
		api_.open = function () {
			openPanel();
		};
		api_.close = function () {
			closePanel(false);
		};
		api_.startCall = function () {
			openPanel();
			startCall();
		};
		api_.endCall = hangUp;
		window.VoicePress = api_;

		showPane('call');
		setView('idle');
		paint(0, 0);
	}

	/* ------------------------------------------------------------------- boot */

	onReady(function () {
		var capture = cfg.capture === undefined ? truthy(cfg.isCheckout) : truthy(cfg.capture);
		if (capture) {
			try {
				initCapture();
			} catch (e) {
				log('checkout capture failed to start', e);
			}
		}
		if (truthy(cfg.widget) && !truthy(cfg.captureOnly)) {
			try {
				initWidget();
			} catch (e2) {
				log('widget failed to start', e2);
			}
		}
	});
})(window, document);
