# BRIS — Barangay Resident Information System

A web-based resident information system for **Barangay San Isidro, Pilar, Bohol**. It is used by two roles: the **Barangay Captain** (System Administrator) and the **Barangay Secretary**. Residents are data subjects only and have no accounts.

The requirements, design, and project plan are in [`docs/`](docs/): `BRIS_SRS_Updated_Nov2026.docx`, `BRIS_SDD_Updated_Nov2026.docx`, and `BRIS_SPMP_Updated_Nov2026.docx`. Changes from the previous revision are tracked changes in each file.

## Features

- **Resident registry:** residents (detailed registration with duplicate check), households, families, and the seven puroks (1-A, 1-B, 2–6)
- **Sector classifications, skills, achievements, and social assistance records**
- **Relief distribution:** eligible households listed automatically, segregated by purok, fair share per purok when supply is short, Captain approval, printable release lists, one claim per household
- **Births, deaths, and transfers** that update the registry
- **Livelihood and job opportunities:** capable residents shortlisted automatically and segregated by purok
- **Dashboard** with an action list and a predictive and demographic view
- **Reports:** all reports with print/PDF and CSV export, plus AI resident analysis suggestions
- **AI-Powered Barangay Assistant:** questions in plain language, answered only from BRIS records through approved read-only query functions, using a **locally hosted model** (Ollama, `qwen2.5:3b`) so resident data never leaves the server
- **Data privacy:** consent purposes, data-subject requests, and an append-only audit log
- **Administration:** user and role management, nightly encrypted backups, and restore

## Tech stack

React, TypeScript, and Vite (frontend) · Laravel 12 REST API with Sanctum (backend) · MySQL/MariaDB · Ollama (local AI model)

## Running it locally (Windows)

Requirements: PHP 8.2+, Composer, Node.js, MySQL or MariaDB (for example XAMPP), and [Ollama](https://ollama.com).

```powershell
# Backend
cd backend
composer install
copy .env.example .env        # then set DB_* and the MYSQLDUMP_PATH / MYSQL_CLIENT_PATH values
php artisan key:generate
php artisan migrate --seed

# Frontend
cd ..\frontend
npm install

# AI model
ollama pull qwen2.5:3b
```

Then double-click **`start-bris.bat`**. It starts MySQL (XAMPP), the AI model, the backend, the scheduler (nightly backup and analysis), and the frontend, and opens http://localhost:5173.

The seeder creates two accounts with the temporary password `ChangeMe123!`, which must be changed at first login: **`captain`** and **`secretary`**. Change them before any real use.

## Tests

```powershell
cd backend
php artisan test
```

Tests use a separate MySQL database named `bris_testing` (create it first).
