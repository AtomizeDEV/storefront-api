<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Internal\v1\Order as OrderResource;
use Fleetbase\Models\Order;
use Fleetbase\Models\Driver;
use Fleetbase\Models\Route;

class LiveController extends Controller
{
    public function coordinates()
    {
        $coordinates = [];

        $orders = Order::where('company_uuid', session('company'))
            ->whereNotIn('status', ['canceled', 'completed'])
            ->get();

        foreach ($orders as $order) {
            $coordinates[] = $order->getCurrentDestinationLocation();
        }

        return response()->json($coordinates);
    }

    public function routes()
    {
        $routes = Route::where('company_uuid', session('company'))
            ->whereHas(
                'order',
                function ($q) {
                    $q->whereNotIn('status', ['canceled', 'completed']);
                    $q->whereNotNull('driver_assigned_uuid');
                    $q->whereNull('deleted_at');
                }
            )
            ->get();

        return response()->json($routes);
    }

    public function orders()
    {
        $orders = Order::where('company_uuid', session('company'))
            ->whereHas('payload')
            ->whereNotIn('status', ['canceled', 'completed'])
            ->whereNotNull('driver_assigned_uuid')
            ->whereNull('deleted_at')
            ->get();

        return OrderResource::collection($orders);
    }

    public function drivers()
    {
        $drivers = Driver::where(['company_uuid' => session('company'), 'online' => 1])
            ->whereHas(
                'currentJob',
                function ($q) {
                    $q->whereNotIn('status', ['canceled', 'completed']);
                }
            )
            ->get();

        return response()->json($drivers);
    }
}
