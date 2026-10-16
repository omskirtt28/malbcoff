---
tags: [index]
updated: 2026-10-16
---
# Malbcoff POS Vault — Start Here

Ito ang "memory" ng project. **Basahin muna ang notes dito bago magbasa ng code** para tipid sa usage.

## Paano gamitin with Claude Code
1. Sabihin: *"Read malbcoff-vault/00 Start Here.md and Plan/Owner Updates Plan.md, then do Patch N only."*
2. Claude Code reads lang yung files na naka-list sa patch note (hindi buong codebase).
3. Pagkatapos ng patch, i-update ang related Feature note + i-tick sa [[Owner Updates Plan]].

## Map
- [[Architecture Map]] — paano dumadaan ang request, folders, layers
- [[File Index]] — bawat file at ano ginagawa
- [[Data Model]] — tables, columns, movement types
- [[Roles and Access]] — sino pwede saan
- [[Conventions]] — rules na dapat sundin sa bawat patch

## Features
- [[Dashboard]] · [[Stock Monitoring]] · [[Sales Records]] · [[Stock Movement]]
- [[Stock In (Receive Stock)]] · [[Branch Transfers]] · [[POS]] · [[Inventory]]
- [[Users and Accounts]] · [[Auth and Session]]

## Current work
- [[Owner Updates Plan]] — 7 patches, naka-order
- [[BUG Stock Monitoring Owner Branch View]] — root cause

## Source docs (nasa root, mahaba — basahin lang kung kailangan)
`ARCHITECTURE.md`, `SCHEMA.md`, `RULES.md`, `PRD.md`, `DESIGN.md`
