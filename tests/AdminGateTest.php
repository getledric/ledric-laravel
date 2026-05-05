<?php

namespace Ledric\Laravel\Tests;

use Illuminate\Http\Request;
use Ledric\Laravel\Http\Middleware\AdminGate;

class AdminGateTest extends TestCase
{
    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);

        $gate = new AdminGate();
        $req  = Request::create('/ledric-admin');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $gate->handle($req, function () {
            return 'ok';
        });
    }

    public function test_user_not_in_allowlist_is_rejected(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);

        $user = new FakeUser(99);
        $this->actingAs($user);

        $gate = new AdminGate();
        $req  = Request::create('/ledric-admin');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $gate->handle($req, function () {
            return 'ok';
        });
    }

    public function test_user_in_allowlist_passes(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);

        $user = new FakeUser(7);
        $this->actingAs($user);

        $gate = new AdminGate();
        $req  = Request::create('/ledric-admin');

        $result = $gate->handle($req, function () {
            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function test_empty_allowlist_rejects_everyone(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', []);

        $user = new FakeUser(7);
        $this->actingAs($user);

        $gate = new AdminGate();
        $req  = Request::create('/ledric-admin');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $gate->handle($req, function () {
            return 'ok';
        });
    }
}

class FakeUser implements \Illuminate\Contracts\Auth\Authenticatable
{
    /** @var mixed */
    private $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPassword()
    {
        return '';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName()
    {
        return '';
    }
}
