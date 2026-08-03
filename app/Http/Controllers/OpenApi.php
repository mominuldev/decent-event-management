<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OAT;

#[OAT\Info(
    title: 'Decent Event Management API',
    version: '1.0.0',
    description: 'Event ticketing and management system API with registration, payment, ticketing, check-in, and reporting capabilities.'
)]
#[OAT\Server(
    url: 'https://api.example.com/v1',
    description: 'Production server'
)]
#[OAT\Server(
    url: 'http://localhost:8000/api/v1',
    description: 'Local development server'
)]
#[OAT\Tag(
    name: 'Authentication',
    description: 'Admin and attendee authentication endpoints'
)]
#[OAT\Tag(
    name: 'Registrations',
    description: 'Registration management endpoints'
)]
#[OAT\Tag(
    name: 'Payments',
    description: 'Payment processing and management'
)]
#[OAT\Tag(
    name: 'Tickets',
    description: 'Ticket issuance and management'
)]
#[OAT\Tag(
    name: 'Check-in',
    description: 'Event admission and scanning'
)]
#[OAT\Tag(
    name: 'Reports',
    description: 'Reporting and analytics'
)]
#[OAT\Tag(
    name: 'Settings',
    description: 'System configuration'
)]
#[OAT\Tag(
    name: 'Ticket Types',
    description: 'Ticket type / capacity / pricing management'
)]
#[OAT\Tag(
    name: 'Attendees',
    description: 'Attendee record management (admin)'
)]
#[OAT\Tag(
    name: 'Volunteers',
    description: 'Volunteer and device enrolment management'
)]
#[OAT\Tag(
    name: 'Two-Factor',
    description: 'Staff TOTP two-factor authentication'
)]
#[OAT\Tag(
    name: 'Public',
    description: 'Unauthenticated browse and registration endpoints'
)]
#[OAT\Tag(
    name: 'Attendee Self-Service',
    description: 'Authenticated attendee profile, registration, and ticket endpoints'
)]
#[OAT\Tag(
    name: 'Scanner',
    description: 'Volunteer scanner device enrolment, manifest sync, and offline scan upload'
)]
#[OAT\Tag(
    name: 'Webhooks',
    description: 'Server-to-server payment gateway callbacks'
)]
#[OAT\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Token issued by the relevant login endpoint. Admin, attendee, and scanner guards each mint tokens scoped to that guard and cannot be used interchangeably.'
)]
class OpenApi
{
    // This class exists only to hold OpenAPI documentation attributes
}
