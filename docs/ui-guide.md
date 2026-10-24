# UI & Design System Guide

This document outlines the UI architecture, design tokens, component library, and UX conventions implemented for HPanel, modeled after modern geometric dashboard interfaces.

---

## 1. Design Tokens & Theme System

All colors and spacing are managed via CSS custom properties in `resources/css/app.css` and mapped to Tailwind CSS v4 `@theme` tokens. **Components never use hardcoded hex values.**

### 1.1 Color Tokens

| Token Name | Tailwind Mapping | Light Mode | Dark Mode | Description |
|---|---|---|---|---|
| `--bg-app` | `bg-app` | `#F3F4F6` | `#080C14` | Page background behind the entire shell |
| `--bg-shell` | `bg-shell` | `#F7F8FA` | `#0D131F` | Outer rounded frame, sidebar, topbar |
| `--bg-card` | `card` | `#FFFFFF` | `#131B2B` | Cards, elevated surfaces, dropdown panels |
| `--border` | `border` | `#E5E7EB` | `#1F2A3D` | 1px border lines and dividers |
| `--text` | `text` | `#0F172A` | `#F1F5F9` | High-contrast body and title text (WCAG AAA) |
| `--text-muted` | `muted` | `#6B7280` | `#94A3B8` | Subtitles, labels, timestamps (WCAG AA) |
| `--primary-500` | `primary-500` | `#2563EB` | `#2563EB` | Main buttons, active nav, focus rings |
| `--primary-600` | `primary-600` | `#1D4ED8` | `#1D4ED8` | Hover state for primary buttons |
| `--primary-700` | `primary-700` | `#1E40AF` | `#1E40AF` | Darker gradient stop |
| `--primary-300` | `primary-300` | `#93C5FD` | `#3B82F6` | Soft highlights / progress fills |
| `--primary-100` | `primary-100` | `#DBEAFE` | `#1E3A8A` | Light badges and selection tints |

### 1.2 Gradients & Patterns

- **Primary Gradient (`--gradient-primary` / `.bg-gradient-primary`)**:
  - Light: `linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%)`
  - Dark: `linear-gradient(135deg, #2563EB 0%, #172554 100%)`
  - Used for: Primary buttons, the 1st highlighted stat tile, active primary tabs.
- **Card Dark Gradient (`--gradient-card-dark` / `.bg-card-dark-gradient`)**:
  - Light: `linear-gradient(145deg, #0c1626 0%, #060b13 100%)`
  - Dark: `linear-gradient(145deg, #091222 0%, #040810 100%)`
  - Used for: Feature cards (Time Tracker / Telemetry Readout).
- **Diagonal Hatch Pattern (`--hatch-pattern` / `.bg-hatch`)**:
  - `repeating-linear-gradient(45deg, ... 0, ... 2px, transparent 0, transparent 7px)`
  - Used for: Inactive capsule chart bars and gauge track backgrounds.

### 1.3 Status Chips (Semantic Colors)

| Status | Background Token | Text Token | Typical Hosting Usage |
|---|---|---|---|
| **Success** | `--status-success-bg` (`#DCFCE7` / `rgba(34,197,94,0.16)`) | `--status-success-text` (`#15803D` / `#4ADE80`) | Active, Issued, Running, Completed |
| **Info** | `--status-info-bg` (`#DBEAFE` / `rgba(59,130,246,0.16)`) | `--status-info-text` (`#1D4ED8` / `#60A5FA`) | In Progress, Primary Domain, SSL Pending |
| **Warning** | `--status-warning-bg` (`#FEF3C7` / `rgba(245,158,11,0.16)`) | `--status-warning-text` (`#B45309` / `#FBBF24`) | Pending Verification, Expiring Soon |
| **Danger** | `--status-danger-bg` (`#FEE2E2` / `rgba(239,68,68,0.16)`) | `--status-danger-text` (`#B91C1C` / `#F87171`) | Suspended, Failed, Quota Exceeded |

---

## 2. Typography & Component Geometry

### 2.1 Font Stack
- **Font**: `@fontsource/plus-jakarta-sans` loaded locally via npm (bundled in Vite for zero CDN latency and privacy compliance).
- **Fallback**: System sans-serif stack (`system-ui`, `-apple-system`, `BlinkMacSystemFont`, `Segoe UI`, `Roboto`).
- **Monospace**: `JetBrains Mono`, `ui-monospace`, `SFMono-Regular` for logs, hashes, and paths.

### 2.2 Corner Radii
- **Outer Shell Frame**: `rounded-[28px]` with a page margin (`p-2 sm:p-4 lg:p-6`).
- **Cards, Modals & Dropdowns**: `rounded-[20px]` (modals use `rounded-[24px]`).
- **Buttons, Inputs & Selects**: Fully pill-shaped (`rounded-full` / `9999px`).
- **Icon Buttons & Badges**: Circular (`rounded-full` / `w-9 h-9`, `w-8 h-8`).

---

## 3. Light / Dark Mode & Zero FOUC Architecture

### 3.1 Flash-Free Head Script
Every layout (`components/layouts/app.blade.php` and `layouts/guest.blade.php`) executes an inline script in `<head>` before stylesheets load:

```javascript
(function() {
    var theme = localStorage.getItem('theme') || localStorage.getItem('color-theme');
    if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
})();
```

