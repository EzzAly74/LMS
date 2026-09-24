<?php
namespace App\Services;
use App\Http\Traits\HelperTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class HRSystemService
{
    use HelperTrait;
    public function __construct()
    {
        $this->client = new Client();
        $this->hr_base_url = config('hr.base_url');
        $this->verify_ssl  = filter_var(config('hr.verify_ssl', true), FILTER_VALIDATE_BOOLEAN);
    }

    /************************ Main Integration *************************/
    /**
     * @param  bool  $asMultipart  Send $data as multipart/form-data instead of
     *                             JSON. Required by the endpoints HR declares
     *                             that way in its swagger (e.g.
     *                             `Auth/Mobilelogin`), which answer HTTP 500
     *                             to a JSON body.
     */
    public function thirdPartyIntegration($method, $url, $data = null, $token = null, bool $asMultipart = false)
    {
        try {
            $options = [
                'json' => $data ?? (object)[],
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'verify'  => $this->verify_ssl,
                'timeout' => 20,
            ];
            if ($token) {
                $options['headers']['Authorization'] = 'Bearer ' . $token;
            }
            if ($data) {
                $options['json'] = $data;
            }
            if ($asMultipart) {
                // Guzzle sets its own Content-Type (with the boundary) for
                // multipart, so the JSON body and header must both go.
                unset($options['json'], $options['headers']['Content-Type']);
                $options['multipart'] = collect($data ?? [])
                    ->map(fn ($value, $name) => [
                        'name'     => $name,
                        'contents' => (string) $value,
                    ])
                    ->values()
                    ->all();
            }
            $response = $this->client->request($method, $url, $options);
            return json_decode($response->getBody()->getContents(), false);
        } catch (RequestException $e) {
            return $this->handleException($e);
        } catch (\Throwable $e) {
            Log::error('HRSystemService request failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /************************Handle Exception*************************/
    public function handleException(RequestException $e)
    {
        Log::error('HRSystemService RequestException', [
            'message' => $e->getMessage(),
            'body'    => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
        ]);
        return null;
    }

    /************************ Employees Login Hr System *************************/
    /**
     * Track the last reason getAccessToken returned null. One of:
     *  - 'unreachable' : Could not contact the HR API (network/SSL/timeout).
     *  - 'invalid'     : HR API responded but rejected the credentials.
     *  - null          : No error (success).
     */
    public ?string $lastError = null;

    /**
     * The raw rejection message HR returned on the last failed attempt, e.g.
     * "خطا فى اسم المستخدم" (unknown username) vs "خطا فى كلمه المرور" (wrong
     * password). HR answers both with HTTP 200 + `success:false`, so this is
     * the only signal that tells the two apart — keep it for logging.
     */
    public ?string $lastMessage = null;

    /**
     * Endpoint used to authenticate a learner (as opposed to the HR admin
     * account). `Auth/login` gates on a control-panel permission and refuses
     * ordinary employees; `Auth/Mobilelogin` is the app-facing endpoint that
     * does not, so every learner sign-in goes through it.
     */
    public const LEARNER_LOGIN_ENDPOINT = 'Auth/Mobilelogin';

    /**
     * Exchange credentials for an HR token.
     *
     * @param  string  $endpoint  HR path to post to, relative to the base url.
     *                            Defaults to `Auth/login` (the admin/service
     *                            account route); learner sign-ins pass
     *                            {@see self::LEARNER_LOGIN_ENDPOINT}.
     */
    public function getAccessToken($email, $password, $getUserDetails = false, string $endpoint = 'Auth/login')
    {
        $this->lastError   = null;
        $this->lastMessage = null;

        // `Auth/Mobilelogin` is declared as multipart/form-data with PascalCase
        // fields in HR's swagger and answers HTTP 500 to anything else;
        // `Auth/login` takes the JSON DTO with lowercase fields.
        $isMultipart = $endpoint === self::LEARNER_LOGIN_ENDPOINT;

        $data = $isMultipart
            ? ['Email' => $email, 'Password' => $password]
            : ['email' => $email, 'password' => $password];

        $login = $this->thirdPartyIntegration(
            'POST',
            $this->hr_base_url . $endpoint,
            $data,
            null,
            $isMultipart
        );

        if ($login === null) {
            $this->lastError = 'unreachable';
            return null;
        }

        if (!is_object($login) || !isset($login->data)) {
            $this->lastError   = 'invalid';
            $this->lastMessage = is_object($login) ? ($login->message ?? null) : null;
            return null;
        }

        return $getUserDetails ? $login->data : ($login->data->token ?? null);
    }


    /************************ Get All Employees From HR System *************************/
    /**
     * Fetch all current employees from the HR system.
     *
     * @param  string  $culture  Language for returned name fields: 'ar' or 'en' (default 'ar')
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function getAllEmployees(string $culture = 'ar')
    {
        $email = config('hr.admin_email');
        $password = config('hr.admin_password');
        $token = $this->getAccessToken($email, $password);
        if (!$token) {
            // Return the documented empty Collection rather than a
            // JsonResponse — callers are console commands and services that
            // go straight on to ->filter()/->mapWithKeys(), so a response
            // object here turned a bad HR_ADMIN_* credential into a fatal
            // __call() error that hid the real cause.
            Log::error('HRSystemService: could not obtain an HR admin token', [
                'hr_message' => $this->lastMessage,
                'reason'     => $this->lastError,
            ]);
            return collect();
        }

        $url  = $this->hr_base_url . 'Employee/GetCurrentEmployees?culture=' . $culture;
        $data = $this->thirdPartyIntegration('POST', $url, null, $token);

        if (!is_object($data) || !isset($data->statusCode)) {
            return collect();
        }

        return ($data->statusCode == 200) ? collect($data->data ?? []) : collect();
    }

    /************************ Get All Jobs From HR System *************************/
    /**
     * Pulls the authoritative job catalogue from the HR system.
     *
     * The endpoint returns a flat list of job records shaped as
     * `{ id, name, notes, creationTime, lastModificationTime, … }`.
     * It does **not** carry an employee count; pair with
     * {@see self::getAllEmployees()} when filtering by "has employees".
     *
     * @param  string  $culture  Language for returned name fields: 'ar' or 'en' (default 'ar')
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function getAllJobs(string $culture = 'ar')
    {
        $token = $this->getAccessToken(config('hr.admin_email'), config('hr.admin_password'));
        if (! $token) {
            return collect();
        }

        $url  = $this->hr_base_url . 'Job?culture=' . $culture;
        $data = $this->thirdPartyIntegration('POST', $url, null, $token);

        if (! is_object($data) || ! isset($data->data) || ! is_array($data->data)) {
            return collect();
        }

        return collect($data->data);
    }
}
