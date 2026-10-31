<?php
/**
 * Use cases, agent definitions (prompts, tools, extractions) and Bolna agent provisioning.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the five VoicePress voice agents and keeps them in sync with Bolna.
 *
 * Store facts (name, persona, delivery ETA, return window, support hours, policies) are baked into the
 * prompts at provisioning time. Per-call facts arrive as Bolna user_data variables ({{customer_name}} …),
 * exactly as listed in the build spec's variable contract.
 */
class VoicePress_Agents {

	/**
	 * Agent keys, in display order.
	 */
	const AGENT_KEYS = array( 'cod_confirmation', 'delivery', 'cart_recovery', 'feedback', 'support' );

	/**
	 * Use case => agent key. 'test' has no fixed agent (the caller chooses one).
	 */
	const USE_CASE_AGENTS = array(
		'cod_confirmation'  => 'cod_confirmation',
		'delivery_reminder' => 'delivery',
		'ndr_resolution'    => 'delivery',
		'cart_recovery'     => 'cart_recovery',
		'feedback'          => 'feedback',
		'support_callback'  => 'support',
		'web_call'          => 'support',
		'inbound'           => 'support',
		'test'              => '',
	);

	/**
	 * Agent key => custom function tools the agent gets (when a public URL is set).
	 */
	const AGENT_TOOLS = array(
		'cod_confirmation' => array( 'confirm_order', 'cancel_order', 'reschedule_delivery', 'update_address' ),
		'delivery'         => array( 'get_order_details', 'cancel_order', 'reschedule_delivery', 'update_address' ),
		'cart_recovery'    => array( 'search_products' ),
		'feedback'         => array( 'record_feedback', 'create_ticket' ),
		'support'          => array( 'identify_customer', 'get_order_details', 'cancel_order', 'reschedule_delivery', 'update_address', 'request_return', 'search_products', 'create_ticket' ),
	);

	/**
	 * Extraction category every agent gets on create.
	 */
	const EXTRACTION_CATEGORY = 'VoicePress';

	/**
	 * Model used for the extraction pass.
	 */
	const EXTRACTION_MODEL = 'gpt-4.1-mini';

	/**
	 * Let the agent hang up by itself once it has said goodbye (Bolna "hangup using a prompt").
	 * Set to false to fall back to silence / caller hang-up only.
	 */
	const HANGUP_BY_PROMPT = true;

	/**
	 * No hooks needed: agents are provisioned on demand (REST admin/provision, admin/sync-agents).
	 */
	public static function init() {
	}

	/* ---------------------------------------------------------------------------------------------
	 * Use cases & labels
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Whether a string is one of the agent keys.
	 *
	 * @param mixed $agent_key Agent key.
	 * @return bool
	 */
	public static function is_agent_key( $agent_key ) {
		return is_string( $agent_key ) && in_array( $agent_key, self::AGENT_KEYS, true );
	}

	/**
	 * Agent key that handles a use case ('' for 'test' or unknown use cases).
	 *
	 * @param string $use_case Use case key.
	 * @return string
	 */
	public static function use_case_agent_key( $use_case ) {
		$use_case = is_string( $use_case ) ? $use_case : '';
		if ( array_key_exists( $use_case, self::USE_CASE_AGENTS ) ) {
			return self::USE_CASE_AGENTS[ $use_case ];
		}
		return self::is_agent_key( $use_case ) ? $use_case : '';
	}

	/**
	 * Bolna agent id for a use case, '' when the agent has not been created yet.
	 *
	 * @param string $use_case Use case key.
	 * @return string
	 */
	public static function agent_for_use_case( $use_case ) {
		$agent_key = self::use_case_agent_key( $use_case );
		return '' === $agent_key ? '' : self::saved_agent_id( $agent_key );
	}

