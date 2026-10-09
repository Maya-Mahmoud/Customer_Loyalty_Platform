# 🛒 Customer Loyalty Platform (Multi-Tenant SaaS)

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" />
  <img src="https://img.shields.io/badge/Angular-17-DD0031?style=for-the-badge&logo=angular&logoColor=white" />
  <img src="https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge&logo=mysql&logoColor=white" />
  <img src="https://img.shields.io/badge/Tailwind-CSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white" />
  <img src="https://img.shields.io/badge/PHPUnit-Testing-777BB4?style=for-the-badge&logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/Status-Live%20Production-green?style=for-the-badge" />
</p>

> A robust, multi-tenant SaaS customer loyalty and rewards ecosystem designed for commercial retail, ensuring strict data isolation, immutable transaction ledgers, and comprehensive anti-fraud controls.

---

## 🔗 Quick Links
- **Live Demo Video:** [Watch Platform Demo](#) *(Add your video link here)*
- **Live Production URL:** *(Add your live domain here)*

---

## 🏗️ Architecture & System Design
This platform was architected and delivered end-to-end—starting from comprehensive business requirements analysis, role permission matrices, and MOSCOW prioritisation, down to backend API development, Angular SPA frontend, and cPanel production deployment.

### Core Engineering Highlights:
- **Strict Multi-Tenancy & Data Isolation:** Built with robust tenant boundaries ensuring zero data leakage across shops regarding customers, invoices, or analytics.
- **Immutable Ledger Architecture:** Customer point balances are dynamically derived from an append-only ledger of immutable entries rather than a static stored total, making balances fully auditable and dispute-proof.
- **Configurable Loyalty Rules Engine:** Supports flexible threshold types, reward calculations, discount caps, minimum invoice requirements, cross-branch accrual, and historical versioning so rule updates never apply retroactively.
- **Advanced Anti-Fraud Controls:** Implements 12 rigorous validation checks, including duplicate-invoice constraints, staff phone number blocking, cancellation-pattern detection, and separation of recording from approval.
- **Frictionless No-Account Customer Lookup:** Enables customers to check balances securely via phone number verification and recent receipt details, removing adoption barriers.
- **Automated Testing & Bilingual Support:** Covered with PHPUnit automated feature tests and fully localized in Arabic and English with complete RTL layout support.

---

## 🛠️ Tech Stack
- **Backend:** Laravel 12, PHP 8.2, Eloquent ORM, RESTful APIs, PHPUnit
- **Frontend:** Angular 17 (Standalone Components, Signals, RxJS), Tailwind CSS
- **Database:** MySQL
- **Deployment & Infrastructure:** cPanel Shared Hosting, Apache Rewrite Rules, HTTPS Configuration

---

## 🚀 Getting Started Locally

### Prerequisites
- PHP >= 8.2 & Composer
- Node.js & npm / Angular CLI
- MySQL

### Installation

1. **Clone the repository:**
   ```bash
   git clone [https://github.com/Maya-Mahmoud/Customer_Loyalty_Platform.git](https://github.com/Maya-Mahmoud/Customer_Loyalty_Platform.git)
   cd Customer_Loyalty_Platform
