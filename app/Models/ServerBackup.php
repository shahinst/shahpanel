<?php

namespace App\Models;

use App\Enums\ServerBackupStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerBackup extends Model
{
    protected $fillable = [
        'server_id',
        'triggered_by',
        'status',
        'storage_dir',
        'manifest',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServerBackupStatus::class,
            'manifest' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function absoluteStoragePath(): string
    {
        return storage_path('app/'.$this->storage_dir);
    }

    /**
     * @return list<string>
     */
    public function backupFileNames(): array
    {
        $files = $this->manifest['files'] ?? [];

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $key): string => is_string($key) ? $key : '',
            array_keys($files)
        ), static fn (string $key): bool => $key !== ''));
    }

    /**
     * @return list<string>
     */
    public function sectionNames(): array
    {
        $sections = $this->manifest['sections'] ?? [];

        if (! is_array($sections)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $key): string => is_string($key) ? $key : '',
            array_keys($sections)
        ), static fn (string $key): bool => $key !== ''));
    }
}
