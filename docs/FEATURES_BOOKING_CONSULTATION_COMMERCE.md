# Planned Modules — Booking, Consultations, SINA, Commerce and Payments

## Location and event booking (Phase 10)

Admin creates fixed clinic location, temporary/pop-up venue, scheduled event or optional mobile service, each with address/optional coordinates, brand/tenant/branch, dates, staffing, fee, capacities, service coverage and cancellation/deposit settings. A tentative event is visible only as clearly *tentative* / preregistration; no falsely confirmed booking. A confirmed event publishes sessions/slots.

Patient finds nearby location using optional geolocation **or** typed city/postcode, selects date/slot/clinic, sees price and terms, books/reschedules/cancels and gets status notifications. Reservation uses expiring per-booking holds and row-locked capacity: concurrent requests cannot exceed capacity. Cross-tenant location details may be publicly discoverable where published, but booking/patient clinical data stays tenant-scoped.

## Human expert consultation (Phase 13)

Expert/doctor has verified competencies and availability within authorized scopes; patients can book/attend sessions, use chat, see tariff before starting, receive notes and cancellation/refund details. Consultant access limited to assigned patients/sessions. Store messages privately and maintain consent/audit.

## SINA AI consultation (Phase 13)

SINA is private conversational assistance with a transparent configured fee unit (session/message/usage), clear credits before charging, timeout and error policy, abuse/rate limiting, escalation to an authorized expert. No invented microscopy results and no autonomous diagnosis. Patient wallet is **not** the tenant's LBA-AI credit wallet.

## E-commerce (Phase 11)

Multi-brand product catalog, variants, currency/tenant prices, inventory per branch, carts, orders, shipping/pickup, fulfillment, invoices/refunds, order history. Business/legal merchant owns settlements; do not silently pool unrelated merchants.

## Payment adapters (Phase 12)

**Midtrans**, **PayPal**, **iPaymu**. Uniform interface: create intent, verify provider-origin webhook/status, map normalized states, reconcile, refund (where supported) and deduplicate using idempotency keys. Orders and token topups are marked paid only after server verification, **not** from return URL. Keep four ownership-separated transaction categories: product order, patient consultation, tenant AI credits, subscription.

Gateway currency, contracts, merchant credential format, taxes/shipping, cancellation/deposits and tariffs require business/legal approval prior to production. Never put secrets in JS/mobile/browser or logs.

## POS / inventory

Admin and clinic POS/inventory can be built as optional modular Filament Custom Pages/Resources in a later approved milestone. Do not assume a full accounting/general-ledger module exists within the initial MVP.
