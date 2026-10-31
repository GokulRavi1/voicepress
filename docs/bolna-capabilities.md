# Bolna capabilities digest: WooCommerce voice-AI plugin

## Deployment (no Docker needed)
- Hosted Bolna is plain HTTPS REST (`https://api.bolna.ai`, `Authorization: Bearer <api_key>` from Dashboard → Developers → API Keys, shown once, server-side only); a WP plugin calls it via `wp_remote_*`, nothing to self-host. (https://www.bolna.ai/docs/api-reference/authentication; https://www.bolna.ai/docs/concepts/security)
- Docker/Kubernetes applies only to enterprise on-prem or self-hosting the MIT open-source core. (https://www.bolna.ai/docs/enterprise/on-premise-deployments; https://www.bolna.ai/docs/developer-resources/overview)

## Pricing, concurrency, limits
- Flat **$0.06/min (₹5.52/min)** covers STT+LLM+TTS on "preferred models" (Deepgram nova-2/3, Sarvam, gpt-4o-mini/4.1-mini/5-mini, ElevenLabs flash/turbo, Cartesia sonic). Any non-preferred component is billed at usage rates. Telephony and a platform fee (FAQ: $0.02/min) appear as separate components. The docs conflict on whether $0.06 is all-in, so check Account Info → Pricing Information. Prepaid wallet; $600+ top-ups are discounted. (https://www.bolna.ai/docs/pricing/preferred-models; https://www.bolna.ai/docs/get-started/what-it-costs; https://www.bolna.ai/docs/frequently-asked-questions)
- Pilot: 6,500 min/$300 or 12,000 min/$500 (~$0.046/$0.042 per min), concurrency 25, minutes never expire. (https://www.bolna.ai/docs/pricing/pilot-plan)
- BYOK keys cut cost. On the Basic plan, any BYOK component moves that call to base usage pricing. (https://www.bolna.ai/docs/pricing/call-pricing)
- Concurrency: trial 2 (verified numbers only); paid starts at 10 and auto-scales with usage. Excess outbound calls are queued, never rejected; inbound is unlimited. `GET /user/me` → `concurrency.max/current`. (https://www.bolna.ai/docs/pricing/outbound-calling-concurrency; https://www.bolna.ai/docs/api-reference/limits)
- Rate limits: `/call`, `/v2/agent/` and agent executions allow 500 req/min; all others 1000; back off on `429`. (https://www.bolna.ai/docs/api-reference/rate-limiting)
- Data is stored in the US (us-east-1) by default; Indian residency (ap-south-1) is available. (https://www.bolna.ai/docs/concepts/security)

## Languages
- 18 languages, **12 Indian**: Hindi `hi`, Bengali `bn`, Assamese `as`, Gujarati `gu`, Kannada `kn`, Malayalam `ml`, Marathi `mr`, Odia `od`, Punjabi `pa`, Tamil `ta`, Telugu `te`, Urdu `ur` (plus English and five others). Send plain codes (`hi`, not `hi-IN`). Assamese has no confirmed TTS voice; Sarvam covers most Indic STT/TTS. (https://www.bolna.ai/docs/customizations/supported-languages; https://www.bolna.ai/docs/customizations/multilingual-languages-support)

## Telephony
- India: Plivo, Exotel, Vobiz. Outside India: Twilio. All support inbound and outbound, and you can bring your own account. Any SIP carrier works via a BYO trunk (provider `trunk`). (https://www.bolna.ai/docs/supported-telephony-providers)
- Buy numbers in the dashboard at $5/month. Indian numbers come via Plivo (Karnataka 80, Maharashtra 22) or Vobiz (after KYC), and need compliance approval. Activation takes up to 3 min (`429` + `retry_after_seconds`). Without `from_phone_number`, Indian callees see a shared +91 number. (https://www.bolna.ai/docs/buying-phone-numbers; https://www.bolna.ai/docs/api-reference/calls/make; https://www.bolna.ai/docs/outbound/making-calls)
- TRAI: commercial calls need DLT-registered numbers. 140-series is for promotional calls (Vobiz; TATA DLT, ₹5,900). 160-series is for transactional/service calls (Plivo). (https://www.bolna.ai/docs/obtaining-regulated-phone-numbers)
- SIP: point numbers at `sip:trunk.bolna.ai:5060` and allowlist `15.252.21.46` and `13.206.230.198`. Codecs are PCMU/PCMA, with 1–1000 channels (default 10). India guides cover Airtel, Exotel, Plivo, Vobiz, Acefone and VoiceLink. (https://www.bolna.ai/docs/sip-trunking/overview)

## Custom function tools
- API shape: `tasks[].tools_config.api_tools = {tools: [...], tools_params: {<name>: {...}}}`.
  - Tool fields: `name`, `description` (decides when the tool fires), OpenAI-style `parameters`, `key: "custom_task"` (mandatory), `pre_call_message` (spoken while waiting).
  - `tools_params` entry: `method` (GET/POST/PUT/PATCH/DELETE), `url`, `param`, `headers`.
  (https://www.bolna.ai/docs/graph-agent/tools-and-rag; https://www.bolna.ai/docs/tool-calling/custom-function-schema)
- Templating: `%(x)s` / `%(x)i` / `%(x)f` work in `param` and in the URL path (e.g. `/orders/%(order_id)s`). Names must match `properties` exactly. The LLM keeps asking until it has every `required` param. The JSON response is fed back to the LLM. (https://www.bolna.ai/docs/tool-calling/custom-function-schema; https://www.bolna.ai/docs/tool-calling/fetch-date-and-time)
- Auth: `api_token` is the full Authorization value (`"Bearer …"`), plus optional `headers`. Calls come from `13.203.39.153`, `13.126.9.249` and `13.202.133.53`. There is **no HMAC** on tool calls or webhooks, so trust rests on the IP allowlist plus a secret header. (https://www.bolna.ai/docs/tool-calling/custom-function-calls; https://www.bolna.ai/docs/concepts/security)
- Prompt variables and system vars (`from_number`, `to_number`, `call_sid`, `agent_id`) auto-fill params with the same name. (https://www.bolna.ai/docs/tool-calling/custom-function-variables)
- `PATCH /v2/agent/{id}` ignores tools; change `api_tools` with `PUT`. (https://www.bolna.ai/docs/api-reference/agent/v2/patch_update)
- Pre-call webhook: set per tool.
  - `pre_call_webhook_param` is a JSON template with `%(arg)s` and acts as the on/off switch.
  - `pre_call_webhook_url` is optional and defaults to the agent `webhook_url`.
  - It is a fire-and-forget POST of the in-progress execution merged with your fields.
  (https://www.bolna.ai/docs/tool-calling/pre-call-webhooks)
- Human transfer: tool `key: "transfer_call"` with `tools_params` `{url: null, param: {call_sid: "%(call_sid)s", call_transfer_number: "+91…"}}`.
  - Use one tool per department; the pre-tool message can be set per language.
  - Its pre-call webhook can send `%(reason)s` and `%(summary)s`.
  - SIP-URI targets work only on Plivo/SIP trunk; the outcome lands in `transfer_call_data`.
  (https://www.bolna.ai/docs/tool-calling/transfer-calls)

## Dynamic context
- `POST /call` `user_data` fills `{{var}}`/`{var}` in the prompt and welcome message. Nested JSON paths work (`{{order.items.0.name}}`). An unresolved `{{x}}` shows up as literal text. (https://www.bolna.ai/docs/prompting/using-context)
- Auto-injected: `agent_id`, `execution_id`, `call_sid`, `from_number`, `to_number`, `current_date`, `current_time`, `timezone` (set via `user_data.timezone`). On outbound calls `to_number` is the customer; on inbound calls `from_number` is the caller. (https://www.bolna.ai/docs/prompting/using-context)
- Inbound caller lookup: Bolna GETs your endpoint with `contact_number`, `agent_id` and `execution_id` (Bearer auth, **3 s timeout**). Top-level JSON keys become variables, e.g. orders looked up by phone. (https://www.bolna.ai/docs/customizations/identify-incoming-callers)

## Knowledge base
- Sources are `.pdf` uploads or URLs. English or Multilingual mode is fixed at creation. Attach a source once its status is `processed`. API: `POST /knowledgebase`, `GET /knowledgebase/{rag_id}`, `GET /knowledgebase/all`, `DELETE /knowledgebase/{rag_id}`. (https://www.bolna.ai/docs/knowledge-base/add-sources; https://www.bolna.ai/docs/knowledge-base/manage-and-connect; https://www.bolna.ai/docs/api-reference/knowledgebase/overview)

## Batch calling
- `POST /batches` (multipart) takes:
  - `agent_id`
  - CSV `file`: `contact_number` in E.164; other columns become variables; optional `agent_language`
  - repeated `from_phone_numbers`
  - `retry_config`
- Then call `POST /batches/{id}/schedule` with `scheduled_at` in ISO 8601 with a numeric offset (no `Z`), at least 2 min and at most 30 days ahead.
(https://www.bolna.ai/docs/outbound/batch-calling; https://www.bolna.ai/docs/api-reference/batches/create; https://www.bolna.ai/docs/api-reference/batches/schedule)

## Workflows (beta): call → retry → WhatsApp
- Node types:
  - `start`
  - `agent`: branches on `call.status` (completed/busy/no_answer/voicemail…)
  - `extraction`
  - `api`
  - `callback`: pauses until your POST, signed with `X-Bolna-Signature` HMAC-SHA256
  - `time`
  - `retry`: 1–10 delayed re-runs
  - `aisensy_whatsapp`: sends an approved template via your AiSensy project; fire-and-forget
  - `end`
  (https://www.bolna.ai/docs/workflows/introduction; https://www.bolna.ai/docs/workflows/nodes; https://www.bolna.ai/docs/workflows/aisensy-whatsapp)
- Run one contact with `POST /workflows/{id}/run`; `reference_id` dedupes (`409 duplicate_run`). Campaigns take up to 150k rows. Each finished execution sends one HTTPS webhook (port 443/8443) with your custom auth headers. (https://www.bolna.ai/docs/api-reference/workflows/run; https://www.bolna.ai/docs/workflows/campaigns; https://www.bolna.ai/docs/workflows/execution-webhook)

## Web call SDK (browser WebRTC)
- Package: `@bolna/web-call` v3.0.1, from npm or jsDelivr (exposes `window.BolnaWebCall`).
- Auth: the API key stays server-side. Your backend (e.g. a WP REST route behind nonce/rate limiting) POSTs `https://api.bolna.ai/web-call/session` with `agent_id`, `user_data` and optional `agent_data.language`. It returns a single-use session that lasts about 120 s.
- Client: `new BolnaWebCall({sessionUrl})`, then `start()` from a click.
  - Events: `state-change`, `call-start`, `call-end`, `error` (`at_capacity`, `microphone_denied`…).
  - `getRunId()` returns the execution id.
  - `userData` is capped at 50 KB and is visible in call history.

(https://www.bolna.ai/docs/developer-resources/sdks/web-call; https://www.bolna.ai/docs/developer-resources/sdks/web-call-api)

## Extractions & dispositions
- Categories hold dispositions; each category runs as one LLM pass with its own model (default `gpt-4.1-mini`). A disposition can be free text (typed timestamp/numeric/boolean/email/regex), pre-defined options, or both.
- Output: `extracted_data[Category][Name] = {subjective, objective, confidence, …}`.
- API: `/dispositions` CRUD, `/dispositions/bulk` (atomic), `POST /v2/agent/{id}/dispositions/test`.
- Extractions are skipped when the caller never spoke.

(https://www.bolna.ai/docs/api-reference/dispositions/overview; https://www.bolna.ai/docs/prompting/extraction-output; https://www.bolna.ai/docs/migrating-to-extractions/overview)

## Call webhooks
- The agent `webhook_url` (PATCH-able) gets a POST on every status change, carrying the full execution. Custom headers and a status filter are optional. Statuses: scheduled→queued→initiated→ringing→in-progress→call-disconnected→completed, plus rescheduled, no-answer, busy, failed, canceled, stopped, error, balance-low. (https://www.bolna.ai/docs/post-call/polling-call-status-webhooks; https://www.bolna.ai/docs/post-call/list-phone-call-status)
- Handling:
  - Act on `completed`; cost, transcript and extractions are still empty at `call-disconnected`.
  - Dedupe on `id`.
  - Trust `termination_reason` (`voicemail_reached`, `number_blocked` for DND…) over `status`.
  - Webhooks come from the same three source IPs. Reply 2xx fast, since non-2xx responses and timeouts are retried.
  (https://www.bolna.ai/docs/post-call/execution-payload; https://www.bolna.ai/docs/post-call/termination-reasons; https://www.bolna.ai/docs/api-reference/limits)

## Executions API
- `GET /executions/{id}` (+ `/log`, `/debug`).
- `GET /v2/agent/{agent_id}/executions`: `page_number`, `page_size` ≤50, `from`+`to` in UTC, range ≤7 days.
- `GET /batches/{id}/executions`.
- `POST /call/{execution_id}/stop` cancels queued or scheduled calls.

(https://www.bolna.ai/docs/api-reference/executions/overview; https://www.bolna.ai/docs/api-reference/executions/get_executions; https://www.bolna.ai/docs/api-reference/calls/stop_call)

## Guardrails, retry, voicemail
- Calling hours: `agent_config.calling_guardrails {call_start_hour, call_end_hour}` (0–23, recipient's timezone). Calls outside the window become `rescheduled`. Set `bypass_call_guardrails: true` per call to skip the check. TRAI allows 9 AM–9 PM IST and requires respecting DND. (https://www.bolna.ai/docs/outbound/calling-guardrails)
- `retry_config` on `/call` or `/batches`:
  - `enabled`
  - `max_retries` (1–3)
  - `retry_on_statuses` (no-answer/busy/failed/error)
  - `retry_on_voicemail`
  - `retry_intervals_minutes`
  - `prioritize_retries`

  Webhooks carry `retry_count`/`retry_history`. (https://www.bolna.ai/docs/outbound/auto-retry)
- Voicemail detection is off by default (Twilio wait is configurable 2–10 s). It hangs up, sets `answered_by_voice_mail`, and can drop real people. Auto-reschedule (beta) calls back at a time the customer asks for, up to 14 days out. DTMF keypad input (Twilio/Plivo/SIP) suits order numbers. (https://www.bolna.ai/docs/agent-setup/call-tab)

## Multilingual switching
- `multilingual_config` holds a prompt, voice and STT per language. `language_switch_trigger` is `requested_or_auto_detected` (default) or `caller_requested`.
- Auto-detect covers English + 10 Indian languages (not Odia or Assamese). It ignores code-mixed words, numbers, order IDs and OTPs. System messages follow the active language.
- Start language: set `agent_data.language` (on `/call` and the web-call session) or an `agent_language` variable/CSV column. Conflicting values return `400`.

(https://www.bolna.ai/docs/customizations/language-switching-behavior; https://www.bolna.ai/docs/customizations/start-call-in-language)

## E-commerce starters
- Cart-abandonment and COD-confirmation agent templates (English + Hindi) can be imported. (https://www.bolna.ai/docs/agent-templates/cart-abandonment-agent; https://www.bolna.ai/docs/agent-templates/cod-confirmation-agent)