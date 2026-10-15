# MCP Server for Tronopay — Design Document

> Версия: 1.1 (revised after review) · Проект: Tronopay — Model Context Protocol server for client (task-creator) operations

## 1. Target & Scope

An **HTTP/SSE MCP server** embedded in the Symfony 8.1 application, exposing the
**same client-facing API** the existing REST API (API Platform) provides, but
through the Model Context Protocol so an LLM can act as the task creator:

- Create tasks, list/view them
- Retrieve invoice, result and attached files
- Cancel tasks, accept/reject worker results

The MCP server targets the **client role** (task creator) — the same actor that
hits the `POST /api/tasks`, `GET /api/tasks/{id}/invoice`, etc. endpoints today.
Workers and administrators are out of scope for this iteration.

## 2. MCP Protocol Essentials

MCP is a JSON-RPC 2.0 protocol with a well-defined lifecycle. The server advertises
its capabilities (`tools`, `resources`, `prompts`) and the client calls them.

### 2.1 Transport: Streamable HTTP (recommended for 2025+)

The **Streamable HTTP transport** (protocol version `2025-06-18` and later) uses
a single endpoint:

| Method | Content-Type | Purpose |
|--------|-------------|---------|
| `POST /mcp` | `application/json` | Send JSON-RPC request; receive `application/json` or `text/event-stream` |
| `DELETE /mcp` | — | Session teardown |

A `POST` that returns JSON is a standard request→response pair (used for
`initialize`, `tools/list`, `tools/call`, etc.). If the server needs to push a
notification later, the response switches to `text/event-stream` and streams SSE
events (server-initiated notifications like `resources/updated` or
`notifications/cancelled`). Our server is request-driven — every tool call is a
direct POST→JSON flow, which keeps things simple.

The legacy two-endpoint transport (`GET /sse` + `POST /message`) is also easy to
support and we can add it if a specific client requires it; the core JSON-RPC
handler is independent of the transport wrapper.

### 2.2 Lifecycle

```
Client                                   Server
  │                                         │
  │  POST /mcp ── initialize ────────────→ │ serverInfo, capabilities
  │ ←── { protocolVersion, capabilities }   │
  │                                         │
  │  POST /mcp ── notifications/initialized │ (no response)
  │                                         │
  │  POST /mcp ── tools/list ────────────→ │
  │ ←── { tools: [...] }                    │
  │                                         │
  │  POST /mcp ── tools/call ────────────→ │
  │ ←── { content: [...] }                  │
```

The handshake declares `serverInfo` (name `tronopay-mcp`, version from
`composer.json`) and `capabilities` (`tools`, `resources`, `prompts` as
applicable). After `notifications/initialized` the server is ready.

The canonical protocol uses capabilities keyed by the spec version:
```json
{
  "capabilities": {
    "tools": { "listChanged": false },
    "resources": { "subscribe": false, "listChanged": false },
    "prompts": { "listChanged": false }
  },
  "protocolVersion": "2025-06-18",
  "serverInfo": { "name": "tronopay-mcp", "version": "1.0.0" }
}
```

### 2.3 Tools (the core capability)

Each tool has:
- `name` — unique, snake_case identifier.
- `description` — one sentence; an LLM reads this.
- `inputSchema` — JSON Schema object describing the arguments.

`tools/call` returns `content: [{ type: "text", text: "<markdown or json>" }]`
or `{ type: "resource", resource: { uri, mimeType, text/blobs } }`. We'll use
`text` with JSON for structured data and Markdown for human-readable summaries.

### 2.4 Resources (read-only views)

Resources expose structured data through `resources/list` and `resources/read`.
We model each task and its sub-entities (invoice, result) as resources:
- `task://{id}` — the task entity (JSON)
- `task://{id}/invoice` — invoice info (JSON)
- `task://{id}/result` — result with file metadata (JSON)
- `task://{id}/result/files/{fileId}` — raw file (binary, with `mimeType`)

Resources let the LLM `resources/read` a task without invoking a tool, which
fits the MCP "resource" semantics better than a `get_task` tool. Tools stay for
state-changing operations (create, cancel, accept, reject).

### 2.5 Prompts (optional)

One or two templated prompts help the LLM get started:
- `create_task` — fill-in-the-blank template for task creation.
- `check_invoice` — template for a task whose payment is outstanding.

These are low-effort value-adds.

## 3. Architecture

### 3.1 Package Layout

All new code lives under `src/Mcp/`. No third-party MCP SDK is required:
JSON-RPC and SSE are thin enough to implement directly on Symfony primitives
(`Request`, `StreamedResponse`, `JsonResponse`).

