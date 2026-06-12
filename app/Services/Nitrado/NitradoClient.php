<?php

namespace App\Services\Nitrado;

use Illuminate\Support\Facades\Http;

class NitradoClient
{
    private const API_BASE = 'https://api.nitrado.net';
    private const FILENAME_RE = '/DayZServer_X1_x64_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})\.ADM$/';

    public function __construct(private string $token, private int $serviceId) {}

    private function post(string $path, array $body): array
    {
        $res = Http::withToken($this->token)->acceptJson()->asJson()->timeout(20)
            ->post(self::API_BASE.$path, $body);
        $json = $res->json();
        if (!$res->ok() || ($json['status'] ?? null) !== 'success') {
            throw new \RuntimeException("Nitrado API error {$res->status()}");
        }
        return $json['data'] ?? [];
    }

    /** @return string[] current banned gamertags from settings.general.bans */
    public function getBans(): array
    {
        $data = $this->get("/services/{$this->serviceId}/gameservers/settings");
        $raw = $data['settings']['general']['bans'] ?? '';
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $raw)), fn ($s) => $s !== ''));
    }

    public function addBan(string $gamertag): void
    {
        $bans = $this->getBans();
        if (in_array($gamertag, $bans, true)) return; // idempotent
        $bans[] = $gamertag;
        $this->updateBans($bans);
    }

    public function removeBan(string $gamertag): void
    {
        $bans = array_values(array_filter($this->getBans(), fn ($b) => $b !== $gamertag));
        $this->updateBans($bans);
    }

    private function updateBans(array $bans): void
    {
        $this->post("/services/{$this->serviceId}/gameservers/settings", [
            'category' => 'general',
            'key' => 'bans',
            'value' => implode("\r\n", $bans),
        ]);
    }

    private function get(string $path): array
    {
        $res = Http::withToken($this->token)->acceptJson()->timeout(20)
            ->get(self::API_BASE.$path);
        $json = $res->json();
        if (!$res->ok() || ($json['status'] ?? null) !== 'success') {
            throw new \RuntimeException("Nitrado API error {$res->status()}");
        }
        return $json['data'] ?? [];
    }

    private function parseFilenameTs(string $name): ?\DateTimeImmutable
    {
        if (!preg_match(self::FILENAME_RE, $name, $m)) return null;
        return new \DateTimeImmutable(str_replace(' ', 'T', "{$m[1]} ".str_replace('-', ':', $m[2]).'Z'));
    }

    /** @return array<int,array{name:string,path:string,timestamp:?\DateTimeImmutable,modifiedAt:?int}> */
    public function listAdmFiles(): array
    {
        $gs = $this->get("/services/{$this->serviceId}/gameservers")['gameserver'] ?? null;
        $base = $gs['game_specific']['path'] ?? null;
        if (!$base) return [];

        $data = $this->get("/services/{$this->serviceId}/gameservers/file_server/list?dir=".urlencode($base.'config'));
        $entries = $data['entries'] ?? [];

        $files = [];
        foreach ($entries as $e) {
            $name = $e['name'] ?? '';
            $path = $e['path'] ?? '';
            if (!str_ends_with($name, '.ADM') || $path === '') continue;
            $ts = $this->parseFilenameTs($name);
            if (!$ts) continue;
            $files[$path] = [
                'name' => $name,
                'path' => $path,
                'timestamp' => $ts,
                'modifiedAt' => isset($e['modified_at']) && is_int($e['modified_at']) ? $e['modified_at'] : null,
            ];
        }

        $files = array_values($files);
        usort($files, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']); // oldest first
        return $files;
    }

    public function downloadFile(string $filePath): string
    {
        $data = $this->get("/services/{$this->serviceId}/gameservers/file_server/download?file=".urlencode($filePath));
        $url = $data['token']['url'] ?? null;
        if (!$url) throw new \RuntimeException("Missing download url for {$filePath}");
        $res = Http::timeout(30)->get($url);
        if (!$res->ok()) throw new \RuntimeException("Download failed {$res->status()} for {$filePath}");
        return $res->body();
    }
}
