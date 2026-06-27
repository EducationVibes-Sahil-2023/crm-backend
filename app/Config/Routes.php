<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// REST API (CORS enabled, JSON responses)
$routes->group('api', ['filter' => 'cors'], static function ($routes) {
    // Preflight requests for any api/* path
    $routes->options('(:any)', static function () {
    });

    $routes->get('health', 'Api\Health::index');

    // Authentication
    $routes->post('auth/login', 'Api\Auth::login');
    $routes->get('auth/me', 'Api\Auth::me');
    $routes->post('auth/logout', 'Api\Auth::logout');
    // Self-service password recovery (public — emails a short-lived reset link)
    $routes->post('auth/forgot-password', 'Api\Auth::forgotPassword');
    $routes->post('auth/reset-password', 'Api\Auth::resetPassword');
    // Two-step verification
    $routes->post('auth/2fa/verify', 'Api\Auth::verifyTwofa');   // completes a 2FA login (uses challenge, no bearer)
    $routes->post('auth/2fa/setup', 'Api\Auth::twofaSetup');     // authenticated
    $routes->post('auth/2fa/enable', 'Api\Auth::twofaEnable');   // authenticated
    $routes->post('auth/2fa/disable', 'Api\Auth::twofaDisable'); // authenticated

    // Account management (admin) — real login accounts
    $routes->post('users/(:num)/activate', 'Api\Users::activate/$1');
    $routes->post('users/(:num)/deactivate', 'Api\Users::deactivate/$1');
    $routes->post('users/(:num)/reset-2fa', 'Api\Users::resetTwofa/$1');
    $routes->resource('users', ['controller' => 'Api\Users']);

    $routes->resource('tasks', ['controller' => 'Api\Tasks']);

    // Team directory (Users page) — per-tenant, DB-backed
    $routes->resource('directory', ['controller' => 'Api\Directory']);

    // Media library — folders (nested) + uploaded files
    $routes->resource('media/folders', ['controller' => 'Api\MediaFolders']);
    $routes->resource('media/files', ['controller' => 'Api\MediaFiles']);

    // Asset management — tracking + costing + admin/user verification workflow
    $routes->post('assets/(:num)/submit', 'Api\Assets::submit/$1');
    $routes->post('assets/(:num)/verify', 'Api\Assets::verify/$1');
    $routes->post('assets/(:num)/reject', 'Api\Assets::reject/$1');
    $routes->post('assets/(:num)/reopen', 'Api\Assets::reopen/$1');
    $routes->post('assets/(:num)/comments', 'Api\Assets::comment/$1');
    $routes->resource('assets', ['controller' => 'Api\Assets']);

    // Inventory — stock items + movement tracking + user assignments
    $routes->post('inventory/(:num)/adjust', 'Api\Inventory::adjust/$1');
    $routes->post('inventory/(:num)/assign', 'Api\Inventory::assign/$1');
    $routes->post('inventory/assignments/(:num)/return', 'Api\Inventory::returnUnits/$1');
    $routes->resource('inventory', ['controller' => 'Api\Inventory']);

    // AI assistant — server-side relay to Claude (keeps the API key secret)
    $routes->post('ai/chat', 'Api\Ai::chat');

    // Super admin — exchange console credentials for a JWT (sub: super-admin)
    $routes->post('super-admin/token', 'Api\SuperAdmin::token');

    // Platform config (branding/plans/permissions/…) + landing demos, DB-backed.
    // Reading config is public (landing branding); writes are guarded in-controller.
    $routes->get('platform', 'Api\Platform::index');
    $routes->post('platform', 'Api\Platform::save');
    $routes->get('platform/demos', 'Api\Platform::demos');
    $routes->post('platform/demos/book', 'Api\Platform::bookDemo');
    $routes->post('platform/demos', 'Api\Platform::saveDemos');

    // CRM Leads — fully normalised domain (leads table)
    $routes->resource('leads', ['controller' => 'Api\Leads']);

    // Generic per-workspace JSON store — replaces front-end localStorage.
    $routes->get('store', 'Api\Store::index');
    $routes->get('store/(:segment)', 'Api\Store::show/$1');
    $routes->put('store/(:segment)', 'Api\Store::update/$1');
    $routes->delete('store/(:segment)', 'Api\Store::delete/$1');

    // Multi-tenant provisioning — a dedicated database per client workspace
    $routes->get('tenants', 'Api\Tenants::index');
    $routes->post('tenants/provision', 'Api\Tenants::provision');
    $routes->post('tenants/update', 'Api\Tenants::updateClient');
    $routes->post('tenants/impersonate', 'Api\Tenants::impersonate');
    $routes->post('tenants/reset-password', 'Api\Tenants::resetPassword');
    $routes->post('tenants/drop', 'Api\Tenants::drop');

    // Gmail — real OAuth connect, read inbox, send mail (tokens stored per user)
    $routes->get('gmail/callback', 'Api\Gmail::callback'); // Google redirect (no bearer; user recovered from state)
    $routes->get('gmail/status', 'Api\Gmail::status');
    $routes->get('gmail/config', 'Api\Gmail::getConfig');
    $routes->post('gmail/config', 'Api\Gmail::saveConfig');
    $routes->get('gmail/auth-url', 'Api\Gmail::authUrl');
    $routes->get('gmail/messages', 'Api\Gmail::messages');
    $routes->get('gmail/message/(:segment)', 'Api\Gmail::message/$1');
    $routes->post('gmail/send', 'Api\Gmail::send');
    $routes->get('gmail/calendar', 'Api\Gmail::calendarEvents');
    $routes->post('gmail/calendar', 'Api\Gmail::createCalendarEvent');
    $routes->post('gmail/disconnect', 'Api\Gmail::disconnect');

    // SMTP — relay configuration + sending (alternative to Gmail OAuth)
    $routes->get('smtp/config', 'Api\Smtp::getConfig');
    $routes->post('smtp/config', 'Api\Smtp::saveConfig');
    $routes->post('smtp/test', 'Api\Smtp::test');
    $routes->post('smtp/send', 'Api\Smtp::send');
});
