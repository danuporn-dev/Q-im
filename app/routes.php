<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CustomerController;
use App\Controllers\MerchantController;
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
    $r->add('POST', '/auth/dev', [AuthController::class, 'dev']);
    $r->add('POST', '/auth/login', [AuthController::class, 'login']);
    $r->add('POST', '/auth/logout', [AuthController::class, 'logout']);
    $r->add('GET', '/me', [AuthController::class, 'me']);

    // Discovery (public)
    $r->add('GET', '/shops', [CatalogController::class, 'shops']);
    $r->add('GET', '/categories', [CatalogController::class, 'categories']);
    $r->add('GET', '/shops/{id}', [CatalogController::class, 'shop']);
    $r->add('GET', '/shops/{id}/menus', [CatalogController::class, 'menus']);
    $r->add('GET', '/images/{key}', [CatalogController::class, 'image']);

    // Customer
    $r->add('GET', '/cart', [CustomerController::class, 'cart']);
    $r->add('PUT', '/cart', [CustomerController::class, 'updateCart']);
    $r->add('POST', '/orders', [CustomerController::class, 'checkout']);
    $r->add('GET', '/orders', [CustomerController::class, 'orders']);
    $r->add('GET', '/orders/{id}', [CustomerController::class, 'order']);
    $r->add('POST', '/orders/{id}/cancel', [CustomerController::class, 'cancel']);

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
    foreach (['accept', 'reject', 'prepare', 'ready', 'cancel'] as $action) {
        $r->add('POST', "/merchant/orders/{id}/$action", MerchantController::transition($action));
    }
};
