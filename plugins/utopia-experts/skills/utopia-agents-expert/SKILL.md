---
name: utopia-agents-expert
description: Expert reference for utopia-php/agents — provider-agnostic AI agents library supporting OpenAI/Anthropic/Gemini/Deepseek/Perplexity/XAI/OpenRouter. Consult when building "Appwrite Assistant", tool calling, or SSE streaming to Realtime.
---

# utopia-php/agents Expert

## Purpose
Provider-agnostic PHP library for building AI agents with conversation history, streaming, attachments, and structured-output schemas across OpenAI, Anthropic, Gemini, Deepseek, Perplexity, XAI, and OpenRouter.

## Public API
- `Utopia\Agents\Agent` — orchestrator with instructions, description, schema
- `Utopia\Agents\Conversation` — multi-turn history, `listen()` for SSE callbacks, `send()` returns final Message, token counters
- `Utopia\Agents\Adapter` (abstract) — provider interface; `send()`, `getInputTokens()`, `supportsAttachment()`, attachment limit methods
- `Utopia\Agents\Adapters\{OpenAI, Anthropic, Deepseek, Perplexity, XAI, Gemini, OpenRouter}`
- `Utopia\Agents\Message` — content + role + attachments + MIME detection
- `Utopia\Agents\Role` + `Roles\{User, Assistant}`
- `Utopia\Agents\Schema\Schema` + `SchemaObject` — JSON Schema for structured output

## Core patterns
- **Adapter pattern** — one provider per adapter, model picked via class constants (`OpenAI::MODEL_GPT_4O`, `Anthropic::MODEL_CLAUDE_4_SONNET`)
- **`Conversation::listen(callable)`** — non-blocking token-delta callback for SSE streaming; `send()` still returns the aggregated final `Message`
- **Instructions are a keyed array** (`description`, `tone`, …) — renderable as system prompt
- **Attachment validation delegated to the adapter** (`getMaxAttachmentBytes`, `getAllowedAttachmentMimeTypes`, `supportsAttachment`) — per-provider limits
- **Token accounting includes cache creation/read** (Anthropic prompt caching) — exposed on `Conversation`

## Gotchas
- **Adapter picks the model, not Agent** — swapping models means constructing a new adapter; no hot-swap mid-conversation
- **Attachment defaults**: 10 per message, 5 MB each, 20 MB total, image MIME allowlist. Non-image attachments (PDFs, audio) require subclassing the adapter to widen `getAllowedAttachmentMimeTypes()`
- **`Conversation::send()` is synchronous HTTP** via `utopia-php/fetch` — blocks worker threads; use `listen()` to at least stream progress
- Requires PHP 8.3+ and `utopia-php/fetch 0.5.*` — newer than some Appwrite services

## Appwrite leverage opportunities
- **Tool/function calling layer**: `Schema` currently targets structured output, not tool definitions. Build a `Tool` class + adapter `toolCall()` path so Appwrite can expose `listDocuments`, `createUser`, etc. as callable tools — unlocks "Appwrite Assistant" that actually mutates projects
- **Anthropic prompt caching metrics**: `Conversation::getCacheCreationInputTokens()` and `getCacheReadInputTokens()` already exist — pipe these into `utopia-php/telemetry` so per-project AI spend dashboards show cache hit rate and can alert on regressions
- **SSE bridge to Appwrite Realtime**: wrap `listen()` so deltas publish to a Realtime channel — browser clients get agent streaming without a custom SSE endpoint
- **Per-project attachment limit override**: subclass adapters with tier-based limits (free: 1 MB/att, pro: 10 MB) by overriding `getMaxAttachmentBytes()` — cleaner than validating ad-hoc at controller level

## Example
```php
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;

$adapter = new Anthropic($apiKey, Anthropic::MODEL_CLAUDE_4_SONNET, maxTokens: 2048);
$agent = (new Agent($adapter))->setInstructions([
    'description' => 'Appwrite support bot. Cite docs.',
    'tone' => 'concise, friendly',
]);

$conversation = new Conversation($agent);
$conversation
    ->listen(fn (string $chunk) => print($chunk))
    ->message(new User('u1', 'Jake'), new Message('How do I enable TOTP MFA?'))
    ->send();

$cacheHitRate = $conversation->getCacheReadInputTokens() / max(1, $conversation->getInputTokens());
```
