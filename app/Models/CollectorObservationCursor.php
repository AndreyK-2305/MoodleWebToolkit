<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $remote_operation_id
 * @property int $execution_id
 * @property int $stdout_offset
 * @property int $last_wire_sequence
 * @property string|null $file_device
 * @property string|null $file_inode
 * @property string $prefix_sha256
 * @property bool $discarding
 * @property string $reader_health
 * @property bool $read_complete
 */
class CollectorObservationCursor extends Model
{
    protected $fillable = ['remote_operation_id', 'execution_id', 'stdout_offset', 'last_wire_sequence',
        'file_device', 'file_inode', 'prefix_sha256', 'discarding', 'reader_health', 'read_complete'];

    protected function casts(): array
    {
        return ['stdout_offset' => 'integer', 'last_wire_sequence' => 'integer', 'discarding' => 'boolean', 'read_complete' => 'boolean'];
    }
}