```
src/Mcp/
├── McpBundle.php                   # Symfony bundle (optional; auto-config works without it)
├── Controller/
│   └── McpController.php           # Single route handler (POST /mcp, DELETE /mcp)
├── Server/
│   ├── McpServer.php               # JSON-RPC dispatcher, session mgmt
│   ├── InitializeHandler.php        # Handles initialize + notifications/initialized
│   └── CapabilityRegistry.php       # Collects capabilities from tagged services
├── Tool/
│   ├── ToolInterface.php            # Tool contract
│   ├── ToolRegistry.php             # Collects tagged tool services
│   ├── CreateTaskTool.php
│   ├── CancelTaskTool.php
│   ├── AcceptTaskTool.php
│   └── RejectTaskTool.php
├── Resource/
│   ├── ResourceInterface.php
│   ├── ResourceRegistry.php
│   ├── TaskResourceProvider.php     # task://{id}
│   ├── TaskInvoiceResourceProvider.php
│   ├── TaskResultResourceProvider.php
│   └── TaskResultFileResourceProvider.php
├── Prompt/
│   ├── PromptInterface.php
│   ├── PromptRegistry.php
│   ├── CreateTaskPrompt.php
│   └── CheckInvoicePrompt.php
├── Transport/
│   ├── TransportInterface.php
│   └── StreamableHttpTransport.php  # POST→JSON / SSE dispatcher
├── Dto/
│   ├── JsonRpcRequest.php
│   ├── JsonRpcResponse.php
│   └── JsonRpcError.php
└── Exception/
    └── McpException.php             # Mapped to JSON-RPC error codes
```

### 3.2 Service Layer Refactoring (Recommended)

Currently the business logic for client operations lives in API Platform state
processors/providers (under `src/Helper/`). To avoid duplicating it in MCP tools,
we extract a shared `App\Service\TaskClientApi` service. Both the API-Platform
processors/providers and the MCP tools delegate to it. This is a **small,
one-time refactoring** that pays for itself immediately.

```php
// New service — the single source of truth for client-side operations
namespace App\Service;

final class TaskClientApi
{
    public function __construct(
        private TaskRepository $tasks,
        private TaskService $taskService,
        private TaskPasswordVerifier $passwordVerifier,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private FileStorageInterface $storage,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function createTask(CreateTaskRequest $request): CreateTaskResponse;
    public function cancelTask(string $taskId, string $plaintextPassword): Task;
    public function acceptTask(string $taskId, string $plaintextPassword): Task;
    public function rejectTask(string $taskId, string $plaintextPassword): Task;
    public function getInvoice(string $taskId, string $plaintextPassword): GetTaskInvoiceResponse;
    public function getResult(string $taskId, string $plaintextPassword): GetTaskResultResponse;
    public function getTask(string $taskId): ?Task;   // public read, no password needed
    public function listTasks(int $page, int $per): array;  // returns list of Task + pagination
    public function streamResultFile(string $taskId, string $fileId, string $plaintextPassword): Response;
}
```

**Password verifier refactoring**: `TaskPasswordVerifier` currently reads from
`$request->headers->get('X-Task-Password')`. We add a plain-text variant:

```php
public function verifyPlaintext(Task $task, string $password): void
{
    if ('' === $password || !$this->passwordHasher->isPasswordValid($task, $password)) {
        throw new AccessDeniedHttpException('Invalid task password.');
    }
}
```

The existing `verify(Task, ?Request)` delegates to it after extracting the header.

### 3.3 Request Flow

```
POST /mcp (JSON-RPC body)
        │
        ▼
  McpController
        │
        ▼
  StreamableHttpTransport
        │  parse JSON-RPC envelope
        ▼
  McpServer.dispatch(method, params)
        │
        ├── "initialize"   → InitializeHandler
        ├── "tools/list"   → ToolRegistry.list()
        ├── "tools/call"   → ToolRegistry.call(name, args)
        ├── "resources/list"   → ResourceRegistry.list()
        ├── "resources/read"   → ResourceRegistry.read(uri)
        ├── "prompts/list"  → PromptRegistry.list()
        └── "prompts/get"   → PromptRegistry.get(name, args)
                │
                ▼
        TaskClientApi (shared service layer)
                │
                ▼
        EntityManager / Repository / TaskService
```

### 3.4 Symfony Integration

- **Route**: `#[Route('/mcp', methods: ['GET', 'POST', 'DELETE'])]` in `McpController`.
  Only `POST` and `DELETE` carry JSON-RPC; `GET` is for SSE setup (legacy transport).
