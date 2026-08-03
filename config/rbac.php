<?php

/**
 * Versioned RBAC catalogue — docs/02 §2.3. Roles and permissions are
 * seeded from this file, never created ad hoc, so staging and production
 * are provably identical (docs/02 §2.5). All under the `web-admin` guard;
 * Volunteer row-level scoping (device, gate, check-in window) and
 * Attendee ownership are enforced by middleware and policies, not by
 * additional permission rows — see docs/02 §2.1 and §2.5.
 */
return [

    'guard' => 'web-admin',

    'permissions' => [

        // Registrations & attendees
        'registration.view_any',
        'registration.view',
        'registration.create',
        'registration.update',
        'registration.cancel',
        'registration.delete',
        'registration.export',
        'attendee.view_any',
        'attendee.view',
        'attendee.update',
        'attendee.delete',
        'attendee.merge_duplicates',
        'attendee.view_contact_details',
        'guest.manage',

        // Tickets
        'ticket.view_any',
        'ticket.view',
        'ticket.issue',
        'ticket.reissue',
        'ticket.void',
        'ticket.download_pdf',
        'ticket.transfer',
        'ticket_type.view_any',
        'ticket_type.manage',
        'ticket_type.set_price',
        'ticket_type.delete',

        // Payments
        'payment.view_any',
        'payment.view',
        'payment.initiate',
        'payment.verify_manual',
        'payment.reject_manual',
        'payment.refund',
        'payment.view_transactions',
        'payment.view_raw_gateway_payload',
        'payment.reconcile',
        'payment.manage_gateway_credentials',
        'payment.export',

        // Check-in
        'checkin.scan',
        'checkin.admit',
        'checkin.manual_override',
        'checkin.undo',
        'checkin.view_any',
        'checkin.view',
        'checkin.view_live_dashboard',
        'checkin.resolve_conflict',
        'checkin.sync',

        // Gates
        'gate.view_any',
        'gate.view',
        'gate.manage',
        'gate.delete',

        // Devices & volunteers
        'volunteer.view_any',
        'volunteer.create',
        'volunteer.update',
        'volunteer.assign_gate',
        'volunteer.revoke_access',
        'device.enrol',
        'device.view_any',
        'device.revoke',
        'device.view_sync_status',

        // Notifications
        'notification.view_any',
        'notification.resend',
        'notification.send_broadcast',
        'notification.manage_templates',
        'notification.view_costs',

        // Content management (Phase 3.5) — pages, blocks, menus, sponsors,
        // schedule, FAQs, galleries, and the shared media library.
        'content.view_any',
        'content.view',
        'content.create',
        'content.update',
        'content.publish',
        'content.delete',
        'content.manage_media',

        // Reporting
        'report.view_registrations',
        'report.view_revenue',
        'report.view_attendance',
        'report.view_tshirt',
        'report.view_batch_breakdown',
        'report.export_pdf',
        'report.export_excel',
        'report.export_csv',

        // System administration
        'user.view_any',
        'user.create',
        'user.update',
        'user.assign_role',
        'user.deactivate',
        'role.view_any',
        'role.manage',
        'settings.view',
        'settings.update',
        'qr.rotate_signing_key',
        'activity_log.view',
        'activity_log.export',
        'system.impersonate_attendee',
    ],

    'roles' => [

        // Full system authority — docs/02 §2.2.
        'Super Admin' => ['*'],

        // Runs the event day to day; everything operational, nothing
        // structurally dangerous (no credentials, no key rotation, no
        // hard delete, no role/user management).
        'Event Manager' => [
            'registration.view_any', 'registration.view', 'registration.create',
            'registration.update', 'registration.cancel', 'registration.export',
            'attendee.view_any', 'attendee.view', 'attendee.update',
            'attendee.merge_duplicates', 'attendee.view_contact_details', 'guest.manage',

            'ticket.view_any', 'ticket.view', 'ticket.issue', 'ticket.reissue',
            'ticket.void', 'ticket.download_pdf', 'ticket.transfer', 'ticket_type.view_any',

            'payment.view_any', 'payment.view', 'payment.initiate',
            'payment.verify_manual', 'payment.reject_manual', 'payment.refund',
            'payment.view_transactions', 'payment.reconcile', 'payment.export',

            'checkin.scan', 'checkin.admit', 'checkin.manual_override', 'checkin.undo',
            'checkin.view_any', 'checkin.view', 'checkin.view_live_dashboard', 'checkin.resolve_conflict',
            'gate.view_any', 'gate.view',

            'volunteer.view_any', 'volunteer.create', 'volunteer.update', 'volunteer.assign_gate',
            'volunteer.revoke_access', 'device.enrol', 'device.view_any',
            'device.revoke', 'device.view_sync_status',

            'notification.view_any', 'notification.resend',
            'notification.send_broadcast', 'notification.view_costs',

            // Editing and publishing site content is operational work, so it
            // sits with the Event Manager. `content.delete` does not — it
            // follows the same no-hard-delete rule as registration.delete,
            // attendee.delete, ticket_type.delete and gate.delete above.
            'content.view_any', 'content.view', 'content.create',
            'content.update', 'content.publish', 'content.manage_media',

            'report.view_registrations', 'report.view_revenue', 'report.view_attendance',
            'report.view_tshirt', 'report.view_batch_breakdown',
            'report.export_pdf', 'report.export_excel', 'report.export_csv',

            'settings.view', 'activity_log.view',
        ],

        // Scans and admits, scoped server-side to an enrolled device, an
        // assigned gate, and the active check-in window. Nothing else —
        // docs/02 §2.2 "Volunteer".
        'Volunteer' => [
            'checkin.scan', 'checkin.admit', 'checkin.sync',
            'checkin.view_any', 'checkin.view_live_dashboard',
            'device.view_sync_status', 'report.view_attendance',
        ],
    ],

];
