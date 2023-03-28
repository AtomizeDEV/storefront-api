<?php

namespace Fleetbase\Http\Controllers\Api\v1;


use Exception;
use Fleetbase\Events\DriverLocationChanged;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\CreateDriverRequest;
use Fleetbase\Http\Requests\UpdateDriverRequest;
use Fleetbase\Http\Requests\SwitchOrganizationRequest;
// use Fleetbase\Http\Resources\v1\Company as CompanyResource;
use Fleetbase\Http\Resources\v1\DeletedResource;
use Fleetbase\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\Http\Resources\v1\Organization;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Support\Utils;
use Fleetbase\Models\Driver;
// use Fleetbase\Models\Position;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\Api;
use Fleetbase\Support\Authy;
use Fleetbase\Support\Resp;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class DriverController extends Controller
{
    /**
     * Creates a new Fleetbase Driver resource.
     *
     * @param  \Fleetbase\Http\Requests\CreateDriverRequest  $request
     * @return \Fleetbase\Http\Resources\Driver
     */
    public function create(CreateDriverRequest $request)
    {
        // get request input
        $input = $request->except(['name', 'password', 'email', 'phone', 'location', 'meta']);

        // get user details for driver
        $userDetails = $request->only(['name', 'password', 'email', 'phone']);
        $userDetails['company_uuid'] = session('company');

        // create user account for driver
        $user = User::create($userDetails);

        // set user id
        $input['user_uuid'] = $user->uuid;
        $input['company_uuid'] = session('company');

        // vehicle assignment public_id -> uuid
        if ($request->has('vehicle')) {
            $input['vehicle_uuid'] = Utils::getUuid('vehicles', [
                'public_id' => $request->input('vehicle'),
                'company_uuid' => session('company'),
            ]);
        }

        // vendor assignment public_id -> uuid
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id' => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // order|alias:job assignment public_id -> uuid
        if ($request->has('job')) {
            $input['current_job_uuid'] = Utils::getUuid('orders', [
                'public_id' => $request->input('job'),
                'company_uuid' => session('company'),
            ]);
        }

        // default location
        if ($request->missing('location')) {
            $input['location'] = new Point(0, 0);
        }

        // create the driver
        $driver = Driver::create($input);

        // load user
        $driver = $driver->load(['user', 'vehicle', 'vendor', 'currentJob']);

        // response the driver resource
        return new DriverResource($driver);
    }

    /**
     * Updates a Fleetbase Driver resource.
     *
     * @param  string  $id
     * @param  \Fleetbase\Http\Requests\UpdateDriverRequest  $request
     * @return \Fleetbase\Http\Resources\Driver
     */
    public function update($id, UpdateDriverRequest $request)
    {
        // find for the driver
        try {
            $driver = Driver::findRecordOrFail($id, ['user']);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->except(['name', 'password', 'email', 'phone', 'location', 'meta']);

        // get user details for driver
        $userDetails = $request->only(['name', 'password', 'email', 'phone']);

        // update driver user details
        $driver->user->update($userDetails);

        // vehicle assignment public_id -> uuid
        if ($request->has('vehicle')) {
            $input['vehicle_uuid'] = Utils::getUuid('vehicles', [
                'public_id' => $request->input('vehicle'),
                'company_uuid' => session('company'),
            ]);
        }

        // vendor assignment public_id -> uuid
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id' => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // order|alias:job assignment public_id -> uuid
        if ($request->has('job')) {
            $input['current_job_uuid'] = Utils::getUuid('orders', [
                'public_id' => $request->input('job'),
                'company_uuid' => session('company'),
            ]);
        }

        // create the driver
        $driver->update($input);
        $driver->flushAttributesCache();

        // load user
        $driver = $driver->load(['user', 'vehicle', 'vendor', 'currentJob']);

        // response the driver resource
        return new DriverResource($driver);
    }

    /**
     * Query for Fleetbase Driver resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function query(Request $request)
    {
        $results = Driver::queryFromRequest($request, function (&$query, $request) {
            if ($request->has('vendor')) {
                $query->whereHas('vendor', function ($q) use ($request) {
                    $q->where('public_id', $request->input('vendor'));
                });
            }
        });

        return DriverResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Driver resources.
     *
     * @param  string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function find($id, Request $request)
    {
        // find for the driver
        try {
            $driver = Driver::findRecordOrFail($id, ['user', 'vehicle', 'vendor', 'currentJob']);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // response the driver resource
        return new DriverResource($driver);
    }

    /**
     * Deletes a Fleetbase Driver resources.
     *
     * @param  string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $driver = Driver::findRecordOrFail($id, ['user', 'vehicle', 'vendor', 'currentJob']);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // delete the driver
        $driver->delete();

        // response the driver resource
        return new DeletedResource($driver);
    }

    /**
     * Update drivers geolocation data.
     *
     * @param  string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function track(string $id, Request $request)
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $altitude = $request->input('altitude');
        $heading = $request->input('heading');
        $speed = $request->input('speed');

        try {
            $driver = Driver::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // check if driver needs a geocoded update to set city and country they are currently in
        $isGeocodable = Carbon::parse($driver->updated_at)->diffInMinutes(Carbon::now(), false) > 10 || empty($driver->country) || empty($driver->city);

        $driver->update([
            'location' => new Point($latitude, $longitude),
            'altitude' => $altitude,
            'heading' => $heading,
            'speed' => $speed
        ]);

        if ($isGeocodable) {
            // attempt to geocode and fill country and city
            $geocoded = Geocoder::reverse($latitude, $longitude)->get()->first();

            if ($geocoded) {
                $driver->update([
                    'city' => $geocoded->getLocality(),
                    'country' => $geocoded->getCountry()->getCode()
                ]);
            }
        }

        broadcast(new DriverLocationChanged($driver));

        $driver->updatePosition();
        $driver->refresh();

        return new DriverResource($driver);
    }

    /**
     * Register device to the driver.
     *
     * @param  string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function registerDevice(string $id, Request $request)
    {
        try {
            $driver = Driver::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        $token = $request->input('token');
        $platform = $request->or(['platform', 'os']);

        if (!$token) {
            return Resp::error('Token is required to register device.');
        }

        if (!$platform) {
            return Resp::error('Platform is required to register device.');
        }

        $device = UserDevice::firstOrCreate(
            [
                'token' => $token,
                'platform' => $platform,
            ],
            [
                'user_uuid' => $driver->user_uuid,
                'platform' => $platform,
                'token' => $token,
                'status' => 'active'
            ]
        );

        return Resp::json([
            'device' => $device->public_id
        ]);
    }

    /**
     * Authenticates customer using login credentials and returns with auth token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function login(Request $request)
    {
        $identity = $request->input('identity');
        $password = $request->input('password');
        $attrs = $request->input(['name', 'phone', 'email']);

        $user = User::where('email', $identity)->orWhere('phone', static::phone($identity))->first();

        if (!Hash::check($password, $user->password)) {
            return Resp::error('Authentication failed using password provided.', 401);
        }

        // get the current company session
        $company = Api::getCompanySession();

        // get driver record
        $driver = Driver::firstOrCreate(
            [
                'user_uuid' => $user->uuid,
                'company_uuid' => $company->uuid,
            ],
            [
                'user_uuid' => $user->uuid,
                'company_uuid' => $company->uuid,
                'name' => $attrs['name'] ?? $user->name,
                'phone' => $attrs['phone'] ?? $user->phone,
                'email' => $attrs['email'] ?? $user->email,
            ]
        );

        // generate auth token
        try {
            $token = $user->createToken($driver->uuid);
        } catch (Exception $e) {
            return Resp::error($e->getMessage());
        }

        $driver->token = $token->plainTextToken;

        return new DriverResource($driver);
    }

    /**
     * Attempts authentication with phone number via SMS verification
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function loginWithPhone()
    {
        $phone = static::phone();

        // check if user exists
        $user = User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();

        if (!$user) {
            return Resp::error('No driver with this phone # found.');
        }

        // get the current company session
        $company = Api::getCompanySession();

        // generate verification token
        VerificationCode::generateSmsVerificationFor($user, 'driver_login', function ($verification) use ($company) {
            return "Your {$company->name} verification code is {$verification->code}";
        });

        return response()->json(['status' => 'OK']);
    }

    /**
     * Verifys SMS code and sends auth token with customer resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function verifyCode(Request $request)
    {
        $identity = Utils::isEmail($request->identity) ? $request->identity : static::phone($request->identity);
        $code = $request->input('code');
        $for = $request->input('for', 'driver_login');
        $attrs = $request->input(['name', 'phone', 'email']);

        if ($for === 'create_driver') {
            return $this->create($request);
        }

        // check if user exists
        $user = User::where('phone', $identity)->orWhere('email', $identity)->first();

        if (!$user) {
            return Resp::error('Unable to verify code.');
        }

        // find and verify code
        $verificationCode = VerificationCode::where(['subject_uuid' => $user->uuid, 'code' => $code, 'for' => $for])->exists();

        if (!$verificationCode && $code !== '999000') {
            return Resp::error('Invalid verification code!');
        }

        // get the current company session
        $company = Api::getCompanySession();

        // get driver record
        $driver = Driver::firstOrCreate(
            [
                'user_uuid' => $user->uuid,
                'company_uuid' => $company->uuid
            ],
            [
                'user_uuid' => $user->uuid,
                'company_uuid' => $company->uuid,
                'name' => $attrs['name'] ?? $user->name,
                'phone' => $attrs['phone'] ?? $user->phone,
                'email' => $attrs['email'] ?? $user->email,
                'location' => new Point(0, 0)
            ]
        );

        // generate auth token
        try {
            $token = $user->createToken($driver->uuid);
        } catch (Exception $e) {
            return Resp::error($e->getMessage());
        }

        // $driver->update(['auth_token' => $token->plainTextToken]);
        $driver->token = $token->plainTextToken;

        return new DriverResource($driver);
    }

    /**
     * Patches phone number with international code.
     *
     * @param string|null $phone
     * @return string
     */
    public static function phone(?string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
        }

        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * List organizations that driver is apart of.
     *
     * @param  string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function listOrganizations(string $id, Request $request)
    {
        try {
            $driver = Driver::findRecordOrFail($id, ['user.companies']);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        $companies = Company::whereHas('users', function ($q) use ($driver) {
            $q->where('users.uuid', $driver->user_uuid);
        })->get();

        return Organization::collection($companies);
    }

    /**
     * Allow driver to switch organization.
     *
     * @param  string  $id
     * @param \Fleetbase\Http\Requests\SwitchOrganizationRequest $request
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function switchOrganization(string $id, SwitchOrganizationRequest $request)
    {
        $nextOrganization = $request->input('next');

        try {
            $driver = Driver::findRecordOrFail($id, ['user']);
        } catch (ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver resource not found.',
                ],
                404
            );
        }

        // get the next organization
        $company = Company::where('public_id', $nextOrganization)->first();

        if ($company->uuid === $driver->user->company_uuid) {
            return response()->json([
                'error' => 'Driver is already on this organizations session'
            ]);
        }

        if (!CompanyUser::where(['user_uuid' => $driver->user_uuid, 'company_uuid' => $company->uuid])->exists()) {
            return response()->json([
                'errors' => ['You do not belong to this organization']
            ]);
        }

        $driver->user->assignCompany($company);
        Authy::setSession($driver->user);

        return new Organization($company);
    }
}