- **No firewall**: MCP mirrors the public API — create/get/list are open,
  password-protected operations verify per-call. We may add `MCP_AUTH_TOKEN` later
  if throttling or audit trails are needed.
- **CORS**: The MCP endpoint must be added to `nelmio_cors.yaml` (or the existing
  Nelmio config's `allow_origin` pattern) so browser-based MCP clients can reach it.
- **Environment**: `MCP_ENABLED=1` (default) as a kill switch. `MCP_MAX_REQUEST_SIZE=1048576`
  for upload limits (file downloads are the inverse — server→client — not relevant here).
- **Docker**: exposed on port 80 via the existing `php-app` nginx container; no
  new port needed since it piggy-backs on the existing HTTP stack.
- **Serialization**: reuse `symfony/serializer` groups (`task:read`, `task:create`)
  via `AbstractNormalizer::GROUPS` so the JSON shapes match the REST API.

## 4. Tool Definitions

### 4.1 `create_task`

| Field | Value |
|-------|-------|
| Name | `create_task` |
| Description | Create a new task on Tronopay. Returns the task id, status, payment address and the required deposit in TRX. |
| Args | See schema below. |

**Input Schema** (JSON Schema):

```json
{
  "type": "object",
  "properties": {
    "statement": {
      "type": "string",
      "description": "Task description / statement. What needs to be done."
    },
    "password": {
      "type": "string",
      "minLength": 8,
      "maxLength": 64,
      "description": "Password to protect the task. Required for any later action on this task (get result, accept, reject, cancel). Minimum 8 characters."
    },
    "type": {
      "type": "string",
      "enum": ["mark", "verify", "classify", "compare", "photo", "video", "audio", "location", "delivery", "other"],
      "description": "Task type. Default: other."
    },
    "price": {
      "type": "string",
      "pattern": "^[0-9]+(\\.[0-9]+)?$",
      "description": "Price in TRX. Minimum 10 TRX. Example: \"25.5\" or \"100\"."
    },
    "location": {
      "type": "string",
      "description": "Optional coordinates in \"latitude, longitude\" format. Example: \"51.500675, -0.143105\"."
    },
    "refund_address": {
      "type": "string",
      "description": "Optional TRON address (base58) for refunds if the task is rejected. Without this, the escrow cannot be refunded automatically."
    },
    "webhook": {
      "type": "string",
      "format": "uri",
      "description": "Optional HTTPS URL to notify on status changes."
    }
  },
  "required": ["statement", "password"]
}
```

**Output** (JSON text in `content[0].text`):

```json
{
  "id": "01926a5e-...",
  "status": "created",
  "price": "25.000000",
  "paymentAddress": "TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t",
  "hint": "Please deposit 25.000000 TRX to the address \"TR7NHq...\" within 1 hour; otherwise, the task will be cancelled."
}
```

### 4.2 `cancel_task`

| Field | Value |
|-------|-------|
| Name | `cancel_task` |
| Description | Cancel (soft-delete) a task. Requires the task password. |
| Args | `task_id` (string, UUID), `password` (string) |
| Output | Task JSON with status=`canceled` |

### 4.3 `accept_task`

| Field | Value |
|-------|-------|
| Name | `accept_task` |
| Description | Accept the worker's result and release escrow to the worker. Requires the task password. Escrow payout happens later via the payout process. |
| Args | `task_id` (string, UUID), `password` (string) |
| Output | Task JSON with status=`accepted` |

### 4.4 `reject_task`

| Field | Value |
|-------|-------|
| Name | `reject_task` |
| Description | Reject the worker's result and request a refund. Requires the task password. Escrow refund happens later via the payout process. |
| Args | `task_id` (string, UUID), `password` (string) |
| Output | Task JSON with status=`rejected` |

### 4.5 `list_tasks`

_Implemented in addition to the original four tools_ — a client naturally needs a
way to enumerate its tasks.

| Field | Value |
|-------|-------|
| Name | `list_tasks` |
| Description | List tasks (newest first). Paginated. |
| Args | `page` (integer, default 1, min 1), `per` (integer, default 30, 1–100) |
| Output | `{ "tasks": [...], "total": N, "page": 1, "per": 30, "totalPages": N }` |

## 5. Resource Definitions

### 5.1 `task://{id}`

Returns a serialized Task entity (same shape as `GET /api/tasks/{id}` JSON, with
serialization group `task:read`). No password required — public metadata only
(id, status, type, statement, price, location, timestamps). Fields like password
hash, wallet index, and comments are excluded by `diffFormat()` rules.

### 5.2 `task://{id}/invoice`

Returns the invoice DTO. Password required: passed as query parameter
`?password=...` in the URI. The query params are extracted before resource
matching (the resource URI is `task://{id}/invoice`, not `task://{id}/invoice?password=`).

**Implementation note**: MCP `resources/read` passes a `uri` string. We parse it
with a simple router. For password-protected resources, the password travels in
the resource URI's query string. This is acceptable because MCP sessions are
local (localhost or trusted network); if the wire becomes untrusted, we'd add
session-level auth.

### 5.3 `task://{id}/result`

Returns `GetTaskResultResponse` JSON: id, status, result text, files array
(each with id, name, mimeType, size, download url). Password required.

### 5.4 `task://{id}/result/files/{fileId}`

Returns the raw file bytes with correct `mimeType`. Password required. This uses
the MCP resource content model:

```json
{
  "contents": [{
    "uri": "task://01926a5e.../result/files/01926a60...",
    "mimeType": "image/png",
    "blob": "<base64>"
  }]
}
```

Files are **always returned inline as `base64` in the `blob` field** — LLM clients
will analyze the contents (images, audio, video, text). No download-URL fallback:
MCP resources stay self-contained so a consuming agent never has to make a second
HTTP call just to fetch bytes.

## 6. Prompts

Two templates as starting points:

### `create_task` prompt

```json
{
  "name": "create_task",
  "description": "Create a new task with a natural-language description",
  "arguments": [
    { "name": "description", "description": "What needs to be done, in natural language", "required": true },
    { "name": "budget_trx", "description": "Budget in TRX, e.g. '50'", "required": true },
    { "name": "type", "description": "Task type: photo, video, verify, etc.", "required": false }
  ]
}
```

Renders to a message asking the LLM to craft a concrete `create_task` call.

### `check_task` prompt

```json
{
  "name": "check_task",
  "description": "Check the current state of a task",
  "arguments": [
    { "name": "task_id", "required": true },
    { "name": "password", "required": false }
  ]
}
```

Renders guidance to read the task resource, check invoice/result status, and
suggest next actions (pay, wait for worker, review result, accept/reject).

## 7. Error Handling

JSON-RPC errors are returned with standard codes:

| Code | Meaning | Example |
|------|---------|---------|
| `-32601` | Method not found | Unknown tool/resource |
| `-32602` | Invalid params | Missing required arg, bad UUID format |
| `-32603` | Internal error | Unexpected exception |
| `-32000` | Task not found | UUID valid but no such task |
| `-32001` | Invalid password | Wrong or missing task password |
| `-32002` | Invalid transition | Task status doesn't allow the requested operation (e.g. accepting a task that never completed) |
| `-32003` | Validation failed | Input validation errors (e.g. price below minimum) |

The `data` field of the error object includes a human-readable message and,
for validation errors, a list of field-level messages.

## 8. Implementation Plan

### Phase 1: Core Protocol Layer (≈4–6 hours)

1. Create `src/Mcp/Dto/JsonRpcRequest.php`, `JsonRpcResponse.php`, `JsonRpcError.php` —
   simple value objects with `fromPsrRequest()` / `toResponse()` factories.
2. Create `src/Mcp/Transport/StreamableHttpTransport.php` — parses the request
   body, delegates to `McpServer`, returns `JsonResponse` or `StreamedResponse`.
3. Create `src/Mcp/Server/McpServer.php` — JSON-RPC method router.
4. Create `src/Mcp/Server/InitializeHandler.php` — the handshake.
5. Create `src/Mcp/Controller/McpController.php` — `#[Route('/mcp')]` endpoint.
6. Add route, CORS, `MCP_ENABLED` env var.

**Test**: `curl -X POST http://localhost/mcp -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}'` returns capabilities.

### Phase 2: Service Layer Refactoring (≈2–3 hours)

1. Add `verifyPlaintext(Task, string)` to `TaskPasswordVerifier`. Rewrite
   `verify(Task, ?Request)` to call it.
2. Create `App\Service\TaskClientApi` with the methods listed in §3.2.
3. Rewire existing API Platform processors/providers to call `TaskClientApi`
   instead of inlining the logic (a handful of 5-line delegators).
4. Run the existing test suite to catch regressions.

### Phase 3: Tools (≈2–3 hours)

1. Create `ToolInterface` + `ToolRegistry` (tagged service collection).
2. Implement `CreateTaskTool`, `CancelTaskTool`, `AcceptTaskTool`, `RejectTaskTool`.
   Each is ≈15 lines: delegate to `TaskClientApi`, return JSON.
3. Register in `McpServer` — wire up `tools/list` and `tools/call`.

### Phase 4: Resources (≈2–3 hours)

1. Create `ResourceInterface` + `ResourceRegistry`.
2. Implement the four resource providers from §5.
3. Wire `resources/list` and `resources/read` in `McpServer`.

### Phase 5: Prompts (≈1 hour)

1. Create `PromptInterface` + `PromptRegistry`.
2. Implement `CreateTaskPrompt` and `CheckTaskPrompt`.
3. Wire in `McpServer`.

### Phase 6: Testing & Polish (≈2–3 hours)

1. Create `tests/Mcp/` with functional tests against the `/mcp` endpoint,
   full lifecycle: create → get invoice → (simulate payment) → get result →
   accept → verify status.
2. Test error cases: bad password, invalid transition, missing args.
3. Add `MCP_MAX_REQUEST_SIZE` and a rate limiter if needed.

### Total: ≈14–18 hours

## 9. Configuration Reference

```bash
# .env (new)
MCP_ENABLED=1
MCP_MAX_REQUEST_SIZE=1048576
```

```yaml
# config/packages/nelmio_cors.yaml (add path)
nelmio_cors:
    paths:
        '^/mcp':
            allow_origin: ['*']   # or same as API
            allow_methods: ['POST', 'DELETE', 'OPTIONS']
            allow_headers: ['Content-Type', 'Authorization']
```

No new `config/packages/mcp.yaml` is needed — autowiring + `#[AutoconfigureTag]`
for tool/resource/prompt registrations is enough. If we want to disable a
capability without code changes, we can add:

```yaml
# config/packages/mcp.yaml (optional)
mcp:
  capabilities:
    tools: true
    resources: true
    prompts: true
```

## 10. Security Considerations

1. **No authentication on the `/mcp` endpoint** — final decision: keep it open,
   exactly like the REST API today (create/get/list are public; protected
   operations verify the task password per-call). `MCP_AUTH_TOKEN` may be added
   later as an opt-in env var, but it is not part of this design.

2. **Task password in transit**: the password travels inside the JSON-RPC body
   (for tools) or the resource URI query string (for resources). Both are
   protected by TLS when the endpoint is behind HTTPS.
3. **File access**: the `task://{id}/result/files/{fileId}` resource is
   password-gated (same as the REST API's `GET /api/tasks/{id}/result/files/{fileId}`
   with `X-Task-Password` header).
4. **Rate limiting**: consider `symfony/rate-limiter` on `/mcp` to prevent abuse
   of the unauthenticated `create_task` call.

## 11. Decisions Resolution

| # | Question | Decision |
|---|----------|----------|
| 1 | Should the MCP endpoint require an API key? | **No.** Keep it open, identical to the REST API. `MCP_AUTH_TOKEN` may be added later as an opt-in env var. |
| 2 | Should file downloads inline base64 or return URLs? | **Always inline base64** — files are analyzed by LLM clients; resources stay self-contained. |
| 3 | Streamable HTTP vs legacy SSE transport? | **Streamable HTTP first** (POST→JSON). Add `GET /sse` + `POST /message` later if a client needs it; `McpServer` is transport-agnostic. |

## 12. Implementation Notes (as-built divergences)

The implementation shipped in `src/Mcp` and the shared `src/Service/TaskClientApi`
tracks this document with the following intentional differences:

1. **`AutowireIterator`, not `TaggedIterator`.** Symfony 8.1 renamed the attribute;
   the registries use `#[AutowireIterator('app.mcp.tool' | 'app.mcp.resource' | 'app.mcp.prompt')]`.
2. **`TaskClientApiInterface`.** The shared client service exposes an interface and
   the API Platform processors/providers type-hint it (instead of the concrete
   service), so they remain unit-testable. MCP tools/resources inject the concrete
   service.
3. **Password verification lives in `TaskClientApi`.** `TaskPasswordVerifier::verifyPlaintext()`
   is called inside the service, one place for the REST and MCP paths. The API
   Platform processors/providers only forward the `X-Task-Password` header value.
4. **Output JSON is camelCase**, mirroring the REST API exactly (e.g. `taskStatus`,
   `paymentAddress`, `priceTrx`) — no JSON-LD envelope, no snake_case.
5. **`list_tasks` tool added** (see §4.5) beyond the four originally specified.
6. **Route is `POST /mcp` + `DELETE /mcp`** only (no `GET`/SSE legacy path yet),
   and the endpoint always returns `application/json` — it bypasses API Platform's
   `application/ld+json` content negotiation entirely.
7. **Resource `read` returns the MCP `contents` array** of `{uri, mimeType, text|blob}
   items; files are always `blob` (base64) with no URL fallback, per Decision 2.