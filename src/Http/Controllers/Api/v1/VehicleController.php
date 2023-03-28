<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\CreateVehicleRequest;
use Fleetbase\Http\Requests\UpdateVehicleRequest;
use Fleetbase\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\Models\Vehicle;
use Fleetbase\Models\Driver;
use Fleetbase\Support\Utils;
use Exception;
use Fleetbase\Http\Resources\v1\DeletedResource;

class VehicleController extends Controller
{
    /**
     * Creates a new Fleetbase Vehicle resource.
     *
     * @param  \Fleetbase\Http\Requests\CreateVehicleRequest  $request
     * @return \Fleetbase\Http\Resources\Vehicle
     */
    public function create(CreateVehicleRequest $request)
    {
        // get request input
        $input = $request->only(['status', 'make', 'model', 'year', 'trim', 'type', 'plate_number', 'vin', 'meta']);
        // make sure company is set
        $input['company_uuid'] = session('company');

        // create instance of vehicle model
        $vehicle = new Vehicle();

        // if vin is applied try to decode vin and apply
        if ($request->has('vin')) {
            $vehicle->applyAllDataFromVin($request->input('vin'));
        }

        // vendor assignment
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id' => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // apply user input to vehicle
        $vehicle = $vehicle->fill($input);

        // save the vehicle
        $vehicle->save();

        // driver assignment
        if ($request->has('driver')) {
            // set this vehicle to the driver
            try {
                $driver = Driver::findRecordOrFail($request->input('driver'));
            } catch (ModelNotFoundException $exception) {
                return response()->json(
                    [
                        'error' => 'The driver attempted to assign this vehicle was not found.',
                    ],
                    404
                );
            }

            $driver->vehicle_uuid = $vehicle->uuid;
            $driver->save();
        }

        // response the driver resource
        return new VehicleResource($vehicle);
    }

    /**
     * Updates a Fleetbase Vehicle resource.
     *
     * @param  string  $id
     * @param  \Fleetbase\Http\Requests\UpdateVehicleRequest  $request
     * @return \Fleetbase\Http\Resources\Vehicle
     */
    public function update($id, UpdateVehicleRequest $request)
    {
        // find for the vehicle
        try {
            $vehicle = Vehicle::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['status', 'make', 'model', 'year', 'trim', 'type', 'plate_number', 'vin', 'meta']);

        // vendor assignment
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id' => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // update the vehicle w/ user input
        $vehicle->fill($input);

        // if the vin has changed do another vin run
        if ($vehicle->isDirty('vin')) {
            $vehicle->applyAllDataFromVin();
        }

        // save the update
        $vehicle->save();

        // get udpated vehicle
        $vehicle = $vehicle->refresh();

        // response the vehicle resource
        return new VehicleResource($vehicle);
    }

    /**
     * Query for Fleetbase Vehicle resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function query(Request $request)
    {
        $results = Vehicle::queryFromRequest($request, function (&$query, $request) {
            if ($request->has('vendor')) {
                $query->whereHas('vendor', function ($q) use ($request) {
                    $q->where('public_id', $request->input('vendor'));
                });
            }
        });

        return VehicleResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Vehicle resources.
     *
     * @param  string  $id
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function find($id)
    {
        // find for the vehicle
        try {
            $vehicle = Vehicle::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // response the vehicle resource
        return new VehicleResource($vehicle);
    }

    /**
     * Deletes a Fleetbase Vehicle resources.
     *
     * @param  string  $id
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function delete($id)
    {
        // find for the driver
        try {
            $vehicle = Vehicle::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // delete the vehicle
        $vehicle->delete();

        // response the vehicle resource
        return new DeletedResource($vehicle);
    }
}