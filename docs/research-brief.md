# Research brief: voice AI for WooCommerce stores, powered by Bolna

Prepared 31 Oct 2026 for the Bolna hackathon. The judges are the Bolna team and the winner gets a YC interview.

Every number in sections 1 to 7 was fact-checked and marked "verified" or "corrected". Where a number was corrected, only the corrected value appears. Numbers a vendor reported about its own customers are labelled as vendor-reported. Section 8 lists every number we dropped: wrong, out of date, replaced by a correction, too weak, or never checked.

**Bottom line**
- **Biggest platform, no voice AI.** WooCommerce has more live stores than any other platform: 4.32M, against 3.19M on Shopify. Voice AI for online stores has been built for Shopify. On WordPress.org, no WooCommerce plugin built around AI phone calls has more than 10+ active installs.
- **The pain is worst in India.** Only 41% of orders were prepaid in Jan–Nov 2025 (ClickPost). About 26% of cash-on-delivery (COD) orders come back to the seller undelivered (return to origin, or RTO), against under 2% of prepaid orders (Shipway).
- **No Docker anywhere.** Bolna is a hosted API, so the whole product is a PHP plugin. The merchant needs no Docker, no Python server, no Redis and no tunnel. They install the plugin and paste a Bolna API key. Self-hosting Bolna without Docker is possible, but it still needs servers that shared WordPress hosting can't run (see section 3).

---

## 1. Corrected problem statement

The draft said "more than 70% of e-commerce stores are built on WordPress". No tracker supports this. Store Leads finds WordPress on 4,437,602 of the 14,209,748 live stores it tracks, which is about 31%.

**Paragraph**

WordPress runs about 40% of websites (39.5% on 31 Oct 2026, down from 43.0% in January; W3Techs). Its free WooCommerce plugin is the most-used store platform on the web:
- 4.32 million live stores against 3.19 million on Shopify;
- about 30% of the 14.2 million live stores Store Leads tracks;
- 47.4% of sites running a recognised e-commerce system (W3Techs).

India alone has 1.2 lakh+ live WooCommerce stores. Store Leads puts it level with Shopify; AfterShip puts it ahead. Most of WooCommerce's stores are small, low-traffic sites: it has 11.5% of all sites with a known CMS but only 2.7% of the top 100,000 sites. These are merchants who run their own stores, with no call centre and no developer. They lose the most to orders that fail. In India only 41% of orders were prepaid in Jan–Nov 2025 (ClickPost), and about 26% of cash-on-delivery orders come back unsold, against under 2% of prepaid ones (Shipway). A phone call fixes this, and AI phone agents for online stores now exist, but they were built for Shopify. On WordPress.org, no WooCommerce plugin built around AI phone calls has more than 10+ active installs.

**One line**

WooCommerce runs 4.3 million live stores, more than any other platform and about 3 in 10 of the stores Store Leads tracks, yet AI phone agents have been built for Shopify, not for them.

**Say this, not that**

| Don't say | Say |
|---|---|
| "70% of e-commerce stores are on WordPress" | "WooCommerce is the #1 store platform: 4.3M live stores, about 30% of the stores Store Leads tracks, and 47% of sites with a known e-commerce system" |
| "WordPress powers 43% of the web" | "About 40% of websites (39.5%, W3Techs, Oct 2026)" |
| "WooCommerce dominates India" | "1.2 lakh+ Indian WooCommerce stores, neck and neck with Shopify" |
| "63% of Indian websites run WordPress" | "63.1% of .in websites with a known CMS" |
| "30% of all online stores" | "30% of the 14.2M live stores Store Leads tracks" |

**Where the "70%" may have come from.** These are guesses, so don't present them as fact:
- Baymard's 70.22% cart-abandonment rate.
- WordPress's 58.2% share of sites built on a CMS (63.1% on .in).
- WooCommerce and Shopify together running 79.8% of sites with a known e-commerce system (47.4% + 32.4%).

Also, 95.2% of WordPress stores run WooCommerce. That means WooCommerce dominates selling on WordPress, not that WordPress dominates online selling.

---

## 2. Key numbers

All shares are as published by each source. Store Leads percentages are shares of the stores Store Leads tracks.

