# Patches

Every way this fork differs from `Vatsim-Scandinavia/events`, and how you find out when
upstream breaks one.

Current upstream: see `.events-upstream-version` (`v2.0.0-rc.3`).

## The rule

**Prefer an added file over a modified one.** Every modified file is a conflict on some
future release. An added file is free, because upstream never touches a path it does not
know about.

New settings go in `config/vatssa.php`. New tests go in `tests/Feature/Vatssa*`. Mark every
change to an upstream file with a `VATSSA:` comment where the language allows it.

Every entry below names its **detector**: what tells you upstream has moved underneath it.
**Update this file in the same commit as any fork change.**

| | Count |
|---|---|
| Upstream files modified: code and config | 12 |
| Upstream files modified: frontend | 9 |
| Upstream files modified: tests | 7 |
| Upstream files modified: docs | 2 |
| Upstream files deleted | 7 |
| Files added | 9 |

Check the totals at any time:

```bash
git diff --name-status "$(cat .events-upstream-version)" HEAD | cut -c1 | sort | uniq -c
```

---

## Patches to upstream behaviour

### 1. Everything applies across the hub

VATSSA is one division with one roster: active in one area means you may control in all of
them. Upstream gives roles per FIR, and maps one Control Center area to one FIR.

| File | Change |
|---|---|
| `config/vatssa.php` (added) | `hub_wide`, on unless `VATSSA_HUB_WIDE=false` |
| `phpunit.xml` | Sets `VATSSA_HUB_WIDE=false`, so upstream's tests keep testing upstream's behaviour |
| `app/Auth/Roles.php` | `hubWideOnly()`; a role can only be offered across the hub |
| `app/Http/Controllers/Admin/RoleAssignmentController.php` | Refuses a role in one FIR; wording of the automatic-role error |

**Detector:** `tests/Feature/VatssaHubWideRolesTest.php`.

**On conflict.** Any new place upstream assigns a role must respect `Roles::hubWideOnly()`.
Search for `fir_id` writes in anything upstream added.

### 2. Controller role from the Control Center roster

Anyone with `atc_active` in **any** CC area, or a visiting endorsement for any area, gets one
hub-wide Controller role. The area map is not used. Losing both takes the role away at the
next hourly sync, subject to the removal limit.

| File | Change |
|---|---|
| `app/Integrations/ControlCenter/RosterSync.php` | `hubWide()`; one hub-wide grant; no area warnings; replaces any FIR-scoped automatic roles |
| `app/Integrations/ControlCenter/Coverage.php` | Any area covers every FIR; rating checks stay |
| `app/Integrations/ControlCenter/ControlCenter.php` | Areas and FIRs is optional in hub-wide mode |

**Detector:** `tests/Feature/VatssaHubWideRosterSyncTest.php`.

**On conflict.** This is the riskiest patch in the fork. If upstream changes `plan()`, check
that the removal key still treats a hub-wide role as wanted, or every sync will remove and
re-add it. Keep the mass-removal brake (`SyncContext::mayRemove`). Proven on staging 21 Nov
2026: grant, held removal, approved removal.

### 3. Division Staff role

The built-in "vACC Staff" is "Division Staff". Done in code before the first deploy, because
`roles:sync` recreates built-ins by name on every start: renaming it later would leave an
orphan role row.

| File | Change |
|---|---|
| `app/Auth/Roles.php` | `VACC_STAFF = 'Division Staff'`. The constant keeps upstream's name on purpose |
| `resources/js/components/staffing/open-interest.tsx` | Label |
| `GLOSSARY.md`, `README.md` | Label |

**Detector:** upstream's own role tests, with the expected name patched (see Test files).

**On conflict.** Never change this string on a live hub without migrating the role row.

### 4. VATSSA wording

"vACC" becomes "division" in text members and admins read. The Handover sign-in button reads
"VATSSA". Automatic roles are described as coming from Control Center.

| File | Change |
|---|---|
| `app/Enums/SignInProvider.php` | Handover label |
| `app/Http/Requests/SiteSettingsRequest.php` | Validation message |
| `app/Integrations/Discord/Discord.php` | Description |
| `resources/js/pages/Admin/Firs/Index.tsx` | Description |
| `resources/js/pages/Admin/Integrations/Index.tsx` | Description |
| `resources/js/pages/Admin/Settings/Edit.tsx` | Title, description, subdivision hint |
| `resources/js/pages/Admin/Users/Edit.tsx` | Automatic-role tooltip |
| `resources/js/pages/Profile/Edit.tsx` | Automatic-role tooltip |
| `resources/js/pages/Error.tsx` | Error message |

