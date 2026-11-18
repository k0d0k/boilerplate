# Requirements-to-Tests Acceptance Matrix

| Requirement | Feature/module | Test target | Gate |
|---|---|---|---|
| One Laravel app with 3 distinct Filament panels | Foundation | Panel routes/providers, forbidden login/panel direct access | Phase 1A/1B |
| Single unified staff login without role buttons | Identity | Server chooses authorized panel/membership | Phase 1B |
| Consistent premium Filament theme | Theme | Screenshots 1440/1024/390/360, no Bootstrap/Tabler deps | Phase 1A |
| Multi-language id/ms-MY/en | Localization | Switch/reset fallback, complete nav/messages, independent timezone/currency | Phase 1B + each module |
| Modules separate from `vendor/` | Modular monolith | Registered module loaded only on allowed panel; framework diff clean | Phase 1A/1B |
| Tenant DB isolation | Tenancy | A cannot see B in HTTP/Livewire/API/files/queue | Phase 2 |
| Unified patient history | Patients/LBA | Manual+AI+Hybrid same patient exam timeline | Phase 3–7 |
| Manual image annotations without AI | LBA | Save with nullable/no job; 0 AI debit | Phase 4 |
| Human review before publishing | LBA review | Operator cannot publish; reviewer-only immutable reports | Phase 7 |
| AI private adapter model provenance | Vision | No patient identity sent; schema/replay/offline tests | Phase 5 |
| Token ledger | Billing | reserve/capture/release available/reserved idempotent | Phase 6 |
| Fixed and temporary booking venue | Booking | Location/branch separation, event tentative/confirmed | Phase 10 |
| Slot capacity | Booking | parallel bookings cannot exceed slots; expiry restores hold | Phase 10 |
| Human/SINA consultations | Consult | Self-only, informed tariff, refund, private messages | Phase 13 |
| Patient vs tenant wallet separation | Billing | no shared row/ledger/balance transfer path | Phase 13 |
| Commerce and payments | Orders/Payments | merchant mapping, verified webhook, duplicate no-op | Phase 11–12 |
| Trusted uploaded module | Module Manager | unsigned rejected; quarantined, no HTTP exec, rollback | Phase 14 |
| Never expose patient data in Principal analytics | All | Principal clinical queries forbidden, aggregate safe | Every phase |
| Cloud workspace resilience | Ops | Complete local exported ZIP available after each accepted checkpoint | Every phase |