| Stat | Value | Source | As of |
|---|---|---|---|
| **Market** | | | |
| WordPress share of websites | 39.5% of all sites; 58.2% of sites with a known CMS | [W3Techs](https://w3techs.com/technologies/details/cm-wordpress) | 2026-10-31 |
| WordPress share trend | 43.6% (1 Jan 2025), then 43.0% (1 Jan 2026), then 39.5% (31 Oct 2026). Share of known-CMS sites: 62.0%, then 60.2%, then 58.2% | [W3Techs history](https://w3techs.com/technologies/history_overview/content_management/all/y) | 2026-10-31 |
| WooCommerce share of sites using a recognised e-commerce system | 47.4% (Shopify 32.4%). Also 7.8% of all websites (Shopify 5.3%) | [W3Techs](https://w3techs.com/technologies/subdetails/ecommerce) | 2026-10-31 |
| Live stores, WooCommerce vs Shopify | 4,321,090 (~30.4%) vs 3,189,133 (~22.4%) of 14,209,748 tracked | [Store Leads](https://storeleads.app/reports) | 2026-10-23 |
| Live stores running WordPress | 4,437,602 (~31.2% of tracked stores); 95.2% of these run WooCommerce | [Store Leads](https://storeleads.app/reports/technology/Wordpress) | 2026-10-23 |
| E-commerce share in Wappalyzer's own crawl | WooCommerce ~1.7M sites (24%), Shopify ~1.6M (22%), Wix ~1.5M (20%) | [Wappalyzer](https://www.wappalyzer.com/technologies/ecommerce/) | 2026 (undated; read 2026-10-31) |
| Share of known-CMS sites, by traffic rank | WooCommerce: 11.5% overall, 8.9% of the top 1M, 2.7% of the top 100k. Shopify: 7.9%, 15.7%, 8.3% | [W3Techs: WooCommerce](https://w3techs.com/technologies/breakdown/cm-woocommerce/ranking), [Shopify](https://w3techs.com/technologies/breakdown/cm-shopify/ranking) | 2026-10-31 |
| BuiltWith count (second-hand, low confidence; cite only if asked) | 6,774,190 sites; ~8% of the top 1M e-commerce sites (Shopify 20.4%) | [Barn2, citing BuiltWith](https://barn2.com/blog/woocommerce-stats/) | 2026-04-07 |
| **India** | | | |
| WooCommerce vs Shopify in India, per Store Leads (a minimum, since some stores can't be placed in a country) | 123,183 (~34.6%) vs 130,632 (~36.7%) of 356,148 India-based stores | [Store Leads](https://storeleads.app/reports/woocommerce/IN/top-stores) | 2026-10-23 |
| WooCommerce vs Shopify in India, per AfterShip (medium confidence) | 120.58K (45.13%) vs 76.43K (28.61%) of 267.16K | [AfterShip](https://www.aftership.com/ecommerce/statistics/regions/in) | 2026 (undated; read 2026-10-31) |
| India's rank by store count | 4th country for WooCommerce, 5th for Shopify. 253,815 stores on the two platforms combined (a minimum) | [Store Leads: WooCommerce](https://storeleads.app/reports/woocommerce), [Shopify](https://storeleads.app/reports/shopify) | 2026-10-23 |
| WordPress on .in websites | 63.1% of .in sites with a known CMS (Shopify 14.5%) | [W3Techs](https://w3techs.com/technologies/segmentation/tld-in-/content_management) | 2026-10-31 |
| India online retail | $65–66B gross sales and 290–300M shoppers in 2025. Growing more than 20% a year to $170–180B by 2030. Seller count tripled in 5 years | [Bain (with Flipkart)](https://www.bain.com/insights/how-india-shops-online-2026/) | 2026-04-08 |
| **COD and RTO pain (each source's own customer data, mostly direct-to-consumer brands)** | | | |
| Prepaid share of orders (brands shipping through ClickPost, 635M+ orders) | 41% in Jan–Nov 2025, vs 32% in 2024. That leaves ~59% COD (our subtraction) | [Social Samosa](https://www.socialsamosa.com/report/prepaid-e-commerce-orders-rise-10958917) | Published 2025-12-30 |
| RTO rate, COD vs prepaid (Shipway shipments) | ~26% vs under 2%. COD RTO reaches 35% in Patna, the highest city | [Mediainfoline](https://www.mediainfoline.com/techno/click-buy-return-shipway-unveils-why-online-orders-come-back) | FY25 data; published 2025-07-29 |
| Cost of one RTO on a ₹1,000 order (illustrative vendor estimate) | ₹150–300 direct; ₹450–900 including ₹300–600 spent acquiring the customer | [ClickPost blog](https://www.clickpost.ai/blog/rto-reduction-tools) | 2026-08-05 |
| Orders returned after failed delivery attempts (ClickPost brands) | 28% after one failed attempt; 81% after three | [ClickPost](https://www.clickpost.ai/assessment/peak-season-rto) | Oct–Nov 2025 data |
| Festive-season RTO (ClickPost brands only) | ₹545 crore of goods came back, from 35.8M orders analysed | [ClickPost](https://www.clickpost.ai/assessment/peak-season-rto) | Oct–Nov 2025 |
| Delivery success in Tier-2 and smaller cities (Velocity, 40 lakh+ orders) | Over 67% of shipments go there; only ~60% are delivered, vs 73% in metros. AI calls, address checks and COD-to-prepaid nudges improved delivery completion by 11% | [India Shipping News](https://indiashippingnews.com/export-import/ai-helps-d2c-brands-improve-delivery-completion-rates-by-11-in-tier-2-beyond-velocity-data-shows/) | 2026-04-08 |
| **Shopper behaviour** | | | |
| Cart abandonment (global average of 50 studies) | 70.22% | [Baymard](https://baymard.com/lists/cart-abandonment-rate) | 2025-09-22 |
| Abandoned-cart email (Omnisend merchants, mostly outside India) | 35.75% opened; 1.51% of delivered emails led to a purchase | [Omnisend](https://www.omnisend.com/blog/abandoned-cart-email/) | 2025 data |
| "Where is my order?" share of support queries (UK/EU/US retail; old data) | 21% in Mar 2023; 36% at the Dec 2022–Jan 2023 peak | [DigitalGenius](https://www.digitalgenius.com/blog/what-is-a-normal-wismo-rate) | 2023-04-17 |
| Language preference in India | 98% of internet users access content in Indian languages; ~57% of urban users prefer regional-language content | [IAMAI-Kantar via The Week](https://www.theweek.in/wire-updates/business/2025/01/16/dcm51-biz-iamai-internet-usage-report.html) | 2024 data; published 2025-01-16 |
| Meesho's Hindi/English voice shopping assistant (company-reported; not a controlled test) | Voice use went from ~10% of users to ~40% of users who had the assistant; those users converted 22% better | [Inc42](https://inc42.com/features/meesho-bets-big-on-voice-commerce-will-india-respond) | 2026-03-29 |
| **Voice AI results (vendor-reported)** | | | |
| AI calls plus WhatsApp vs WhatsApp alone (GoKwik, 30 brands, 6 months) | 2x as many carts recovered; over 10x return on the extra spend; 60–65% of calls answered | [MediaNews4U](https://www.medianews4u.com/d2c-brands-using-ai-voice-calls-double-abandoned-cart-recoveries-gokwik/) | 2026-09-24 |
| Hypothesis on Bolna (cart recovery; Hypothesis's product is built for Shopify) | 100,000+ calls; ₹2.5 Cr+ recovered | [Bolna](https://www.bolna.ai/customer-story/hypothesis) | Undated; read 2026-10-31 |
| GoKwik on Bolna | 400,000 shoppers; 250,000 call-minutes; 250+ calls at the same time | [Bolna](https://www.bolna.ai/customer-story/gokwik) | Undated; read 2026-10-31 |
| Off Duty with BiteSpeed (cart recovery) | 14,615 calls placed, 8,152 connected; 13.21% conversion; ₹16.9 lakh in under 30 days | [BiteSpeed PDF](https://files.bitespeed.co/Off_Duty.pdf) | Undated (file 2026-03-24) |
| BeastLife with LimeChat (cart recovery) | 23.89% conversion. LimeChat's headline says 92x ROI but doesn't show how it was calculated | [LimeChat](https://www.limechat.ai/case-study/beastlife-unlocks-92x-roi-using-voice-ai-for-cart-recovery) | Undated; read 2026-10-31 |
| Cahoot with LimeChat (voice and WhatsApp as one sequence) | 1.8x sales from recovered checkouts, vs either channel alone | [LimeChat](https://www.limechat.ai/case-study/how-cahoot-drove-sales-by-running-voice-and-whatsapp-together) | Undated; read 2026-10-31 |
| BiteSpeed AI voice agents (launch press release) | Up to 10% of revenue for brands such as Mokobara, Bombay Shaving Co. and Ugaoo; 65% connect rate; about a third of the cost of human calling teams; 100+ brands | [The Week (Business Wire India)](https://www.theweek.in/wire-updates/business/2025/11/20/dcm29-bitespeed.html) | 2025-11-20 |
| Delhivery SmartNDR (WhatsApp first, then up to 3 AI calls in Hindi or English) | On average across clients, 2–4% lower overall RTO and 6–10% lower COD RTO; ₹5 per failed-delivery attempt | [Delhivery](https://help.delhivery.com/docs/smartndr) | 2026-07-08 |
| RoroSaur with Shift (human failed-delivery calls, not AI) | RTO down 28%; failed deliveries resolved in under 12 hours instead of 48–72 | [Shift](https://shift.in/case-studies/roro-saur/) | Undated; read 2026-10-31 |
| Ekart with Ringg (failed-delivery and sales-lead calls) | 1 lakh+ AI calls a month in 5+ languages | [Ringg](https://www.ringg.ai/case-studies/ekart) | 2026-07-09 |
| Meesho's customer-support voice bot | 60,000 calls a day in Hindi and English; 95% resolved without a human; call time halved; some per-call costs down 75% | [TechCrunch](https://techcrunch.com/2024/11/26/ai-helps-indias-meesho-cut-customer-call-costs-by-75/) | 2024-11-26 |
| **Costs and prices** | | | |
| Human calling vs Bolna, per minute (India) | Human ₹7–8/min before hiring, training and staff turnover. Bolna ₹5.5/min self-serve, ₹1.75–2/min for large enterprise contracts | [Inc42](https://inc42.com/features/the-unit-economics-of-indias-voice-ai-boom/) | 2026-07-15 |
| Customer care executive pay (0–5 years' experience) | ₹2.6–2.9 lakh a year, about ₹22–24k a month before tax | [AmbitionBox](https://www.ambitionbox.com/profile/customer-care-executive-salary) | 2026-10-31 |
| AI COD-confirmation calls on Shopify (Breeze Buddy, by Juspay) | 75 calls a month free, then ₹9 per call. About ₹507/month for 500 calls (₹6 per extra call); about ₹1,521/month for 2,000 (₹4 per extra call) | [Shopify App Store](https://apps.shopify.com/breeze-buddy) | 2026-10-31 |
| AI calling per minute on Shopify (Scalysis) | ₹3.3/min on the Growth plan down to ₹2.375/min on Enterprise | [Shopify App Store](https://apps.shopify.com/scalysis-ai-calling) | 2026-10-31 |
| AI calling on Shopify (CONSIO) | $30 a month for 100 minutes, plus $0.10 per extra minute | [Shopify App Store](https://apps.shopify.com/consio) | 2026-10-31 |
| Automated "press 1" COD confirmation call on WooCommerce (Softpulse) | ₹2.00 per call; ₹0.40 per SMS | [WordPress.org](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=cod-confirmation-for-india) | 2026-10-31 |
| **The gap** | | | |
| Most-installed WooCommerce plugin built around AI phone calls | 10+ active installs (StoreVoiceAI, which answers inbound calls on US numbers only; RevCent ties at 10+) | [WordPress.org](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=storevoiceai-for-woocommerce) | 2026-10-31 |
| Most-installed WooCommerce plugin that phones COD buyers ("press 1" call, listed since 2018) | 70+ active installs | [WordPress.org](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=cod-confirmation-for-india) | 2026-10-31 |
| Shopify App Store search for "ai voice agent" | 23 of the 24 first-page results (excluding ads) are voice or calling apps | [Shopify App Store](https://apps.shopify.com/search?q=ai+voice+agent) | 2026-10-31 |
| WooCommerce.com Marketplace | 12 "voice" and 9 "cash on delivery" extensions; none of them makes AI or automated calls | [WooCommerce.com](https://woocommerce.com/search/?q=voice) | 2026-10-31 |
| General voice-AI platforms (Vapi, Retell, Bland, Synthflow, Bolna) | No WooCommerce plugin from any of them; zero mentions of WooCommerce in their docs indexes | [Synthflow integrations](https://synthflow.ai/integrations), plus each vendor's llms.txt | 2026-10-31 |
| COD verification by text on WooCommerce | SMS Alert 3,000+, Notiqoo 1,000+, Interakt 800+ installs | [WordPress.org](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=sms-alert) | 2026-10-31 |
| New COD verification, fraud and RTO plugins on WordPress.org | About 4 in 5 were first listed in 2025–26. Only 4 of the new ones make calls (automated or human), and 1 uses conversational AI (ZenTalk, under 10 installs) | [WordPress.org](https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request%5Bsearch%5D=cod%20verification&request%5Bper_page%5D=60) | 2026-10-31 |

---

## 3. The gap: why WooCommerce stores don't have voice AI today

**In short:** voice AI for online stores was built where the app store is, on Shopify. A WooCommerce merchant today gets text messages, automated "press 1" calls, or a developer project.

**Evidence**
1. **Shopify got voice AI first.**
   - 23 of the 24 first-page Shopify results for "ai voice agent" are voice or calling apps, with public prices: Breeze Buddy ₹9 per call after 75 free, Scalysis from ₹3.3/min, CONSIO $30 for 100 minutes.
   - The big Indian players launched voice on Shopify:
     - BiteSpeed (Nov 2025) installs only on Shopify.
     - GoKwik's KwikEngage is a Shopify app.
     - Interakt (part of Jio Haptik) launched its Voice AI Agent on 5 Oct 2026 for Shopify only.
2. **WordPress.org has almost nothing.**
   - No WooCommerce plugin built around AI phone calls has more than 10+ installs. StoreVoiceAI answers inbound calls on US numbers only; RevCent's payments plugin also has 10+.
   - The two plugins that make outbound AI calls, ZenTalk (COD) and CallFusionAI (abandoned carts), have under 10 installs each.
3. **Calling on WooCommerce means automated "press 1" calls.**
   - The best is Softpulse's COD confirmation plugin: 70+ installs since 2018, ₹2 per call. Next are bSecure (60+), Vrobo (20+) and Robo IVR Call Pakistan (10+).
   - On Shopify, two "press 1" apps, Hillteck and Robo Calls Pakistan, have 157 and 134 reviews. Reviews undercount installs, so the real gap is bigger.
4. **Today, text meets the demand.**
   - SMS Alert has 3,000+ installs, Notiqoo 1,000+, and Interakt's WhatsApp-only plugin 800+ (last updated July 2025).
   - Smart COD (30,000+) sets COD rules and fees but makes no calls.
   - About 4 in 5 COD plugins appeared in 2025–26. Only 4 of them make calls, and only 1 uses conversational AI.
5. **WooCommerce's own marketplace** has no product that makes outbound calls.
6. **Voice-AI platforms don't support WooCommerce.**
   - Vapi, Retell, Bland, Synthflow and Bolna ship no WooCommerce plugin, and their docs indexes never mention it.
   - Synthflow lists Shopify. Retell connects to Shopify only through an unofficial third-party connector. Exotel's app and docs cover Shopify COD calls only. ElevenLabs' WooCommerce integration page returns 404.
   - Bolna's hosted store agents connect through "a custom function that calls your order API". That is a developer job.
7. **Self-hosting Bolna isn't realistic for a small store, with or without Docker.**
   - Bolna's documented open-source setup runs four Docker containers: a telephony server, the Bolna server, ngrok and Redis.
   - Installing it with Python's package manager (`pip install bolna`, v0.11.20, Python 3.10+) avoids Docker. It still needs a Python server running all the time, Redis, a public tunnel and a Twilio or Plivo account.
   - Typical shared WordPress hosting can't run any of that. This is our inference, not a sourced statement.
8. **No WooCommerce case study.** We found no published voice-AI case study from a WooCommerce store. Bolna's Hypothesis case is a product built for Shopify, and BiteSpeed installs only on Shopify.

**What a WooCommerce merchant can do today**

| Option | What it means for a small WooCommerce store |
|---|---|
| Text verification (SMS or WhatsApp one-time code) | The most common choice (SMS Alert 3,000+, Notiqoo 1,000+, Interakt 800+). Text only, no conversation |
| Automated "press 1" call plugins | Key presses only. The best one (Softpulse) has 70+ installs after 8 years, at ₹2 per call |
| Voice vendors built for Shopify | BiteSpeed installs only on Shopify. Interakt's voice agent is Shopify-only. GoKwik's WooCommerce page doesn't mention AI calling |
| General voice APIs (Vapi, Retell, Bland, Synthflow, Bolna) | No WooCommerce plugin. A developer has to connect the store's order events and order data |
| Self-hosting Bolna's open-source version | The Docker route needs 4 containers. The no-Docker route needs a Python 3.10+ server running all the time, Redis, a public tunnel and Twilio or Plivo. Neither runs on shared PHP hosting (our inference) |
| New AI plugins on WordPress.org | ZenTalk (AI COD calls, Sep 2026) and CallFusionAI (carts) have under 10 installs each. StoreVoiceAI (10+) takes inbound calls only, on US numbers |

**Why the gap exists (our reading, not sourced)**
- Shopify gives vendors one app store, one API and built-in billing. WooCommerce stores are self-hosted on countless different setups, so vendors launch on Shopify first.
- WooCommerce's stores are mostly small and low-traffic. It has 2.7% of the top 100,000 sites, against 8.3% for Shopify. These merchants can't fund an integration project.
- The open-source route needs servers that PHP hosting can't run.

**What's missing:** a WordPress.org plugin that installs without Docker or a developer and:
- makes outbound conversational calls in Hindi, English and regional languages when a COD order is placed, a checkout is abandoned or a delivery fails;
- writes the result back to the order: status, note, address fix or switch to prepaid;
- falls back to WhatsApp or SMS;
- answers inbound "where is my order?" calls from order data;
- shows a clear price per call or per minute. Benchmarks: ₹2 per automated "press 1" call (Softpulse), ₹4–9 per AI call (Breeze Buddy), ₹2.375–3.3 per minute (Scalysis).

**The window is closing**
- **ZenTalk** listed AI COD-confirmation calls for WooCommerce in Sep 2026.
- **GoKwik** ships its own WooCommerce checkout plugin (outside WordPress.org) and is adding COD and failed-delivery calls to its AI calling.
- **Shiprocket**'s TwentyTwo AI voice platform, in beta since June 2026, lists COD confirmation as a use.
- **Interakt** already has 800+ WooCommerce installs, and a Shopify voice agent launched on 5 Oct 2026.
- **Distribution on WordPress.org can be fast.** OrderGuard, a COD plugin listed on 14 Oct 2026, had 300+ installs within three weeks.

Being first on WordPress.org with real usage is the moat.

---

## 4. Use-case catalog (prioritized)

**P0: what the hackathon demo shows** (five agents)
1. COD confirmation call that also reads back the address and offers a prepaid discount (rows 6, 7, 8). It moves the order from On hold to Processing and adds a call-summary note.
2. Abandoned-cart call that sends a checkout link by WhatsApp or SMS (row 4).
3. "Talk to us" web-call agent on product pages that answers store questions (row 1).
4. Inbound order-status phone line (row 14).
5. Daily briefing call to the store owner (row 26).

All five switch between Hindi and English (row 25). The first build after the hackathon should be failed-delivery calls (row 13). That is the strongest lever on returns: 28% of orders come back after one failed delivery attempt and 81% after three. It needs courier notifications to trigger, which is why it isn't P0.

Terms used in the table:
- **RTO:** return to origin, an order that comes back undelivered.
- **NDR:** non-delivery report, the courier's notice that a delivery attempt failed.
- **AOV:** average order value.
- **WISMO:** "where is my order?" questions.
- **AWB:** the courier's tracking number.
- **Action Scheduler:** the background job runner that ships with WooCommerce.

| # | Use case | Stage | Trigger (WooCommerce hook or schedule) | Direction | What the agent does | WooCommerce data used, and actions taken | Metric moved (evidence) | Why it matters in India | Priority |
|---|---|---|---|---|---|---|---|---|---|
| **P0: in the demo** | | | | | | | | | |
| 6 | COD order confirmation | Before dispatch | `woocommerce_checkout_order_processed` / `woocommerce_store_api_checkout_order_processed` with `payment_method = cod`. Hold the order with the `woocommerce_cod_process_payment_order_status` filter, set to `on-hold`. Call within about 2–10 min; retries through Action Scheduler | Outbound | Confirms the buyer, the items, the amount to pay and that they still want it. Outcomes: confirm, cancel, call later, suspicious | Items, total, billing name and phone, address. Confirm: set `processing` and push to courier. Cancel: set `cancelled` and restock. No answer after N tries: tag as unverified. Add an order note with a summary and recording link | RTO %, fake-order rate, confirmation rate. GoKwik on Bolna shows COD questions handled at scale; the only named-brand COD figure is an undefined vendor claim | Very high: about 59% of orders are COD (ClickPost); COD RTO is ~26% vs under 2% for prepaid (Shipway) | P0 (main demo) |
| 7 | Address and pincode check | Before dispatch | Same call as #6 (also for prepaid orders), or an address-quality rule on `woocommerce_new_order` (no landmark, pincode doesn't match city, very short address) | Outbound | Reads back the address and pincode; confirms a landmark and an alternate phone. For corrections, sends a WhatsApp or SMS edit link rather than relying on speech recognition for a long address | Shipping fields, whether the pincode is serviceable. Update shipping fields, add a landmark note, block dispatch until fixed | First-attempt delivery rate, address-related failed deliveries (RoroSaur with Shift) | Very high: Tier-2 and smaller cities get over 67% of shipments but only ~60% are delivered (Velocity) | P0 (part of #6) |
| 8 | COD-to-prepaid conversion | Before dispatch | Part of #6, or a separate call for high-value orders or high-RTO pincodes | Outbound | Offers a small incentive to pay now (COD fee waived, cashback); sends a UPI payment link; confirms payment | Total, COD fee line, allowed discount. Set `pending`, switch the payment method, send the payment link. On `woocommerce_payment_complete`, set `processing` and remove the fee | Share of COD orders converted to prepaid, RTO %, cash-handling cost. Velocity counts COD-to-prepaid nudges among the steps behind its 11% improvement | Very high: prepaid RTO is under 2% vs ~26% for COD (Shipway) | P0 (part of #6) |
| 4 | Abandoned cart or checkout recovery | Checkout | Phone captured at checkout and no order after 15–60 min. Action Scheduler sweeps `checkout-draft` orders (block checkout), `pending` orders, or a cart-capture table. Cancel the job if `woocommerce_new_order` or `woocommerce_payment_complete` fires | Outbound, plus WhatsApp or SMS | Calls about the exact items and total; resolves what's blocking the purchase (size, delivery date, COD fee, coupon); offers only a pre-approved incentive; sends a checkout link; stops once the order is placed | Cart lines, name and phone, total, coupons, delivery estimate. Payment link (`$order->get_checkout_payment_url()`) or cart-restore URL, single-use coupon, outcome saved in order meta and notes | Recovered revenue and recovery rate (Hypothesis on Bolna; GoKwik's 30 brands; Off Duty; BeastLife; Cahoot; BiteSpeed) | Very high: the best-evidenced Indian voice use case. It counts as a promotional call, so it needs consent and an opt-out, and must respect the Do Not Disturb (DND) registry | P0 |
| 14 | Inbound order-status line ("where is my order?") | Support | Inbound call to the store number (Bolna inbound agent with caller lookup), or a web call from the "Track order" page | Inbound or web call | Finds orders by the caller's phone or an order number (keypad entry works); gives status, courier, delivery estimate and tracking. Tools: cancel, change address, transfer to a human | `wc_get_orders(['billing_phone' => …])`, status, tracking meta, notes. Read-only by default; changes allowed only after a one-time code or phone-number match | Fewer support calls for staff, shorter calls, lower cost per contact (Meesho). "Where is my order?" was 21% of retail support queries, 36% at peak (DigitalGenius, 2023, outside India) | Very high: Meesho's Hindi/English bot resolves 95% of 60,000 daily calls without a human | P0 |
| 1 | Store FAQ and product questions | Before purchase | "Talk to us" web-call button on product and cart pages; inbound store number | Web call or inbound | Answers questions on sizing, ingredients, warranty, delivery time to a pincode, COD availability and fee, and return policy, using store data only. Hands off to a human or WhatsApp when unsure | Products via `GET /wc/v3/products` (price, stock status, attributes, description), shipping zones and methods, refund and terms pages as the knowledge base. Sends a product or cart link; logs unanswered questions for the owner | Conversion before purchase, fewer support contacts | High: shoppers ask about COD, discounts and delivery during calls (GoKwik on Bolna) | P0 |
| 25 | Language layer | All stages | Language detected from the caller's first words; default set from `billing_state`; saved preference | All | Speaks Hindi, English, Hinglish and regional languages, switching mid-call | Language preference saved in customer or order meta and reused on later calls | Resolution rate and satisfaction in Tier-2+ cities (Ekart with Ringg; Meesho) | Very high: ~57% of urban internet users prefer regional-language content (IAMAI-Kantar). Bolna supports 12 Indian languages | P0 (Hindi and English) |
| 26 | Daily briefing call to the store owner | Operations | Daily Action Scheduler job (for example 09:00 IST), plus an inbound line for the owner | Outbound to owner, or inbound | Reads out yesterday's orders and revenue against the day before, COD orders awaiting confirmation, cancellations, failed-delivery and return alerts, low stock and top products. The owner can act by voice | `wc-analytics/reports/revenue/stats`, orders by status, `wc-analytics/reports/stock`, call outcomes. Bulk status updates, hide a product, add notes (only from the owner's number or with a PIN) | Owner time saved, faster decisions | High (our reading): WooCommerce stores are mostly small and owner-run (W3Techs traffic ranks) | P0 |
| **P1: next** | | | | | | | | | |
| 13 | Failed-delivery resolution | Delivery | Courier's failed-delivery notification, with a reason code, sent to a plugin endpoint. Call within 30–90 min; up to 3 attempts 2 hours apart (the pattern Delhivery's SmartNDR uses) | Outbound | Checks the courier's stated reason (this catches false "customer not available" reports); records a re-delivery date or slot, a landmark fix, or intent to cancel | Tracking number, failure reason, attempt count, address. Send the re-attempt request and address to the courier's API; add a note; flag the order as likely RTO if refused | Share of failed deliveries resolved, RTO % (Delhivery SmartNDR; RoroSaur with Shift; Ekart with Ringg) | Very high: 28% of orders come back after one failed attempt and 81% after three (ClickPost) | P1 (first build after the hackathon) |
| 12 | Out-for-delivery reminder | Delivery | Courier or shipping-aggregator notification (Shiprocket, Delhivery and others) on "out for delivery", or a tracking check through Action Scheduler | Outbound | "Arriving today": confirms someone will be home, cash or UPI is ready, an alternate receiver, and the landmark | Tracking number, courier, COD amount, address. Add a note; send an alternate phone or time slot to the courier's API | First-attempt delivery rate, failed deliveries, COD RTO | High: only ~60% of Tier-2+ shipments are delivered vs 73% in metros (Velocity) | P1 |
| 5 | Failed payment / UPI drop-off recovery | Checkout | `woocommerce_order_status_failed`, or a `pending` order still unpaid after 10–20 min | Outbound | Explains the failure; offers a new payment link or another UPI app, or a switch to COD if the store allows it | Order total, payment gateway, failure note. Resend the payment link, or switch to COD and set `processing`; add an order note | Payment success rate, recovered orders | High: these buyers already tried to pay (our reading) | P1 |
| 10 | Cancellations | After purchase | Inbound "cancel" request; cancellation form; notification on `woocommerce_order_status_cancelled` | Inbound or outbound | Verifies the caller; checks whether the order has shipped; records the reason; tries to save the sale (swap or delay); cancels if not yet shipped; explains the refund timeline | Status, tracking meta, payment method. `update_status('cancelled')`, restock, `wc_create_refund()` for prepaid orders, cancel the pickup through the aggregator's API | Orders cancelled before shipping, save rate, support cost | High: cancelling before dispatch avoids paying for both the outbound and return shipment | P1 (a tool on the #14 agent) |
| 15 | Starting a return or exchange | Returns | Inbound request; N days after delivery; a request created in a returns plugin | Inbound or outbound | Checks the return window and eligibility; records the reason; offers an exchange first; books a pickup | Order and delivery dates, items, return policy. Return record or note, exchange order, return pickup through the aggregator's API | Share of returns turned into exchanges, refund cost | High in fashion and footwear | P1 |
| 16 | Refund status | Refunds | Inbound; or proactive on `woocommerce_order_refunded` / `woocommerce_order_partially_refunded` | Inbound or outbound | States the amount, method, date and when the money should arrive; collects UPI or bank details for COD refunds through a secure link | `$order->get_refunds()`, payment gateway's refund ID. Add a note; create a manual COD-refund task | Repeat contacts, disputes | High: COD refunds need the customer's UPI or bank details | P1 |
| 17 | Reviews and satisfaction score | After delivery | `woocommerce_order_status_completed` (or courier's "delivered"), plus 3–7 days | Outbound | 60-second call: 0–10 recommendation score, star rating and a short spoken review (translated). Unhappy customers are passed to support | Order items. Product review (`wp_insert_comment`, type `review`, with `rating` meta), score saved in customer meta, support ticket for unhappy customers | Review volume, satisfaction score, unhappy customers won back | Medium-high: captures reviews from customers who won't type one | P1 |
| 18 | Reorder reminder | Repeat purchase | Scheduled from the completed date plus the product's usage cycle (product meta, for example `_replenish_days`) | Outbound | Reminds the customer they're about to run out; confirms the same variant and quantity; takes the order | Order history, usage-cycle meta. `wc_create_order()` with the same items plus a payment link, or COD plus confirmation | Repeat rate, customer lifetime value | High for consumables (beauty, nutrition, pet, groceries) | P1 |
| 28 | Extra check for risky or high-value orders | Before dispatch | `woocommerce_new_order` plus rules (first order, high value, COD, past cancellations, risky pincode) | Outbound | Stronger check: a one-time code on the call, an alternate number, and a request for a partial advance through a payment link | Customer history, the store's own RTO history by pincode. Keep the order `on-hold`, send a partial-payment link, or cancel | Fraud losses, RTO on high-value orders | High for electronics and other expensive COD orders | P1 |
| 2 | Guided shopping | Before purchase | Web call from the home or category page; a missed call triggers a callback | Web call or inbound | Asks about need, budget and size or skin type; recommends 2–3 products in stock; sends a cart link | Catalogue attributes and tags, stock, ratings. `?add-to-cart=` link; optional coupon via `POST /wc/v3/coupons` | Conversion, order value. Meesho's voice users converted 22% better (self-selected users, not a controlled test) | Medium-high: brings in-store "shopkeeper" style help in Hindi or Hinglish | P1 |
| **P2: later** | | | | | | | | | |
| 3 | Wholesale and bulk-order leads | Before purchase (business buyers) | Quote or wholesale form submitted (form webhook, or `user_register` with a wholesale role); cart quantity or value above a threshold | Outbound within minutes, or web call | Asks for business type, GST number, monthly volume, city and timeline; explains tiered pricing and minimum order; books a callback from the owner | Customer and user meta, tiered price rules. Create or update the customer (`/wc/v3/customers`), tag the lead, draft a quote order, notify the owner | Speed of response to leads, share of qualified leads | High for manufacturers and wholesalers on WooCommerce. Ekart uses voice AI to qualify business leads (Ringg) | P2 |
| 9 | Add-on or size/colour change before dispatch | After purchase | `woocommerce_order_status_processing`, before the shipping label is created (30–120 min window) | Outbound (part of #6) or inbound | Offers an add-on that ships in the same box; handles a size or colour swap before shipping | Line items, `get_cross_sell_ids()`, stock. Add or remove items, recalculate totals, add a note | Order value, exchanges avoided | Medium: one shipment instead of two | P2 |
| 11 | Dispatch delay or backorder notice | Fulfilment | Sweep for `processing` orders past the promised dispatch time with no tracking; `woocommerce_product_on_backorder` | Outbound | Apologises; gives a new delivery estimate; offers to wait, swap or cancel | Order date, promised time, stock. Add a note, change status, issue a coupon or partial refund | Cancellations, order-status call volume, satisfaction | Medium: backlogs during festive sales | P2 |
| 19 | Back-in-stock alert | Repeat purchase / marketing | `woocommerce_product_set_stock_status` / `woocommerce_variation_set_stock_status` changing to `instock` for waitlisted items | Outbound (opt-in only) | Announces the restock of the exact size or variant; offers to reserve it or place the order | Waitlist (product, phone, consent), stock. Order or cart link; clear the waitlist entry | Waitlist conversion, sell-through | Medium: size-driven fashion drops | P2 |
| 20 | Price-drop or sale alert | Marketing | `woocommerce_product_object_updated_props` when the price falls on a wishlisted item | Outbound (opt-in only) | Announces the new price or offer; answers questions; sends a link | Wishlist (from a plugin), old vs new price. Link, coupon | Wishlist conversion | Medium. Promotional, so DND rules and 140-series numbers apply | P2 |
| 21 | Win back lapsed customers | Repeat purchase | Weekly sweep for customers whose last order is 60–120+ days old (`wc_customer_lookup.date_last_active`, or `wc_get_orders` by date) | Outbound (opt-in only) | Mentions their last purchase; asks why they stopped; offers a comeback coupon | Order history, lifetime value. Single-use coupon; reason for leaving saved in meta | Reactivation rate | Medium | P2 |
| 22 | Follow-up offer after delivery | Repeat purchase | N days after delivery, or after a high score in #17 | Outbound | Gives usage tips and suggests a related product | Items, `get_cross_sell_ids()` / `get_upsell_ids()`. Cart link, coupon | Order value, repeat rate | Medium | P2 |
| 23 | Subscription renewals and failed renewals | Repeat purchase | WooCommerce Subscriptions: N days before `woocommerce_scheduled_subscription_payment`; `woocommerce_subscription_renewal_payment_failed`; `woocommerce_subscription_status_on-hold` | Outbound | Reminder before renewal with options to skip, pause or swap; recovery of failed payments with a payment link; an offer to stay before cancellation | Subscription (next payment, items, payment method). Update dates, swap items, send a renewal payment link, pause | Subscribers lost to failed payments, renewal rate | Medium: failed autopay payments need a nudge | P2 |
| 24 | Festive and loyalty campaign calls | Marketing | Scheduled batch (Diwali, end-of-season sale) built from customer segments | Outbound batch (opt-in only) | Short personalised offer; answers questions; sends a link; honours opt-outs | Segments, coupons, consent log. Per-customer coupon, opt-out flag | Campaign revenue, opt-out rate | Medium: festive peaks. Promotional, so 140-series numbers and DND rules apply | P2 |
| 27 | Low-stock and supplier reorder alert | Operations | `woocommerce_low_stock` / `woocommerce_no_stock` | Outbound to the owner or supplier | Alerts the owner; can also call the supplier to confirm a purchase-order quantity and date | Stock, sales speed, supplier meta. Purchase-order note, backorder setting, hide the product | Days out of stock, lost sales | Medium | P2 |
| 29 | Warranty, installation and how-to help | After delivery | Inbound; or 1 day after delivery for products that need setup | Inbound or outbound | Gives setup and troubleshooting steps from the knowledge base; registers the warranty; books a technician | Items and serial numbers, knowledge-base documents. Warranty record or note, support ticket | Returns caused by misuse, satisfaction | Medium: electronics and appliances | P2 |

**How it runs without Docker**
- **Plain PHP.** Triggers are WooCommerce hooks and Action Scheduler jobs. Calls go out through Bolna's hosted `POST https://api.bolna.ai/call` using `wp_remote_post`.
- **Two-way connection.** During the call, Bolna's custom-function tools call back into authenticated WordPress REST routes (`register_rest_route`). Call results arrive by webhook.
- **Nothing else to run:** no Docker, no Python, no Redis, no tunnel.
- **Hosting:** on shared hosting, trigger WP-Cron from a real system cron job so scheduled calls fire on time. The site must be reachable over public HTTPS so Bolna can reach it.
- **Missing core events.** WooCommerce has no built-in events for abandoned carts, "out for delivery" or failed delivery:
  - Row 4 needs the phone number captured at checkout.
  - Rows 12–13 need courier or shipping-aggregator notifications sent to a plugin endpoint.
- **Draft orders get deleted.** WooCommerce's daily `woocommerce_cleanup_draft_orders` job deletes block-checkout `checkout-draft` orders. Sweep them before it runs, or keep a separate cart table.

**Design rules from the evidence**
- **Pair the call with a link, and call fast.** GoKwik, Cahoot and BeastLife all pair the call with a WhatsApp checkout link. Call within minutes, and stop as soon as the order is placed.
- **Retry like Delhivery:** up to 3 attempts, 2 hours apart. Bolna's `retry_config` allows up to 3 retries.
- **Read addresses back.** For corrections, send an edit link instead of transcribing the address.
- **Separate promotional from service calls.** Promotional calls (rows 4, 19–22, 24) need consent, an opt-out, DND checks and registered 140-series numbers. Rows 6–8, 10 and 12–16 are service calls. Enforce calling hours with Bolna's calling guardrails.
- **Plan for bursts.** GoKwik ran 250+ calls at the same time on Bolna.

---

## 5. Competitive landscape

Installs and reviews as of 31 Oct 2026. WordPress.org shows installs in brackets ("10+", "70+"). Shopify shows reviews, which undercount installs.

| Player | Where | Call technology | Use cases | Traction | Price | What it means for us |
|---|---|---|---|---|---|---|
| **WordPress.org (WooCommerce)** | | | | | | |
| ZenTalk (Live Chat and AI Sales Agent) | WordPress.org, listed Sep 2026 | Conversational AI, outbound | COD confirmation | Under 10 installs | Per confirmed minute | Closest direct rival. WooCommerce first, "Shopify support coming soon". Shows the window is closing |
| StoreVoiceAI for WooCommerce | WordPress.org, listed May 2026 | Conversational AI, inbound only | Support and sales line | 10+ | Not checked | US numbers only, so no Indian COD use |
| RevCent Payments for WooCommerce | WordPress.org | Payments plugin that advertises AI voice agents, including outbound cart calls | Payments, carts | 10+ | Not checked | Voice is a side feature |
| CallFusionAI Abandoned Cart | WordPress.org, Jul 2026 | Outbound AI calls | Carts | Under 10 | Not checked | Early, no usage yet |
| ChatLab | WordPress.org | AI chatbot with an optional phone line | Support, sales | 200+ | Phone line only on the $360/month plan | Phone is a premium extra |
| CallPage | WordPress.org | AI answers incoming calls | Callbacks | 1,000+ | Not checked | Not specific to WooCommerce |
| AI Engine | WordPress.org | Chatbot on the website with "realtime audio"; no phone calls | Chat | 90,000+ | Not checked | WordPress users do install AI plugins |
| COD Order Confirmation for India (Softpulse) | WordPress.org, since 2018 | Automated "press 1" call plus SMS | COD | 70+ | ₹2.00 per call, ₹0.40 per SMS | The best calling option after 8 years. Sets the price floor |
| bSecure | WordPress.org | Checkout plugin with "automated confirmation calls" (type not stated) | COD | 60+ | Not checked | |
| Vrobo | WordPress.org | Automated robocalls | COD, failed delivery | 20+ | Not checked | |
| Robo IVR Call Pakistan (H3 Technologies) | WordPress.org | Automated calls | Order confirmation (Pakistan) | 10+ | Not checked | COD calling demand exists beyond India |
| SMS Alert | WordPress.org | SMS one-time code (can be limited to COD orders) | COD verification | 3,000+ | Not checked | Text is the default today |
| Notiqoo | WordPress.org | WhatsApp (COD code only in the paid Pro version) | COD verification | 1,000+ (free plugin) | Not checked | |
| Interakt (Jio Haptik) | WordPress.org | WhatsApp only | COD double-confirmation, carts | 800+; last updated 2025-07-22 | Not checked | Already reaches WooCommerce stores; its voice AI is Shopify-only |
| Smart COD | WordPress.org | No calls: COD rules and fees. AI risk scoring sold separately | COD rules | 30,000+ | AI scoring is a paid service | Largest COD plugin. A partner, not a rival |
| OrderGuard | WordPress.org, listed 2026-10-14 | No calls | COD collection and return risk | 300+ | Not checked | A new COD plugin reached 300+ installs in under 3 weeks |
| Shiprocket | WordPress.org shipping plugin, plus Engage360 and TwentyTwo | Plugin: no calls. Engage360: WhatsApp first, automated calls as fallback. TwentyTwo: AI voice, beta since June 2026 | Shipping, COD confirmation | 10,000+ (shipping plugin) | Engage360: monthly fee plus a per-order fee | Watch TwentyTwo. No WooCommerce integration named yet |
| WooCommerce.com Marketplace | WooCommerce's official store | None | None | 12 "voice" and 9 "COD" extensions; none makes outbound calls | – | The shelf is empty |
| **Shopify App Store** | | | | | | |
| CONSIO | Shopify, Oct 2024 | AI, inbound and outbound | Carts, support | 45 reviews | $30/month for 100 min plus $0.10/min ($60 for 400 min; $120 for 1,000) | The most-reviewed app built mainly for AI calls |
| Callsy; Loop | Shopify | AI calling | Carts | 15 and 10 reviews | Not checked | A crowded field |
| Breeze Buddy (Juspay) | Shopify, Dec 2025 | AI voice | COD and address check | 5 reviews | 75 calls free, then ₹9 per call. About ₹507/month for 500 calls; about ₹1,521/month for 2,000 | Price benchmark: ₹4–9 per call |
| Scalysis | Shopify, Aug 2026 | AI; "28+ Indian languages" (search tagline) | COD, carts, failed deliveries | 2 reviews | ₹3.3/min down to ₹2.375/min | Price benchmark per minute |
| BiteSpeed | Shopify only | WhatsApp plus AI voice | Carts, COD | 406 reviews. Says 100+ brands and a 65% connect rate | Not checked | Strong on Shopify; can't install on WooCommerce |
| GoKwik KwikEngage | Shopify app. GoKwik also ships its own WooCommerce checkout plugin, outside WordPress.org | WhatsApp plus AI calling | Carts; COD and failed-delivery calls being added | 299 reviews; over 1M calls a month | Not checked | A Bolna customer and the most likely to bring calling to WooCommerce. Present it as proof the use case works, not as an enemy |
| Interakt Voice AI Agent (Jio Haptik) | Shopify only, launched 2026-10-05 | AI voice | COD confirm, cancel and address change; carts | Not reported | Needs Interakt's own plan called "Shopify Premier" | The newest Shopify-only launch |
| Hillteck Verify COD Orders | Shopify | Automated calls plus WhatsApp | COD | 157 reviews | Not checked | Even old-style automated calling is used far more on Shopify |
| Robo Calls Pakistan (Devsol) | Shopify | Automated "press 1" call | COD | 134 reviews | Not checked | |
| **Voice platforms and logistics** | | | | | | |
| Vapi, Retell, Bland, Synthflow, Bolna | API platforms | Conversational AI | Any | No WooCommerce plugins; zero WooCommerce mentions in their docs indexes | – | A WooCommerce store needs a developer to use them. That is our opening |
| Exotel | Shopify app and docs | Automated calls, one-time codes | COD | No WooCommerce docs | Not checked | |
| ElevenLabs Agents | Shopify integration page | AI | Not applicable | WooCommerce page returns 404 | – | |
| Delhivery SmartNDR | Delhivery's own clients | WhatsApp, then up to 3 AI calls in Hindi or English | Failed deliveries | 6–10% lower COD RTO on average across clients | ₹5 per failed-delivery attempt | Works only with Delhivery shipments. Proves failed-delivery calls pay off |
| Caller.Digital | API vendor | AI calls | COD, carts | Says WooCommerce works through an "official plugin"; none found on WordPress.org | ₹8–25 per verified order (vendor's claim) | |

**Takeaways**
1. **Shopify is crowded and priced.** Several apps already sell AI calling with public prices.
2. **WooCommerce is empty.** On WordPress.org nobody doing AI calls has more than 10+ installs, and the best calling plugin is automated "press 1" calls with 70+.
3. **The real threats** are the companies that already reach WooCommerce stores and could add voice: GoKwik's checkout plugin, Interakt's 800+ installs, Shiprocket's TwentyTwo, and the early entrant ZenTalk.

---

## 6. Why Bolna (capabilities used)

Bolna already handles the hard parts behind a hosted HTTPS API: Indian phone networks, 12 Indian languages, tool calls during the call, and structured call results. That makes a no-Docker WordPress plugin possible.

**How a call flows (no Docker at any step)**
1. A WooCommerce event fires, through a hook or an Action Scheduler job.
2. The plugin (PHP) sends `POST api.bolna.ai/call` with the order data.
3. Bolna dials the customer through Plivo, Exotel or Vobiz.
4. During the call, Bolna's tools call the plugin's REST routes, protected by a secret header and a list of allowed Bolna IP addresses.
5. When the call ends, a webhook with status `completed` arrives.
6. The plugin updates the order status and adds a note with the recording.

| Bolna capability (docs) | How the plugin uses it | Rows |
|---|---|---|
| Hosted REST API at `https://api.bolna.ai` with a Bearer API key kept on the server ([auth](https://www.bolna.ai/docs/api-reference/authentication), [security](https://www.bolna.ai/docs/concepts/security)) | PHP calls it with `wp_remote_*`. Docker or Kubernetes matter only for enterprise on-premise installs or self-hosting the open-source core ([on-prem](https://www.bolna.ai/docs/enterprise/on-premise-deployments)) | All |
| Outbound calls: `POST /call` with `agent_id`, `recipient_phone_number`, `from_phone_number`, `scheduled_at`, `user_data` ([make call](https://www.bolna.ai/docs/api-reference/calls/make)) | Fired from WooCommerce hooks and Action Scheduler jobs. `user_data` carries the name, items, total, address and payment link | 4, 6–8, 12–13 |
| Order details in the prompt: nested variables such as `{{order.items.0.name}}`, plus automatic `current_date` and `timezone` ([context](https://www.bolna.ai/docs/prompting/using-context)) | Personalised scripts. Always send every variable: one left unfilled is read aloud as raw text | All |
| Custom-function tools: URLs with order-number placeholders (`/orders/%(order_id)s`), an auth header, and `pre_call_message`, a line spoken while the tool runs ([schema](https://www.bolna.ai/docs/tool-calling/custom-function-schema), [calls](https://www.bolna.ai/docs/tool-calling/custom-function-calls)) | Changes WooCommerce during the call: confirm or cancel, update the address, switch to prepaid, look up status | 6–8, 10, 14 |
| Inbound caller lookup: Bolna asks our endpoint about the `contact_number` before answering, with a 3-second limit ([docs](https://www.bolna.ai/docs/customizations/identify-incoming-callers)) | The agent knows the caller's latest order before they speak | 14 |
| Web call SDK `@bolna/web-call` v3.0.1. The session is created on the server, can be used once and lasts about 120 s ([SDK](https://www.bolna.ai/docs/developer-resources/sdks/web-call)) | "Talk to us" and "Track my order" buttons on the site. The API key never reaches the browser | 1, 2, 14 |
| Knowledge base built from PDFs or URLs, in English or multilingual mode ([KB](https://www.bolna.ai/docs/knowledge-base/add-sources)) | Shipping, returns, warranty and FAQ pages | 1, 15, 29 |
| 18 languages, 12 of them Indian. Automatic switching across English and 10 Indian languages; start language set per call ([languages](https://www.bolna.ai/docs/customizations/supported-languages), [switching](https://www.bolna.ai/docs/customizations/language-switching-behavior), [start language](https://www.bolna.ai/docs/customizations/start-call-in-language)) | Default language from the billing state; switches mid-call. Mixed Hindi-English words, numbers, order IDs and one-time codes don't trigger a switch | 25 |
| Indian phone networks: Plivo, Exotel and Vobiz (Twilio outside India), or the merchant's own phone line (SIP trunk). Numbers cost $5/month. Regulator-registered 140-series numbers for promotional calls, 160-series for service calls ([telephony](https://www.bolna.ai/docs/supported-telephony-providers), [regulated numbers](https://www.bolna.ai/docs/obtaining-regulated-phone-numbers)) | The merchant's own +91 number, with separate numbers for service and promotional calls | All |
| Extractions and call outcomes: preset options or free text, each with a confidence score ([extractions](https://www.bolna.ai/docs/prompting/extraction-output), [dispositions](https://www.bolna.ai/docs/api-reference/dispositions/overview)) | The call outcome sets the order status: confirmed, cancelled, address changed, prepaid accepted, or call later | 6–8, 13, 17 |
| Webhook on every call-status change, with `termination_reason` (for example `voicemail_reached`, or `number_blocked` for numbers on the DND list) ([webhooks](https://www.bolna.ai/docs/post-call/polling-call-status-webhooks), [reasons](https://www.bolna.ai/docs/post-call/termination-reasons)) | Writes the final order note and recording on `completed`; ignores duplicate deliveries using `id`; flags customers who are on the DND list | All outbound |
| `retry_config` (up to 3 retries); `calling_guardrails` (calling hours in the customer's time zone; Bolna's docs cite India's 9 AM–9 PM rule); automatic call-back at a time the customer asks for (beta, up to 14 days); keypad input ([retry](https://www.bolna.ai/docs/outbound/auto-retry), [guardrails](https://www.bolna.ai/docs/outbound/calling-guardrails), [call tab](https://www.bolna.ai/docs/agent-setup/call-tab)) | Delhivery-style retries; legal calling hours; honours "call me after 6"; order numbers typed on the keypad | 4, 6, 13, 14 |
| Workflows (beta): call, then retry, then a WhatsApp template sent through AiSensy (a WhatsApp messaging service); `reference_id` stops duplicate runs ([workflows](https://www.bolna.ai/docs/workflows/introduction), [AiSensy node](https://www.bolna.ai/docs/workflows/aisensy-whatsapp)) | The call-plus-WhatsApp sequence behind the best cart results (GoKwik, Cahoot) | 4, 8, 13 |
| Batch calling: upload a CSV, then schedule ([batch](https://www.bolna.ai/docs/outbound/batch-calling)) | Review, reorder and festive campaigns | 17, 18, 24 |
| Transfer to a human (`transfer_call` tool) ([docs](https://www.bolna.ai/docs/tool-calling/transfer-calls)) | Hands the caller to the owner or support staff | 10, 14 |
| Capacity: paid plans start at 10 calls at the same time and scale up with use. Extra outbound calls wait in a queue instead of failing; no limit on inbound calls ([concurrency](https://www.bolna.ai/docs/pricing/outbound-calling-concurrency)) | Sale-day spikes. GoKwik ran 250+ calls at once on Bolna | All |
| Ready-made agents for cart abandonment and COD confirmation, in English and Hindi ([COD agent](https://www.bolna.ai/docs/agent-templates/cod-confirmation-agent), [cart agent](https://www.bolna.ai/docs/agent-templates/cart-abandonment-agent)) | Fast to build at the hackathon: import them, then connect our tools | 4, 6 |
| Data stored in the US by default, with an option to keep it in India ([security](https://www.bolna.ai/docs/concepts/security)) | An option for Indian merchants who want their data kept in India | All |

**Pricing**
- **Verified rate:** Bolna charges ₹5.5/min self-serve and ₹1.75–2/min for large enterprise contracts (Inc42, quoting a Bolna cofounder).
- **Docs rate:** Bolna's docs list $0.06/min on "preferred models". Phone-network charges and a platform fee may be billed on top, and the docs contradict each other on this. Check the all-in rate in the Bolna dashboard before putting it on a slide.
- **Pilot packs:** 6,500 minutes for $300, or 12,000 minutes for $500.

**Bolna already does this for online stores (vendor-reported, on bolna.ai)**
- **Hypothesis:** 100,000+ cart-recovery calls and ₹2.5 Cr+ recovered, about ₹250 per call.
- **GoKwik:** 400,000 shoppers and 250,000 minutes, about 37 seconds per shopper, with 250+ calls at the same time.

**What it gives Bolna.** WooCommerce appears nowhere in Bolna's docs index. Bolna's online-store page tells merchants to "add a custom function" that calls their order API, which is a developer job. This plugin turns that into a one-click install. It makes WordPress.org a self-serve channel that sells Bolna minutes to 4.3 million WooCommerce stores, 1.2 lakh+ of them in India.

**Gotchas to design around**
- **Securing requests.** Bolna doesn't sign its tool calls or webhooks. Accept only Bolna's IP addresses (13.203.39.153, 13.126.9.249, 13.202.133.53) and require a secret header on every WordPress REST route.
- **Updating an agent's tools.** `PATCH /v2/agent/{id}` ignores tool changes, so update `api_tools` with `PUT`.
- **3-second lookup limit.** Keep the inbound lookup endpoint fast; look orders up by an indexed phone number.
- **Wait for `completed`.** Transcript, cost and extracted outcomes are still empty at `call-disconnected`. Outcomes are skipped entirely if the caller never spoke.
- **Voicemail detection** is off by default and can hang up on real people. Test it before turning it on.
- **Trial limits.** Trial accounts allow 2 calls at the same time, to verified numbers only. Indian numbers need compliance approval and take up to 3 minutes to activate.
- **Self-hosting is not needed.** If a merchant insists, the open-source core runs on Docker Compose (4 containers) or through `pip install bolna` (Python 3.10+) with Redis, a public endpoint and Twilio or Plivo. The plugin needs none of this.

---

## 7. Pitch kit

**YC-style one-liner**
> One-click AI phone agents for the 4.3 million WooCommerce stores, powered by Bolna.

- **For the YC form (50 characters or fewer):** "AI phone agents for WooCommerce stores" (38 characters).
- **Opening line:** "On Shopify, 23 of the first 24 results for 'ai voice agent' are calling apps. On WordPress.org, no AI phone-call plugin for WooCommerce has more than 10+ installs. We're fixing that."

**Submission description (185 of 200 characters, plain ASCII, counted with PowerShell `.Length`)**
> A WordPress plugin that gives any WooCommerce store Bolna AI phone agents: they confirm COD orders, recover carts and answer order-status calls in Hindi and English. No Docker, no code.

**Talking points**
1. **WooCommerce is the #1 store platform, not "70% of e-commerce".** It has 4,321,090 live stores against Shopify's 3,189,133 (Store Leads, Oct 2026), and 47.4% of sites running a known e-commerce system (W3Techs).
2. **Shopify got voice AI; WooCommerce got robocalls.** 23 of the first 24 Shopify results for "ai voice agent" are calling apps. On WordPress.org the top AI phone-call plugin has 10+ installs. The top COD-calling plugin is a 2018 "press 1" robocall with 70+ installs, at ₹2 a call.
3. **India is the entry market.** India has 1.2 lakh+ WooCommerce stores, WooCommerce's 4th-biggest country. Online retail there is $65–66B and growing more than 20% a year toward $170–180B by 2030 (Bain).
4. **Cash on delivery is still the default, and much of it comes back.** Only 41% of orders were prepaid in Jan–Nov 2025 across 635M+ orders shipped through ClickPost. About 26% of COD orders come back undelivered, against under 2% of prepaid, and up to 35% in Patna (Shipway, FY25).
5. **Every return costs real money.** ClickPost estimates one return on a ₹1,000 order costs ₹150–300 directly, or ₹450–900 counting ad spend. ₹545 crore of goods came back across ClickPost's brands in Oct–Nov 2025 alone.
6. **Speed decides the outcome.** 28% of orders that miss one delivery attempt end up returned; after three misses, 81% do (ClickPost). Our agent calls within minutes.
7. **Voice beats text.** Adding AI calls to WhatsApp recovered twice as many abandoned carts, and 60–65% of calls were answered (GoKwik, 30 brands, company data). Abandoned-cart emails turn only 1.51% of sends into purchases (Omnisend). Delhivery reports 6–10% lower COD returns from AI calls after failed deliveries, at ₹5 a call.
8. **Bolna already runs store calling at scale, and needs no server from us.** Hypothesis ran 100,000+ cart-recovery calls on Bolna and recovered ₹2.5 Cr+. GoKwik reached 400,000 shoppers with 250+ calls at once. Because Bolna is a hosted API, our whole product is a PHP plugin: no Docker.
9. **The costs work for a small store.** Bolna costs ₹5.5 a minute self-serve, against ₹7–8 for human calling before hiring and staff turnover (Inc42).
   - **Assumption:** about one minute per call, to be measured in a pilot. GoKwik on Bolna averaged about 37 seconds per shopper.
   - **Break-even:** a COD confirmation call pays for itself if it prevents one return in every 27–55 calls. That means cutting a ~26% COD return rate by about 2–4 percentage points, counting direct costs only.
   - **Staffing comparison:** one junior support hire (about ₹22–24k a month before tax) costs the same as roughly 4,000 minutes of Bolna calls.
   - These are our calculations from the Shipway, ClickPost, Inc42 and AmbitionBox figures.
10. **It speaks the customer's language.** About 57% of urban Indian internet users prefer content in regional languages (IAMAI-Kantar). Meesho's Hindi/English voice bot resolves 95% of 60,000 daily calls without a human. Bolna covers 12 Indian languages and switches mid-call.

**60-second demo**

| Time | On screen | What we say or play |
|---|---|---|
| 0:00–0:06 | Slide: "4.3M WooCommerce stores. Best AI calling plugin: 10+ installs." | "WooCommerce is the most-used store platform in the world, and it has no AI phone agents. On Shopify, 23 of the first 24 search results are calling apps." |
| 0:06–0:14 | WordPress admin, our plugin's settings, on ordinary shared hosting: Bolna API key, agent picker, switches for COD confirmation, cart recovery and the order-status line, language set to Hindi and English | "One plugin, one API key. No Docker, no server." |
| 0:14–0:18 | The store: place a ₹1,499 cash-on-delivery order with a teammate's phone number. The order shows On hold | "A cash-on-delivery order. About a quarter of these come back unsold." |
| 0:18–0:42 | Live call on speaker. The agent confirms the items and amount in English. The customer answers in Hindi and the agent switches. It reads back the address and pincode, takes a landmark, and offers ₹50 off to pay by UPI now. The customer agrees and the payment link arrives on the phone | Let the call play |
| 0:42–0:50 | Refresh the order: On hold changes to Processing. Note: "Confirmed by phone; landmark added; prepaid link sent", with the recording and outcome fields | "The agent updated WooCommerce during the call, through a Bolna tool. Nobody touched it." |
| 0:50–0:56 | 6-second clip: an inbound call, "Mera order kab aayega?" ("When will my order arrive?"). The agent recognises the caller's number and gives the status and delivery date | "The same plugin answers 'where is my order?', 24/7." |
| 0:56–1:00 | Closing card: "One plugin. No Docker. Powered by Bolna." | "A five-rupee call against a ₹150–300 return. WooCommerce, meet Bolna." |

**Demo prep**
- **Host the store publicly.** Use any public HTTPS WordPress host so Bolna's tools and webhooks can reach it. Running on localhost would need a tunnel, which contradicts the "no Docker, no tunnel" story.
- **Verify every demo phone.** Bolna trial accounts allow 2 calls at once, to verified numbers only. Verify each phone in advance, or use pilot credit.
- **Start from a template.** Use Bolna's English + Hindi COD-confirmation agent, with English as the start language and automatic switching on.
- **Cover tool delays.** Add a `pre_call_message` to each tool (for example "one second, updating your order").
- **Have a WhatsApp fallback.** WhatsApp through Bolna Workflows needs an AiSensy account and an approved message template. If approval doesn't arrive in time, send the payment link by SMS or show it on screen.
- **Caller ID.** Without `from_phone_number`, the customer sees a shared +91 number, which is fine for the demo. A dedicated Indian number needs compliance approval.
- **Record backups.** Record a backup video of the whole flow. Keep 10-second clips of the cart call (row 4) and the owner briefing (row 26) ready for questions.

**Likely judge questions**
- **"Isn't WordPress shrinking?"** Yes: its share of websites fell from 43.0% to 39.5% this year (W3Techs). It still has the most live stores (4.32M vs 3.19M), and those stores are mostly small. Those are the merchants who can't build voice integrations themselves.
- **"Why won't GoKwik, BiteSpeed or Interakt just do this?"** They might. GoKwik has its own WooCommerce checkout plugin, and Interakt has 800+ WooCommerce installs. Today their voice products are built for Shopify first, or for Shopify only. We win on WordPress.org distribution, an install with no setup, and being built on Bolna. Speed is the moat. Present GoKwik as a Bolna success story, not as a rival.
- **"Can't a store host Bolna itself?"** The documented route is four Docker containers. A pip install avoids Docker but still needs a Python server running all the time, Redis, a public tunnel and a Twilio or Plivo account. Shared WordPress hosting can't run that. Our plugin needs none of it.
- **"Isn't this just a robocall?"** A robocall can only take a key press. Our agent fixes the address, switches language, converts the order to prepaid and writes the result to the order. WooCommerce's best robocall plugin has 70+ installs after 8 years.
- **"Show me proof that COD calls cut returns."** Named-brand data on COD calls is thin, so we don't overclaim. The proof is strongest around delivery:
  - Delhivery reports 6–10% lower COD returns from AI calls after failed deliveries.
  - Human calls after failed deliveries cut RoroSaur's returns by 28%.
  - Velocity reports delivery completion improved by 11%.
  - Then add the break-even maths from talking point 9.
- **"What about TRAI rules and Do Not Disturb?"**
  - We keep service calls (COD, delivery, order status) separate from promotional ones (carts, campaigns).
  - We enforce legal calling hours with Bolna's guardrails.
  - When a call is blocked because the number is on the DND list (`number_blocked`), we record it on the order.

**Rules for numbers on stage**
- **Credit vendors.** Name the source on the slide. Say "reported by" for vendor results, and never add different vendors' numbers together.
- **Store Leads' base.** Its shares are "of the stores Store Leads tracks", not "of all online stores".
- **Logistics sources.** COD, return and failed-delivery figures come from logistics companies' own customers (ClickPost, Shipway, Velocity, Delhivery), not official statistics.
- **Precise wording:**
  - Say "Hindi and English", not "vernacular", for Meesho's assistant.
  - Say ".in websites", not "Indian websites", for W3Techs' 63.1%.
- **Off Duty connect rate.** Don't quote one: the PDF contradicts itself. Say 8,152 of 14,615 calls connected.
- **Ambiguous percentages.** Don't restate Velocity's "+11%" as "60% to 71%". Don't call Delhivery's "6–10%" percentage points. The sources don't say which they mean.

---

## 8. Numbers we dropped (unverified, out of date or superseded) and why

| Dropped number or claim | Why | Use instead |
|---|---|---|
| "More than 70% of e-commerce stores are built on WordPress" | Wrong. Store Leads finds WordPress on about 31% of the live stores it tracks | 4.32M WooCommerce stores, about 30% of tracked stores; 47.4% of sites with a known e-commerce system |
| "WordPress powers 43% of the web" | Out of date | 39.5% (W3Techs, 31 Oct 2026) |
| "WooCommerce dominates India"; "63% of Indian websites" | The trackers disagree: Store Leads puts Shopify slightly ahead. The 63% figure covers .in websites only | "1.2 lakh+ stores, neck and neck with Shopify"; "63.1% of .in sites with a known CMS" |
| Bolna case studies: Hypothesis "95,000 calls" and "₹1 Cr+"; GoKwik "150 concurrent calls" | These are the starting values of animated counters on the page, and an old page description | 100,000+ calls and ₹2.5 Cr+; 250+ calls at once |
| BiteSpeed "409 reviews" and "500+ enterprise brands"; Off Duty connect rate "57.8%" or "57.5%"; Off Duty "15–20% lower RTO risk" | Wrong review count. "500+" can't be traced. The PDF contradicts its own counts. "RTO risk" is undefined, with no baseline, and may be a generic claim | 406 reviews; 100+ brands; 8,152 of 14,615 connected (55.8%); keep the RTO-risk claim off slides |
| Competitor claims: "36 of 44 new COD plugins"; "none uses conversational AI"; "every COD calling plugin is a robocall"; "StoreVoiceAI leads"; "CONSIO is the most-reviewed voice app"; "GoKwik isn't on WooCommerce"; "Shiprocket has no AI voice"; "Interakt needs Shopify's Premier plan" | The counts can't be reproduced. ZenTalk uses AI. RevCent ties StoreVoiceAI at 10+. BiteSpeed has 406 reviews. GoKwik has a direct WooCommerce checkout plugin. Shiprocket's TwentyTwo is in beta. "Shopify Premier" is Interakt's own plan | The corrected wording in sections 3 and 5 |
| "Self-hosting Bolna requires Docker" | Docker is the documented route but not the only one | "Any self-hosted setup needs a Python server, Redis and a tunnel. Our plugin needs none of them" |
| COD at "60–65% of orders"; COD returns "25–30% vs 2–3% prepaid" (Razorpay and Amazon Shipping blogs citing ET Prime) | Second-hand, with no primary source | About 59% COD (ClickPost, our subtraction); 26% vs under 2% (Shipway) |
| "20% of packages fail the first delivery attempt"; failed-delivery rates of "15–30%"; return reductions of "20–45%" | Vendor press releases and blogs that don't explain their method | ClickPost's 28% / 81%; Velocity's ~60% vs 73% |
| "Where is my order?" at "25–40%", "30–50%" or "50–60% at peak" | Blogs citing each other, with no original study behind them | 21% / 36% (DigitalGenius, 2023, outside India), always with the date |
| Vendor web-page claims: Sarvam "30% of carts recovered"; Velocity Vani "27%+"; Convertway "14.88%"; Exotel "25% / 40% / 3x"; ClickPost's Parth agent "90% of failed deliveries" and "15–20% lower returns"; Ringg "3–4x"; Exotel with Zivame "up to 80%" | No named customer, time period or method | The named case studies in section 2 |
| Momentum and other collected figures: W3Techs WooCommerce share of e-commerce sites "51.1% to 47.4%"; Store Leads "WooCommerce down 10% a year vs Shopify up 15%"; HTTP Archive counts; WordPress.org "7M+ WooCommerce installs"; Brainstorm Force cart plugin "300,000+ installs"; ClickPost Oct 2026 "52% prepaid"; Store Leads company-size splits | Collected during research but never verified | Re-check at the source before quoting. Expect a question about WooCommerce's momentum and answer it with WordPress's 43.0% to 39.5% drop |
| Meesho "+162% regional-language use", "+40% voice search" and "961k sellers"; Bolna "store calls were about half of early usage"; Redseer shopper forecasts; Amazon, ONDC and Shiprocket seller counts; caller.digital's "Bolna + Shopify takes 3–5 weeks to build" and "Bolna ₹5.52/min vs Tabbly ₹6.80/min"; AfterShip India sales by platform (Magento "$746.51B"); dev.to "WooCommerce holds 65%"; W3Techs "88.4% of sites hosted in India" | Not verified, from a competitor's blog, implausible, unsourced, or inflated by where sites are hosted | Inc42's figures (in section 2) for Bolna pricing; otherwise don't use |