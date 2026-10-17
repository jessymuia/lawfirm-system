<?php

namespace App\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TEN-4: one cell (database server) in the control plane.
 *
 * @property int $id
 * @property string $name
 * @property string|null $region
 * @property string|null $host
 * @property string|null $replica_host ENG-23
 * @property int|null $replica_port
 * @property int|null $port
 * @property string|null $username
 * @property string|null $password_env
 * @property string|null $sqlite_directory
 * @property int $capacity
 * @property bool $accepting
 */
class Cell extends Model
{
    protected $connection = 'control';

    protected $fillable = ['name', 'region', 'host', 'port', 'username', 'password_env', 'sqlite_directory', 'capacity', 'accepting', 'files_disk', 'queue', 'replica_host', 'replica_port'];

    protected $casts = ['port' => 'integer', 'replica_port' => 'integer', 'capacity' => 'integer', 'accepting' => 'boolean'];
}
