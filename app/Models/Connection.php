<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Connection extends Model
{
    protected $fillable = ['server_id', 'name', 'type', 'status', 'configuration', 'secret_reference'];

    protected $hidden = ['secret_reference'];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'configuration' => 'array',
        ];
    }
}