	/**
	 * Agent cards: agent_key => [label, description, use_cases, icon].
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'cod_confirmation' => array(
				'label'       => __( 'COD Confirmation', 'voicepress' ),
				'description' => __( 'Calls every cash on delivery order before it ships to confirm the items, amount and address. Handles cancellations, new delivery dates, address fixes and pay-online requests, so fewer fake orders ship and RTO drops.', 'voicepress' ),
				'use_cases'   => array( 'cod_confirmation' ),
				'icon'        => 'dashicons-money-alt',
			),
			'delivery'         => array(
				'label'       => __( 'Delivery Updates', 'voicepress' ),
				'description' => __( 'Tells customers their order is out for delivery and rescues failed deliveries (NDR) by fixing the date, time slot or address before the parcel goes back.', 'voicepress' ),
				'use_cases'   => array( 'delivery_reminder', 'ndr_resolution' ),
				'icon'        => 'dashicons-car',
			),
			'cart_recovery'    => array(
				'label'       => __( 'Cart Recovery', 'voicepress' ),
				'description' => __( 'Calls shoppers who left items in their cart, answers product questions live and offers a one-time discount code to win the sale back.', 'voicepress' ),
				'use_cases'   => array( 'cart_recovery' ),
				'icon'        => 'dashicons-cart',
			),
			'feedback'         => array(
				'label'       => __( 'Feedback & Reviews', 'voicepress' ),
				'description' => __( 'Calls after delivery to collect a 1 to 5 star rating and comments, and escalates unhappy customers to your team.', 'voicepress' ),
				'use_cases'   => array( 'feedback' ),
				'icon'        => 'dashicons-star-filled',
			),
			'support'          => array(
				'label'       => __( 'Support Desk', 'voicepress' ),
				'description' => __( '24x7 voice support on your phone line and website: order status, cancellations, returns, delivery changes, product questions and escalations, with live order lookups.', 'voicepress' ),
				'use_cases'   => array( 'support_callback', 'web_call', 'inbound' ),
				'icon'        => 'dashicons-phone',
			),
		);
	}

	/**
	 * Human labels for every use case.
	 *
	 * @return array use_case => label.
	 */
	public static function use_case_labels() {
		return array(
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
	}

	/* ---------------------------------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Tool names an agent gets.
	 *
	 * @param string $agent_key Agent key.
	 * @return string[]
	 */
	public static function tool_names( $agent_key ) {
		return ( is_string( $agent_key ) && array_key_exists( $agent_key, self::AGENT_TOOLS ) ) ? self::AGENT_TOOLS[ $agent_key ] : array();
	}

	/**
	 * Whether live tools can be attached (Bolna must reach this site through the public URL).
	 *
	 * @return bool
	 */
	public static function tools_enabled() {
		return '' !== self::public_url() && '' !== self::secret();
	}

	/**
	 * Bolna api_tools block for an agent, or null when tools are disabled (no public URL).
	 *
	 * @param string $agent_key Agent key.
	 * @return array|null
	 */
	public static function build_tools( $agent_key ) {
		$names = self::tool_names( $agent_key );
		if ( empty( $names ) || ! self::tools_enabled() ) {
			return null;
		}
		if ( ! class_exists( 'VoicePress_REST' ) || ! method_exists( 'VoicePress_REST', 'tool_url' ) ) {
			return null;
		}

		$definitions = self::tool_definitions();
		$secret      = self::secret();
		$tools       = array();
		$params      = array();

		foreach ( $names as $name ) {
			if ( ! isset( $definitions[ $name ] ) ) {
				continue;
			}
			$def = $definitions[ $name ];

			$tools[] = array(
				'key'              => 'custom_task',
				'name'             => $name,
				'description'      => $def['description'],
				'pre_call_message' => $def['pre_call_message'],
				'parameters'       => array(
					'type'       => 'object',
					'required'   => array_values( $def['required'] ),
					'properties' => $def['properties'],
				),
			);

			$map = array();
			foreach ( array_keys( $def['properties'] ) as $param ) {
				$map[ $param ] = '%(' . $param . ')s';
			}

			$params[ $name ] = array(
				'url'       => (string) VoicePress_REST::tool_url( $name ),
				'method'    => 'POST',
				'param'     => $map,
				'api_token' => 'Bearer ' . $secret,
				'headers'   => (object) array(),
			);
		}

		if ( empty( $tools ) ) {
			return null;
		}

		return array(
			'tools'        => $tools,
			'tools_params' => $params,
		);
	}

	/**
	 * Tool definitions: name => [description, pre_call_message, properties (JSON schema), required].
	 * Names, params and required lists match the spec's tool table exactly.
	 *
	 * @return array
	 */
	private static function tool_definitions() {
		$from     = self::string_param( 'Phone number that started this call. Copy it exactly from the call context in the system prompt.' );
		$to       = self::string_param( 'Phone number that received this call. Copy it exactly from the call context in the system prompt.' );
		$call_sid = self::string_param( 'Unique ID of this call. Copy it exactly from the call context in the system prompt.' );
		$order_id = self::string_param( 'The order number, digits only, for example 40213. Use the order number from the prompt or the one the customer said.' );
		$last4    = self::string_param( 'Last 4 digits of the phone number on the order. Only send it after a previous result asked you to verify the customer.' );

		return array(
			'identify_customer'   => array(
				'description'      => 'Finds the caller\'s account from the phone number on this call and returns their name and recent orders. Call it once, as soon as the caller first speaks on an inbound phone call, before answering anything. Do not call it on web calls or when the customer\'s details are already given in the prompt.',
				'pre_call_message' => 'Give me a second while I pull up your account.',
				'properties'       => array(
					'from_number' => $from,
					'to_number'   => $to,
					'call_sid'    => $call_sid,
				),
				'required'         => array( 'from_number', 'to_number' ),
			),
			'get_order_details'   => array(
				'description'      => 'Gets the live details of one order: status, items, total, payment method, delivery address, expected delivery and which changes are allowed. Call it when the customer asks about an order, and before any cancellation, return, reschedule or address change. Needs an order number from the prompt or from the customer; never guess one.',
				'pre_call_message' => 'Let me check that order for you.',
				'properties'       => array(
					'order_id'    => $order_id,
					'from_number' => $from,
					'to_number'   => $to,
					'phone_last4' => $last4,
				),
				'required'         => array( 'order_id', 'from_number', 'to_number' ),
			),
			'confirm_order'       => array(
				'description'      => 'Marks the cash on delivery order as confirmed so it can be shipped. Call it once, only after the customer has clearly said they want the order and the delivery address is correct or has been updated. Do not call it if they want to cancel or are undecided.',
				'pre_call_message' => 'Lovely, confirming your order now.',
				'properties'       => array(
					'order_id'    => $order_id,
					'from_number' => $from,
					'to_number'   => $to,
				),
				'required'         => array( 'order_id' ),
			),
			'cancel_order'        => array(
				'description'      => 'Cancels an order that has not shipped yet. Call it only after you have asked the reason and the customer has clearly confirmed they want to cancel. Store policy decides if it is allowed; if it is not, explain the reason given in the result and offer the alternative.',
				'pre_call_message' => 'Okay, cancelling that for you.',
				'properties'       => array(
					'order_id'    => $order_id,
					'reason'      => self::string_param( 'Why the customer is cancelling, one short sentence in their words.' ),
					'from_number' => $from,
					'to_number'   => $to,
					'phone_last4' => $last4,
				),
				'required'         => array( 'order_id', 'reason', 'from_number', 'to_number' ),
			),
			'reschedule_delivery' => array(
				'description'      => 'Saves the customer\'s preferred delivery date and time slot for an order. Call it when the customer will not be available and has chosen a new date. Do not use it for address changes.',
				'pre_call_message' => 'Sure, noting the new delivery date.',
				'properties'       => array(
					'order_id'       => $order_id,
					'preferred_date' => self::string_param( 'The new delivery date as YYYY-MM-DD, worked out from today\'s date, or exactly what the customer said if unsure, like tomorrow or Saturday.' ),
					'time_slot'      => self::string_param( 'Preferred time slot, if the customer gave one: morning, afternoon or evening.' ),
					'from_number'    => $from,
					'to_number'      => $to,
					'phone_last4'    => $last4,
				),
				'required'         => array( 'order_id', 'preferred_date', 'from_number', 'to_number' ),
			),
			'update_address'      => array(
				'description'      => 'Saves a corrected or new delivery address for an order so the store team can update it before dispatch. Call it only after you have the complete address and the customer confirmed it when you read it back.',
				'pre_call_message' => 'Got it, updating the address now.',
				'properties'       => array(
					'order_id'    => $order_id,
					'new_address' => self::string_param( 'The complete new address in one line: house or flat number, street, area or landmark, city and pin code.' ),
					'from_number' => $from,
					'to_number'   => $to,
					'phone_last4' => $last4,
				),
				'required'         => array( 'order_id', 'new_address', 'from_number', 'to_number' ),
			),
			'request_return'      => array(
				'description'      => 'Raises a return request for a delivered order, for example a damaged or wrong item, size issue or quality problem. Call it only after checking the order and after the customer confirms they want to return it. The return window and store policy decide if it is accepted.',
				'pre_call_message' => 'Okay, let me raise that return for you.',
				'properties'       => array(
					'order_id'        => $order_id,
					'reason'          => self::string_param( 'What is wrong, in the customer\'s words, one short sentence.' ),
					'reason_category' => array(
						'type'        => 'string',
						'enum'        => array( 'damaged', 'wrong_item', 'size_issue', 'quality', 'changed_mind', 'other' ),
						'description' => 'Best matching category for the reason.',
					),
					'from_number'     => $from,
					'to_number'       => $to,
					'phone_last4'     => $last4,
				),
				'required'         => array( 'order_id', 'reason', 'reason_category', 'from_number', 'to_number' ),
			),
			'record_feedback'     => array(
				'description'      => 'Saves the customer\'s star rating and comments for their order. Call it once, after the customer has given a rating from 1 to 5 and you have heard their comments. Do not call it if they refuse to give a rating.',
				'pre_call_message' => 'Thank you, noting that down.',
				'properties'       => array(
					'order_id' => $order_id,
					'rating'   => array(
						'type'        => 'string',
						'enum'        => array( '1', '2', '3', '4', '5' ),
						'description' => 'The customer\'s rating as a whole number from 1 to 5.',
					),
					'comment'  => self::string_param( 'Short summary of what the customer liked or disliked, one or two sentences.' ),
				),
				'required'         => array( 'order_id', 'rating' ),
			),
			'search_products'     => array(
				'description'      => 'Searches the store\'s catalogue and returns up to three matching products with price, stock and a short description. Call it when the customer asks about a product, its price, availability or details. Answer only from the result; if nothing matches, say so.',
				'pre_call_message' => 'Let me look that up for you.',
				'properties'       => array(
					'query' => self::string_param( 'Product name or keywords the customer mentioned, for example cotton kurta.' ),
				),
				'required'         => array( 'query' ),
			),
			'create_ticket'       => array(
				'description'      => 'Raises a ticket so the store team calls the customer back. Call it when you cannot solve something on this call: a complaint, a damaged or missing item, a payment problem, a policy dispute, or when the customer asks for a person. Tell the customer before you raise it.',
				'pre_call_message' => 'Let me raise this with our team.',
				'properties'       => array(
					'summary'     => self::string_param( 'One or two sentences: what the problem is and what the customer wants.' ),
					'priority'    => array(
						'type'        => 'string',
						'enum'        => array( 'low', 'normal', 'high' ),
						'description' => 'high for damaged or missing items, payment problems or a very upset customer; low for suggestions; otherwise normal.',
					),
					'order_id'    => self::string_param( 'Related order number, digits only, if there is one.' ),
					'from_number' => $from,
					'to_number'   => $to,
				),
				'required'         => array( 'summary' ),
			),
		);
	}

	/**
	 * JSON schema for a string parameter.
	 *
	 * @param string $description Description for the LLM.
	 * @return array
	 */
	private static function string_param( $description ) {
		return array(
			'type'        => 'string',
			'description' => $description,
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Payload (agent_config + agent_prompts)
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Full Bolna payload for an agent (based on the verified agent_base.json config).
	 *
	 * @param string $agent_key Agent key.
	 * @return array ['agent_config' => array, 'agent_prompts' => ['task_1' => ['system_prompt' => string]]], [] for unknown keys.
	 */
	public static function build_payload( $agent_key ) {
		if ( ! self::is_agent_key( $agent_key ) ) {
			return array();
		}

		$tools    = self::build_tools( $agent_key );
		$provider = self::telephony_provider();
		$format   = self::audio_format( $provider );

		$webhook = null;
		if ( '' !== self::public_url() && class_exists( 'VoicePress_REST' ) && method_exists( 'VoicePress_REST', 'webhook_url' ) ) {
			$webhook = (string) VoicePress_REST::webhook_url();
			$webhook = '' === $webhook ? null : $webhook;
		}

		$tools_config = array(
			'llm_agent'   => array(
				'agent_type'      => 'simple_llm_agent',
				'llm_config'      => array(
					'stop'                  => null,
					'min_p'                 => 0.1,
					'model'                 => 'gpt-4.1-mini',
					'top_k'                 => 0,
					'top_p'                 => 0.9,
					'family'                => 'openai',
					'base_url'              => null,
					'provider'              => 'openai',
					'verbosity'             => null,
					'extra_body'            => null,
					'max_tokens'            => 200,
					'temperature'           => 0.2,
					'request_json'          => false,
					'agent_flow_type'       => 'streaming',
					'presence_penalty'      => 0.0,
					'reasoning_effort'      => null,
					'compact_threshold'     => null,
					'frequency_penalty'     => 0.0,
					'use_responses_api'     => false,
					'extraction_details'    => null,
					'summarization_details' => null,
				),
				'agent_flow_type' => 'streaming',
			),
			'synthesizer' => array(
				'stream'              => true,
				'caching'             => true,
				'provider'            => 'elevenlabs',
				'buffer_size'         => 250,
				'audio_format'        => 'wav',
				'provider_config'     => array(
					'model'                             => 'eleven_turbo_v2_5',
					'speed'                             => 1.0,
					'style'                             => 0.0,
					'voice'                             => 'Angelica',
					'voice_id'                          => 'IkSv4tkouLJ6kYsQA7XD',
					'temperature'                       => 0.5,
					'similarity_boost'                  => 0.75,
					'pronunciation_dictionary_locators' => null,
				),
				'pronunciation_rules' => null,
			),
			'transcriber' => array(
				'task'                   => 'transcribe',
				'delay'                  => 'medium',
				'model'                  => 'nova-3',
				'active'                 => null,
				'stream'                 => true,
				'context'                => null,
				'encoding'               => 'linear16',
				'keywords'               => null,
				'language'               => 'en',
				'provider'               => 'deepgram',
				'endpointing'            => 250,
				'mip_opt_out'            => null,
				'multilingual'           => null,
				'deepgram_host'          => null,
				'eot_threshold'          => null,
				'sampling_rate'          => 16000,
				'vad_threshold'          => 0.5,
				'eot_timeout_ms'         => null,
				'language_hints'         => null,
				'noise_reduction'        => false,
				'assemblyai_config'      => null,
				'deepgram_flux_host'     => null,
				'eager_eot_threshold'    => null,
				'vad_prefix_padding_ms'  => 300,
				'deepgram_host_protocol' => null,
			),
			'input'       => array(
				'format'   => $format,
				'provider' => $provider,
			),
			'output'      => array(
				'format'   => $format,
				'provider' => $provider,
			),
			'api_tools'   => $tools,
		);

		$payload = array(
			'agent_config'  => array(
				'agent_name'            => self::agent_name( $agent_key ),
				'agent_welcome_message' => self::welcome_message( $agent_key ),
				'webhook_url'           => $webhook,
				'call_summary_enabled'  => true,
				'tasks'                 => array(
					array(
						'task_type'    => 'conversation',
						'toolchain'    => array(
							'execution' => 'sequential',
							'pipelines' => array( array( 'transcriber', 'llm', 'synthesizer' ) ),
						),
						'task_config'  => self::task_config(),
						'tools_config' => $tools_config,
					),
				),
			),
			'agent_prompts' => array(
				'task_1' => array(
					'system_prompt' => self::system_prompt( $agent_key, null !== $tools ),
				),
			),
		);

		/**
		 * Filters the Bolna agent payload before it is sent (create or update).
		 *
		 * @param array  $payload   ['agent_config' => …, 'agent_prompts' => …].
		 * @param string $agent_key Agent key.
		 */
		$filtered = apply_filters( 'voicepress_agent_payload', $payload, $agent_key );

		return is_array( $filtered ) ? $filtered : $payload;
	}

	/**
	 * Task config copied from the verified agent_base.json, with VoicePress call limits.
	 *
	 * @return array
	 */
	private static function task_config() {
		$config = array(
			'voicemail'                          => false,
			'use_fillers'                        => false,
			'dtmf_enabled'                       => false,
			'ambient_noise'                      => false,
			'inbound_limit'                      => -1,
			'backchanneling'                     => false,
			'call_terminate'                     => 300,
			'auto_reschedule'                    => false,
			'optimize_latency'                   => true,
			'incremental_delay'                  => 900,
			'ambient_noise_track'                => null,
			'call_hangup_message'                => null,
			'max_silence_repeats'                => null,
			'check_if_user_online'               => true,
			'hangup_after_LLMCall'               => false,
			'hangup_after_silence'               => 15,
			'welcome_message_delay'              => 0,
			'whitelist_phone_numbers'            => null,
			'call_cancellation_prompt'           => null,
			'disallow_unknown_numbers'           => false,
			'language_detection_turns'           => null,
			'noise_cancellation_level'           => null,
			'voicemail_check_interval'           => 7.0,
			'voicemail_detection_time'           => 2.5,
			'check_user_online_message'          => 'Hello, are you still there?',
			'backchanneling_message_gap'         => 5,
			'backchanneling_start_delay'         => 5,
			'interruption_backoff_period'        => 100,
			'interruptible_hangup_message'       => false,
			'voicemail_detection_duration'       => 30.0,
			'discard_pre_welcome_utterance'      => false,
			'voicemail_min_transcript_length'    => 7,
			'number_of_words_for_interruption'   => 1,
			'trigger_user_online_message_after'  => 10,
			'user_online_message_in_llm_history' => false,
		);

		if ( self::HANGUP_BY_PROMPT ) {
			$config['hangup_after_LLMCall']     = true;
			$config['call_cancellation_prompt'] = self::hangup_prompt();
		}

		return $config;
	}

	/**
	 * Prompt Bolna uses to decide when the call is over (only after the agent's goodbye).
	 *
	 * @return string
	 */
	private static function hangup_prompt() {
		return implode(
			"\n",
			array(
				'You decide whether a phone call between an AI voice assistant and a customer has finished and should be hung up now.',
				'The call has finished only when the assistant has already said goodbye to the customer, for example "Thank you, have a great day. Goodbye!", and the customer has not asked a new question or raised a new issue after that goodbye.',
				'In every other case the call has not finished. The customer saying okay, yes, no, thanks or bye in the middle of the conversation does not finish the call until the assistant has said its goodbye.',
			)
		);
	}

	/**
	 * Agent name shown in Bolna: "{store_name} · {Label}".
	 *
	 * @param string $agent_key Agent key.
	 * @return string
	 */
	private static function agent_name( $agent_key ) {
		$labels = self::labels();
		$label  = isset( $labels[ $agent_key ]['label'] ) ? $labels[ $agent_key ]['label'] : $agent_key;
		$facts  = self::store_facts();
		return $facts['store'] . ' · ' . $label;
	}

	/**
	 * First message the agent speaks. Outbound agents only use {{customer_name}} ('there' when unknown);
	 * the support agent uses no variables because inbound calls carry no user_data.
	 *
	 * @param string $agent_key Agent key.
	 * @return string
	 */
	public static function welcome_message( $agent_key ) {
		$messages = array(
			'cod_confirmation' => 'Hi {{customer_name}}, this is [[agent]] from [[store]]. I\'m calling to confirm the cash on delivery order you placed with us. Do you have a minute?',
			'delivery'         => 'Hi {{customer_name}}, this is [[agent]] from [[store]], calling about the delivery of your order. Do you have a minute?',
			'cart_recovery'    => 'Hi {{customer_name}}, this is [[agent]] from [[store]]. I saw you were shopping on our website and wanted to help you complete your order. Do you have a minute?',
			'feedback'         => 'Hi {{customer_name}}, this is [[agent]] from [[store]]. I\'m calling to hear how you liked your recent order. Do you have a minute?',
			'support'          => 'Hi, this is [[agent]] from [[store]] customer care. How can I help you today?',
		);
		return isset( $messages[ $agent_key ] ) ? self::fill( $messages[ $agent_key ] ) : '';
	}

	/* ---------------------------------------------------------------------------------------------
	 * System prompts
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * System prompt for an agent with store facts baked in.
	 *
	 * @param string    $agent_key     Agent key.
	 * @param bool|null $tools_enabled Whether live tools are attached (null = detect).
	 * @return string
	 */
	public static function system_prompt( $agent_key, $tools_enabled = null ) {
		if ( ! self::is_agent_key( $agent_key ) ) {
			return '';
		}
		$tools = null === $tools_enabled ? ( null !== self::build_tools( $agent_key ) ) : (bool) $tools_enabled;

		switch ( $agent_key ) {
			case 'cod_confirmation':
				$parts = self::prompt_cod( $tools );
				break;
			case 'delivery':
				$parts = self::prompt_delivery( $tools );
				break;
			case 'cart_recovery':
				$parts = self::prompt_cart( $tools );
				break;
			case 'feedback':
				$parts = self::prompt_feedback( $tools );
				break;
			default:
				$parts = self::prompt_support( $tools );
				break;
		}

		$lines = array_merge(
			$parts['intro'],
			$parts['facts'],
			self::section_store(),
			$parts['flow'],
			$parts['objections'],
			$tools ? self::section_actions( $agent_key ) : $parts['no_tools'],
			self::section_speak(),
			self::section_rules(),
			self::section_closing()
		);

		return trim( self::fill( implode( "\n", $lines ) ) );
	}

	/**
	 * COD confirmation prompt parts.
	 *
	 * @param bool $tools Tools attached.
	 * @return array
	 */
	private static function prompt_cod( $tools ) {
		return array(
			'intro'      => array(
				'You are [[agent]], a friendly order confirmation agent for [[store]], calling to confirm a cash on delivery order before it ships. Your goal is a clear decision: confirm, cancel, new date, new address or pay online.',
			),
			'facts'      => array(
				'',
				'## Why you are calling',
				'- Customer first name: {{customer_name}}',
				'- Order number: {{order_id}}',
				'- Items: {{items}}',
				'- Amount to pay: {{order_total}} by {{payment_method}}',
				'- Delivery address: {{delivery_address}}',
			),
			'flow'       => array(
				'',
				'## Conversation flow',
				'1. Your greeting asked for a minute. If they cannot talk, say it takes thirty seconds; if still not, say the team will call later and say goodbye.',
				'2. Briefly read the items and the amount to pay, and ask if they want to go ahead.',
				'3. If yes, read the delivery address and ask if it is correct.',
				$tools
					? '4. Once both are confirmed, call confirm_order, then say it should reach them in {{expected_delivery}} and, if paying cash, to keep the amount ready.'
					: '4. Once both are confirmed, say the order is confirmed and should reach them in {{expected_delivery}} and, if paying cash, to keep the amount ready.',
				'5. If they want a change:',
				$tools
					? '- Cancel: ask the reason once and offer a fix if one exists, like a new date or address. If they still want to cancel, call cancel_order.'
					: '- Cancel: ask the reason once and offer a fix if one exists, like a new date or address. If they still want to cancel, accept it politely.',
				$tools
					? '- Later delivery: ask for the date and a morning, afternoon or evening slot, call reschedule_delivery, then confirm_order.'
					: '- Later delivery: ask for the date and a morning, afternoon or evening slot.',
				$tools
					? '- New address: ask for the full address with landmark and pin code, read it back, call update_address, then confirm_order.'
					: '- New address: ask for the full address with landmark and pin code, and read it back.',
				$tools
					? '- Pay online: say the store team will share online payment details, then call confirm_order.'
					: '- Pay online: say the store team will share online payment details.',
			),
			'objections' => array(
				'',
				'## Objections',
				'- Did not order it: ask once if someone at home may have ordered. If not, treat it as a cancellation.',
				'- Thinks it is a scam: you are from [[store]], calling about their order, and never ask for payment details.',
				'- Undecided: answer from the facts here; if still unsure, say the team will check again later and say goodbye.',
			),
			'no_tools'   => self::section_no_tools( 'You cannot change the order during this call. Make the decision clear, repeat it back in one sentence, and say the store team will update the order shortly.' ),
		);
	}

	/**
	 * Delivery (out for delivery + failed delivery / NDR) prompt parts.
	 *
	 * @param bool $tools Tools attached.
	 * @return array
	 */
	private static function prompt_delivery( $tools ) {
		return array(
			'intro'      => array(
				'You are [[agent]], a delivery assistant for [[store]], calling about the delivery of an order. Your goal is a successful delivery: confirm someone can receive it, or fix the date or address.',
			),
			'facts'      => array(
				'',
				'## Why you are calling',
				'- Customer first name: {{customer_name}}',
				'- Situation: {{call_reason}}, meaning {{call_reason_text}}',
				'- Order number: {{order_id}}',
				'- Items: {{items}}',
				'- Order total: {{order_total}} by {{payment_method}}',
				'- Cash to collect on delivery: {{is_cod}}',
				'- Delivery address: {{delivery_address}}',
			),
			'flow'       => array(
				'',
				'## Conversation flow',
				'1. Your greeting asked if they have a minute. If not, apologise for the timing and say goodbye.',
				'2. If out_for_delivery: say it is out for delivery today to their address and ask if someone will be there to receive it. If cash to collect is yes, ask them to keep the order total ready.',
				'3. If delivery_failed: say our delivery partner could not deliver it, then ask what happened and when they will be available.',
				'4. Then:',
				'- Available: thank them; the delivery partner will reach them as planned or on the next attempt.',
				$tools
					? '- Another day: ask for the date and a morning, afternoon or evening slot, then call reschedule_delivery.'
					: '- Another day: ask for the date and a morning, afternoon or evening slot.',
				$tools
					? '- Wrong or incomplete address: ask for the full address with landmark and pin code, read it back, then call update_address.'
					: '- Wrong or incomplete address: ask for the full address with landmark and pin code, and read it back.',
				$tools
					? '- Wants to cancel: ask the reason once and offer a new date or address. If they still want to cancel, call cancel_order.'
					: '- Wants to cancel: ask the reason once and offer a new date or address. If they still want to cancel, accept it politely.',
				$tools
					? '- Other order questions: call get_order_details and answer only from it.'
					: '- Other order questions: answer only from the facts here.',
			),
			'objections' => array(
				'',
				'## Objections',
				'- Courier did not call or was rude: apologise and say you have noted it for the store team.',
				'- Wants an exact time: you cannot promise a time, only the slot they choose.',
				'- Already paid: never ask for cash when cash to collect is no.',
			),
			'no_tools'   => self::section_no_tools( 'You cannot change the order during this call. Make the decision clear, repeat it back in one sentence, and say the store team will update the delivery shortly.' ),
		);
	}

	/**
	 * Abandoned cart recovery prompt parts.
	 *
	 * @param bool $tools Tools attached.
	 * @return array
	 */
	private static function prompt_cart( $tools ) {
		return array(
			'intro'      => array(
				'You are [[agent]], a friendly shopping assistant for [[store]]. You are calling a shopper who left items in their cart without ordering. Your goal is to help them complete the purchase by answering questions and, if it helps, offering one discount. Be helpful, never pushy.',
			),
			'facts'      => array(
				'',
				'## Why you are calling',
				'- Customer first name: {{customer_name}}',
				'- Reason: {{call_reason_text}}',
				'- Items in cart: {{cart_items}}',
				'- Cart total: {{cart_total}}',
				'- Discount: {{discount_percent}} percent off with code {{discount_code}}, spoken as {{discount_code_spoken}}',
			),
			'flow'       => array(
				'',
				'## Conversation flow',
				'1. Your greeting asked if they have a minute. If not, apologise for the timing and say goodbye.',
				'2. Mention the items they left and ask if anything stopped them from ordering.',
				'3. Help based on their answer:',
				$tools
					? '- Product questions: call search_products and answer only from the result.'
					: '- Product questions: answer only from the cart details and store facts; otherwise say the store team will follow up.',
				'- Price or delivery charges: offer the discount.',
				'- Payment or checkout trouble: suggest trying again and mention the payment options in the store policies.',
				'4. To give the discount, say the code slowly, character by character, exactly as spoken above, and offer to repeat it. It works once at checkout on our website and is valid for three days.',
				'5. If they will order, thank them and say they can finish checkout on our website.',
			),
			'objections' => array(
				'',
				'## Objections',
				'- How did you get my number: they entered it at checkout on our website.',
				'- Found it cheaper: you cannot match prices, but the discount may help.',
				'- Will buy later: that is fine; remind them the code is valid for three days.',
				'- Not interested: thank them and say goodbye. Offer the discount at most once, and never if no code is given above.',
			),
			'no_tools'   => array(),
		);
	}

	/**
	 * Post-delivery feedback prompt parts.
	 *
	 * @param bool $tools Tools attached.
	 * @return array
	 */
	private static function prompt_feedback( $tools ) {
		return array(
			'intro'      => array(
				'You are [[agent]], a customer experience agent for [[store]]. You are calling a customer whose order was delivered. Your goal is an honest rating from 1 to 5 stars and a short comment, and to make unhappy customers feel heard.',
			),
			'facts'      => array(
				'',
				'## Why you are calling',
				'- Customer first name: {{customer_name}}',
				'- Reason: {{call_reason_text}}',
				'- Order number: {{order_id}}',
				'- Items: {{items}}',
				'- Delivered on: {{delivered_on}}',
			),
			'flow'       => array(
				'',
				'## Conversation flow',
				'1. Your greeting asked if they have a minute. If not, thank them and say goodbye.',
				'2. Ask how they liked the items.',
				'3. Ask them to rate their experience from 1 to 5 stars. If they give a half, use the lower number.',
				'4. Respond to the rating:',
				'- 4 or 5: thank them warmly, ask what they liked most, and invite them to leave a review on our website.',
				'- 3: ask what could have been better.',
				$tools
					? '- 1 or 2: apologise sincerely and ask what went wrong. Offer a call back from the store team; if they agree, call create_ticket with priority high.'
					: '- 1 or 2: apologise sincerely, ask what went wrong, and say the store team will review it and call them back.',
				$tools
					? '5. Call record_feedback once with the rating and a short summary of their comments.'
					: '5. Repeat the rating back once so it is clear.',
				'6. Thank them for the feedback.',
			),
			'objections' => array(
				'',
				'## Objections',
				$tools
					? '- Not received, damaged or wrong item: apologise, skip the rating, and offer a call back from the store team with create_ticket at priority high. Returns are accepted within [[days]] days of delivery.'
					: '- Not received, damaged or wrong item: apologise, skip the rating, and say the store team will call them back. Returns are accepted within [[days]] days of delivery.',
				'- Wants a refund or compensation: promise nothing; the store team will review it.',
				'- Does not want to rate: that is fine; thank them and say goodbye.',
			),
			'no_tools'   => self::section_no_tools( 'You cannot save anything during this call. Make sure the rating and comments are clear; the store team will review them after the call.' ),
		);
	}

	/**
	 * Support desk prompt parts (inbound phone, website voice widget and requested callbacks).
	 *
	 * @param bool $tools Tools attached.
	 * @return array
	 */
	private static function prompt_support( $tools ) {
		$start = $tools
			? array(
				'',
				'## Start of the call',
				'- Channel unknown: this is an inbound phone call. When the caller first speaks, call identify_customer before answering. If found, greet them by first name and mention their latest order instead of asking for the number. If not found, ask for the order number.',
				'- Channel web: they are on our website. Use the details above, never identify_customer. For other orders, ask for the order number.',
				'- Channel phone: this is the call back they requested. Greet them by name and ask how you can help.',
			)
			: array(
				'',
				'## Start of the call',
				'- If the details above are known, greet them by first name and ask how you can help. Otherwise ask for their name and order number.',
				'- Channel phone means this is the call back they requested.',
			);

		$requests = $tools
			? array(
				'',
				'## Handling requests',
				'- For any order question or change, first call get_order_details and use only what it returns.',
				'- Where is my order: share the status and expected delivery.',
				'- Cancel: ask the reason, confirm, then call cancel_order.',
				'- Return or refund: ask what went wrong, confirm, then call request_return with the best category.',
				'- New date or address: read it back, then call reschedule_delivery or update_address.',
				'- Product questions: call search_products and answer only from the result.',
				'- Complaint, payment problem, policy dispute, or they want a person: call create_ticket.',
				'- If something is not allowed, explain why kindly and offer the alternative from the result.',
				'- Angry customer: apologise once, stay calm and focus on the fix.',
			)
			: array(
				'',
				'## Handling requests',
				'- Answer only from the details above and the store facts.',
				'- For any order change, return or complaint, note the order number and exactly what they need, read it back, and say the store team will follow up during support hours.',
				'- Angry customer: apologise once, stay calm and focus on the fix.',
			);

		return array(
			'intro'      => array(
				'You are [[agent]], a warm, capable customer support agent for [[store]]. You help customers by phone and on our website with orders, cancellations, returns, delivery changes, product questions and complaints.',
			),
			'facts'      => array(
				'',
				'## About this call',
				'- Channel: {{channel}}',
				'- Customer first name: {{customer_name}}',
				'- What we know about them:',
				'{{customer_context}}',
				'Values that are empty, None or still in double curly brackets are unknown.',
			),
			'flow'       => $start,
			'objections' => $requests,
			'no_tools'   => self::section_no_tools( 'You cannot look up or change orders during this call; the store team handles requests after the call.' ),
		);
	}

	/**
	 * "Store facts" section, baked in at provisioning time.
	 *
	 * @return string[]
	 */
	private static function section_store() {
		$facts = self::store_facts();
		$lines = array(
			'',
			'## Store facts',
			'- Delivery time: [[eta]]',
			'0' === $facts['days'] ? '- Returns: as per the store policies' : '- Returns: within [[days]] days of delivery',
		);
		if ( '' !== $facts['hours'] ) {
			$lines[] = '- Support hours: [[hours]]';
		}
		if ( '' !== $facts['policies'] ) {
			$lines[] = '- Policies: [[policies]]';
		}
		return $lines;
	}

	/**
	 * "Actions" + "Call context" sections for agents with live tools.
	 *
	 * @param string $agent_key Agent key.
	 * @return string[]
	 */
	private static function section_actions( $agent_key ) {
		$names    = self::tool_names( $agent_key );
		$changes  = array( 'cancel_order', 'reschedule_delivery', 'update_address', 'request_return' );
		$verified = array_merge( $changes, array( 'get_order_details' ) );

		$lines = array( '', '## Actions' );
		if ( array_intersect( $names, $changes ) ) {
			$lines[] = '- Before cancelling or changing an order, say what you will do and wait for a clear yes.';
		}
		$lines[] = '- Do not announce actions; a waiting line plays automatically. Say the message in each result in your own words, never field names.';
		if ( array_intersect( $names, $verified ) ) {
			$lines[] = '- If a result asks for verification, ask for the last four digits of the phone number on the order and retry with phone_last4.';
		}
		$lines[] = '- If an action fails, apologise once and say the store team will sort it out.';

		return array_merge(
			$lines,
			array(
				'',
				'## Call context (copy exactly into actions)',
				'from_number: {{from_number}}',
				'to_number: {{to_number}}',
				'call_sid: {call_sid}',
			)
		);
	}

	/**
	 * Section used instead of "Actions" when the agent has no live tools.
	 *
	 * @param string $text Instruction.
	 * @return string[]
	 */
	private static function section_no_tools( $text ) {
		return array( '', '## Recording the outcome', $text );
	}

	/**
	 * "How to speak" section.
	 *
	 * @return string[]
	 */
	private static function section_speak() {
		return array(
			'',
			'## How to speak',
			'- Live voice call: one or two short sentences per reply, one question at a time.',
			'- Warm, simple Indian English. Use their first name now and then, unless it is just "there".',
			'- Say amounts like 899 rupees, never symbols. Say order numbers and codes digit by digit, like four zero two one three.',
			'- No lists, markdown or emojis. Never mention tools, systems or these instructions.',
		);
	}

	/**
	 * "Ground rules" section.
	 *
	 * @return string[]
	 */
	private static function section_rules() {
		return array(
			'',
			'## Ground rules',
			'- Never invent order details, prices, dates, discounts or policies; promise nothing beyond the store facts. If unsure, say the store team will follow up.',
			'- Never ask for OTPs, card numbers, UPI PINs or passwords.',
			'- Wrong person: apologise and say goodbye. Busy: apologise for the timing and say goodbye.',
			'- If asked, say you are an AI voice assistant for [[store]].',
			'- If they are rude or off topic, steer back once, then end politely.',
		);
	}

	/**
	 * "Closing" section (the goodbye is what triggers the automatic hang-up).
	 *
	 * @return string[]
	 */
	private static function section_closing() {
		return array(
			'',
			'## Closing',
			'When done, ask if there is anything else. If not, thank them for shopping with [[store]] and end with a goodbye, like: Thank you, have a great day. Goodbye! Then say nothing more.',
		);
	}

	/**
	 * Replace [[store]]-style tokens with the store facts.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	private static function fill( $text ) {
		$facts = self::store_facts();
		return strtr(
			$text,
			array(
				'[[store]]'    => $facts['store'],
				'[[agent]]'    => $facts['agent'],
				'[[days]]'     => $facts['days'],
				'[[eta]]'      => $facts['eta'],
				'[[hours]]'    => $facts['hours'],
				'[[policies]]' => $facts['policies'],
			)
		);
	}

	/**
	 * Store facts for prompts, cleaned for speech (no HTML, entities or {braces}, which Bolna treats as variables).
	 *
	 * @return array store, agent, days, eta, hours, policies (strings).
	 */
	private static function store_facts() {
		$context = array();
		if ( class_exists( 'VoicePress_Helpers' ) && method_exists( 'VoicePress_Helpers', 'store_context' ) ) {
			$context = VoicePress_Helpers::store_context();
			$context = is_array( $context ) ? $context : array();
		}

		$value = function ( $context_key, $setting_key, $fallback ) use ( $context ) {
			if ( isset( $context[ $context_key ] ) && '' !== trim( (string) $context[ $context_key ] ) ) {
				return $context[ $context_key ];
			}
			$setting = self::setting( $setting_key, $fallback );
			return ( is_scalar( $setting ) && '' !== trim( (string) $setting ) ) ? $setting : $fallback;
		};

		$store = self::clean_text( $value( 'store_name', 'store_name', get_bloginfo( 'name' ) ), 80 );
		$agent = self::clean_text( $value( 'agent_name', 'agent_persona', 'Riya' ), 40 );
		$days  = (int) $value( 'return_window_days', 'return_window_days', 7 );
		$eta   = self::clean_text( $value( 'delivery_eta', 'delivery_eta', '3 to 5 working days' ), 80 );

		return array(
			'store'    => '' !== $store ? $store : 'our store',
			'agent'    => '' !== $agent ? $agent : 'Riya',
			'days'     => (string) max( 0, $days ),
			'eta'      => '' !== $eta ? $eta : '3 to 5 working days',
			'hours'    => self::clean_text( $value( 'support_hours', 'support_hours', '' ), 80 ),
			'policies' => self::clean_text( $value( 'store_policies', 'store_policies', '' ), 900 ),
		);
	}

	/**
	 * Plain single-line text for prompts.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Max characters (cut at a word boundary).
	 * @return string
	 */
	private static function clean_text( $value, $max ) {
		$text = is_scalar( $value ) ? (string) $value : '';
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = str_replace( array( '{', '}', '[[', ']]' ), array( '(', ')', '[', ']' ), $text );
		$flat = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( null === $flat ? (string) preg_replace( '/\s+/', ' ', $text ) : $flat );

		if ( $max > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
			$cut   = mb_substr( $text, 0, $max, 'UTF-8' );
			$space = strrpos( $cut, ' ' );
			$text  = rtrim( false !== $space && $space > 0 ? substr( $cut, 0, $space ) : $cut, ' ,;:-' );
		}

		return $text;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Extractions (dispositions)
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Dispositions created for an agent (names exactly as the call outcome logic expects).
	 *
	 * @param string $agent_key Agent key.
	 * @return array[] create_disposition() payloads without agent_id / category.
	 */
	public static function dispositions( $agent_key ) {
		$note = function ( $question ) {
			return array(
				'name'            => 'Customer Note',
				'question'        => $question,
				'is_subjective'   => true,
				'is_objective'    => false,
				'subjective_type' => 'text',
			);
		};
		$objective = function ( $name, $question, array $options ) {
			$list = array();
			foreach ( $options as $value => $condition ) {
				$list[] = array(
					'value'     => $value,
					'condition' => $condition,
				);
			}
			return array(
				'name'              => $name,
				'question'          => $question,
				'is_subjective'     => false,
				'is_objective'      => true,
				'objective_options' => $list,
			);
		};

		switch ( $agent_key ) {
			case 'cod_confirmation':
				return array(
					$objective(
						'Order Decision',
						'What did the customer decide about their cash on delivery order on this call? If more than one applies, prefer cancelled, then address_change, then reschedule, then wants_prepaid.',
						array(
							'confirmed'      => 'The customer confirmed they want the order delivered as planned, with no change to the date, address or payment method.',
							'cancelled'      => 'The customer asked to cancel the order, said they did not place it, or said they no longer want it.',
							'reschedule'     => 'The customer wants the order but asked for delivery on a later date or in a different time slot.',
							'address_change' => 'The customer wants the order but gave a new or corrected delivery address.',
							'wants_prepaid'  => 'The customer wants the order but asked to pay online or in advance instead of paying cash on delivery.',
							'no_decision'    => 'There was no clear decision: the customer was busy, undecided, asked to be called later, was the wrong person, or the call ended early.',
						)
					),
					$note( 'In one or two short sentences, what should the store team know or do after this call? Include the cancellation reason, the exact new delivery date and time slot, or the full new address exactly as the customer gave it, if any.' ),
				);

			case 'delivery':
				return array(
					$objective(
						'Delivery Decision',
						'What did the customer decide about the delivery of their order on this call? If more than one applies, prefer cancel, then address_change, then reschedule.',
						array(
							'available'      => 'The customer confirmed they or someone else will be available to receive the delivery as planned or on the next attempt.',
							'reschedule'     => 'The customer asked for the delivery on another date or in a different time slot.',
							'address_change' => 'The customer gave a new or corrected delivery address or landmark.',
							'cancel'         => 'The customer said they no longer want the order or asked to cancel or return it.',
							'no_decision'    => 'There was no clear decision: the customer was busy, asked to be called later, was the wrong person, or the call ended early.',
						)
					),
					$note( 'In one or two short sentences, what should the store team or delivery partner know? Include the new delivery date and time slot, the full new address or landmark, the cancellation reason, or any complaint about the courier, if any.' ),
				);

			case 'cart_recovery':
				return array(
					$objective(
						'Cart Outcome',
						'What was the outcome of this call about the items the customer left in their cart?',
						array(
							'will_purchase'  => 'The customer said they will complete the purchase or place the order.',
							'needs_help'     => 'The customer wants to buy but needs more help, for example product, size, payment or delivery questions that were not fully resolved.',
							'price_concern'  => 'The customer hesitated mainly because of the price or delivery charges, or found it cheaper elsewhere, and did not commit to buying.',
							'not_interested' => 'The customer said they are not interested in buying these items.',
							'call_later'     => 'The customer was busy or asked to be called back later.',
							'no_decision'    => 'The call ended without a clear outcome, or it was the wrong person.',
						)
					),
					$note( 'In one or two short sentences, what stopped the customer from ordering, and what did they say they will do next?' ),
				);

			case 'feedback':
				return array(
					array(
						'name'            => 'Rating',
						'question'        => 'What star rating from 1 to 5 did the customer give for their order or experience? Answer with one whole number from 1 to 5, or 0 if they did not give a rating.',
						'is_subjective'   => true,
						'is_objective'    => false,
						'subjective_type' => 'numeric',
					),
					$objective(
						'Sentiment',
						'What was the customer\'s overall feeling about their order and experience?',
						array(
							'positive' => 'The customer was happy or satisfied, for example a rating of 4 or 5 or clear praise.',
							'neutral'  => 'The customer was okay but not enthusiastic, gave a rating of 3, or gave mixed or very little feedback.',
							'negative' => 'The customer was unhappy, gave a rating of 1 or 2, complained, or reported a problem such as a damaged, wrong or missing item.',
						)
					),
					$note( 'In one or two short sentences, what did the customer like or dislike, and is there any problem the store team must fix?' ),
				);

			case 'support':
				return array(
					$objective(
						'Call Reason',
						'What was the main reason the customer contacted support on this call?',
						array(
							'order_status'     => 'The customer asked where their order is, its status, delivery date or payment status.',
							'return_refund'    => 'The customer wanted to return or exchange an item or asked for a refund.',
							'cancellation'     => 'The customer wanted to cancel an order.',
							'delivery_change'  => 'The customer wanted to change the delivery date, time slot or address.',
							'product_question' => 'The customer asked about a product, its price, size, stock or details.',
							'complaint'        => 'The customer complained about a product, delivery, payment or service, or asked to speak to a person.',
							'other'            => 'Any other reason, or the reason was not clear.',
						)
					),
					$objective(
						'Resolved',
						'Was the customer\'s request fully handled during this call?',
						array(
							'yes' => 'The question was answered or the requested action was completed, and nothing is left pending for the store team.',
							'no'  => 'The request could not be completed on the call, a ticket or call back was needed, or the customer was left unhappy.',
						)
					),
					$note( 'In one or two short sentences, what did the customer need, and what was done or is still pending?' ),
				);
		}

		return array();
	}

	/* ---------------------------------------------------------------------------------------------
	 * Provisioning
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Create the agent in Bolna (first time, with extractions) or update it in place.
	 *
	 * @param string $agent_key Agent key.
	 * @return array|WP_Error ['agent_id', 'action' => 'created'|'updated', 'extractions' => 'ok'|error string, 'tools_enabled' => bool]
	 */
	public static function provision( $agent_key ) {
		if ( ! self::is_agent_key( $agent_key ) ) {
			return new WP_Error( 'voicepress_agent', __( 'Unknown agent.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$client = self::client();
		if ( null === $client ) {
			return new WP_Error( 'voicepress_not_configured', __( 'Add your Bolna API key in VoicePress → Settings first.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$lock = 'voicepress_provisioning_' . $agent_key;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'voicepress_busy', __( 'This agent is already being set up. Please wait a few seconds and try again.', 'voicepress' ), array( 'status' => 409 ) );
		}
		set_transient( $lock, 1, 90 );

		try {
			$result = self::provision_with( $client, $agent_key );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'voicepress_agent', $e->getMessage(), array( 'status' => 500 ) );
		}

		delete_transient( $lock );

		return $result;
	}

	/**
	 * Create or update every agent.
	 *
	 * @return array agent_key => provision() result, or an error message string.
	 */
	public static function provision_all() {
		self::extend_time_limit();
		$results = array();
		foreach ( self::AGENT_KEYS as $agent_key ) {
			$result                = self::provision( $agent_key );
			$results[ $agent_key ] = is_wp_error( $result ) ? $result->get_error_message() : $result;
		}
		return $results;
	}

	/**
	 * Push the current prompts, tools, webhook and store facts to every agent that already exists.
	 *
	 * @return array agent_key => provision() result, or an error message string (agents not created yet are left out).
	 */
	public static function sync_all() {
		self::extend_time_limit();
		$results = array();
		foreach ( self::AGENT_KEYS as $agent_key ) {
			if ( '' === self::saved_agent_id( $agent_key ) ) {
				continue;
			}
			$result                = self::provision( $agent_key );
			$results[ $agent_key ] = is_wp_error( $result ) ? $result->get_error_message() : $result;
		}
		return $results;
	}

	/**
	 * Route inbound calls on a Bolna phone number to the Support agent.
	 *
	 * @param string $phone_number_id Bolna phone number id.
	 * @return array|WP_Error
	 */
	public static function link_inbound( $phone_number_id ) {
		$phone_number_id = is_scalar( $phone_number_id ) ? trim( sanitize_text_field( (string) $phone_number_id ) ) : '';
		if ( '' === $phone_number_id ) {
			return new WP_Error( 'voicepress_inbound', __( 'Choose a phone number to link.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$client = self::client();
		if ( null === $client ) {
			return new WP_Error( 'voicepress_not_configured', __( 'Add your Bolna API key in VoicePress → Settings first.', 'voicepress' ), array( 'status' => 400 ) );
		}

		$agent_id = self::saved_agent_id( 'support' );
		if ( '' === $agent_id ) {
			return new WP_Error( 'voicepress_inbound', __( 'Create the Support Desk agent first (VoicePress → Agents).', 'voicepress' ), array( 'status' => 400 ) );
		}

		try {
			$response = $client->setup_inbound( $agent_id, $phone_number_id );
		} catch ( \Throwable $e ) {
			$response = new WP_Error( 'voicepress_inbound', $e->getMessage(), array( 'status' => 500 ) );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( class_exists( 'VoicePress_Settings' ) ) {
			VoicePress_Settings::update( array( 'phone_number_id' => $phone_number_id ) );
		}

		return array(
			'agent_id'        => $agent_id,
			'phone_number_id' => $phone_number_id,
			'message'         => __( 'Inbound calls to this number are now answered by the Support Desk agent.', 'voicepress' ),
			'response'        => $response,
		);
	}

	/**
	 * Create or update one agent with a ready client.
	 *
	 * @param VoicePress_Bolna_Client $client    Client.
	 * @param string                  $agent_key Agent key.
	 * @return array|WP_Error
	 */
	private static function provision_with( $client, $agent_key ) {
		$payload = self::build_payload( $agent_key );
		if ( empty( $payload['agent_config'] ) || ! isset( $payload['agent_prompts'] ) ) {
			return new WP_Error( 'voicepress_agent', __( 'Could not build the agent configuration.', 'voicepress' ), array( 'status' => 500 ) );
		}

		$labels        = self::labels();
		$label         = $labels[ $agent_key ]['label'];
		$tools_enabled = ! empty( $payload['agent_config']['tasks'][0]['tools_config']['api_tools'] );
		$agent_id      = self::saved_agent_id( $agent_key );

		if ( '' !== $agent_id ) {
			$response = $client->update_agent( $agent_id, $payload['agent_config'], $payload['agent_prompts'] );
			if ( ! is_wp_error( $response ) ) {
				return array(
					'agent_id'      => $agent_id,
					'action'        => 'updated',
					'extractions'   => self::ensure_extractions( $client, $agent_id, $agent_key, false ),
					'tools_enabled' => $tools_enabled,
				);
			}
			if ( ! self::is_missing_agent_error( $response ) ) {
				/* translators: 1: agent label, 2: Bolna error message. */
				return self::wrap_error( $response, __( 'Could not update the %1$s agent: %2$s', 'voicepress' ), $label );
			}
			// The agent was deleted in Bolna: create a fresh one below.
		}

		$response = $client->create_agent( $payload['agent_config'], $payload['agent_prompts'] );
		if ( is_wp_error( $response ) ) {
			/* translators: 1: agent label, 2: Bolna error message. */
			return self::wrap_error( $response, __( 'Could not create the %1$s agent: %2$s', 'voicepress' ), $label );
		}

		$new_id = self::pick_id( $response, array( 'agent_id', 'id' ) );
		if ( '' === $new_id ) {
			/* translators: %s: agent label. */
			return new WP_Error( 'voicepress_agent', sprintf( __( 'Bolna did not return an id for the %s agent.', 'voicepress' ), $label ), array( 'status' => 502 ) );
		}

		if ( class_exists( 'VoicePress_Settings' ) ) {
			VoicePress_Settings::set_agent_id( $agent_key, $new_id );
		}

		return array(
			'agent_id'      => $new_id,
			'action'        => 'created',
			'extractions'   => self::ensure_extractions( $client, $new_id, $agent_key, true ),
			'tools_enabled' => $tools_enabled,
		);
	}

	/**
	 * Make sure the agent has the "VoicePress" extraction category with all its dispositions.
	 * New agents get everything; existing agents only get what is provably missing (no duplicates).
	 *
	 * @param VoicePress_Bolna_Client $client    Client.
	 * @param string                  $agent_id  Bolna agent id.
	 * @param string                  $agent_key Agent key.
	 * @param bool                    $is_new    Agent was just created.
	 * @return string 'ok' or an error message.
	 */
	private static function ensure_extractions( $client, $agent_id, $agent_key, $is_new ) {
		$definitions = self::dispositions( $agent_key );
		if ( empty( $definitions ) ) {
			return 'ok';
		}

		try {
			$category_id = '';
			$existing    = array();

			if ( ! $is_new ) {
				$categories = self::as_list( $client->list_extraction_categories( $agent_id ) );
				if ( null === $categories ) {
					return 'ok'; // Unreadable listing: extractions were added when the agent was created.
				}
				foreach ( $categories as $category ) {
					if ( ! is_array( $category ) || ! isset( $category['name'] ) || self::EXTRACTION_CATEGORY !== $category['name'] ) {
						continue;
					}
					$category_id = self::pick_id( $category, array( 'id', 'category_id' ) );
					$items       = isset( $category['dispositions'] ) && is_array( $category['dispositions'] ) ? $category['dispositions'] : array();
					foreach ( $items as $item ) {
						if ( ! is_array( $item ) || ! isset( $item['name'] ) ) {
							return 'ok'; // Names not readable: assume the set is complete.
						}
						$existing[] = (string) $item['name'];
					}
					if ( empty( $items ) && '' !== $category_id ) {
						return 'ok';
					}
					break;
				}
			}

			if ( '' === $category_id && ( $is_new || empty( $existing ) ) ) {
				$created = $client->create_extraction_category( $agent_id, self::EXTRACTION_CATEGORY, self::EXTRACTION_MODEL );
				if ( is_wp_error( $created ) ) {
					if ( ! $is_new ) {
						return $created->get_error_message();
					}
				} else {
					$category_id = self::pick_id( $created, array( 'id', 'category_id' ) );
				}
			}

			$errors = array();
			foreach ( $definitions as $definition ) {
				if ( in_array( $definition['name'], $existing, true ) ) {
					continue;
				}
				$data             = $definition;
				$data['agent_id'] = $agent_id;
				if ( '' !== $category_id ) {
					$data['category_id'] = $category_id;
				} else {
					$data['category'] = self::EXTRACTION_CATEGORY; // Bolna creates/matches the category by name.
				}
				$response = $client->create_disposition( $data );
				if ( is_wp_error( $response ) ) {
					$errors[] = $definition['name'] . ': ' . $response->get_error_message();
				}
			}

			return empty( $errors ) ? 'ok' : implode( '; ', $errors );
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Saved Bolna agent id for an agent key.
	 *
	 * @param string $agent_key Agent key.
	 * @return string
	 */
	private static function saved_agent_id( $agent_key ) {
		if ( ! class_exists( 'VoicePress_Settings' ) ) {
			return '';
		}
		$id = VoicePress_Settings::agent_id( $agent_key );
		return is_scalar( $id ) ? trim( (string) $id ) : '';
	}

	/**
	 * Bolna client or null when not configured.
	 *
	 * @return VoicePress_Bolna_Client|null
	 */
	private static function client() {
		if ( ! class_exists( 'VoicePress_Settings' ) || ! method_exists( 'VoicePress_Settings', 'client' ) ) {
			return null;
		}
		$client = VoicePress_Settings::client();
		return is_object( $client ) ? $client : null;
	}

	/**
	 * Setting value with fallback.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	private static function setting( $key, $fallback = null ) {
		if ( ! class_exists( 'VoicePress_Settings' ) ) {
			return $fallback;
		}
		$value = VoicePress_Settings::get( $key, $fallback );
		return null === $value ? $fallback : $value;
	}

	/**
	 * Public (tunnel) URL, '' when unset.
	 *
	 * @return string
	 */
	private static function public_url() {
		if ( ! class_exists( 'VoicePress_Settings' ) || ! method_exists( 'VoicePress_Settings', 'public_url' ) ) {
			return '';
		}
		return trim( (string) VoicePress_Settings::public_url() );
	}

	/**
	 * Webhook / tool secret.
	 *
	 * @return string
	 */
	private static function secret() {
		if ( ! class_exists( 'VoicePress_Settings' ) || ! method_exists( 'VoicePress_Settings', 'secret' ) ) {
			return '';
		}
		return (string) VoicePress_Settings::secret();
	}

	/**
	 * Telephony provider for the agent's input/output.
	 *
	 * @return string
	 */
	private static function telephony_provider() {
		$provider = strtolower( trim( (string) self::setting( 'telephony_provider', 'twilio' ) ) );
		return in_array( $provider, array( 'twilio', 'plivo', 'exotel', 'vobiz', 'trunk', 'sip-trunk' ), true ) ? $provider : 'twilio';
	}

	/**
	 * Audio format Bolna expects for a telephony provider.
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	private static function audio_format( $provider ) {
		if ( 'trunk' === $provider ) {
			return 'linear16';
		}
		if ( 'sip-trunk' === $provider ) {
			return 'ulaw';
		}
		return 'wav';
	}

	/**
	 * Whether a Bolna error means the saved agent no longer exists.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private static function is_missing_agent_error( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 404 === $status ) {
			return true;
		}
		$message = strtolower( $error->get_error_message() );
		return in_array( $status, array( 400, 422 ), true ) && ( false !== strpos( $message, 'not found' ) || false !== strpos( $message, 'does not exist' ) );
	}

	/**
	 * Re-wrap a Bolna error with context, keeping its data (status).
	 *
	 * @param WP_Error $error  Original error.
	 * @param string   $format sprintf format with %1$s label and %2$s message.
	 * @param string   $label  Agent label.
	 * @return WP_Error
	 */
	private static function wrap_error( $error, $format, $label ) {
		return new WP_Error( 'voicepress_agent', sprintf( $format, $label, $error->get_error_message() ), $error->get_error_data() );
	}

	/**
	 * First non-empty id-like value from a response.
	 *
	 * @param mixed    $data Response.
	 * @param string[] $keys Keys to try.
	 * @return string
	 */
	private static function pick_id( $data, array $keys ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				return (string) $data[ $key ];
			}
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			return self::pick_id( $data['data'], $keys );
		}
		return '';
	}

	/**
	 * A list from a Bolna list response (bare array or wrapped), null when the shape is unknown or an error.
	 *
	 * @param mixed $data Response.
	 * @return array|null
	 */
	private static function as_list( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( empty( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return $data;
		}
		foreach ( array( 'data', 'categories', 'extraction_categories', 'items', 'results' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return self::as_list( $data[ $key ] );
			}
		}
		return null;
	}

	/**
	 * Give multi-agent provisioning enough time (several API calls per agent).
	 */
	private static function extend_time_limit() {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 180 );
		}
	}
}
