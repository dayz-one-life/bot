<?php

use Illuminate\Support\Facades\Schema;

it('boots the framework and can use the schema builder', function () {
    Schema::create('smoke', function ($t) { $t->id(); $t->string('name'); });
    expect(Schema::hasTable('smoke'))->toBeTrue();
});
