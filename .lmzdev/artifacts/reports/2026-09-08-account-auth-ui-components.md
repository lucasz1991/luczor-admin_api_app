# Account, Auth and Workspace component redesign

## Delivered

- Six authentication views use the common UI input/button system, clear German labels, visible status/error messages and the existing routes, CSRF tokens, autocomplete and posted field names.
- Account devices use shared page/panel/stat/tab/table/input/select/button components. Tabs separate devices, cost records and connection instructions. Device ownership, revocation and master selection forms are unchanged.
- Device pairing has a clear device/account summary and the original explicit confirmation action.
- The web workspace opens directly on the conversation, including on mobile. Separate Chat, All Chats and Devices sections avoid scrolling through unrelated lists before reaching the composer. Opening/creating a chat requests the conversation tab through the common tab event. Device selection remains in the composer; workspace/personal modes and scope wording remain distinct.
- Profile data and password management have separate tabs. A password validation failure opens the security section. The admin profile retains server-side selectTab rendering for overview/account/usage; no duplicate client-side source of truth.
- User list, identity cards, creation/edit forms, empty states, stats, usage tables and pagination use shared tokens/components. Existing user-ui page/badge components now adapt to the common ui namespace rather than duplicating styles.
- Added a small user-ui.resource-row for selectable workspace device/chat records. Native checkboxes and hidden fields retain their existing boolean submission contracts.

## RailTime provenance (read-only source)

From C:/xampp/htdocs/RailTime/App:
- resources/views/livewire/admin/employees.blade.php: structured search/filter bar, readable person rows and pagination.
- resources/views/livewire/admin/user-profile/partials/identity-card.blade.php: person/eckdaten composition, role/status grouping and compact mobile identity.
- resources/views/livewire/profile/profile-tab-content.blade.php and components/ui/accordion/tabs.blade.php: grouped personal/security areas and tab navigation.
- resources/views/components/ui/page.blade.php and components/ui/forms/input.blade.php: attribute-bag controls, named action slots and the shared page hierarchy.
- Authentication forms: labelled/autocomplete form structure with clear feedback retained, adapted to the existing Luczor routes.

The prior RailTime-derived Livewire PHP modules remain in place; this task changes their views only. No RailTime files, Livewire/PHP business logic, migrations, Tauri source or production data were changed.

## Verification

- php artisan test --compact tests/Feature/UserWorkspaceViewsTest.php tests/Feature/AccountDeviceConnectionTest.php tests/Feature/WebWorkspaceTest.php
- Result: 23 tests / 152 assertions passed, including user/admin authorization, account/device connection and workspace scope behaviour. Repeated after pagination component integration with the same result.
- Scoped git diff --check passed.
- Shared component asset build and desktop/mobile browser verification are coordinated by root, not claimed as completed by this agent.

## Coordination

Source ownership handed back to root. HEAD advanced externally to b51ca97 during this work and already includes the initial device/adapter changes; this agent did not commit or revert those changes.

