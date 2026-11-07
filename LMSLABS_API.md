# LMS Labs AI API: AI Science Experiment (mod_aiscience)

What lms-labs.com must provide for AI Science Experiment 1.3. All AI in the plugin goes through these two endpoints. There is no other AI option in the plugin: no Moodle AI subsystem and no customer API key.

## Authentication
- JSON POST, `Content-Type: application/json`.
- Header `Authorization: Bearer <site API key>`. The key never appears in the body or the URL.
- Every body carries `pluginId: "aiscience"`, `siteId` (the registered Moodle site URL; the plugin sends its wwwroot when no Site ID is set), `siteUrl` (the Moodle wwwroot) and `feature: "science"`.
- The Site ID and key are the same LMS Labs credentials used by the other plugins (shared through local_aiconfig when installed).
- Wrong or unknown Site ID/key: `401 {"error":"unauthorised"}`. A site that isn't allowed this product: `403`.

## POST /api/ai/status
Request: `{pluginId, siteId, siteUrl, feature: "science"}`

Response 200:
```json
{"connected": true, "balance": 250, "prices": {"plan": 1, "build": 10, "refine": 2, "repair": 0, "mark": 1, "suggest": 1}}
```
`balance` is the site's shared LMS Labs credit balance (the same pool as the other plugins). For a site with unlimited credits send `balance: -1` with `balanceState: "unlimited"`; the plugin shows it as unlimited. `-1` without that state means unknown. The plugin shows the prices on its buttons, so prices can change on the server without a plugin update.

## POST /api/ai/science
Request:
```json
{"pluginId":"aiscience","siteId":"...","siteUrl":"https://school.example","feature":"science",
 "task":"plan|build|refine|repair|mark|suggest","jobId":"<sha1>","requestId":"<sha1>",
 "prompt":"<full prompt text>","maxTokens":32000,"items":["claim","ev1"]}
```
- `jobId` groups an experiment build with its repair rounds. `requestId` is unique per call (safe to use for idempotency).
- `items` is sent only for `mark`: the answer keys in this student's report.

Response 200:
```json
{"text":"<model output>","creditsCharged":3,"charged":{"claim":1,"ev1":1,"why":1},"balance":247,"model":"<model name>"}
```

Errors:
- `402 {"error":"insufficient_credits","balance":n}`
- `401`/`403` as above
- `422 {"error":"bad_request","field":"..."}`
- `5xx {"error":"...","message":"<short reason>"}`

**Nothing is charged on any error.**

## Charging rules (server side; the server is the only place credits change)
| task | what it is | price | max output tokens |
|---|---|---|---|
| plan | lesson plan JSON from the teacher's brief | 1 | 8,000 |
| build | the full experiment page (HTML) | 10 | 32,000 |
| refine | rebuild from an earlier version with a teacher's instruction | 2 | 32,000 |
| repair | fix failed automated checks | 0 if the same `jobId` paid for a build or refine in the last 2 hours, at most 2 per job; otherwise charged as a build | 32,000 |
| mark | mark one student's answers (JSON keyed by answer key) | 1 per key in `items` that has a mark in the model's JSON reply | 200 + 150 per item, cap 4,000 |
| suggest | Claim-Evidence-Reasoning rubric suggestion | 1 | 800 |

- Charge only after the model answers successfully. For `mark`, parse the reply as JSON (strip ``` fences, take the first `{...}`), and charge only for keys that are in `items` and have a numeric `mark`. Return them in `charged`.
- Check the balance before calling the model: `402` if it is below the price (for mark, below the number of items).
- Take credits under a lock or in a single atomic update, so parallel requests can't overdraw.
- Refuse prompts over 200,000 characters (422). Enforce the token caps above, so the endpoint can't be used as a general-purpose AI.
- Use a strong coding model for build/refine/repair (long single-file HTML), and a fast model for mark/suggest/plan.
- Log each charge with siteId, task, credits, jobId and time, and show the usage in the customer's LMS Labs account as "AI Science Experiment".

## Product listing
- Product id `aiscience`, name "AI Science Experiment", Moodle™ activity plugin.
- Product page, docs page and quicklinks entry, with the same credit prices.

## QA account for the plugin's live test suite
- Create a test customer site, "AI Science Experiment QA", with 1,000 credits.
- Show its Site ID and API key to the developer in the Replit chat.
- Confirm the production API base URL (expected https://lms-labs.com).

## Go live
Put it through the normal release pipeline, publish, and reply with a summary of what changed.
