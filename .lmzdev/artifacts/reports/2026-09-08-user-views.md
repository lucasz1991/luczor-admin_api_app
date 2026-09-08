# Luczor Benutzeransichten aus RailTime

## Umgesetzt

- Interaktive Administratorenliste unter `/admin/users`: Suche nach Name/E-Mail, Rolle und Kontostatus, sichere Sortierung, 15/30/50 Einträge pro Seite, deutsche Pagination, Personenzeilen mit Initialen, Rollen/Status und Geräte-/Projektzahlen. Anlage normaler Benutzer direkt in einem ausklappbaren Formular.
- Administratives Benutzerprofil unter `/admin/users/{id}`: Identitätskarte, Kontodaten und Nutzung/Kosten der letzten 30 Tage. Kosten ohne Meldung bleiben sichtbar unbekannt. Datenabfragen sind an den ausgewählten Benutzer gebunden; keine Chattexte werden eingeblendet.
- Eigenes Profil unter der bestehenden Jetstream-Route `/user/profile`: Identität, Name/E-Mail als Livewire-Modul und vorhandener Fortify-Passwortpfad mit beschrifteten Feldern.
- Gemeinsamer Service für bisherige HTTP-Formulare und neue Livewire-Aktionen. Aktive/verifizierte Administratorrolle wird bei jedem Livewire-Request erneut geprüft. Administratorkonten sind in der Benutzerverwaltung unverändert geschützt. Neue Benutzer können durch manipulierte Formularfelder keine Administratorrolle erhalten.
- Die Profil-ID ist mit Livewire `Locked` gebunden. Eigene Profiländerungen verwenden die vorhandene Fortify-Aktion und erhalten die E-Mail-Neuverifikation. Passwortänderungen erfordern weiter das aktuelle Passwort. Adminänderungen werden mit dem vorhandenen `AuditLogger` protokolliert; Passwörter sind nicht Teil des Audits und werden nach einem Anlageversuch aus dem Livewire-Zustand entfernt.

## Tatsächlich übernommene RailTime-Quellen

Quelle ausschließlich lesend: `C:/xampp/htdocs/RailTime/App`.

- `app/Livewire/Admin/Employees.php`: Filterzustand, Reset der Pagination, Sortier-Whitelist und begrenzte Seitengrößen wurden in `app/Livewire/Admin/Users.php` adaptiert.
- `resources/views/livewire/admin/employees.blade.php` und `components/tables/rows/employees/employee-row.blade.php`: Filterleiste, Personenzeilen, Status-Badges, responsive Aufteilung und Profilaufruf als Grundlage der neuen Liste.
- `app/Livewire/Profile/ProfileIdentityCard.php`: Fortify-basierter Identitätseditor, Synchronisierung und öffentliches Speichern-Ereignis in der gleichnamigen Luczor-Komponente adaptiert.
- `resources/views/livewire/admin/user-profile.blade.php` und `admin/user-profile/partials/identity-card.blade.php`: Identitätskarte mit Person/Eckdaten und gegliederte Profilbereiche.
- `resources/views/components/ui/badge.blade.php` und `ui/page.blade.php`: Komponentenstruktur übernommen und in `components/user-ui/` auf Luczor-Farben und vorhandene Seitenschale reduziert.

RailTime-spezifische Personal-, Lohn-, Team-RBAC-, Dokument-, Videoanruf-, Bilderupload- und Tracking-Abhängigkeiten wurden nicht in Luczor importiert. Luczor besitzt dafür keinen entsprechenden Datenvertrag. Die Quelle blieb unverändert; keine Framework- oder Paketupgrades.

## Prüfung

- `php artisan test --compact tests/Feature/UserWorkspaceViewsTest.php tests/Feature/AccountDeviceConnectionTest.php`: 13 Tests, 82 Assertions bestanden.
- Nach deutscher Pagination: eigene UserWorkspaceViews-Suite erneut 8 Tests, 55 Assertions bestanden.
- Fokussiertes PHPStan: fünf geänderte/neue Produktions-PHP-Dateien ohne Fehler.
- Fokussiertes Pint bestanden; letzter neuer Testimport automatisch formatiert.
- `git diff --check` für eigene Änderungen ohne Whitespacefehler.
- Root prüft die visuellen Ansichten im isolierten Preview auf Port 9032. Suche und Benutzerliste dort bereits funktional bestätigt; weitere Browserergebnisse gehören zum Root-Abschluss.

## Integration und Abgrenzung

Root hat die beiden GET-Routen und die Navigationslinks ergänzt. Die Benutzeransichten sind lokal implementiert; keine Produktivmigration, reale Kontoanlage, Benutzer-Mail, externe API oder Änderung an bestehenden Daten wurde von diesem Arbeitspaket ausgelöst.

Livewire-Version aus composer.json: 3.8.2; Laravel 12.61.1. Abgleich der verwendeten APIs mit offizieller Version-3-Dokumentation: https://livewire.laravel.com/docs/3.x/locked und https://livewire.laravel.com/docs/3.x/pagination.
