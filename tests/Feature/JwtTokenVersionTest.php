<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tymon\JWTAuth\JWT;

class JwtTokenVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('test-only-', 8),
            'jwt.algo' => 'HS256',
            'lighthouse.schema_cache.enable' => false,
        ]);

        Route::get('/jwt-version-test', fn () => response()->json(['user_id' => Auth::id()]))
            ->middleware('auth:api');

        Schema::create('licenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
        });
    }

    public function test_password_reset_revokes_old_jwts_and_accepts_new_login(): void
    {
        $user = User::factory()->create();
        $oldToken = app(JWT::class)->fromUser($user);

        $this->withToken($oldToken)->getJson('/jwt-version-test')
            ->assertOk()->assertJsonPath('user_id', $user->id);

        // leget-auth increments the shared DB version atomically with the reset.
        $user->forceFill(['token_version' => 1])->save();
        $this->forgetAuthentication();
        $this->withToken($oldToken)->getJson('/jwt-version-test')->assertUnauthorized();

        $newToken = app(JWT::class)->fromUser($user->fresh());
        $this->forgetAuthentication();
        $this->withToken($newToken)->getJson('/jwt-version-test')
            ->assertOk()->assertJsonPath('user_id', $user->id);
    }

    public function test_jwt_without_version_is_accepted_only_before_first_reset(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenWithVersion($user, null, omitVersion: true);

        $this->withToken($token)->getJson('/jwt-version-test')->assertOk();

        $user->forceFill(['token_version' => 1])->save();
        $this->forgetAuthentication();
        $this->withToken($token)->getJson('/jwt-version-test')->assertUnauthorized();
    }

    public function test_refresh_preserves_version_and_cannot_revive_a_revoked_session(): void
    {
        $user = User::factory()->create(['token_version' => 1]);
        $token = app(JWT::class)->fromUser($user);
        $refreshed = app(JWT::class)->setToken($token)->refresh();

        $this->forgetAuthentication();
        $this->withToken($refreshed)->getJson('/jwt-version-test')
            ->assertOk()->assertJsonPath('user_id', $user->id);

        $user->forceFill(['token_version' => 2])->save();
        $this->forgetAuthentication();
        // Even refreshing an otherwise valid signed JWT keeps its revoked version.
        $staleRefresh = app(JWT::class)->setToken($refreshed)->refresh();
        $this->forgetAuthentication();
        $this->withToken($staleRefresh)->getJson('/jwt-version-test')->assertUnauthorized();
    }

    #[DataProvider('invalidVersions')]
    public function test_malformed_and_mismatched_versions_are_rejected(mixed $version): void
    {
        $user = User::factory()->create();
        $token = $this->tokenWithVersion($user, $version);

        $this->withToken($token)->getJson('/jwt-version-test')->assertUnauthorized();
    }

    public static function invalidVersions(): array
    {
        return [
            'string' => ['0'],
            'boolean' => [false],
            'null' => [null],
            'float' => [0.5],
            'negative' => [-1],
            'future version' => [1],
        ];
    }

    public function test_graphql_protected_queries_reject_revoked_sessions(): void
    {
        $user = User::factory()->create();
        $oldToken = app(JWT::class)->fromUser($user);
        $query = ['query' => '{ myLicenses { id } }'];

        $this->withToken($oldToken)->postJson('/graphql', $query)
            ->assertOk()->assertJsonPath('data.myLicenses', [])->assertJsonMissingPath('errors');

        $user->forceFill(['token_version' => 1])->save();
        $this->forgetAuthentication();
        $this->withToken($oldToken)->postJson('/graphql', $query)
            ->assertOk()->assertJsonPath('errors.0.message', 'Unauthenticated.')
            ->assertJsonPath('errors.0.extensions.guards', ['api'])->assertJsonPath('data', null);

        $newToken = app(JWT::class)->fromUser($user->fresh());
        $this->forgetAuthentication();
        $this->withToken($newToken)->postJson('/graphql', $query)
            ->assertOk()->assertJsonPath('data.myLicenses', [])->assertJsonMissingPath('errors');
    }

    #[DataProvider('graphqlTokenSources')]
    public function test_graphql_alternate_token_sources_cannot_bypass_revocation(string $source): void
    {
        $user = User::factory()->create();
        $token = app(JWT::class)->fromUser($user);
        $body = ['query' => '{ myLicenses { id } }'];
        $uri = '/graphql';
        if ($source === 'body') {
            $body['token'] = $token;
        } else {
            $uri .= '?token='.rawurlencode($token);
        }

        $this->postJson($uri, $body)
            ->assertOk()->assertJsonPath('data.myLicenses', [])->assertJsonMissingPath('errors');

        $user->forceFill(['token_version' => 1])->save();
        $this->forgetAuthentication();

        $this->postJson($uri, $body)
            ->assertOk()->assertJsonPath('errors.0.message', 'Unauthenticated.')
            ->assertJsonPath('errors.0.extensions.guards', ['api'])->assertJsonPath('data', null);
    }

    public static function graphqlTokenSources(): array
    {
        return ['body token' => ['body'], 'query token' => ['query']];
    }

    private function tokenWithVersion(User $user, mixed $version, bool $omitVersion = false): string
    {
        $claims = app(JWT::class)->makePayload($user)->toArray();
        if ($omitVersion) {
            unset($claims['token_version']);
        } else {
            $claims['token_version'] = $version;
        }

        $payload = app('tymon.jwt.payload.factory')->customClaims($claims)->make(true);

        return app('tymon.jwt.manager')->encode($payload)->get();
    }

    /** Mimic the fresh guard and token instances of the next HTTP request. */
    private function forgetAuthentication(): void
    {
        Auth::forgetGuards();
        app(JWT::class)->unsetToken();
    }
}
