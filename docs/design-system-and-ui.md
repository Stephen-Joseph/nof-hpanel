# Design System, Theming, and UI Architecture

## 1. Overview
Phase 16 establishes a modern, cohesive, and accessible design system for the control panel:
- **Design Tokens**: Standardized color palettes, typography, spacing, border radii, and shadows implemented with Tailwind CSS v4's `@theme` directive and custom CSS variables.
- **Light & Dark Mode**: Persistent theme switching via `localStorage`, zero-flash initialization script (FOUC prevention), and automatic system preference (`prefers-color-scheme`) detection.
- **Responsive Navigation**: Collapsible desktop sidebar and accessible off-canvas mobile drawer with smooth slide-over transitions.
- **Reusable Blade Component Library**: Modular UI components for tables, badges, buttons, form inputs, modal dialogs, danger confirmation dialogs, toasts, stat cards, empty states, and code/log viewers.
- **Global Search & Command Palette**: Keyboard-driven (`Ctrl+K` / `Cmd+K`) quick search spanning panel navigation, domains, hosting accounts, and server nodes with strict multi-tenant isolation.
- **Customer & Role-Aware Dashboards**:
  - Customer view: 5 quick resource gauge tiles (Disk Storage, Monthly Traffic, Inodes, Websites, Databases), Quick Tools launchpad, and Active Domains table.
  - Admin view: Fleet health, node capacity bars, and administrative shortcuts.
  - Reseller view: Resource pool quotas (accounts, storage, bandwidth) and branding overview.

---

## 2. Theming and Color Tokens

### Tailwind CSS v4 Theme Configuration
Tokens are defined in `resources/css/app.css`:
```css
@theme {
    --font-sans: 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

    --color-brand-50: #eef2ff;
    --color-brand-500: #6366f1;
    --color-brand-600: #4f46e5;
    --color-brand-700: #4338ca;
}
```

### Light / Dark Mode Architecture
- **Instant Script**: Executed in `<head>` before the DOM renders to prevent unstyled flashes:
  ```html
  <script>
      if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
          document.documentElement.classList.add('dark');
      } else {
          document.documentElement.classList.remove('dark');
      }
  </script>
  ```
- **Toggle Control**: Invoking `window.toggleTheme()` updates both the `dark` class on the root element, saves the preference in `localStorage`, and fires a custom `theme-changed` DOM event.

---

## 3. Reusable Component Library (`<x-...>`)

All reusable UI elements reside in `resources/views/components/`:

| Component | Tag | Key Props / Features |
|---|---|---|
| **Card** | `<x-card>` | `title`, `subtitle`, `headerActions`, `footer`, rounded surface with dark/light borders |
| **Data Table** | `<x-table>` | `<x-table.header>`, `<x-table.th>`, `<x-table.row>`, `<x-table.cell>`, `pagination` |
| **Badge** | `<x-badge>` | `variant` (`success`, `danger`, `warning`, `info`, `primary`, `neutral`), `dot` (pulsing dot) |
| **Button** | `<x-button>` | `variant` (`primary`, `secondary`, `danger`, `success`, `outline`, `ghost`), `size`, `loading` |
| **Text Input** | `<x-input>` | `name`, `label`, `type`, `hint`, `prefixIcon`, `suffixIcon`, auto `$errors` integration |
| **Select** | `<x-select>` | `name`, `label`, custom chevron arrow, validation error handling |
| **Textarea** | `<x-textarea>` | `name`, `label`, `rows`, `monospace` |
| **Toggle Switch** | `<x-toggle>` | `name`, `label`, `description`, accessible `role="switch"` |
| **Checkbox** | `<x-checkbox>` | `name`, `label`, `description`, high-contrast checkmark |
| **Modal Dialog** | `<x-modal>` | `name`, `title`, `maxWidth`, Alpine.js escape key and focus trap handling |
| **Confirm Dialog** | `<x-confirm-dialog>` | Danger alert icon, title, description, confirm & cancel actions |
| **Toast Banner** | `<x-toast>` | Floating notification container listening to sessions & Livewire `notify` events |
| **Stat Card** | `<x-stat-card>` | `title`, `value`, `subtext`, `progress` (0-100%), color variant |
| **Code / Log Viewer** | `<x-code-block>` | Monospaced dark terminal box, syntax styling, copy-to-clipboard button |
| **Empty State** | `<x-empty-state>` | Centered icon, title, description, and action button slot |
| **Skeleton Loader** | `<x-skeleton>` | Animated pulse placeholders with configurable lines and heights |

---

## 4. Global Search & Command Palette

- **Invocation**: Triggered anywhere via `Ctrl+K` / `Cmd+K` or clicking the search box in the sticky topbar.
- **Search Capabilities**:
  - Panel Navigation: Fast keyword search for pages, tools, and security settings.
  - Domain Registry: Direct resolution to domain SSL, PHP, and redirect settings.
  - Hosting Accounts: Direct resolution to account details and file managers.
  - Server Fleet: Admin-only search across nodes and IP addresses.
- **Multi-Tenant Isolation**: Customers can only find domains and accounts belonging to their user ID; resellers can only find assets within their customer tree; root administrators search system-wide.

---

## 5. Dashboard Overview

The unified dashboard (`/dashboard`) automatically branches by authenticated user role:
- **Customer View**:
  1. Five resource metric tiles:
     - Disk Storage (GB used / limit, % progress gauge).
     - Monthly Traffic (GB used / limit, % progress gauge).
     - File Inodes (Count used / limit, % progress gauge).
     - Active Websites (Virtual hosts count / package cap).
     - MariaDB Databases (Schemas count / package cap).
  2. Launchpad: Quick shortcuts to File Manager, Databases, WordPress Installer, Git Deploy, Backups, Cron Jobs, Analytics.
  3. Hosted Websites Table: Displays domain, document root, PHP version, HTTPS active badge, and direct management links.
- **Admin View**:
  1. Infrastructure tiles: Total Servers (Active vs Draining), Accounts, Domains, Packages, Usage telemetry state.
  2. Server Fleet table: Node names, IPs, allocated accounts capacity bar, and status badge.
- **Reseller View**:
  1. Resource pool tiles: Allocated client accounts, aggregate customer storage, and monthly bandwidth pool.
  2. Direct link to Reseller Control Center and white-label domain status.

