<?php
declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Models;

use Illuminate\Database\Eloquent\Model;

final class PhoneticSpeakingRecording extends Model
{
    protected $table = 'phonetic_speaking_recordings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'reported_duration_seconds' => 'integer',
            'score' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function word()
    {
        return $this->belongsTo(PhoneticEntry::class, 'entry_id');
    }
}
