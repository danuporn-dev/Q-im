<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\CatalogController;
use App\Controllers\CustomerController;
use App\Controllers\LineController;
use App\Controllers\MerchantController;
use App\Controllers\PaymentWebhookController;
use App\Support\Database;
use App\Support\Request;
use App\Support\Router;

return static function (Router $r): void {
    $r->add('GET', '/health', static function (Request $q): array {
        Database::value('SELECT 1');
        return [['status' => 'ok'], 200];
    });

    // Identity
    $r->add('GET', '/config', [AuthController::class, 'config']);
    $r->add('POST', '/auth/line', [LineController::class, 'login']);
    $r->add('POST', '/line/webhook', [LineController::class, 'webhook']);
    $r->add('POST', '/auth/dev', [AuthController::class, 'dev']);
    $r->add('GET', '/dev/messages', [AuthController::class, 'devMessages']);
    $r->add('POST', '/auth/login', [AuthController::class, 'login']);
    $r->add('POST', '/auth/logout', [AuthController::class, 'logout']);
    $r->add('GET', '/me', [AuthController::class, 'me']);

    // Discovery (public)
    $r->add('GET', '/shops', [CatalogController::class, 'shops']);
    $r->add('GET', '/food-courts', [CatalogController::class, 'foodCourts']);
    $r->add('GET', '/categories', [CatalogController::class, 'categories']);
    $r->add('GET', '/shops/{id}', [CatalogController::class, 'shop']);
    $r->add('GET', '/shops/{id}/menus', [CatalogController::class, 'menus']);
    $r->add('GET', '/images/{key}', [CatalogController::class, 'image']);
    $r->add('GET', '/images/{key}/thumb', [CatalogController::class, 'thumb']);

    $r->add('GET', '/shops/{id}/reviews', [CatalogController::class, 'reviews']);

    // Customer
    $r->add('GET', '/favorites', [CustomerController::class, 'favorites']);
    foreach (['shops', 'menus'] as $kind) {
        $r->add('PUT', "/favorites/$kind/{id}", CustomerController::favorite($kind, true));
        $r->add('DELETE', "/favorites/$kind/{id}", CustomerController::favorite($kind, false));
    }
    $r->add('POST', '/orders/{id}/review', [CustomerController::class, 'review']);
    $r->add('GET', '/notifications', [CustomerController::class, 'notifications']);
    $r->add('GET', '/notifications/count', [CustomerController::class, 'unread']);
    $r->add('POST', '/notifications/read-all', [CustomerController::class, 'readAll']);
    $r->add('POST', '/notifications/{id}/read', [CustomerController::class, 'read']);
    $r->add('GET', '/cart', [CustomerController::class, 'cart']);
    $r->add('PUT', '/cart', [CustomerController::class, 'updateCart']);
    $r->add('POST', '/orders', [CustomerController::class, 'checkout']);
    $r->add('GET', '/orders', [CustomerController::class, 'orders']);
    $r->add('GET', '/orders/{id}', [CustomerController::class, 'order']);
    $r->add('POST', '/orders/{id}/cancel', [CustomerController::class, 'cancel']);
    $r->add('POST', '/orders/{id}/online-payment', [CustomerController::class, 'onlinePayment']);
    $r->add('POST', '/orders/{id}/online-payment/check', [CustomerController::class, 'checkPayment']);
    $r->add('POST', '/orders/{id}/payment-method', [CustomerController::class, 'paymentMethod']);
    // Stripe's webhook, at /api/webhooks/stripe: signed by Stripe, so no session and no CSRF token (D42).
    $r->add('POST', '/webhooks/stripe', [PaymentWebhookController::class, 'stripe']);

    // Merchant: shop management
    $r->add('GET', '/merchant/shops/{id}/settings', [MerchantController::class, 'settings']);
    $r->add('PATCH', '/merchant/shops/{id}/settings', [MerchantController::class, 'updateSettings']);
    $r->add('GET', '/merchant/shops/{id}/menus', [MerchantController::class, 'menus']);
    $r->add('POST', '/merchant/shops/{id}/menus', [MerchantController::class, 'createMenu']);
    $r->add('PATCH', '/merchant/menus/{id}', [MerchantController::class, 'updateMenu']);
    $r->add('POST', '/merchant/shops/{id}/categories', [MerchantController::class, 'createCategory']);
    $r->add('PATCH', '/merchant/shops/{id}/categories/{sub}', [MerchantController::class, 'updateCategory']);
    $r->add('POST', '/merchant/shops/{id}/images', [MerchantController::class, 'upload']);

    // Merchant: orders
    $r->add('GET', '/merchant/shops/{id}/orders', [MerchantController::class, 'orders']);
    $r->add('GET', '/merchant/orders/{id}', [MerchantController::class, 'order']);
    $r->add('POST', '/merchant/orders/{id}/payment', [MerchantController::class, 'payment']);
    $r->add('POST', '/merchant/orders/{id}/complete', [MerchantController::class, 'complete']);
    $r->add('GET', '/merchant/shops/{id}/notifications', [MerchantController::class, 'bell']);
    $r->add('GET', '/merchant/shops/{id}/reviews', [MerchantController::class, 'reviews']);
    $r->add('GET', '/merchant/shops/{id}/reports', [MerchantController::class, 'reports']);
    foreach (['accept', 'reject', 'prepare', 'ready', 'cancel'] as $action) {
        $r->add('POST', "/merchant/orders/{id}/$action", MerchantController::transition($action));
    }

    AdminController::routes($r);
};
