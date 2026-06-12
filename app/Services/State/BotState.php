<?php

namespace App\Services\State;

use Illuminate\Support\Facades\DB;

class BotState
{
    public function get(string $key, ?string $default = null): ?string
    {
        $row = DB::table('bot_state')->where('key', $key)->first();
        return $row->value ?? $default;
    }

    public function set(string $key, string $value): void
    {
        DB::table('bot_state')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === null ? $default : (int) $v;
    }

    public function setInt(string $key, int $value): void
    {
        $this->set($key, (string) $value);
    }

    public function delete(string $key): void
    {
        DB::table('bot_state')->where('key', $key)->delete();
    }
}
