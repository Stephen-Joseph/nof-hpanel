# Installer & Provisioning Progress Module

## 1. Overview
This module provides real-time progress visualization and interactive user feedback across two critical environments:
1. **Terminal Installer (`deploy/install.sh`)**: An ANSI-powered, TTY-detecting terminal installation script featuring live step counters, percentage bars, elapsed time counters, active command log tailing, error traps, and an ASCII summary table.
2. **Web Control Panel (Laravel Livewire + Blade)**: Live multi-stage pipeline steppers (`<x-ui.progress-steps>`), segmented progress bars, database-backed `provisioning_steps` records, 2-second reactive polling (`wire:poll.2s`), and optional client-side mini-games (`<x-ui.while-you-wait>`) to keep users engaged during longer server tasks.

---

## 2. Terminal Installer (`deploy/install.sh`)

### Features & Flags
- `--dry-run`: Runs an end-to-end simulation without root permissions or modifying system packages.
- `--no-progress`: Disables ANSI terminal animations and spinners; falls back to standard log lines (CI / headless environments).
- `--quiet` / `-q`: Suppresses all progress bars and step messages, printing only critical errors or warnings.
- Output log redirection: Redirects noisy package manager output (`apt`, `composer`) to `/var/log/hpanel-install.log` (or `/tmp/hpanel-install.log` if unprivileged).
- Live Log Tailing: The last line of the installation log is dynamically extracted and displayed alongside the progress bar.
- Summary Table: Renders a structured ASCII table listing every step name, duration, and final status code (`OK` / `FAIL`).

### Example Execution
```bash
# Interactive run with live progress bar and spinners
sudo bash deploy/install.sh --domain panel.myhost.com

# Non-interactive / Piped / CI run
bash deploy/install.sh --dry-run --no-progress
```

---

## 3. Web Provisioning Progress Pipeline

### Data Model: `provisioning_steps`
Table schema:
- `id`: Auto-incrementing primary key.
- `job_id`: Foreign key reference to `agent_jobs.id` (UUID).
- `key`: String identifier (e.g. `create_user`, `directories`, `quota`, `php_pool`, `vhost`, `ssl`, `database`).
- `label`: Human-readable description (e.g. "Create isolated Linux user").
- `status`: Enum (`pending`, `running`, `done`, `failed`).
- `started_at`: Timestamp when the step initiated.
- `finished_at`: Timestamp when confirmed done or failed.
- `message`: Diagnostic log message or agent confirmation.

### Stepper Component (`<x-ui.progress-steps>`)
- Segmented progress bar with blue fill (`from-blue-600 to-sky-400`).
- Step icons:
  - `pending`: Hollow ring with muted border.
  - `running`: Animated spinning SVG loader.
  - `done`: Solid blue checkmark badge.
  - `failed`: Solid red X badge with interactive retry button.
- Dynamically estimates time remaining based on average duration of completed stages.
- Orientations supported: `horizontal` (standard overview) and `vertical` (compact/mobile views).

---

## 4. Interactive "While You Wait" Mini-Games

Lightweight, self-contained Alpine.js mini-games located in `resources/js/games/`:
- **Memory Match (`memory.js`)**: 4x4 card grid featuring hosting icons (Server, Globe, Database, Shield, Cloud, Lock, Lightning, Rocket), move counter, timer, and score calculation.
- **Snake (`snake.js`)**: HTML5 canvas snake game with keyboard (WASD / Arrows) and touch D-Pad controls, food generation, score counter, and wall wrap-around.
- **Typing Test (`typing.js`)**: 30-second typing test of hosting terms (`nginx`, `php-fpm`, `certbot`, `mariadb`, `systemd`, `wireguard`, `redis`), calculating real-time WPM and accuracy percentage.
- **Reaction Test (`reaction.js`)**: Reflex testing tool that transitions from waiting (amber) to active (green) at random delays, measuring reaction time in milliseconds.

### Behavior & Accessibility
- State persistence: User toggle preference (`hpanel_play_while_wait`) and high scores are preserved in `localStorage`.
- Zero external dependencies: Pure vanilla JavaScript and HTML5 canvas; no third-party game frameworks.
- Tab Visibility: Pauses automatically when the browser tab is hidden (`document.hidden`).
- Job Completion Hook: Automatically pauses gameplay and displays a celebration banner when the background task completes.
- Dimensions: Hard-capped at ~320px height to prevent displacing primary server metrics and steppers.

---

## 5. Failure Recovery & Error Trapping
- On installer error, the script traps `ERR`, halts execution, identifies the failing step, and prints the last 20 log lines from `/var/log/hpanel-install.log`.
- On web panel failure, the specific failing step transitions to `failed` status, and an inline `Retry` action is presented to re-dispatch the job.

