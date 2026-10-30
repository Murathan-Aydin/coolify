<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A member's role on one application, service or database, overriding their role on its project.
 */
class ResourceUser extends Model
{
    protected $table = 'resource_user';

    protected $fillable = ['user_id', 'resourceable_type', 'resourceable_id', 'role'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resourceable(): MorphTo
    {
        return $this->morphTo();
    }
}
