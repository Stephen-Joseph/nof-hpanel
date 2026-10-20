# Architecture Decision Records (ADRs)

## ADR 001: Monorepo Layout and Project Structure
- **Date**: 2026-10-19
- **Status**: Accepted
- **Context**: The project consists of a central web hosting control panel (Laravel), an autonomous server daemon (Go), deployment assets, and documentation.
- **Decision**: 
  - Keep the Laravel application at the repository root (`/app`, `/config`, `/database`, `/routes`, etc.) to streamline local PHP development and artisan commands.
  - Colocate the server daemon in `/agent` as an independent Go module.
  - Colocate systemd service units, node installer scripts, and configuration templates in `/deploy`.
  - Maintain all architectural, API, and module documentation in `/docs`.
- **Consequences**: Single source of truth across panel and agent, unified CI workflow, and direct deployment script access.

---

## ADR 002: Frontend Stack Selection (Blade + Livewire + Tailwind CSS)
- **Date**: 2026-10-19
- **Status**: Accepted
- **Context**: The panel requires rich, interactive administrative interfaces (real-time resource meters, async job progress, state badges, inline confirmation modals) while maintaining rapid development and tight integration with Laravel authorization policies and Eloquent models.
- **Decision**: Use **Blade + Livewire 3 + Tailwind CSS v4**.
- **Rationale**:
  - Livewire allows building dynamic, reactive UI components without managing complex API serialization layers or state drift between front-end and back-end.
  - Direct integration with Laravel authorization (`@can`, policies) and validation Form Requests.
  - Tailwind CSS v4 provides a fast, zero-runtime utility-first design system with native CSS variable support and modern theming.
  - Eliminates the operational overhead of a decoupled SPA frontend.

---

## ADR 003: Agent Security Model & Typed Action Registry
- **Date**: 2026-10-19
- **Status**: Accepted
- **Context**: The server agent runs with elevated privileges on target Linux nodes to manage system services, users, and vhosts. Any vulnerability in command execution could compromise the host.
- **Decision**:
  - **No arbitrary shell execution**: Laravel NEVER executes commands directly on target nodes; it communicates exclusively by enqueuing signed action payloads.
  - **Strict typed action registry**: The Go agent exposes only predefined typed actions (`account.create`, `vhost.write`, `ssl.issue`, etc.). Any unrecognized action is immediately rejected.
  - **Argument array execution**: All underlying OS binaries are executed with discrete argument arrays (`exec.Command(binary, args...)`), never passing user strings through `sh -c` or shell concatenation.
  - **Mutual authentication**: mTLS with certificate verification + HMAC-SHA256 payload signatures + 60s timestamp window + nonce replay protection.
  - **Private network binding**: Agent binds only to private or WireGuard interfaces.

---

## ADR 004: Code Quality & Static Analysis Strategy
- **Date**: 2026-10-19
- **Status**: Accepted
- **Context**: Consistent code style, type safety, and automated test enforcement are critical across both PHP and Go codebases.
- **Decision**:
  - **Panel**: Pest for feature and unit testing, Laravel Pint for automated styling compliance, Larastan (PHPStan) at **Level 6** for static analysis.
  - **Agent**: Standard `go test` for unit/integration tests and `go vet` for static analysis.
  - **CI**: Automated GitHub Actions workflow blocking merges on lint or test failures in either component.

