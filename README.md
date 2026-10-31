# Elementor Missing Image Audit & Restore — Media Recovery Tool

### Plugin Documentation & Developer Guide by Jagdish Patidar

An administrative audit and forensic recovery plugin for WordPress and Elementor that recursively scans posts for broken or missing local Elementor video overlay images and provides a granular, 1-click sideload restoration engine.

- **Developer:** Jagdish Patidar
- **Company:** [Drunall Technologies](https://drunall.com)
- **Project Type:** WordPress Plugin / Forensic Diagnostic Utility
- **Repository Type:** Open Source WordPress Plugin
- **Source Code:** Public
- **Tested Up To:** WordPress 6.x, Elementor 3.x, PHP 8.x

---

## Overview

Following large-scale WordPress migrations, domain changes, or content synchronization runs, media assets embedded inside Elementor's complex JSON post metadata (`_elementor_data`) frequently become broken. Specifically, video widgets with custom image overlays often refer to local upload paths that were corrupted, omitted during transfer, or never migrated.

**Elementor Missing Image Audit & Restore** was built to solve this exact maintenance headache. It scans posts across all statuses, detects broken video overlay image references on the filesystem without modifying live content, presents a transparent diagnostic table to the administrator, and offers a controlled, 1-click remote recovery workflow directly from the original production source.

---

## The Business Problem

Locating and fixing broken video overlays in Elementor manually is extraordinarily painful:

- **Invisible in Standard Media Scanners:** Standard broken link checkers look at HTML `<img>` tags and fail to parse Elementor's serialized JSON meta trees (`_elementor_data`).
- **High Manual Labor:** Manually editing dozens of posts inside the heavy Elementor visual builder to re-upload overlays takes days of tedious effort.
- **Risk of Accidental Layout Breakage:** Re-saving pages manually in Elementor can inadvertently trigger unwanted layout changes or wipe other revisions.
- **Cache Persistence:** Even if an image is manually re-uploaded, Elementor's compiled CSS cache frequently continues pointing to broken paths unless explicitly purged.

This plugin automates the entire forensic scan and recovery pipeline with zero guesswork.

---

## Technical Highlights & Features

- **Deep Elementor JSON Traversal:**
  - Recursively navigates Elementor layout data structures across all post statuses (`publish`, `draft`, `private`, `pending`, `future`).
  - Isolates video widget nodes and extracts image overlay IDs and URLs.

- **Non-Destructive Filesystem Auditing:**
  - Verifies physical file presence on the server disk (`wp-content/uploads/`).
  - Read-only diagnostic scan that never modifies database records or layouts during scanning.

- **Batched Non-Blocking Scanning:**
  - AJAX batch execution (20 posts per batch) with instant status reporting.
  - Audit table displaying Post Name, View/Edit links, Elementor Widget ID, and Missing File paths.

- **Controlled 1-Click Remote Restoration:**
  - Granular, item-by-item restore button giving administrators complete control.
  - Sideloads validated assets into the WordPress Media Library with new attachment IDs.
  - Safely rewrites only the target widget's node in `_elementor_data` preserving proper slashes.
  - Automatically purges Elementor's compiled file cache (`Plugin::$instance->files_manager->clear_cache()`).

---

## Technical Architecture

```text
elementor-image-audit/
├── elementor-image-audit.php        # Complete diagnostic engine, JSON traverser, AJAX scanner, sideload handler & UI
└── README.md                        # Plugin documentation and usage guide
```

- **Dependencies:** WordPress 5.6+, Elementor 3.x+, PHP 7.4+
- **Security:** Strict nonce verification (`check_ajax_referer`), administrator capability authorization (`current_user_can('manage_options')`), MIME type validation.

---

## Installation & Setup

1. In WordPress Admin, navigate to **Plugins > Add New > Upload Plugin**.
2. Upload the plugin zip archive and activate it.
3. Go to **Tools > Missing Video Images** in your admin menu.
4. Click **Scan all posts** to generate the audit report.
5. Click **Restore** next to any detected missing image to automatically retrieve and repair it.

---

## Author & License

- **Developer:** Jagdish Patidar
- **Company:** Drunall Technologies
- **License:** GPL-2.0-or-later
