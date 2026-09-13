<?php

namespace App\Services;

use App\Enums\AccountCategory;
use App\Models\Account;
use App\Models\Server;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServerOvpnProfileService
{
    public function hasProfile(Server $server): bool
    {
        if (! filled($server->ovpn_profile_path)) {
            return false;
        }

        return Storage::disk('local')->exists($server->ovpn_profile_path);
    }

    public function store(Server $server, UploadedFile $file): void
    {
        $this->deleteStoredFile($server);

        $originalName = basename((string) $file->getClientOriginalName());
        $contents = (string) $file->get();

        $this->writeProfile($server, $contents, $originalName);

        // PPP accounts may move between MikroTik nodes — keep the same .ovpn
        // available on every active MikroTik server so client pages stay complete.
        Server::query()
            ->active()
            ->where('type', \App\Enums\ServerType::Mikrotik)
            ->whereKeyNot($server->id)
            ->get()
            ->each(function (Server $sibling) use ($contents, $originalName): void {
                $this->writeProfile($sibling, $contents, $originalName);
            });
    }

    public function writeProfile(Server $server, string $contents, string $originalName): void
    {
        $this->deleteStoredFile($server);

        $directory = 'servers/'.$server->id.'/ovpn';
        $path = $directory.'/profile.ovpn';
        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->put($path, $contents);

        $server->update([
            'ovpn_profile_path' => $path,
            'ovpn_profile_original_name' => $originalName !== '' ? $originalName : 'client-profile.ovpn',
        ]);
    }

    public function delete(Server $server): void
    {
        $this->deleteStoredFile($server);

        $server->update([
            'ovpn_profile_path' => null,
            'ovpn_profile_original_name' => null,
        ]);
    }

    public function downloadFilename(Server $server): string
    {
        $original = trim((string) $server->ovpn_profile_original_name);

        if ($original !== '' && str_ends_with(strtolower($original), '.ovpn')) {
            return $original;
        }

        return 'client-profile.ovpn';
    }

    public function downloadResponse(Account $account): BinaryFileResponse
    {
        if ($account->service_type->accountCategory() !== AccountCategory::Ppp) {
            abort(404);
        }

        $server = $account->server;

        if ($server === null || ! $server->isMikrotik() || ! $this->hasProfile($server)) {
            abort(404, __('accounts.ovpn_profile_not_available'));
        }

        $absolutePath = Storage::disk('local')->path($server->ovpn_profile_path);

        return response()->download($absolutePath, $this->downloadFilename($server), [
            'Content-Type' => 'application/x-openvpn-profile',
        ]);
    }

    protected function deleteStoredFile(Server $server): void
    {
        if (! filled($server->ovpn_profile_path)) {
            return;
        }

        Storage::disk('local')->delete($server->ovpn_profile_path);
    }
}
