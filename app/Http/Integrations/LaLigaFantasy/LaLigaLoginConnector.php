<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy;

use App\Http\Integrations\LaLigaFantasy\Requests\AuthorizeRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\ConfirmSignInRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\ExchangeAuthorizationCodeRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\LoginRequest;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;
use Throwable;

class LaLigaLoginConnector extends Connector
{
    use HasTimeout;

    private CookieJar $cookieJar;

    public function __construct()
    {
        $this->cookieJar = new CookieJar;
    }

    protected float $connectTimeout = 3;

    protected float $requestTimeout = 10;

    public function resolveBaseUrl(): string
    {
        return (string) config('services.la_liga_login.base_url');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            RequestOptions::COOKIES => $this->cookieJar,
        ];
    }

    public function cookies(): CookieJar
    {
        return $this->cookieJar;
    }

    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException|Throwable
     */
    public function accessToken(): string
    {
        $policy = (string) config('services.la_liga_login.policy');
        $clientId = (string) config('services.la_liga_login.client_id');
        $redirectUri = (string) config('services.la_liga_login.redirect_uri');
        $email = (string) config('services.la_liga_login.email');
        $password = (string) config('services.la_liga_login.password');
        $guestId = Str::uuid()->toString();
        $authorizeUrl = $this->resolveBaseUrl().'/laligadspprob2c.onmicrosoft.com/oauth2/v2.0/authorize?'.http_build_query([
            'p' => strtoupper($policy),
            'guestId' => $guestId,
            'scope' => "openid {$clientId} offline_access",
            'response_type' => 'code',
            'la' => 'es-es',
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
        ]);

        $this->cookieJar->setCookie(new SetCookie([
            'Name' => 'URL_AUTHORIZE',
            'Value' => $authorizeUrl,
            'Domain' => 'login.laliga.es',
            'Path' => '/',
        ]));

        $authorizeResponse = $this->send(new AuthorizeRequest(
            policy: $policy,
            clientId: $clientId,
            redirectUri: $redirectUri,
            guestId: $guestId,
        ))->throw();
        $loginContext = $this->loginContext($authorizeResponse->body());

        $this->send(new LoginRequest(
            email: $email,
            password: $password,
            transaction: $loginContext['transaction'],
            policy: $policy,
            csrfToken: $loginContext['csrfToken'],
            authorizeUrl: $authorizeUrl,
        ))->throw();

        $confirmationResponse = $this->send(new ConfirmSignInRequest(
            transaction: $loginContext['transaction'],
            policy: $policy,
            csrfToken: $loginContext['csrfToken'],
        ));
        $location = $confirmationResponse->header('Location');

        if (!is_string($location)) {
            throw new RuntimeException('The login confirmation did not return an authorization redirect.');
        }

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $authorizationCode = $query['code'] ?? null;

        if (!is_string($authorizationCode) || $authorizationCode === '') {
            throw new RuntimeException('The login confirmation did not return an authorization code.');
        }

        $tokenResponse = $this->send(new ExchangeAuthorizationCodeRequest(
            authorizationCode: $authorizationCode,
            policy: $policy,
            clientId: $clientId,
            redirectUri: $redirectUri,
        ))->throw();
        $accessToken = $tokenResponse->json('access_token');

        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('The token exchange did not return an access token.');
        }

        return $accessToken;
    }

    /**
     * @return array{csrfToken: string, transaction: string}
     */
    private function loginContext(string $html): array
    {
        preg_match('/"csrf"\s*:\s*"([^"]+)"/', $html, $csrfMatches);
        preg_match('/"transId"\s*:\s*"([^"]+)"/', $html, $transactionMatches);

        $csrfToken = $csrfMatches[1] ?? null;
        $transaction = $transactionMatches[1] ?? null;

        if (!is_string($csrfToken) || !is_string($transaction)) {
            throw new RuntimeException('The B2C authorize response did not include the login context.');
        }

        return [
            'csrfToken' => $csrfToken,
            'transaction' => $transaction,
        ];
    }
}
