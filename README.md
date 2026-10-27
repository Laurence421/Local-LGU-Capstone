# LGU2 Legislative Research Section

**Capstone project.** A web system for the Legislative Research Section of a local government unit's
legislative council. It tracks every proposed ordinance and resolution from first draft to enactment:
research staff check each measure for duplicates and conflicts with existing laws, review it in three
stages, and send it on for docketing. It is one of nine connected LGU2 systems (Records, Ordinance,
Minutes, Agenda, Committee, Committee Journal, Journal, Archives and Research) that hand measures to each
other through APIs and share a single sign-on.

**[▶ Open the live portfolio demo](https://laurence421.github.io/Local-LGU-Capstone/)**: no login needed,
all data is fictional sample data. Everything in the sidebar works: send a draft measure to Records, add
reviews, filter and page through the tabs, export a PDF, manage accounts, and submit and process document
requests. Your changes last until you close the tab; **Reset demo** in the banner restores the sample data.

![Dashboard](docs/screenshots/dashboard.png)

## What it does

- **Dashboard** with live counts of measures at each stage, enacted ordinances and resolutions, an
  incoming-tasks calendar and a recent activity feed.
- **Draft Measures Task**: the research workflow. Incoming drafts, measures in review for docketing,
  proposed and enacted ordinances and resolutions, each with a three-stage review trail, automatic
  category detection and PDF export.
- **Similarity, Citation and Measure Comparison tools** to find duplicate or conflicting measures before
  a draft moves forward.
- **Document requests**: the public requests copies of measures; staff approve them and send the
  documents by email.
- **Records and Correspondence**: docketing and tracking of measures received from the research section.
- **User management** with role-based access (admin, research, records, ordinance, committee and more).
- **Super Admin panel** that opens the other eight LGU2 systems through single sign-on.
- **Connected systems in the demo:** the other teams' systems (Ordinance and Resolution, Minutes, Agenda,
  Committee, Committee Journal, Archive) are separate applications in the real deployment. In the demo each
  opens a preview page showing what that system does with the shared data, so a measure you send from
  Research shows up on the Agenda and in Committee.

| Draft measures | Measure docketing |
|---|---|
| ![Draft measures](docs/screenshots/draft-measures.png) | ![Measure docketing](docs/screenshots/measure-docketing.png) |
| **Similarity checking** | **Measure comparison** |
| ![Similarity checking](docs/screenshots/similarity-checking.png) | ![Measure comparison](docs/screenshots/measure-comparison.png) |
| **Document requests** | **Accounts** |
| ![Document requests](docs/screenshots/document-requests.png) | ![Accounts](docs/screenshots/accounts.png) |

## How it is built

- **Stack:** PHP 8, MySQL/MariaDB, Bootstrap 5, vanilla JavaScript; PHPMailer for email, TCPDF for PDFs.
- **Security:** email one-time-password login with throttling, password reset with hashed tokens,
  server-side role checks on every page, CSRF and same-origin checks, prepared statements throughout,
  upload type allow-lists, and secrets kept in `.env` instead of code.
- **Integration:** the nine systems exchange measures through API-key-protected JSON endpoints and sign
  users in across systems with short-lived, one-time SSO tokens.
- **Operations:** numbered SQL migrations (`tools/migrate.php`), GitHub Actions deployment, and a demo mode
  with seeded sample data for this portfolio.
- **Design decisions** are written up as architecture decision records in [docs/adr/](docs/adr/README.md).

## Copyright

© 2026 the LGU2 Legislative Research Section capstone team. All rights reserved.

This repository is published so the work can be viewed as part of a portfolio. No permission is granted to
copy, modify, distribute, or reuse this code, its design, or its documentation, in whole or in part, without
written permission from the authors. See [LICENSE](LICENSE).
