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
class OpenApi
{
    // This class exists only to hold OpenAPI documentation attributes
}
