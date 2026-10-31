# VoicePress demo video script (about 2.5 minutes)

Record the screen (OBS, Xbox Game Bar with Win+Alt+R, or Loom) with the phone on speaker next to the mic, so the call audio is captured.

## Before you hit record
- PHP server and tunnel running; **VoicePress → Settings → Public URL** set to the current tunnel URL; agents re-synced.
- Two tabs open: the store at http://localhost:8080 and **wp-admin → VoicePress → Dashboard**.
- Phone on speaker, no other apps ringing. Do one rehearsal call first.

## Script

| Time | Screen | Say |
|---|---|---|
| 0:00–0:15 | Deck slide 2 (the gap) | “WooCommerce runs 4.3 million stores, more than Shopify. Shopify got AI phone agents. WooCommerce got robocalls: no AI calling plugin on WordPress.org has more than 10 installs.” |
| 0:15–0:30 | wp-admin → VoicePress → Settings, then Agents | “VoicePress is one plugin. Paste a Bolna key, click Create agents, and five Bolna agents are provisioned with live WooCommerce tools.” |
| 0:30–0:45 | Store: add the Cotton Kurta Set to the cart, check out with **Cash on delivery**, your phone number, preferred language **தமிழ் Tamil** | “A shopper places a cash-on-delivery order. In India about a quarter of these come back unsold.” |
| 0:45–1:25 | Phone on speaker: the agent calls in Tamil. Confirm the order, or ask to change the delivery date. Answer once in English or Hindi to show the switch | (Let the call play.) |
| 1:25–1:45 | wp-admin → WooCommerce → Orders: the *Voice AI* badge shows **Confirmed ✓**. Open the order: VoicePress panel, order notes | “The agent updated WooCommerce during the call through a Bolna tool. Nobody touched it.” |
| 1:45–2:00 | VoicePress → Calls → that call: recording, transcript, extracted outcome | “Every call comes back with a recording, a transcript and a structured outcome.” |
| 2:00–2:20 | Store: click **Talk to us**, ask “Where is my order?” | “The same agents answer the store's phone line and talk in the browser, 24/7.” |
| 2:20–2:35 | VoicePress Dashboard KPIs, then the closing slide | “A ₹5 call against a ₹150–300 return. One plugin, no Docker, powered by Bolna.” |

## Backup shots (if a live call misbehaves)
- **Dashboard → Try it live:** pick *COD confirmation* and *Tamil*, enter your number, then *Call me now*.
- **Orders list:** select an order, then the bulk action *Voice AI: Delivery reminder call*.
- **Automations → Simulate abandoned cart & call**, for the cart-recovery agent with a one-time discount code.
