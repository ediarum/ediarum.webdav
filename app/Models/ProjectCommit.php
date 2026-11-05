<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCommit extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'committed_at',
        'commit_hash',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