### 3.2 Theme Toggle Button (`<x-ui.theme-toggle />`)
An Alpine.js component (`x-data`) that toggles the `.dark` class on `document.documentElement` and persists the selection in `localStorage` without triggering a Livewire round trip:

```blade
<x-ui.theme-toggle size="md" />
```

---

## 4. Reusable Component Catalog

All components are located in `resources/views/components/ui/` and `resources/views/components/layout/`.

### 4.1 Page Header (`<x-layout.page-header>`)
Provides the large bold geometric title, muted subtitle, and optional right-aligned action buttons slot.

```blade
<x-layout.page-header
    title="Domains &amp; Web Routing"
    subtitle="Manage virtual hosts, PHP versions, document roots, and SSL."
>
    <x-slot:actions>
        <x-ui.button variant="primary" href="{{ route('domains.create') }}">
            + Add Domain
        </x-ui.button>
        <x-ui.button variant="outline">Export DNS</x-ui.button>
    </x-slot:actions>
</x-layout.page-header>
```

### 4.2 Buttons (`<x-ui.button>` & `<x-ui.icon-button>`)
Pill-shaped action buttons with loading spinner support and accessible focus rings.

```blade
<!-- Pill button -->
<x-ui.button variant="primary" loading="save">Save Changes</x-ui.button>
<x-ui.button variant="secondary">Cancel</x-ui.button>
<x-ui.button variant="outline">Outlined</x-ui.button>
<x-ui.button variant="ghost">Ghost</x-ui.button>
<x-ui.button variant="danger" wire:click="terminateAccount">Terminate</x-ui.button>

<!-- Circular icon button -->
<x-ui.icon-button label="Delete database" variant="danger" wire:click="destroy">
    <svg class="w-4 h-4" ...></svg>
</x-ui.icon-button>
```

### 4.3 Stat Tile (`<x-ui.stat-tile>`)
Displays large metrics, circular arrow links, and trend badges. The first tile can be highlighted with the primary gradient.

```blade
<x-ui.stat-tile
    title="Websites"
    :value="$metrics['sites']['count']"
    trend-value="+2"
    trend-label="Increased from last month"
    :highlighted="true"
    :href="route('domains.index')"
/>
```

### 4.4 Form Controls (`<x-ui.input>`, `<x-ui.select>`, `<x-ui.textarea>`)
Pill inputs and rounded textareas with automatic Laravel error binding (`$errors->has($name)`), labels, and hint text.

```blade
<x-ui.input
    name="domain"
    label="Domain Name"
    placeholder="example.com"
    wire:model="domain"
    required
/>

<x-ui.select name="php_version" label="PHP Version" wire:model="phpVersion">
    <option value="8.3">PHP 8.3 (Recommended)</option>
    <option value="8.2">PHP 8.2</option>
</x-ui.select>

<x-ui.textarea name="public_key" label="SSH Public Key" rows="3" monospace wire:model="key" />
```

### 4.5 Data Table (`<x-ui.table>`)
Rounded card container with clean dividing borders and optional `<x-slot:thead>`.

```blade
<x-ui.table :headers="['Domain', 'Document Root', 'PHP', 'SSL', 'Actions']">
    @forelse ($domains as $domain)
        <tr class="hover:bg-[var(--bg-shell)]/50 transition-colors">
            <td class="px-6 py-4 font-semibold">{{ $domain->domain }}</td>
            ...
        </tr>
    @empty
        <tr>
            <td colspan="5" class="p-8">
                <x-ui.empty-state title="No domains found" />
            </td>
        </tr>
    @endforelse
</x-ui.table>
```

### 4.6 Empty State (`<x-ui.empty-state>`)
Dashed rounded card with centered icon, bold headline, and optional CTA slot.

```blade
<x-ui.empty-state
    title="No SSL Certificates Found"
    description="Issue an automated Let's Encrypt certificate or import a custom private key."
>
    <x-ui.button variant="primary" wire:click="openModal">Issue SSL</x-ui.button>
</x-ui.empty-state>
```

### 4.7 Modal Dialog (`<x-ui.modal>`)
Accessible Alpine dialog with backdrop blur, focus trap (`tab` navigation loop), `escape` key dismissal, and rounded 24px panel.

```blade
<x-ui.modal name="confirm-deletion" title="Delete Database" max-width="md">
    <p class="text-sm text-[var(--text-muted)]">This will drop the database and remove all stored records.</p>
    <x-slot:footer>
        <x-ui.button variant="secondary" @click="show = false">Cancel</x-ui.button>
        <x-ui.button variant="danger" wire:click="deleteDatabase">Confirm Deletion</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
```

### 4.8 Charts & Custom Visualizations
- **`<x-ui.capsule-bars>`**: Pure Blade + CSS capsule bars with hover states and active percentage pill tooltips.
- **`<x-ui.progress-gauge>`**: SVG semi-circle gauge with diagonal hatch background and concentric progress arcs.

---

## 5. Navigation & Keyboard Shortcuts

- **Command Palette (`Ctrl+K` / `Cmd+K`)**: Opens search modal to quickly jump between Domains, Databases, Logs, Backups, and Servers.
- **`wire:navigate`**: Used on sidebar links and action buttons for SPA-like transitions without full page reloads.
- **Theme Event**: Fires window event `theme-changed` with `{ dark: boolean }` when toggled.

