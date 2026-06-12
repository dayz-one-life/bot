<?php

use App\Services\Admin\AdminGuard;

beforeEach(fn () => $this->guard = new AdminGuard());

it('authorizes a member holding the configured admin role', function () {
    expect($this->guard->isAuthorized(['111', '222'], '222'))->toBeTrue();
});

it('denies a member without the admin role', function () {
    expect($this->guard->isAuthorized(['111'], '222'))->toBeFalse();
});

it('denies when no admin role is configured (fail closed)', function () {
    expect($this->guard->isAuthorized(['111', '222'], null))->toBeFalse();
    expect($this->guard->isAuthorized(['111'], ''))->toBeFalse();
});

it('compares role ids as strings (mixed int/string input)', function () {
    expect($this->guard->isAuthorized([111, 222], '222'))->toBeTrue();
});