**Detector:** nothing fails when upstream adds new "vACC" text, so check by hand on every
absorption:

```bash
git grep -n "vACC" -- app resources/js
```

Expected to remain: code comments, and `DEFAULT_NAME = 'Example vACC'` in
`app/Services/VaccIdentity.php`, which only shows until Settings has a name.

### 5. VATSSA theme

Option B "Signature": dark `#07262C`, teal `#0197B0` buttons, the teal-to-green glow, Hanken
Grotesk. Chosen in Settings → Theme.

| File | Change |
|---|---|
| `app/Enums/Theme.php` | `Vatssa` case |
| `resources/css/app.css` | `[data-theme="vatssa"]` block, 52 added lines |
| `vite.config.js` | Hanken Grotesk through `bunny()` |

**Detector:** `tests/Feature/Admin/SiteSettingsTest.php` lists the themes. It does not catch a
new CSS variable. After absorbing, open staging on the VATSSA theme and look at the home page,
an event, a form and a badge.

### 6. Home page and footer

| File | Change |
|---|---|
| `resources/js/pages/Home.tsx` | "Our airspace" FIR list removed: with 22 FIRs it runs to several lines |
| `resources/js/layouts/AppLayout.tsx` | Footer links `https://vats.im/ssa/privacy` |

**Detector:** a merge conflict at absorption. Check both by eye on staging.

No credit line in the footer, by decision: the code is VATSIM Scandinavia's.

---

## Test files

Upstream's tests, changed only where they assert a patched string or list.

| File | Asserts |
|---|---|
| `tests/Feature/Admin/FirScopedUserManagementTest.php` | Division Staff role key |
| `tests/Feature/Admin/RoleAssignmentManagementTest.php` | Role name and sort position |
| `tests/Feature/Admin/RoleManagementTest.php` | Role name and sort position |
| `tests/Feature/Admin/SiteSettingsTest.php` | VATSSA theme, division wording |
| `tests/Feature/ProfileTest.php` | Role name |
| `tests/Feature/RoleAssignmentTest.php` | Role name |
| `tests/Feature/SignInTest.php` | Handover label |

"Division Staff" sorts differently from "vACC Staff", which is why some role indexes moved.

---

## Added files

| File | What it is |
|---|---|
| `PATCHES.md` | This file |
| `.events-upstream-version` | The upstream tag this fork is based on |
| `.github/CODEOWNERS` | Daniël owns everything |
| `.github/workflows/deploy.yml` | Build to ghcr, deploy over a forced-command key |
| `.github/workflows/upstream-check.yml` | Daily: posts to #tech-status when upstream tags a newer version |
| `.github/workflows/webhook-test.yml` | Manual test of each environment's Discord webhook |
| `config/vatssa.php` | The hub-wide switch |
| `tests/Feature/VatssaHubWideRolesTest.php` | Patch 1 |
| `tests/Feature/VatssaHubWideRosterSyncTest.php` | Patch 2 |

---

## Deleted from upstream

| File | Why |
|---|---|
| `.github/workflows/ci.yml` | Upstream's pipeline |
| `.github/workflows/release.yml` | Upstream's release-please flow |
| `.github/ISSUE_TEMPLATE/` (5 files) | Upstream's issue forms |

**Known gap: nothing runs the tests automatically.** `deploy.yml` builds and deploys without
them, so run the suite by hand before every push to `staging`.

---

## Set in the app, not in code

A fresh database needs these again. None of them is a patch.

- Settings: name `VATSSA`, division, website, logo, theme `VATSSA`. Subdivision empty.
- 22 FIRs, following the GNG packages. Dakar Oceanic is `AFOW`, because FIR codes are four
  letters. FASA covers FACA + FAJA, HTDC covers HTDC + HRYR + HBBA, GOOO covers GOOO + GLRB +
  DIII.
- Event type **Exam**, added to upstream's five.
- Control Center and Discord integrations.

---

## Absorbing an upstream release

The alert in #tech-status starts this. Work on a branch from `staging`.

1. Read upstream's changelog, and check this file for anything it touches.
2. `git fetch upstream --tags`, then `git merge <tag>`. Never squash or rebase: the merge is
   what lets git see the shared history next time.
3. Resolve conflicts using the notes above.
4. Run the `vACC` search from patch 4.
5. Run the full suite, Pint, types and lint.
6. Set `.events-upstream-version` to the new tag and update this file.
7. Merge to `staging` and check it by eye: sign-in, roster sync, the VATSSA theme.
8. Back up, then open the `staging` → `main` PR with a merge commit. Production waits for
   approval.

---

*VATSSA Events fork. Application code © VATSIM Scandinavia, see `LICENSE`.*
