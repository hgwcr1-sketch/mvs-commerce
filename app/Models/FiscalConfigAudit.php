<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalConfigAudit extends Model
{
    public const TYPE_CONNECTION_STAGED = 'connection_staged';

    public const TYPE_CONNECTION_ACTIVATED = 'connection_activated';

    public const TYPE_CONNECTION_DISCARDED = 'connection_discarded';

    public const TYPE_DISCONNECTED = 'disconnected';

    public const TYPE_VERIFIED = 'verified';

    public const TYPE_VERIFY_FAILED = 'verify_failed';

    public const TYPE_IDENTITY = 'identity';

    public const TYPE_PREFERENCES = 'preferences';

    public const RESULT_OK = 'ok';

    public const RESULT_ERROR = 'error';

    protected $fillable = [
        'company_id',
        'user_id',
        'environment',
        'change_type',
        'result',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
